<?php
/**
 * Email template registry with defaults.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Each template: label, recipient (member|admin), subject, body (HTML with merge tags), description.
 * Admin edits are stored in the option "memberkin_email_{id}" (enabled, subject, body).
 */
final class EmailTemplates {

	/**
	 * All templates (defaults).
	 *
	 * @return array<string, array{label:string, recipient:string, subject:string, body:string, description:string}>
	 */
	public static function defaults(): array {
		$t = array(
			'checkout_paid'           => array(
				'label'       => __( 'Welcome (paid signup)', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent after a successful paid checkout.', 'memberkin' ),
				'subject'     => __( 'Welcome to {site_name} — your {level_name} membership is active', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Thank you for joining {site_name}. Your <strong>{level_name}</strong> membership is now active.</p>\n<p><strong>Order:</strong> {order_number}<br><strong>Total paid:</strong> {order_total}<br>{next_payment_line}</p>\n<p>You can manage your membership on your account page: <a href=\"{account_url}\">{account_url}</a></p>", 'memberkin' ),
			),
			'checkout_free'           => array(
				'label'       => __( 'Welcome (free signup)', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent after a free signup.', 'memberkin' ),
				'subject'     => __( 'Welcome to {site_name}', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> membership at {site_name} is active.</p>\n<p>Log in any time: <a href=\"{login_url}\">{login_url}</a></p>", 'memberkin' ),
			),
			'checkout_pending'        => array(
				'label'       => __( 'Order awaiting payment', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a member chooses check or bank transfer.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} order {order_number} — payment instructions', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Thank you for your order of <strong>{level_name}</strong> ({order_total}).</p>\n<p>{payment_instructions}</p>\n<p>Please include the order number <strong>{order_number}</strong> with your payment.</p>", 'memberkin' ),
			),
			'plan_changed'            => array(
				'label'       => __( 'Plan changed', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent after an upgrade or downgrade.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} membership was changed to {level_name}', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your membership is now <strong>{level_name}</strong>.</p>\n<p><strong>Charged today:</strong> {order_total}<br>{next_payment_line}</p>", 'memberkin' ),
			),
			'membership_granted'      => array(
				'label'       => __( 'Membership added by an administrator', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when you add a membership from the Members screen with "notify" checked.', 'memberkin' ),
				'subject'     => __( 'You now have {level_name} access at {site_name}', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>You have been given a <strong>{level_name}</strong> membership at {site_name}.</p>\n<p>{membership_end_line}</p>\n<p>Log in: <a href=\"{login_url}\">{login_url}</a></p>", 'memberkin' ),
			),
			'renewal_receipt'         => array(
				'label'       => __( 'Renewal receipt', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent after each successful renewal payment.', 'memberkin' ),
				'subject'     => __( 'Receipt for your {site_name} membership', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>We received your payment of <strong>{order_total}</strong> for <strong>{level_name}</strong>.</p>\n<p><strong>Invoice:</strong> {invoice_number}<br>{next_payment_line}</p>", 'memberkin' ),
			),
			'renewal_invoice_manual'  => array(
				'label'       => __( 'Renewal invoice (manual payment)', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a renewal paid by check or bank transfer is due.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} membership renewal is due', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> membership renewal of <strong>{order_total}</strong> is due.</p>\n<p>{payment_instructions}</p>\n<p>Please include the order number <strong>{order_number}</strong>.</p>", 'memberkin' ),
			),
			'payment_failed'          => array(
				'label'       => __( 'Payment failed', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a renewal payment fails and will be retried.', 'memberkin' ),
				'subject'     => __( 'Action needed: your {site_name} payment failed', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>We could not charge your payment method for your <strong>{level_name}</strong> membership.</p>\n<p><strong>Reason:</strong> {error}</p>\n<p>{retry_line}</p>\n<p>Please update your payment method to keep your access: <a href=\"{account_url}\">{account_url}</a></p>", 'memberkin' ),
			),
			'payment_failed_final'    => array(
				'label'       => __( 'Payment failed — membership ended', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent after the last retry failed.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} membership has ended', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>We could not collect payment for your <strong>{level_name}</strong> membership after several attempts, so it has ended.</p>\n<p>You can rejoin any time: <a href=\"{levels_url}\">{levels_url}</a></p>", 'memberkin' ),
			),
			'payment_action_required' => array(
				'label'       => __( 'Payment needs confirmation', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when the bank asks the member to authenticate a renewal (3-D Secure).', 'memberkin' ),
				'subject'     => __( 'Please confirm your {site_name} payment', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your bank needs you to confirm the payment for your <strong>{level_name}</strong> membership.</p>\n<p>Please visit your account page to complete it: <a href=\"{account_url}\">{account_url}</a></p>", 'memberkin' ),
			),
			'subscription_cancelled'  => array(
				'label'       => __( 'Subscription cancelled', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a subscription is set to end at the end of the paid period.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} subscription was cancelled', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> subscription was cancelled. You keep access until <strong>{membership_end_date}</strong>.</p>\n<p>Changed your mind? You can undo the cancellation on your account page: <a href=\"{account_url}\">{account_url}</a></p>", 'memberkin' ),
			),
			'membership_cancelled'    => array(
				'label'       => __( 'Membership cancelled', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a membership ends because it was cancelled.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} membership has ended', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> membership has ended.</p>\n<p>You can rejoin any time: <a href=\"{levels_url}\">{levels_url}</a></p>", 'memberkin' ),
			),
			'membership_expired'      => array(
				'label'       => __( 'Membership expired', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a membership reaches its end date.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} membership has expired', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> membership expired on {membership_end_date}.</p>\n<p>Renew here: <a href=\"{renew_url}\">{renew_url}</a></p>", 'memberkin' ),
			),
			'membership_suspended'    => array(
				'label'       => __( 'Membership suspended', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when an administrator suspends a membership.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} membership is suspended', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> membership has been suspended. Please contact us if you have questions.</p>", 'memberkin' ),
			),
			'subscription_paused'     => array(
				'label'       => __( 'Subscription paused', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a subscription is paused.', 'memberkin' ),
				'subject'     => __( 'Your {site_name} subscription is paused', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> subscription is paused. {resume_line}</p>", 'memberkin' ),
			),
			'subscription_resumed'    => array(
				'label'       => __( 'Subscription resumed', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when a paused subscription resumes.', 'memberkin' ),
				'subject'     => __( 'Welcome back — your {site_name} subscription has resumed', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>Your <strong>{level_name}</strong> subscription is active again. {next_payment_line}</p>", 'memberkin' ),
			),
			'refund'                  => array(
				'label'       => __( 'Refund issued', 'memberkin' ),
				'recipient'   => 'member',
				'description' => __( 'Sent when an order is refunded.', 'memberkin' ),
				'subject'     => __( 'Refund for your {site_name} order {order_number}', 'memberkin' ),
				'body'        => __( "<p>Hi {member_first_name},</p>\n<p>We refunded <strong>{refund_amount}</strong> for order {order_number}. It can take a few days to appear on your statement.</p>", 'memberkin' ),
			),
			'admin_checkout'          => array(
				'label'       => __( 'Admin: new signup', 'memberkin' ),
				'recipient'   => 'admin',
				'description' => __( 'Sent to admins after each checkout.', 'memberkin' ),
				'subject'     => __( '[{site_name}] New {level_name} member: {member_name}', 'memberkin' ),
				'body'        => __( "<p>{member_name} ({member_email}) signed up for <strong>{level_name}</strong>.</p>\n<p><strong>Order:</strong> {order_number} — {order_total} — {payment_method}</p>\n<p><a href=\"{admin_member_url}\">View member</a></p>", 'memberkin' ),
			),
			'admin_payment_failed'    => array(
				'label'       => __( 'Admin: payment failed', 'memberkin' ),
				'recipient'   => 'admin',
				'description' => __( 'Sent to admins when a renewal fails.', 'memberkin' ),
				'subject'     => __( '[{site_name}] Payment failed for {member_name}', 'memberkin' ),
				'body'        => __( "<p>A renewal payment for {member_name} ({member_email}), level <strong>{level_name}</strong>, failed.</p>\n<p><strong>Reason:</strong> {error}</p>\n<p><a href=\"{admin_member_url}\">View member</a></p>", 'memberkin' ),
			),
			'admin_cancelled'         => array(
				'label'       => __( 'Admin: cancellation', 'memberkin' ),
				'recipient'   => 'admin',
				'description' => __( 'Sent to admins when a member cancels.', 'memberkin' ),
				'subject'     => __( '[{site_name}] {member_name} cancelled {level_name}', 'memberkin' ),
				'body'        => __( "<p>{member_name} ({member_email}) cancelled <strong>{level_name}</strong>. Access ends {membership_end_date}.</p>\n<p><a href=\"{admin_member_url}\">View member</a></p>", 'memberkin' ),
			),
			'admin_refund'            => array(
				'label'       => __( 'Admin: refund', 'memberkin' ),
				'recipient'   => 'admin',
				'description' => __( 'Sent to admins when an order is refunded.', 'memberkin' ),
				'subject'     => __( '[{site_name}] Refund of {refund_amount} for order {order_number}', 'memberkin' ),
				'body'        => __( "<p>Order {order_number} for {member_name} was refunded: <strong>{refund_amount}</strong>.</p>\n<p><a href=\"{admin_order_url}\">View order</a></p>", 'memberkin' ),
			),
		);
		/**
		 * Filters email templates (modules add their own, e.g. reminders and approvals).
		 *
		 * @param array $t Templates.
		 */
		return (array) apply_filters( 'memberkin_email_templates', $t );
	}

	/**
	 * Merge tags with descriptions (shown in the editor).
	 *
	 * @return array<string, string>
	 */
	public static function merge_tags(): array {
		return (array) apply_filters(
			'memberkin_email_merge_tag_help',
			array(
				'{site_name}'            => __( 'Site title', 'memberkin' ),
				'{site_url}'             => __( 'Site address', 'memberkin' ),
				'{member_name}'          => __( 'Member display name', 'memberkin' ),
				'{member_first_name}'    => __( 'Member first name (falls back to display name)', 'memberkin' ),
				'{member_email}'         => __( 'Member email', 'memberkin' ),
				'{username}'             => __( 'Member username', 'memberkin' ),
				'{level_name}'           => __( 'Membership level', 'memberkin' ),
				'{membership_end_date}'  => __( 'Date access ends', 'memberkin' ),
				'{membership_end_line}'  => __( '"Your membership ends on …" or "does not expire"', 'memberkin' ),
				'{order_number}'         => __( 'Order number', 'memberkin' ),
				'{order_total}'          => __( 'Order total', 'memberkin' ),
				'{order_date}'           => __( 'Order date', 'memberkin' ),
				'{invoice_number}'       => __( 'Invoice number', 'memberkin' ),
				'{payment_method}'       => __( 'Payment method', 'memberkin' ),
				'{next_payment_date}'    => __( 'Next payment date', 'memberkin' ),
				'{next_payment_amount}'  => __( 'Next payment amount', 'memberkin' ),
				'{next_payment_line}'    => __( '"Your next payment of … is on …" (empty when nothing recurs)', 'memberkin' ),
				'{retry_line}'           => __( '"We will try again on …"', 'memberkin' ),
				'{resume_line}'          => __( '"It resumes on …"', 'memberkin' ),
				'{error}'                => __( 'Payment error', 'memberkin' ),
				'{refund_amount}'        => __( 'Refunded amount', 'memberkin' ),
				'{payment_instructions}' => __( 'Payment instructions for unpaid orders', 'memberkin' ),
				'{account_url}'          => __( 'Account page', 'memberkin' ),
				'{login_url}'            => __( 'Login page', 'memberkin' ),
				'{levels_url}'           => __( 'Levels page', 'memberkin' ),
				'{renew_url}'            => __( 'Checkout link for the same level', 'memberkin' ),
				'{admin_member_url}'     => __( 'Admin link to the member (admin emails)', 'memberkin' ),
				'{admin_order_url}'      => __( 'Admin link to the order (admin emails)', 'memberkin' ),
			)
		);
	}

	/**
	 * Effective template (defaults merged with saved edits).
	 *
	 * @param string $id Template id.
	 * @return array{label:string, recipient:string, subject:string, body:string, description:string, enabled:bool}|null
	 */
	public static function get( string $id ): ?array {
		$defaults = self::defaults();
		if ( ! isset( $defaults[ $id ] ) ) {
			return null;
		}
		$saved = get_option( 'memberkin_email_' . $id, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge(
			$defaults[ $id ],
			array( 'enabled' => true ),
			array_intersect_key( $saved, array_flip( array( 'subject', 'body', 'enabled' ) ) )
		);
	}

	/**
	 * Saves edits (sanitised here).
	 *
	 * @param string $id      Template id.
	 * @param bool   $enabled Enabled.
	 * @param string $subject Subject.
	 * @param string $body    Body HTML.
	 */
	public static function save( string $id, bool $enabled, string $subject, string $body ): void {
		update_option(
			'memberkin_email_' . $id,
			array(
				'enabled' => $enabled,
				'subject' => sanitize_text_field( $subject ),
				'body'    => wp_kses_post( $body ),
			),
			false
		);
	}

	/**
	 * Turns an email on or off, keeping any edited subject and message.
	 *
	 * @param string $id      Template id.
	 * @param bool   $enabled Enabled.
	 */
	public static function set_enabled( string $id, bool $enabled ): void {
		$saved            = get_option( 'memberkin_email_' . $id, array() );
		$saved            = is_array( $saved ) ? $saved : array();
		$saved['enabled'] = $enabled;
		update_option( 'memberkin_email_' . $id, $saved, false );
	}

	/**
	 * Restores defaults.
	 *
	 * @param string $id Template id.
	 */
	public static function reset( string $id ): void {
		delete_option( 'memberkin_email_' . $id );
	}
}
