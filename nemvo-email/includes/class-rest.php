<?php
/**
 * REST API for the admin screens.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under nemvo-email/v1: statistics, the log, resending and test
 * messages. All need the plugin's capability (manage_options by default).
 */
class Rest {

	const NS = 'nemvo-email/v1';

	/**
	 * Register routes.
	 *
	 * No route declares arguments: WordPress validates declared arguments
	 * before it calls permission_callback, so a visitor would get "missing
	 * parameter" instead of 401. Each callback checks its own input after the
	 * permission check. The routes are left out of the public REST index.
	 */
	public static function register_routes() {
		$allowed = static function () {
			return current_user_can( Plugin::cap() );
		};
		$route   = static function ( $method, $callback ) use ( $allowed ) {
			return array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => $allowed,
				'show_in_index'       => false,
			);
		};

		register_rest_route( self::NS, '/stats', $route( 'GET', 'stats' ) );
		register_rest_route( self::NS, '/log', $route( 'GET', 'log' ) );
		register_rest_route(
			self::NS,
			'/log/(?P<id>\d+)',
			array(
				$route( 'GET', 'entry' ),
				$route( 'DELETE', 'delete' ),
			)
		);
		register_rest_route( self::NS, '/log/(?P<id>\d+)/resend', $route( 'POST', 'resend' ) );
		register_rest_route( self::NS, '/test', $route( 'POST', 'test' ) );
	}

	/**
	 * A 400 error in the words WordPress uses for its own argument checks.
	 *
	 * @param string[] $missing Missing parameters.
	 * @param string[] $invalid Invalid parameters.
	 * @return \WP_Error|null
	 */
	private static function param_error( array $missing, array $invalid ) {
		if ( $missing ) {
			return new \WP_Error(
				'rest_missing_callback_param',
				/* translators: %s: parameter names */
				sprintf( __( 'Missing parameter(s): %s', 'nemvo-email' ), implode( ', ', $missing ) ),
				array(
					'status' => 400,
					'params' => $missing,
				)
			);
		}
		if ( $invalid ) {
			return new \WP_Error(
				'rest_invalid_param',
				/* translators: %s: parameter names */
				sprintf( __( 'Invalid parameter(s): %s', 'nemvo-email' ), implode( ', ', $invalid ) ),
				array(
					'status' => 400,
					'params' => $invalid,
				)
			);
		}
		return null;
	}

	/**
	 * Read optional or required YYYY-MM-DD parameters.
	 *
	 * @param \WP_REST_Request $request  Request.
	 * @param string[]         $keys     Parameter names.
	 * @param bool             $required Whether they must be present.
	 * @return array|\WP_Error Values by name ('' when absent).
	 */
	private static function dates( \WP_REST_Request $request, array $keys, $required ) {
		$values  = array();
		$missing = array();
		$invalid = array();
		foreach ( $keys as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value || '' === $value ) {
				if ( $required ) {
					$missing[] = $key;
				}
				$values[ $key ] = '';
			} elseif ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$invalid[] = $key;
			} else {
				$values[ $key ] = $value;
			}
		}
		$error = self::param_error( $missing, $invalid );
		return $error ? $error : $values;
	}

	/**
	 * A string parameter, sanitized ('' when absent or not a string).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $key     Name.
	 * @return string
	 */
	private static function text( \WP_REST_Request $request, $key ) {
		$value = $request->get_param( $key );
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * GET /stats.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function stats( \WP_REST_Request $request ) {
		$dates = self::dates( $request, array( 'from', 'to' ), true );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}
		$from = $dates['from'];
		$to   = $dates['to'];
		if ( $to < $from ) {
			return new \WP_Error( 'range', __( 'The period ends before it starts.', 'nemvo-email' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Stats::report( $from, $to ) );
	}

	/**
	 * GET /log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function log( \WP_REST_Request $request ) {
		$dates   = self::dates( $request, array( 'from', 'to' ), false );
		$invalid = is_wp_error( $dates ) ? $dates->get_error_data()['params'] : array();
		$status  = self::text( $request, 'status' );
		if ( ! in_array( $status, array( '', 'sent', 'failed', 'paused' ), true ) ) {
			$invalid[] = 'status';
		}
		$numbers = array(
			'page'     => array( 1, 1, PHP_INT_MAX ),
			'per_page' => array( 50, 1, 200 ),
		);
		foreach ( $numbers as $key => $limits ) {
			$value = $request->get_param( $key );
			if ( null === $value || '' === $value ) {
				$numbers[ $key ] = $limits[0];
			} elseif ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || (int) $value < $limits[1] || (int) $value > $limits[2] ) {
				$invalid[] = $key;
			} else {
				$numbers[ $key ] = (int) $value;
			}
		}
		$error = self::param_error( array(), $invalid );
		if ( $error ) {
			return $error;
		}
		$source            = $request->get_param( 'source' );
		$result            = Log::query(
			array(
				'status'   => $status,
				'search'   => self::text( $request, 'search' ),
				'source'   => null === $source ? null : self::text( $request, 'source' ),
				'domain'   => self::text( $request, 'domain' ),
				'subject'  => self::text( $request, 'subject' ),
				'from'     => $dates['from'],
				'to'       => $dates['to'],
				'page'     => $numbers['page'],
				'per_page' => $numbers['per_page'],
			)
		);
		$result['sources'] = Log::sources();
		return rest_ensure_response( $result );
	}

	/**
	 * GET /log/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function entry( \WP_REST_Request $request ) {
		$row = Log::get( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'This log entry no longer exists.', 'nemvo-email' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $row );
	}

	/**
	 * DELETE /log/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function delete( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'deleted' => Log::delete( array( (int) $request['id'] ) ) ) );
	}

	/**
	 * POST /log/<id>/resend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resend( \WP_REST_Request $request ) {
		$row = Log::get( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'This log entry no longer exists.', 'nemvo-email' ), array( 'status' => 404 ) );
		}
		$result          = Mailer::resend( $row );
		$result['entry'] = $result['log_id'] ? Log::get( $result['log_id'] ) : null;
		return rest_ensure_response( $result );
	}

	/**
	 * POST /test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test( \WP_REST_Request $request ) {
		$error = self::param_error( null === $request->get_param( 'to' ) ? array( 'to' ) : array(), array() );
		if ( $error ) {
			return $error;
		}
		$to = sanitize_email( self::text( $request, 'to' ) );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'to', __( 'Enter a valid email address.', 'nemvo-email' ), array( 'status' => 400 ) );
		}
		$result          = Mailer::send_test( $to );
		$result['entry'] = $result['log_id'] ? Log::get( $result['log_id'] ) : null;
		return rest_ensure_response( $result );
	}
}
