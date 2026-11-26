<?php
/**
 * Grouped settings storage.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Each section is one option "memberkin_settings_{section}" holding an array (add-ons may store their own
 * sections under their own prefix through the "memberkin_settings_option_name" filter).
 * Defaults come from self::defaults() and the "memberkin_settings_defaults" filter.
 */
final class Settings {

	/**
	 * Per-request cache of loaded sections.
	 *
	 * @var array<string, array>
	 */
	private static array $cache = array();

	/**
	 * Sections that are not autoloaded (large or rarely needed).
	 */
	private const NOT_AUTOLOADED = array( 'emails', 'invoices', 'advanced' ); // "invoices" holds order-number prefixes.

	/**
	 * Built-in defaults per section.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = array(
			'general'  => array(
				'currency'          => 'USD',
				'currency_position' => '',
				'business_name'     => get_bloginfo( 'name' ),
				'business_address'  => '',
				'business_country'  => '',
				'business_vat'      => '',
			),
			'pages'    => array(
				'levels'       => 0,
				'checkout'     => 0,
				'confirmation' => 0,
				'account'      => 0,
				'login'        => 0,
				'profile'      => 0,
			),
			'checkout' => array(
				'require_billing_address' => false,
				'username_is_email'       => false,
				'require_name'            => true,
				'terms_page'              => 0,
				'privacy_consent'         => false,
				'auto_login'              => true,
			),
			'access'   => array(
				'filter_searches'       => true,
				'show_excerpts'         => true,
				'excerpt_length'        => 55,
				'restricted_message'    => __( 'This content is for members only.', 'memberkin' ),
				'logged_out_message'    => __( 'Please log in or join to view this content.', 'memberkin' ),
				'redirect_unauthorized' => false,
				'redirect_url'          => '',
				'match_all_rules'       => true,
				'rest_protection'       => true,
				'feed_teaser'           => true,
			),
			'accounts' => array(
				'hide_admin_bar'          => true,
				'block_wp_admin'          => true,
				'disable_wp_registration' => false,
				'login_redirect'          => '',
				'logout_redirect'         => '',
				'allow_member_cancel'     => true,
				'allow_member_pause'      => false,
				'max_pause_days'          => 90,
				'frontend_login'          => true,
			),
			'billing'  => array(
				'grace_days'          => 3,
				'retry_schedule'      => array( 1, 3, 5, 7 ),
				'after_final_failure' => 'cancel',
				'renewal_lead_hours'  => 0,
			),
			'emails'   => array(
				'from_name'     => get_bloginfo( 'name' ),
				'from_email'    => (string) get_option( 'admin_email' ),
				'admin_emails'  => (string) get_option( 'admin_email' ),
				'log_bodies'    => false,
				'log_retention' => 90,
			),
			'security' => array(
				'login_limit'            => 5,
				'checkout_submit_limit'  => 30,
				'checkout_fail_limit'    => 5,
				'card_testing_threshold' => 25,
			),
			'advanced' => array(
				'delete_data_on_uninstall' => false,
				'debug_log'                => false,
				'log_retention'            => 30,
				'webhook_retention'        => 90,
			),
		);

		/**
		 * Filters settings defaults. Modules add their own sections here.
		 *
		 * @param array<string, array<string, mixed>> $defaults Defaults by section.
		 */
		return (array) apply_filters( 'memberkin_settings_defaults', $defaults );
	}

	/**
	 * Option that stores a section.
	 *
	 * @param string $section Section id.
	 */
	public static function option_name( string $section ): string {
		/**
		 * Filters the option name of a settings section.
		 *
		 * @param string $option  Option name.
		 * @param string $section Section id.
		 */
		return (string) apply_filters( 'memberkin_settings_option_name', 'memberkin_settings_' . $section, $section );
	}

	/**
	 * Full section with defaults merged.
	 *
	 * @param string $section Section id.
	 */
	public static function section( string $section ): array {
		if ( ! isset( self::$cache[ $section ] ) ) {
			$stored                  = get_option( self::option_name( $section ), array() );
			$defaults                = self::defaults()[ $section ] ?? array();
			self::$cache[ $section ] = array_merge( $defaults, is_array( $stored ) ? $stored : array() );
		}
		return self::$cache[ $section ];
	}

	/**
	 * Single value.
	 *
	 * @param string $section  Section id.
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when neither stored nor defaulted.
	 */
	public static function get( string $section, string $key, mixed $fallback = null ): mixed {
		$values = self::section( $section );
		return array_key_exists( $key, $values ) ? $values[ $key ] : $fallback;
	}

	/**
	 * Merges values into a section (values must already be sanitised).
	 *
	 * @param string $section Section id.
	 * @param array  $values  Values.
	 */
	public static function update( string $section, array $values ): void {
		$stored = get_option( self::option_name( $section ), array() );
		$stored = array_merge( is_array( $stored ) ? $stored : array(), $values );
		update_option( self::option_name( $section ), $stored, ! in_array( $section, self::NOT_AUTOLOADED, true ) );
		unset( self::$cache[ $section ] );
	}

	/**
	 * Clears the request cache (after external option changes and in tests).
	 */
	public static function flush(): void {
		self::$cache = array();
	}
}
