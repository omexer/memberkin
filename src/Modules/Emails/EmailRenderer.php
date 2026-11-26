<?php
/**
 * Builds merge-tag values and renders emails.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Emails;

use Memberkin\Core\Money;
use Memberkin\Core\Templates;
use Memberkin\Domain\Level;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Repository\LevelRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Context keys: user (WP_User), level (Level), membership (Membership), order (Order), subscription (Subscription),
 * error (string), refund_amount (Money), retry_at (DateTimeImmutable), resume_at (DateTimeImmutable).
 */
final class EmailRenderer {

	/**
	 * Tags whose values are already safe HTML.
	 */
	private const HTML_TAGS = array( '{payment_instructions}' );

	/**
	 * Formats a date in the site's format.
	 *
	 * @param \DateTimeImmutable|null $date Date.
	 */
	private static function date( ?\DateTimeImmutable $date ): string {
		return $date ? (string) wp_date( get_option( 'date_format' ), $date->getTimestamp() ) : '';
	}

	/**
	 * Merge tag values (plain text, unescaped).
	 *
	 * @param array $ctx Context.
	 * @return array<string, string>
	 */
	public static function tags( array $ctx ): array {
		$user  = $ctx['user'] ?? null;
		$m     = $ctx['membership'] ?? null;
		$order = $ctx['order'] ?? null;
		$sub   = $ctx['subscription'] ?? null;
		$level = $ctx['level'] ?? null;
		if ( ! $level instanceof Level ) {
			$level_id = $m instanceof Membership ? $m->level_id : ( $sub instanceof Subscription ? $sub->level_id : ( $order instanceof Order && $order->level_ids() ? $order->level_ids()[0] : 0 ) );
			$level    = $level_id ? memberkin()->get( LevelRepository::class )->find( $level_id ) : null;
		}

		$tags = array(
			'{site_name}'            => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'             => home_url( '/' ),
			'{member_name}'          => $user instanceof \WP_User ? $user->display_name : '',
			'{member_first_name}'    => $user instanceof \WP_User ? ( '' !== $user->first_name ? $user->first_name : $user->display_name ) : '',
			'{member_email}'         => $user instanceof \WP_User ? $user->user_email : '',
			'{username}'             => $user instanceof \WP_User ? $user->user_login : '',
			'{level_name}'           => $level ? $level->name : '',
			'{membership_end_date}'  => '',
			'{membership_end_line}'  => __( 'Your membership does not expire.', 'memberkin' ),
			'{order_number}'         => $order instanceof Order ? $order->number : '',
			'{order_total}'          => $order instanceof Order ? $order->total()->format() : '',
			'{order_date}'           => $order instanceof Order ? self::date( $order->created_at ) : '',
			'{invoice_number}'       => $order instanceof Order ? (string) $order->invoice_number : '',
			'{payment_method}'       => isset( $ctx['payment_method_label'] ) ? (string) $ctx['payment_method_label'] : ( $order instanceof Order ? (string) ( $order->payment_method_label ?? '' ) : '' ),
			'{next_payment_date}'    => '',
			'{next_payment_amount}'  => '',
			'{next_payment_line}'    => '',
			'{retry_line}'           => '',
			'{resume_line}'          => '',
			'{error}'                => (string) ( $ctx['error'] ?? '' ),
			'{refund_amount}'        => isset( $ctx['refund_amount'] ) && $ctx['refund_amount'] instanceof Money ? $ctx['refund_amount']->format() : '',
			'{payment_instructions}' => '',
			'{account_url}'          => memberkin_page_url( 'account' ) ?: home_url( '/' ), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'{login_url}'            => wp_login_url(),
			'{levels_url}'           => memberkin_page_url( 'levels' ) ?: home_url( '/' ), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'{renew_url}'            => $level ? memberkin_checkout_url( $level ) : home_url( '/' ),
			'{admin_member_url}'     => $user instanceof \WP_User ? add_query_arg(
				array(
				'page'    => 'memberkin-members',
				'user_id' => $user->ID,
				),
				admin_url( 'admin.php' )
			) : admin_url(),
			'{admin_order_url}'      => $order instanceof Order ? add_query_arg(
				array(
				'page' => 'memberkin-orders',
				'id'   => $order->id,
				),
				admin_url( 'admin.php' )
			) : admin_url(),
		);

		$ends = $m instanceof Membership ? $m->ends_at : null;
		if ( $sub instanceof Subscription && $sub->cancel_at_period_end ) {
			$ends = $sub->current_period_end;
		}
		if ( $ends ) {
			$tags['{membership_end_date}'] = self::date( $ends );
			/* translators: %s: date. */
			$tags['{membership_end_line}'] = sprintf( __( 'Your membership ends on %s.', 'memberkin' ), self::date( $ends ) );
		}
		if ( $sub instanceof Subscription && $sub->next_payment_at && ! $sub->cancel_at_period_end && $sub->is_live() ) {
			$amount                        = Money::of( $sub->recurring_amount + $sub->tax_amount, $sub->currency )->format();
			$tags['{next_payment_date}']   = self::date( $sub->next_payment_at );
			$tags['{next_payment_amount}'] = $amount;
			/* translators: 1: amount, 2: date. */
			$tags['{next_payment_line}'] = sprintf( __( 'Your next payment of %1$s is on %2$s.', 'memberkin' ), $amount, self::date( $sub->next_payment_at ) );
		}
		if ( isset( $ctx['retry_at'] ) && $ctx['retry_at'] instanceof \DateTimeImmutable ) {
			/* translators: %s: date. */
			$tags['{retry_line}'] = sprintf( __( 'We will try again on %s.', 'memberkin' ), self::date( $ctx['retry_at'] ) );
		}
		if ( isset( $ctx['resume_at'] ) && $ctx['resume_at'] instanceof \DateTimeImmutable ) {
			/* translators: %s: date. */
			$tags['{resume_line}'] = sprintf( __( 'It resumes automatically on %s.', 'memberkin' ), self::date( $ctx['resume_at'] ) );
		}
		if ( $order instanceof Order ) {
			$gateway = memberkin()->get( GatewayRegistry::class )->get( $order->gateway );
			if ( $gateway instanceof AbstractGateway ) {
				$tags['{payment_instructions}'] = wp_kses_post( wpautop( $gateway->instructions( $order ) ) );
			}
		}
		/**
		 * Filters email merge tags (values are plain text except {payment_instructions}).
		 *
		 * @param array<string,string> $tags Tags.
		 * @param array                $ctx  Context.
		 */
		return (array) apply_filters( 'memberkin_email_merge_tags', $tags, $ctx );
	}

	/**
	 * Renders subject and HTML body.
	 *
	 * @param array  $template Template (subject, body).
	 * @param array  $ctx      Context.
	 * @param string $id      Template id.
	 * @return array{subject: string, body: string}
	 */
	public static function render( array $template, array $ctx, string $id = '' ): array {
		$tags    = self::tags( $ctx );
		$subject = wp_strip_all_tags( strtr( (string) $template['subject'], $tags ) );
		$escaped = array();
		/**
		 * Merge tags whose values are HTML built by the plugin (not escaped; the body still goes through wp_kses_post()).
		 *
		 * @param string[] $html_tags Tags.
		 */
		$html_tags = (array) apply_filters( 'memberkin_email_html_tags', self::HTML_TAGS );
		foreach ( $tags as $tag => $value ) {
			$escaped[ $tag ] = in_array( $tag, $html_tags, true ) ? $value : esc_html( $value );
		}
		$content = wp_kses_post( strtr( (string) $template['body'], $escaped ) );
		$body    = Templates::render(
			'emails/layout',
			array(
				'subject'   => $subject,
				'content'   => $content,
				'site_name' => $tags['{site_name}'],
				'site_url'  => $tags['{site_url}'],
				'template'  => $id,
			)
		);
		/**
		 * Filters a rendered email.
		 *
		 * @param array{subject:string,body:string} $email Email.
		 * @param string                            $id    Template id.
		 * @param array                             $ctx   Context.
		 */
		return (array) apply_filters(
			'memberkin_email_content',
			array(
				'subject' => $subject,
				'body'    => '' !== $body ? $body : $content,
			),
			$id,
			$ctx
		);
	}
}
