<?php
/**
 * Settings field definitions.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Foundation;

use Memberkin\Core\Crypto;
use Memberkin\Core\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative field list for the Settings screen. Modules add fields with the "memberkin_settings_fields" filter
 * and tabs with "memberkin_settings_tabs".
 *
 * Field keys: tab, section (option section), key, label, type, options, description, placeholder, constant (wp-config override).
 * Type "info" is a read-only row whose description is plugin-built HTML (never saved).
 * Types: text, email, url, textarea, html, checkbox, select, number, page, int_list, secret.
 */
final class SettingsFields {

	/**
	 * Tabs: id => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		$tabs = array(
			'general'  => __( 'General', 'memberkin' ),
			'pages'    => __( 'Pages', 'memberkin' ),
			'checkout' => __( 'Checkout', 'memberkin' ),
			'payments' => __( 'Payments', 'memberkin' ),
			'access'   => __( 'Content access', 'memberkin' ),
			'accounts' => __( 'Accounts', 'memberkin' ),
			'emails'   => __( 'Emails', 'memberkin' ),
			'modules'  => __( 'Modules', 'memberkin' ),
			'advanced' => __( 'Advanced', 'memberkin' ),
		);
		/**
		 * Filters settings tabs.
		 *
		 * @param array<string,string> $tabs Tabs.
		 */
		return (array) apply_filters( 'memberkin_settings_tabs', $tabs );
	}

	/**
	 * All fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$currencies = array();
		foreach ( Currency::all() as $code => $info ) {
			$currencies[ $code ] = $code . ' — ' . $info[0];
		}

		$fields = array(
			// General.
			array(
				'tab'         => 'general',
				'section'     => 'general',
				'key'         => 'currency',
				'label'       => __( 'Currency', 'memberkin' ),
				'type'        => 'select',
				'options'     => $currencies,
				'description' => __( 'Changing the currency does not convert existing prices or orders.', 'memberkin' ),
			),
			array(
				'tab'     => 'general',
				'section' => 'general',
				'key'     => 'currency_position',
				'label'   => __( 'Symbol position', 'memberkin' ),
				'type'    => 'select',
				'options' => array(
					''            => __( 'Currency default', 'memberkin' ),
					'left'        => '$10',
					'left_space'  => '$ 10',
					'right'       => '10$',
					'right_space' => '10 $',
				),
			),
			array(
				'tab'         => 'general',
				'section'     => 'general',
				'key'         => 'business_name',
				'label'       => __( 'Business name', 'memberkin' ),
				'type'        => 'text',
				'description' => __( 'Shown on receipts and order details.', 'memberkin' ),
			),
			array(
				'tab'     => 'general',
				'section' => 'general',
				'key'     => 'business_address',
				'label'   => __( 'Business address', 'memberkin' ),
				'type'    => 'textarea',
			),
			array(
				'tab'         => 'general',
				'section'     => 'general',
				'key'         => 'business_country',
				'label'       => __( 'Business country (ISO code)', 'memberkin' ),
				'type'        => 'text',
				'placeholder' => 'DE',
			),
			array(
				'tab'     => 'general',
				'section' => 'general',
				'key'     => 'business_vat',
				'label'   => __( 'Business VAT / tax number', 'memberkin' ),
				'type'    => 'text',
			),

			// Pages.
			array(
				'tab'         => 'pages',
				'section'     => 'pages',
				'key'         => 'levels',
				'label'       => __( 'Levels page', 'memberkin' ),
				'type'        => 'page',
				'description' => '[memberkin_levels]',
			),
			array(
				'tab'         => 'pages',
				'section'     => 'pages',
				'key'         => 'checkout',
				'label'       => __( 'Checkout page', 'memberkin' ),
				'type'        => 'page',
				'description' => '[memberkin_checkout]',
			),
			array(
				'tab'         => 'pages',
				'section'     => 'pages',
				'key'         => 'confirmation',
				'label'       => __( 'Confirmation page', 'memberkin' ),
				'type'        => 'page',
				'description' => '[memberkin_confirmation]',
			),
			array(
				'tab'         => 'pages',
				'section'     => 'pages',
				'key'         => 'account',
				'label'       => __( 'Account page', 'memberkin' ),
				'type'        => 'page',
				'description' => '[memberkin_account]',
			),
			array(
				'tab'         => 'pages',
				'section'     => 'pages',
				'key'         => 'login',
				'label'       => __( 'Login page', 'memberkin' ),
				'type'        => 'page',
				'description' => '[memberkin_login]',
			),
			array(
				'tab'         => 'pages',
				'section'     => 'pages',
				'key'         => 'profile',
				'label'       => __( 'Profile edit page', 'memberkin' ),
				'type'        => 'page',
				'description' => '[memberkin_profile]',
			),

			// Checkout.
			array(
				'tab'     => 'checkout',
				'section' => 'checkout',
				'key'     => 'require_name',
				'label'   => __( 'Require first and last name', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'checkout',
				'section' => 'checkout',
				'key'     => 'username_is_email',
				'label'   => __( 'Use email address as username', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'         => 'checkout',
				'section'     => 'checkout',
				'key'         => 'require_billing_address',
				'label'       => __( 'Always collect billing address', 'memberkin' ),
				'type'        => 'checkbox',
				'description' => __( 'The address is also collected automatically when tax rules need it.', 'memberkin' ),
			),
			array(
				'tab'         => 'checkout',
				'section'     => 'checkout',
				'key'         => 'terms_page',
				'label'       => __( 'Terms of service page', 'memberkin' ),
				'type'        => 'page',
				'description' => __( 'Members must accept it at checkout. Acceptance is logged.', 'memberkin' ),
			),
			array(
				'tab'     => 'checkout',
				'section' => 'checkout',
				'key'     => 'privacy_consent',
				'label'   => __( 'Require privacy policy consent', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'checkout',
				'section' => 'checkout',
				'key'     => 'auto_login',
				'label'   => __( 'Log new members in after checkout', 'memberkin' ),
				'type'    => 'checkbox',
			),

			// Billing (shown on Payments tab).
			array(
				'tab'     => 'payments',
				'section' => 'billing',
				'key'     => 'grace_days',
				'label'   => __( 'Grace period after a failed renewal (days)', 'memberkin' ),
				'type'    => 'number',
				'min'     => 0,
				'max'     => 60,
			),
			array(
				'tab'         => 'payments',
				'section'     => 'billing',
				'key'         => 'retry_schedule',
				'label'       => __( 'Retry failed renewals after (days)', 'memberkin' ),
				'type'        => 'int_list',
				'description' => __( 'Comma-separated delays since the previous attempt, e.g. 1,3,5,7. Applies to renewals charged by this site.', 'memberkin' ),
			),
			array(
				'tab'     => 'payments',
				'section' => 'billing',
				'key'     => 'after_final_failure',
				'label'   => __( 'After the last failed retry', 'memberkin' ),
				'type'    => 'select',
				'options' => array(
					'cancel'  => __( 'Cancel the subscription and end the membership', 'memberkin' ),
					'suspend' => __( 'Keep the subscription unpaid and suspend the membership', 'memberkin' ),
				),
			),

			// Access.
			array(
				'tab'         => 'access',
				'section'     => 'access',
				'key'         => 'restricted_message',
				'label'       => __( 'Message for logged-in non-members', 'memberkin' ),
				'type'        => 'html',
				'description' => __( 'Merge tags: {levels}, {levels_url}, {login_url}.', 'memberkin' ),
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'logged_out_message',
				'label'   => __( 'Message for logged-out visitors', 'memberkin' ),
				'type'    => 'html',
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'show_excerpts',
				'label'   => __( 'Show excerpts of restricted content', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'excerpt_length',
				'label'   => __( 'Automatic excerpt length (words)', 'memberkin' ),
				'type'    => 'number',
				'min'     => 0,
				'max'     => 1000,
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'filter_searches',
				'label'   => __( 'Hide restricted content from searches and archives', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'feed_teaser',
				'label'   => __( 'Show only a teaser of restricted content in feeds', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'rest_protection',
				'label'   => __( 'Protect restricted content in the REST API', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'access',
				'section' => 'access',
				'key'     => 'match_all_rules',
				'label'   => __( 'When several restrictions apply', 'memberkin' ),
				'type'    => 'select',
				'options' => array(
					'1' => __( 'Member must satisfy every restriction', 'memberkin' ),
					'0' => __( 'Satisfying any one restriction is enough', 'memberkin' ),
				),
			),
			array(
				'tab'         => 'access',
				'section'     => 'access',
				'key'         => 'redirect_unauthorized',
				'label'       => __( 'Redirect visitors without access', 'memberkin' ),
				'type'        => 'checkbox',
				'description' => __( 'Instead of showing a message on singular content.', 'memberkin' ),
			),
			array(
				'tab'         => 'access',
				'section'     => 'access',
				'key'         => 'redirect_url',
				'label'       => __( 'Redirect to', 'memberkin' ),
				'type'        => 'url',
				'description' => __( 'Leave empty to use the Levels page.', 'memberkin' ),
			),

			// Accounts.
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'frontend_login',
				'label'   => __( 'Use the Login page instead of wp-login.php for members', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'hide_admin_bar',
				'label'   => __( 'Hide the admin bar for members', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'block_wp_admin',
				'label'   => __( 'Keep members out of wp-admin', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'disable_wp_registration',
				'label'   => __( 'Disable the default WordPress registration form', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'         => 'accounts',
				'section'     => 'accounts',
				'key'         => 'login_redirect',
				'label'       => __( 'After login, redirect members to', 'memberkin' ),
				'type'        => 'url',
				'description' => __( 'Empty: the Account page. Levels can override this.', 'memberkin' ),
			),
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'logout_redirect',
				'label'   => __( 'After logout, redirect to', 'memberkin' ),
				'type'    => 'url',
			),
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'allow_member_cancel',
				'label'   => __( 'Members can cancel their subscriptions', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'         => 'accounts',
				'section'     => 'accounts',
				'key'         => 'allow_member_pause',
				'label'       => __( 'Members can pause their subscriptions', 'memberkin' ),
				'type'        => 'checkbox',
				'description' => __( 'Only offered when the payment gateway supports pausing.', 'memberkin' ),
			),
			array(
				'tab'     => 'accounts',
				'section' => 'accounts',
				'key'     => 'max_pause_days',
				'label'   => __( 'Maximum pause length (days)', 'memberkin' ),
				'type'    => 'number',
				'min'     => 1,
				'max'     => 365,
			),

			// Emails.
			array(
				'tab'     => 'emails',
				'section' => 'emails',
				'key'     => 'from_name',
				'label'   => __( 'From name', 'memberkin' ),
				'type'    => 'text',
			),
			array(
				'tab'     => 'emails',
				'section' => 'emails',
				'key'     => 'from_email',
				'label'   => __( 'From email', 'memberkin' ),
				'type'    => 'email',
			),
			array(
				'tab'         => 'emails',
				'section'     => 'emails',
				'key'         => 'admin_emails',
				'label'       => __( 'Admin notification recipients', 'memberkin' ),
				'type'        => 'text',
				'description' => __( 'Comma-separated email addresses.', 'memberkin' ),
			),
			array(
				'tab'     => 'emails',
				'section' => 'emails',
				'key'     => 'log_bodies',
				'label'   => __( 'Store email bodies in the email log', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'     => 'emails',
				'section' => 'emails',
				'key'     => 'log_retention',
				'label'   => __( 'Keep email log for (days)', 'memberkin' ),
				'type'    => 'number',
				'min'     => 1,
				'max'     => 3650,
			),

			// Security.
			array(
				'tab'         => 'advanced',
				'section'     => 'security',
				'key'         => 'login_limit',
				'heading'     => __( 'Abuse protection', 'memberkin' ),
				'label'       => __( 'Failed logins before a 15-minute lock', 'memberkin' ),
				'type'        => 'number',
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Per username and IP address (an IP is locked after four times as many failures). Applies to all WordPress logins. 0 = off.', 'memberkin' ),
			),
			array(
				'tab'         => 'advanced',
				'section'     => 'security',
				'key'         => 'checkout_submit_limit',
				'label'       => __( 'Checkout submissions per IP address per hour', 'memberkin' ),
				'type'        => 'number',
				'min'         => 0,
				'max'         => 1000,
				'description' => __( '0 = off.', 'memberkin' ),
			),
			array(
				'tab'         => 'advanced',
				'section'     => 'security',
				'key'         => 'checkout_fail_limit',
				'label'       => __( 'Declined payments per IP address or email per hour', 'memberkin' ),
				'type'        => 'number',
				'min'         => 0,
				'max'         => 100,
				'description' => __( 'Further checkouts are refused for the rest of the hour. 0 = off.', 'memberkin' ),
			),
			array(
				'tab'         => 'advanced',
				'section'     => 'security',
				'key'         => 'card_testing_threshold',
				'label'       => __( 'Card-testing alarm: declines across the site in 10 minutes', 'memberkin' ),
				'type'        => 'number',
				'min'         => 0,
				'max'         => 10000,
				'description' => __( 'When reached, sign-ups that create a new account are paused for 30 minutes and admins are emailed. 0 = off.', 'memberkin' ),
			),
			// Advanced.
			array(
				'tab'         => 'advanced',
				'section'     => 'advanced',
				'key'         => 'debug_log',
				'label'       => __( 'Debug logging', 'memberkin' ),
				'type'        => 'checkbox',
				'description' => __( 'Logs gateway requests (secrets and card numbers are redacted).', 'memberkin' ),
			),
			array(
				'tab'     => 'advanced',
				'section' => 'advanced',
				'key'     => 'log_retention',
				'label'   => __( 'Keep technical logs for (days)', 'memberkin' ),
				'type'    => 'number',
				'min'     => 1,
				'max'     => 365,
			),
			array(
				'tab'     => 'advanced',
				'section' => 'advanced',
				'key'     => 'webhook_retention',
				'label'   => __( 'Keep processed webhook events for (days)', 'memberkin' ),
				'type'    => 'number',
				'min'     => 7,
				'max'     => 3650,
			),
			array(
				'tab'         => 'advanced',
				'section'     => 'advanced',
				'key'         => 'delete_data_on_uninstall',
				'label'       => __( 'Delete all data when the plugin is deleted', 'memberkin' ),
				'type'        => 'checkbox',
				'description' => __( 'Removes levels, members, orders, subscriptions, settings and logs permanently.', 'memberkin' ),
			),
		);

		/**
		 * Filters settings fields.
		 *
		 * @param array $fields Field definitions.
		 */
		return (array) apply_filters( 'memberkin_settings_fields', $fields );
	}

	/**
	 * Sanitises a submitted value for a field.
	 *
	 * @param array $field Field definition.
	 * @param mixed $raw   Raw (unslashed) value; null when not submitted.
	 * @param mixed $current Current stored value (used by secrets).
	 * @return mixed
	 */
	public static function sanitize( array $field, mixed $raw, mixed $current ): mixed {
		switch ( $field['type'] ) {
			case 'checkbox':
				return ! empty( $raw );
			case 'number':
				$value = (int) $raw;
				if ( isset( $field['min'] ) ) {
					$value = max( (int) $field['min'], $value );
				}
				if ( isset( $field['max'] ) ) {
					$value = min( (int) $field['max'], $value );
				}
				return $value;
			case 'page':
				$id = absint( $raw );
				return ( $id && get_post( $id ) ) ? $id : 0;
			case 'select':
				$raw = (string) $raw;
				return array_key_exists( $raw, $field['options'] ?? array() ) ? $raw : (string) array_key_first( $field['options'] ?? array( '' => '' ) );
			case 'email':
				return sanitize_email( (string) $raw );
			case 'url':
				return esc_url_raw( trim( (string) $raw ) );
			case 'textarea':
				return sanitize_textarea_field( (string) $raw );
			case 'html':
				return wp_kses_post( (string) $raw );
			case 'int_list':
				$parts = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $raw ) ), static fn( $v ) => $v > 0 );
				return array_values( array_slice( $parts, 0, 10 ) );
			case 'secret':
				$raw = trim( (string) $raw );
				if ( '' === $raw || str_starts_with( $raw, '••••' ) ) {
					return $current; // Unchanged.
				}
				if ( '-' === $raw ) {
					return ''; // Explicit clear.
				}
				return Crypto::encrypt( $raw );
			default:
				return sanitize_text_field( (string) $raw );
		}
	}
}
