<?php
/**
 * Queues, sends and logs emails.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Emails;

use Memberkin\Core\Clock;
use Memberkin\Core\Db;
use Memberkin\Core\Jobs;
use Memberkin\Core\Logger;
use Memberkin\Core\SafeMode;
use Memberkin\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every email is rendered when queued, stored in memberkin_email_log, and delivered by a background job
 * (synchronously in tests). Failed deliveries are retried after 5 minutes, 30 minutes and 2 hours.
 */
class EmailService {

	private const RETRY_MINUTES = array( 5, 30, 120 );

	/**
	 * Last wp_mail error (captured from wp_mail_failed).
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Admin recipients from settings.
	 *
	 * @return string[]
	 */
	public static function admin_recipients(): array {
		$list = preg_split( '/[\s,;]+/', (string) Settings::get( 'emails', 'admin_emails', get_option( 'admin_email' ) ) );
		return array_values( array_filter( array_map( 'sanitize_email', (array) $list ), 'is_email' ) );
	}

	/**
	 * Queues a template email. Returns log ids (empty when disabled or no recipient).
	 *
	 * @param string $id  Template id.
	 * @param array  $ctx Context (see EmailRenderer); "user" is required for member emails; "to" overrides recipients.
	 * @return int[]
	 */
	public function send( string $id, array $ctx ): array {
		$template = EmailTemplates::get( $id );
		if ( ! $template || ! $template['enabled'] ) {
			return array();
		}
		/**
		 * Return false to stop an email.
		 *
		 * @param bool   $send Send.
		 * @param string $id   Template id.
		 * @param array  $ctx  Context.
		 */
		if ( ! apply_filters( 'memberkin_email_should_send', true, $id, $ctx ) ) {
			return array();
		}
		$user = $ctx['user'] ?? null;
		if ( isset( $ctx['to'] ) ) {
			$recipients = (array) $ctx['to'];
		} elseif ( 'admin' === $template['recipient'] ) {
			$recipients = self::admin_recipients();
		} else {
			$recipients = $user instanceof \WP_User && is_email( $user->user_email ) ? array( $user->user_email ) : array();
		}
		if ( ! $recipients ) {
			return array();
		}
		$email = EmailRenderer::render( $template, $ctx, $id );
		$ids   = array();
		foreach ( $recipients as $to ) {
			$ids[] = $this->queue( $id, (string) $to, $email['subject'], $email['body'], $user instanceof \WP_User ? $user->ID : 0, 'member' === $template['recipient'] && ! isset( $ctx['force'] ), isset( $ctx['order'] ) && $ctx['order'] instanceof \Memberkin\Domain\Order ? array( 'order_id' => $ctx['order']->id ) : array() );
		}
		return $ids;
	}

	/**
	 * Stores and queues a message.
	 *
	 * @param string $template  Template id.
	 * @param string $to        Recipient.
	 * @param string $subject   Subject.
	 * @param string $body      HTML body.
	 * @param int    $user_id   Member.
	 * @param bool   $to_member Whether it goes to a member (paused in safe mode).
	 * @param array  $context   Small context stored with the log (e.g. order_id for attachments).
	 */
	public function queue( string $template, string $to, string $subject, string $body, int $user_id = 0, bool $to_member = true, array $context = array() ): int {
		global $wpdb;
		$skip = $to_member && SafeMode::active();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table_name( 'email_log' ),
			array(
				'user_id'    => $user_id,
				'template'   => $template,
				'recipient'  => mb_substr( $to, 0, 255 ),
				'subject'    => mb_substr( $subject, 0, 255 ),
				'body'       => $body,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'status'     => $skip ? 'skipped' : 'queued',
				'error'      => $skip ? 'Safe mode is active.' : null,
				'attempts'   => 0,
				'created_at' => Db::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $skip ) {
			Jobs::async( 'memberkin_send_email', array( $id ) );
		}
		return $id;
	}

	/**
	 * Delivers a queued message (job handler). Returns the final status.
	 *
	 * @param int $log_id Log id.
	 */
	public function deliver( int $log_id ): string {
		global $wpdb;
		$table = Db::table_name( 'email_log' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || ! in_array( $row->status, array( 'queued', 'failed' ), true ) ) {
			return (string) ( $row->status ?? 'missing' );
		}
		$name    = wp_specialchars_decode( (string) Settings::get( 'emails', 'from_name', get_bloginfo( 'name' ) ), ENT_QUOTES );
		$from    = sanitize_email( (string) Settings::get( 'emails', 'from_email', get_option( 'admin_email' ) ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from ) ) {
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '"' ), '', $name ) . ' <' . $from . '>';
		}
		/**
		 * Filters email headers.
		 *
		 * @param string[] $headers Headers.
		 * @param object   $row     Log row.
		 */
		$headers = (array) apply_filters( 'memberkin_email_headers', $headers, $row );

		$this->last_error = '';
		$capture          = function ( \WP_Error $error ): void {
			$this->last_error = $error->get_error_message();
		};
		/**
		 * Filters attachments (file paths) for a logged email, e.g. the PDF invoice.
		 *
		 * @param string[] $attachments Paths.
		 * @param object   $row         Log row (context holds e.g. order_id).
		 */
		$attachments = (array) apply_filters( 'memberkin_email_attachments', array(), $row );
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( (string) $row->recipient, (string) $row->subject, (string) $row->body, $headers, $attachments );
		remove_action( 'wp_mail_failed', $capture );
		/**
		 * Fires after a send attempt so temporary attachments can be removed.
		 *
		 * @param string[] $attachments Paths.
		 */
		do_action( 'memberkin_email_attachments_done', $attachments );

		$attempts = (int) $row->attempts + 1;
		$update   = array(
			'attempts' => $attempts,
			'status'   => $sent ? 'sent' : 'failed',
			'error'    => $sent ? null : mb_substr( '' !== $this->last_error ? $this->last_error : 'wp_mail() returned false.', 0, 1000 ),
		);
		if ( $sent ) {
			$update['sent_at'] = Db::now();
			if ( ! Settings::get( 'emails', 'log_bodies', false ) ) {
				$update['body'] = null;
			}
		}
		$wpdb->update( $table, $update, array( 'id' => $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $sent ) {
			Logger::warning(
				'emails',
				'Email delivery failed: ' . $update['error'],
				array(
					'log_id'   => $log_id,
					'template' => $row->template,
				)
			);
			if ( $attempts <= count( self::RETRY_MINUTES ) ) {
				Jobs::at( Clock::timestamp() + 60 * self::RETRY_MINUTES[ $attempts - 1 ], 'memberkin_send_email', array( $log_id ), false );
			}
		}
		/**
		 * Fires after a delivery attempt.
		 *
		 * @param int  $log_id Log id.
		 * @param bool $sent   Sent.
		 */
		do_action( 'memberkin_email_sent', $log_id, $sent );
		return $update['status'];
	}

	/**
	 * Re-queues a logged email (only possible while its body is stored).
	 *
	 * @param int $log_id Log id.
	 */
	public function resend( int $log_id ): bool {
		global $wpdb;
		$table = Db::table_name( 'email_log' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || null === $row->body || '' === $row->body ) {
			return false;
		}
		$this->queue( (string) $row->template, (string) $row->recipient, (string) $row->subject, (string) $row->body, (int) $row->user_id, false, $row->context ? (array) json_decode( (string) $row->context, true ) : array() );
		return true;
	}
}
