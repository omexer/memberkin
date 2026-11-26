<?php
/**
 * Foundation module (always enabled).
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Foundation;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Clock;
use Memberkin\Core\Db;
use Memberkin\Core\Installer;
use Memberkin\Core\Jobs;
use Memberkin\Core\Logger;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Core\SafeMode;
use Memberkin\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires infrastructure: meta tables, multisite, recurring jobs, cleanup, safe-mode and migration notices, admin menu root.
 */
final class FoundationModule extends Module {

	/**
	 * Where the "Upgrade to Pro" link on the Plugins screen points.
	 */
	public const UPGRADE_URL = 'https://omexer.com';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'foundation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Foundation', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function recurring_jobs(): array {
		return array( 'memberkin_cleanup' => DAY_IN_SECONDS );
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		self::register_meta_tables();

		add_filter( 'user_has_cap', array( Capabilities::class, 'super_admin_caps' ), 10, 4 );
		add_action( 'wp_initialize_site', array( Installer::class, 'on_initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_tables_on_site_delete' ), 10, 2 );
		add_action( 'switch_blog', array( self::class, 'register_meta_tables' ) );
		( new \Memberkin\Rest\RestApi() )->register();
		( new \Memberkin\Service\Security\LoginGuard() )->register();
		( new \Memberkin\Service\Security\CheckoutGuard() )->register();

		add_action( 'init', array( $this, 'ensure_recurring_jobs' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 999 );
		add_action( 'memberkin_cleanup', array( $this, 'cleanup' ) );
		add_filter( 'site_status_tests', array( $this, 'site_health_tests' ) );

		if ( is_admin() ) {
			( new AdminMenu( $plugin, new SettingsScreen( $plugin ) ) )->register();
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'admin_post_memberkin_safe_mode', array( $this, 'handle_safe_mode_action' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( MEMBERKIN_FILE ), array( $this, 'plugin_action_links' ) );
		}
	}

	/**
	 * Exposes our meta tables to the WordPress metadata API (meta types memberkin_level, memberkin_subscription, memberkin_order).
	 */
	public static function register_meta_tables(): void {
		global $wpdb;
		$wpdb->memberkin_levelmeta        = Db::table_name( 'level_meta' );
		$wpdb->memberkin_subscriptionmeta = Db::table_name( 'subscription_meta' );
		$wpdb->memberkin_ordermeta        = Db::table_name( 'order_meta' );
	}

	/**
	 * Makes sure each enabled module's recurring jobs exist. Checked at most hourly, and right away when the set of
	 * jobs changes (a module was switched on or added by an update).
	 */
	public function ensure_recurring_jobs(): void {
		$jobs = array();
		foreach ( $this->plugin->modules()->enabled() as $module ) {
			$jobs += $module->recurring_jobs();
		}
		$fingerprint = md5( (string) wp_json_encode( $jobs ) );
		if ( get_transient( 'memberkin_jobs_checked' ) === $fingerprint ) {
			return;
		}
		foreach ( $jobs as $hook => $interval ) {
			Jobs::ensure_recurring( $hook, (int) $interval );
		}
		set_transient( 'memberkin_jobs_checked', $fingerprint, HOUR_IN_SECONDS );
	}

	/**
	 * Site Health: the private folder (exports, imports, uploaded field files, protected media) must not be public.
	 *
	 * @param array $tests Tests.
	 */
	public function site_health_tests( $tests ) {
		$tests['direct']['memberkin_private_files'] = array(
			'label' => __( 'Membership private files are not publicly accessible', 'memberkin' ),
			'test'  => array( self::class, 'private_files_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health result.
	 */
	public static function private_files_test(): array {
		$exposed = \Memberkin\Core\PrivateFiles::publicly_reachable( true );
		$result  = array(
			'label'       => __( 'Membership private files are not publicly accessible', 'memberkin' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'memberkin' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Exports, imports, uploaded profile-field files and protected media are stored in a private folder that the web server refuses to serve.', 'memberkin' ) . '</p>',
			'actions'     => '',
			'test'        => 'memberkin_private_files',
		);
		if ( true === $exposed ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Membership private files can be downloaded by anyone who knows their address', 'memberkin' );
			$result['description'] = '<p>' . esc_html__( 'Your web server serves files from the private folder. This is usual on nginx, which ignores the .htaccess file there. Add this rule to the server block of your site and reload nginx:', 'memberkin' ) . '</p><p><code>' . esc_html( \Memberkin\Core\PrivateFiles::nginx_rule() ) . '</code></p>';
		} elseif ( null === $exposed ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Could not check whether membership private files are protected', 'memberkin' );
			$result['description'] = '<p>' . esc_html__( 'The site could not request a test file from itself. Make sure the web server denies access to the folder below; on nginx use this rule:', 'memberkin' ) . '</p><p><code>' . esc_html( \Memberkin\Core\PrivateFiles::nginx_rule() ) . '</code></p>';
		}
		return $result;
	}

	/**
	 * Flushes rewrite rules once after install/upgrade (rules are registered on init by modules).
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'memberkin_flush_rewrite' ) ) {
			delete_option( 'memberkin_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Daily housekeeping: logs, expired checkout sessions, old webhook payloads, old email bodies.
	 */
	public function cleanup(): void {
		global $wpdb;
		Logger::prune( (int) Settings::get( 'advanced', 'log_retention', 30 ) );

		$now      = Clock::now();
		$sessions = Db::table_name( 'checkout_sessions' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sessions} WHERE expires_at < %s AND status IN ('open','expired','completed') LIMIT 5000", $now->modify( '-7 days' )->format( 'Y-m-d H:i:s' ) ) );

		$webhooks = Db::table_name( 'webhook_events' );
		$cutoff   = $now->modify( '-' . max( 7, (int) Settings::get( 'advanced', 'webhook_retention', 90 ) ) . ' days' )->format( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$webhooks} WHERE received_at < %s AND status IN ('processed','ignored') LIMIT 5000", $cutoff ) );

		$emails = Db::table_name( 'email_log' );
		$cutoff = $now->modify( '-' . max( 1, (int) Settings::get( 'emails', 'log_retention', 90 ) ) . ' days' )->format( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$emails} WHERE created_at < %s LIMIT 5000", $cutoff ) );
		// phpcs:enable

		/**
		 * Fires during daily cleanup so modules can prune their own data.
		 */
		do_action( 'memberkin_cleanup_modules' );
	}

	/**
	 * Admin notices: safe mode and failed migrations.
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$error = get_option( 'memberkin_migration_error' );
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Memberkin could not update its database:', 'memberkin' ) . ' ' . esc_html( (string) $error ) . '</p></div>';
		}
		if ( SafeMode::active() && ! defined( 'MEMBERKIN_SAFE_MODE' ) ) {
			$url = static fn( string $choice ): string => wp_nonce_url( admin_url( 'admin-post.php?action=memberkin_safe_mode&choice=' . $choice ), 'memberkin_safe_mode' );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Memberkin is in safe mode.', 'memberkin' ) . '</strong> ';
			if ( SafeMode::fingerprint_mismatch() ) {
				esc_html_e( 'This site’s address or database changed, so it may be a copy of your live site. Renewals, payment retries, member emails and webhooks are paused.', 'memberkin' );
			} else {
				esc_html_e( 'Renewals, payment retries, member emails and webhooks are paused.', 'memberkin' );
			}
			echo '</p><p><a class="button button-primary" href="' . esc_url( $url( 'live' ) ) . '">' . esc_html__( 'This is the live site — resume', 'memberkin' ) . '</a> ';
			if ( SafeMode::fingerprint_mismatch() ) {
				echo '<a class="button" href="' . esc_url( $url( 'staging' ) ) . '">' . esc_html__( 'Keep paused (staging site)', 'memberkin' ) . '</a>';
			}
			echo '</p></div>';
		}
	}

	/**
	 * Handles the safe mode buttons.
	 */
	public function handle_safe_mode_action(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'memberkin' ), 403 );
		}
		check_admin_referer( 'memberkin_safe_mode' );
		$choice = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		if ( 'live' === $choice ) {
			SafeMode::confirm_live();
			Logger::info( 'safe-mode', 'Admin confirmed live site; safe mode off.' );
		} elseif ( 'staging' === $choice ) {
			SafeMode::keep_paused();
			Logger::info( 'safe-mode', 'Admin kept safe mode on (staging).' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=memberkin-settings' ) ) . '">' . esc_html__( 'Settings', 'memberkin' ) . '</a>' );
		if ( ! defined( 'MEMBERKIN_PRO_VERSION' ) ) {
			/**
			 * Filters the "Upgrade to Pro" link on the Plugins screen.
			 *
			 * @param string $url URL.
			 */
			$url     = (string) apply_filters( 'memberkin_upgrade_url', self::UPGRADE_URL );
			$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to Pro', 'memberkin' ) . '</a>';
		}
		return $links;
	}
}
