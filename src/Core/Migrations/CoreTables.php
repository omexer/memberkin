<?php
/**
 * Core table definitions (ARCHITECTURE.md §4).
 *
 * @package Memberkin
 */

namespace Memberkin\Core\Migrations;

use Memberkin\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates all core tables with dbDelta().
 *
 * Money columns are BIGINT minor units. Dates are UTC DATETIME.
 * Meta tables follow the WordPress metadata API convention (column "{meta_type}_id").
 */
final class CoreTables implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'core_20261123_001_tables';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * CREATE TABLE statements for dbDelta().
	 *
	 * @return string[]
	 */
	public static function definitions(): array {
		global $wpdb;
		$c   = $wpdb->get_charset_collate();
		$max = 191;

		$t = static fn( string $name ): string => Db::table_name( $name );

		return array(
			"CREATE TABLE {$t('levels')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  slug varchar(191) NOT NULL,
  description longtext NULL,
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  allow_signups tinyint(1) NOT NULL DEFAULT 1,
  display_order int(11) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'USD',
  initial_amount bigint(20) NOT NULL DEFAULT 0,
  signup_fee bigint(20) NOT NULL DEFAULT 0,
  recurring_amount bigint(20) NOT NULL DEFAULT 0,
  billing_interval smallint(5) unsigned NOT NULL DEFAULT 1,
  billing_period varchar(10) NOT NULL DEFAULT 'month',
  billing_cycles int(11) unsigned NOT NULL DEFAULT 0,
  trial_amount bigint(20) NOT NULL DEFAULT 0,
  trial_length smallint(5) unsigned NOT NULL DEFAULT 0,
  trial_period varchar(10) NOT NULL DEFAULT 'day',
  expiration_type varchar(20) NOT NULL DEFAULT 'never',
  expiration_length smallint(5) unsigned NOT NULL DEFAULT 0,
  expiration_period varchar(10) NOT NULL DEFAULT 'month',
  expiration_date datetime NULL DEFAULT NULL,
  tax_class varchar(40) NOT NULL DEFAULT 'standard',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status_order (status,display_order),
  KEY group_id (group_id)
) $c;",

			"CREATE TABLE {$t('level_meta')} (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  memberkin_level_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) NULL DEFAULT NULL,
  meta_value longtext NULL,
  PRIMARY KEY  (meta_id),
  KEY memberkin_level_id (memberkin_level_id),
  KEY meta_key (meta_key($max))
) $c;",

			"CREATE TABLE {$t('level_groups')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  allow_multiple tinyint(1) NOT NULL DEFAULT 0,
  is_upgrade_path tinyint(1) NOT NULL DEFAULT 0,
  fallback_level_id bigint(20) unsigned NOT NULL DEFAULT 0,
  proration_mode varchar(30) NOT NULL DEFAULT 'prorate_immediate',
  downgrade_timing varchar(20) NOT NULL DEFAULT 'period_end',
  display_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY display_order (display_order)
) $c;",

			"CREATE TABLE {$t('memberships')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  source varchar(20) NOT NULL DEFAULT 'checkout',
  subscription_id bigint(20) unsigned NULL DEFAULT NULL,
  initial_order_id bigint(20) unsigned NULL DEFAULT NULL,
  parent_membership_id bigint(20) unsigned NULL DEFAULT NULL,
  coupon_id bigint(20) unsigned NULL DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  initial_amount bigint(20) NOT NULL DEFAULT 0,
  recurring_amount bigint(20) NOT NULL DEFAULT 0,
  billing_interval smallint(5) unsigned NOT NULL DEFAULT 1,
  billing_period varchar(10) NOT NULL DEFAULT 'month',
  billing_cycles int(11) unsigned NOT NULL DEFAULT 0,
  trial_amount bigint(20) NOT NULL DEFAULT 0,
  trial_length smallint(5) unsigned NOT NULL DEFAULT 0,
  trial_period varchar(10) NOT NULL DEFAULT 'day',
  starts_at datetime NOT NULL,
  ends_at datetime NULL DEFAULT NULL,
  grace_until datetime NULL DEFAULT NULL,
  paused_at datetime NULL DEFAULT NULL,
  resume_at datetime NULL DEFAULT NULL,
  suspended_at datetime NULL DEFAULT NULL,
  ended_at datetime NULL DEFAULT NULL,
  end_reason varchar(30) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_status (user_id,status),
  KEY level_status (level_id,status),
  KEY status_ends (status,ends_at),
  KEY subscription_id (subscription_id),
  KEY parent_membership_id (parent_membership_id),
  KEY created_at (created_at),
  KEY ended_at (ended_at)
) $c;",

			"CREATE TABLE {$t('subscriptions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  membership_id bigint(20) unsigned NULL DEFAULT NULL,
  gateway varchar(40) NOT NULL,
  gateway_mode varchar(10) NOT NULL DEFAULT 'live',
  gateway_subscription_id varchar(191) NULL DEFAULT NULL,
  gateway_customer_id varchar(191) NULL DEFAULT NULL,
  payment_method_id bigint(20) unsigned NULL DEFAULT NULL,
  billing_model varchar(10) NOT NULL DEFAULT 'gateway',
  status varchar(20) NOT NULL DEFAULT 'pending',
  currency char(3) NOT NULL DEFAULT 'USD',
  recurring_amount bigint(20) NOT NULL DEFAULT 0,
  tax_amount bigint(20) NOT NULL DEFAULT 0,
  billing_interval smallint(5) unsigned NOT NULL DEFAULT 1,
  billing_period varchar(10) NOT NULL DEFAULT 'month',
  billing_cycles int(11) unsigned NOT NULL DEFAULT 0,
  cycles_completed int(11) unsigned NOT NULL DEFAULT 0,
  coupon_id bigint(20) unsigned NULL DEFAULT NULL,
  coupon_cycles_remaining int(11) NULL DEFAULT NULL,
  trial_ends_at datetime NULL DEFAULT NULL,
  current_period_start datetime NULL DEFAULT NULL,
  current_period_end datetime NULL DEFAULT NULL,
  next_payment_at datetime NULL DEFAULT NULL,
  cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
  cancelled_at datetime NULL DEFAULT NULL,
  cancel_reason varchar(255) NULL DEFAULT NULL,
  ended_at datetime NULL DEFAULT NULL,
  paused_at datetime NULL DEFAULT NULL,
  resume_at datetime NULL DEFAULT NULL,
  retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
  next_retry_at datetime NULL DEFAULT NULL,
  last_payment_error varchar(255) NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY gateway_link (gateway,gateway_mode,gateway_subscription_id),
  KEY status_next_payment (status,next_payment_at),
  KEY status_next_retry (status,next_retry_at),
  KEY user_id (user_id),
  KEY membership_id (membership_id),
  KEY created_at (created_at),
  KEY ended_at (ended_at)
) $c;",

			"CREATE TABLE {$t('subscription_meta')} (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  memberkin_subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) NULL DEFAULT NULL,
  meta_value longtext NULL,
  PRIMARY KEY  (meta_id),
  KEY memberkin_subscription_id (memberkin_subscription_id),
  KEY meta_key (meta_key($max))
) $c;",

			"CREATE TABLE {$t('orders')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number varchar(32) NOT NULL,
  invoice_number varchar(40) NULL DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subscription_id bigint(20) unsigned NULL DEFAULT NULL,
  type varchar(20) NOT NULL DEFAULT 'initial',
  status varchar(20) NOT NULL DEFAULT 'pending',
  currency char(3) NOT NULL DEFAULT 'USD',
  subtotal bigint(20) NOT NULL DEFAULT 0,
  discount_total bigint(20) NOT NULL DEFAULT 0,
  tax_total bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  amount_refunded bigint(20) NOT NULL DEFAULT 0,
  coupon_id bigint(20) unsigned NULL DEFAULT NULL,
  coupon_code varchar(64) NULL DEFAULT NULL,
  tax_country char(2) NULL DEFAULT NULL,
  tax_region varchar(64) NULL DEFAULT NULL,
  vat_number varchar(32) NULL DEFAULT NULL,
  vat_number_valid tinyint(1) NOT NULL DEFAULT 0,
  reverse_charge tinyint(1) NOT NULL DEFAULT 0,
  prices_include_tax tinyint(1) NOT NULL DEFAULT 0,
  billing_name varchar(191) NULL DEFAULT NULL,
  billing_company varchar(191) NULL DEFAULT NULL,
  billing_email varchar(191) NULL DEFAULT NULL,
  billing_line1 varchar(191) NULL DEFAULT NULL,
  billing_line2 varchar(191) NULL DEFAULT NULL,
  billing_city varchar(128) NULL DEFAULT NULL,
  billing_region varchar(128) NULL DEFAULT NULL,
  billing_postcode varchar(32) NULL DEFAULT NULL,
  billing_country char(2) NULL DEFAULT NULL,
  billing_phone varchar(40) NULL DEFAULT NULL,
  gateway varchar(40) NOT NULL DEFAULT '',
  gateway_mode varchar(10) NOT NULL DEFAULT 'live',
  gateway_transaction_id varchar(191) NULL DEFAULT NULL,
  gateway_customer_id varchar(191) NULL DEFAULT NULL,
  payment_method_label varchar(64) NULL DEFAULT NULL,
  ip_address varchar(45) NULL DEFAULT NULL,
  checkout_session_id bigint(20) unsigned NULL DEFAULT NULL,
  paid_at datetime NULL DEFAULT NULL,
  refunded_at datetime NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  notes longtext NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY number (number),
  UNIQUE KEY invoice_number (invoice_number),
  KEY user_created (user_id,created_at),
  KEY status_created (status,created_at),
  KEY subscription_id (subscription_id),
  KEY gateway_txn (gateway,gateway_transaction_id($max)),
  KEY paid_at (paid_at)
) $c;",

			"CREATE TABLE {$t('order_items')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'level',
  level_id bigint(20) unsigned NULL DEFAULT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  quantity int(11) unsigned NOT NULL DEFAULT 1,
  unit_amount bigint(20) NOT NULL DEFAULT 0,
  discount_amount bigint(20) NOT NULL DEFAULT 0,
  tax_rate_id bigint(20) unsigned NULL DEFAULT NULL,
  tax_rate decimal(7,4) NOT NULL DEFAULT 0,
  tax_amount bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) $c;",

			"CREATE TABLE {$t('order_meta')} (
  meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  memberkin_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meta_key varchar(255) NULL DEFAULT NULL,
  meta_value longtext NULL,
  PRIMARY KEY  (meta_id),
  KEY memberkin_order_id (memberkin_order_id),
  KEY meta_key (meta_key($max))
) $c;",

			"CREATE TABLE {$t('refunds')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  amount bigint(20) NOT NULL,
  reason varchar(255) NULL DEFAULT NULL,
  gateway_refund_id varchar(191) NULL DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  credit_note_number varchar(40) NULL DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY created_at (created_at),
  UNIQUE KEY credit_note_number (credit_note_number)
) $c;",

			"CREATE TABLE {$t('payment_methods')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  gateway varchar(40) NOT NULL,
  gateway_mode varchar(10) NOT NULL DEFAULT 'live',
  token varchar(191) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'card',
  brand varchar(30) NULL DEFAULT NULL,
  last4 varchar(4) NULL DEFAULT NULL,
  exp_month tinyint(2) unsigned NULL DEFAULT NULL,
  exp_year smallint(4) unsigned NULL DEFAULT NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_gateway (user_id,gateway),
  KEY expiry (exp_year,exp_month)
) $c;",

			"CREATE TABLE {$t('checkout_sessions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(43) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  level_ids varchar(191) NOT NULL DEFAULT '',
  quote longtext NULL,
  form_data longtext NULL,
  order_id bigint(20) unsigned NULL DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY status_expires (status,expires_at)
) $c;",

			"CREATE TABLE {$t('coupons')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(64) NOT NULL,
  description varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  discount_type varchar(20) NOT NULL DEFAULT 'percent',
  amount bigint(20) NOT NULL DEFAULT 0,
  applies_to varchar(20) NOT NULL DEFAULT 'both',
  recurring_cycles int(11) NULL DEFAULT NULL,
  first_payment_type varchar(20) NULL DEFAULT NULL,
  first_payment_amount bigint(20) NULL DEFAULT NULL,
  trial_override_length smallint(5) unsigned NULL DEFAULT NULL,
  trial_override_period varchar(10) NULL DEFAULT NULL,
  trial_override_amount bigint(20) NULL DEFAULT NULL,
  starts_at datetime NULL DEFAULT NULL,
  expires_at datetime NULL DEFAULT NULL,
  max_uses int(11) unsigned NOT NULL DEFAULT 0,
  uses_count int(11) unsigned NOT NULL DEFAULT 0,
  max_uses_per_user int(11) unsigned NOT NULL DEFAULT 0,
  per_user_window_days int(11) unsigned NOT NULL DEFAULT 0,
  allow_on_change tinyint(1) NOT NULL DEFAULT 1,
  auto_apply_group_id bigint(20) unsigned NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY status_dates (status,starts_at,expires_at)
) $c;",

			"CREATE TABLE {$t('coupon_levels')} (
  coupon_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  override tinyint(1) NOT NULL DEFAULT 0,
  initial_amount bigint(20) NOT NULL DEFAULT 0,
  signup_fee bigint(20) NOT NULL DEFAULT 0,
  recurring_amount bigint(20) NOT NULL DEFAULT 0,
  billing_interval smallint(5) unsigned NOT NULL DEFAULT 1,
  billing_period varchar(10) NOT NULL DEFAULT 'month',
  billing_cycles int(11) unsigned NOT NULL DEFAULT 0,
  trial_amount bigint(20) NOT NULL DEFAULT 0,
  trial_length smallint(5) unsigned NOT NULL DEFAULT 0,
  trial_period varchar(10) NOT NULL DEFAULT 'day',
  expiration_type varchar(20) NOT NULL DEFAULT 'never',
  expiration_length smallint(5) unsigned NOT NULL DEFAULT 0,
  expiration_period varchar(10) NOT NULL DEFAULT 'month',
  PRIMARY KEY  (coupon_id,level_id),
  KEY level_id (level_id)
) $c;",

			"CREATE TABLE {$t('coupon_uses')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  coupon_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  used_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY coupon_user (coupon_id,user_id),
  KEY order_id (order_id)
) $c;",

			"CREATE TABLE {$t('sequences')} (
  name varchar(40) NOT NULL,
  prefix varchar(20) NOT NULL DEFAULT '',
  next_value bigint(20) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (name)
) $c;",

			"CREATE TABLE {$t('content_access')} (
  object_type varchar(10) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  level_id bigint(20) unsigned NOT NULL,
  drip_delay_days int(11) unsigned NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (object_type,object_id,level_id),
  KEY level_object (level_id,object_type)
) $c;",

			"CREATE TABLE {$t('events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(64) NOT NULL,
  data longtext NULL,
  actor_type varchar(10) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY object (object_type,object_id),
  KEY event_created (event,created_at)
) $c;",

			"CREATE TABLE {$t('webhook_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  gateway varchar(40) NOT NULL,
  gateway_mode varchar(10) NOT NULL DEFAULT 'live',
  event_id varchar(191) NOT NULL,
  event_type varchar(100) NOT NULL DEFAULT '',
  payload longtext NULL,
  signature_valid tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'received',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  received_at datetime NOT NULL,
  processed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY gateway_event (gateway,gateway_mode,event_id),
  KEY status_received (status,received_at)
) $c;",

			"CREATE TABLE {$t('email_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  template varchar(64) NOT NULL DEFAULT '',
  recipient varchar(255) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  context longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  error text NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  sent_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY template_created (template,created_at),
  KEY status (status)
) $c;",

			"CREATE TABLE {$t('logs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL DEFAULT 'info',
  channel varchar(40) NOT NULL DEFAULT 'general',
  message text NOT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY channel_created (channel,created_at),
  KEY created_at (created_at)
) $c;",

		);
	}

	/**
	 * Short names of all core tables (used by uninstall and tests). Add-on tables are found by prefix on uninstall.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array(
			'levels',
			'level_meta',
			'level_groups',
			'memberships',
			'subscriptions',
			'subscription_meta',
			'orders',
			'order_items',
			'order_meta',
			'refunds',
			'payment_methods',
			'checkout_sessions',
			'coupons',
			'coupon_levels',
			'coupon_uses',
			'sequences',
			'content_access',
			'events',
			'webhook_events',
			'email_log',
			'logs',
		);
	}
}
