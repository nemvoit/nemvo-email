<?php
/**
 * Personal data: export, erasure and the privacy policy text.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * The log holds email addresses and subjects (and, when kept, content).
 * Tools › Export/Erase Personal Data covers it.
 */
class Privacy {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nemvo-email'] = array(
			'exporter_friendly_name' => __( 'Email log', 'nemvo-email' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['nemvo-email'] = array(
			'eraser_friendly_name' => __( 'Email log', 'nemvo-email' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Entries per export page.
	 */
	const EXPORT_PAGE = 100;

	/**
	 * Export log entries sent to or from an address: date, subject, status,
	 * sender, recipients as the message showed them (To, Cc, Reply-To; Bcc
	 * only the person's own address), what sent it, attachment names and,
	 * when content is kept, the text of the message.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page, from 1.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$rows  = Log::for_address( $email, self::EXPORT_PAGE, ( $page - 1 ) * self::EXPORT_PAGE, true );
		$items = array();
		$email = strtolower( $email );
		$list  = static function ( array $pairs ) {
			return implode(
				', ',
				array_map(
					static function ( $pair ) {
						return '' !== (string) ( $pair[1] ?? '' ) ? $pair[1] . ' <' . $pair[0] . '>' : (string) $pair[0];
					},
					$pairs
				)
			);
		};
		foreach ( $rows as $row ) {
			$people = json_decode( (string) $row['recipients'], true );
			$people = is_array( $people ) ? $people : array();
			$bcc    = array_filter(
				(array) ( $people['bcc'] ?? array() ),
				static function ( $pair ) use ( $email ) {
					return strtolower( (string) ( $pair[0] ?? '' ) ) === $email;
				}
			);
			$from   = '' !== (string) $row['from_name'] ? $row['from_name'] . ' <' . $row['from_email'] . '>' : (string) $row['from_email'];
			$fields = array(
				__( 'Date (UTC)', 'nemvo-email' ) => $row['created_at'],
				__( 'Subject', 'nemvo-email' )    => $row['subject'],
				__( 'Status', 'nemvo-email' )     => $row['status'],
				__( 'From', 'nemvo-email' )       => $from,
				__( 'To', 'nemvo-email' )         => $list( (array) ( $people['to'] ?? array() ) ),
				__( 'Cc', 'nemvo-email' )         => $list( (array) ( $people['cc'] ?? array() ) ),
				__( 'Bcc', 'nemvo-email' )        => $list( $bcc ),
				__( 'Reply-To', 'nemvo-email' )   => $list( (array) ( $people['reply_to'] ?? array() ) ),
				__( 'Sent by', 'nemvo-email' )    => (string) $row['source_name'],
			);
			$files  = json_decode( (string) $row['attachments'], true );
			if ( is_array( $files ) ) {
				$fields[ __( 'Attachments', 'nemvo-email' ) ] = implode( ', ', array_filter( wp_list_pluck( $files, 'name' ) ) );
			}
			if ( null !== $row['body'] ) {
				$text = '' !== (string) $row['alt_body'] ? (string) $row['alt_body'] : wp_strip_all_tags( (string) $row['body'] );

				$fields[ __( 'Message', 'nemvo-email' ) ] = trim( $text );
			}
			$data = array();
			foreach ( $fields as $name => $value ) {
				if ( '' !== (string) $value ) {
					$data[] = array(
						'name'  => $name,
						'value' => (string) $value,
					);
				}
			}
			$items[] = array(
				'group_id'    => 'nemvo-email',
				'group_label' => __( 'Emails sent by the site', 'nemvo-email' ),
				'item_id'     => 'nemvo-email-' . $row['id'],
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => count( $rows ) < self::EXPORT_PAGE,
		);
	}

	/**
	 * Delete log entries sent to or from an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$rows    = Log::for_address( $email );
		$removed = Log::delete( wp_list_pluck( $rows, 'id' ) );
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < 500,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = (int) Settings::get( 'log_retention' );
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Nemvo Email sends the site\'s email through Google and keeps a log. Adjust this text to your settings.', 'nemvo-email' ) . '</p>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nemvo-email' ) . ' </strong>'
			. esc_html__( 'Email from this website, such as order confirmations, password resets and replies to forms, is sent through Google Workspace (Google LLC). Google processes the messages, including your email address and their content, to deliver them.', 'nemvo-email' ) . ' '
			/* translators: %d: days */
			. esc_html( sprintf( _n( 'We keep a log of the email we send (recipient addresses, subject and delivery status) for %d day, to make sure email arrives.', 'We keep a log of the email we send (recipient addresses, subject and delivery status) for %d days, to make sure email arrives.', $days, 'nemvo-email' ), $days ) )
			. '</p>';
		wp_add_privacy_policy_content( 'Nemvo Email', wp_kses_post( $text ) );
	}
}
