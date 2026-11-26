<?php
/**
 * Fills a DEVELOPMENT site's Paid Memberships Pro / MemberPress tables with realistic demo data, to try the migration.
 *
 * Usage: php bin/demo/seed.php /path/to/wp-load.php host pmpro|memberpress|clean
 *
 * Everything it creates is recorded in the option "memberkin_demo_seed" and removed by "clean" (users, posts, terms,
 * source rows, options it set). Never run it on a production site.
 *
 * @package Memberkin
 */

// phpcs:disable -- developer tool, not loaded by WordPress.

if ( count( $argv ) < 4 || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php bin/demo/seed.php /path/to/wp-load.php host pmpro|memberpress|clean\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = $argv[2];
require $argv[1];
global $wpdb;
mt_srand( 20261124 );

$local_host = (bool) preg_match( '/(^localhost|^127\.0\.0\.1|\.test|\.localhost)$/', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
if ( 'production' === wp_get_environment_type() && ! $local_host ) {
	fwrite( STDERR, "Refusing to run: the site is not a local development host and WP_ENVIRONMENT_TYPE is production.\n" );
	exit( 2 );
}

$log = get_option( 'memberkin_demo_seed', array() );
$log += array(
	'rows'    => array(),
	'users'   => array(),
	'posts'   => array(),
	'terms'   => array(),
	'options' => array(),
	'files'   => array(),
	'expect'  => array(),
);

/**
 * Writes a demo file into uploads and records it.
 */
function demo_file( string $name, string $contents ): string {
	global $log;
	$dir = wp_get_upload_dir()['basedir'] . '/memberkin-demo-source';
	wp_mkdir_p( $dir );
	file_put_contents( $dir . '/' . $name, $contents );
	$log['files'][] = $dir . '/' . $name;
	return $dir . '/' . $name;
}

/**
 * Records the value the migration should produce (key => value; files: ['file' => sha1, 'name' => name]).
 */
function demo_expect( int $user, string $key, $value ): void {
	global $log;
	$log['expect'][ $user ][ $key ] = $value;
}

/**
 * Inserts a source row and records it.
 */
function demo_insert( string $table, array $row ): int {
	global $wpdb, $log;
	if ( false === $wpdb->insert( $wpdb->prefix . $table, $row ) ) {
		fwrite( STDERR, "Insert into {$table} failed: {$wpdb->last_error}\n" );
		exit( 1 );
	}
	$id                       = (int) $wpdb->insert_id;
	$log['rows'][ $table ][] = $id;
	return $id;
}

function demo_user( string $prefix, int $n ): int {
	global $log;
	$login = sprintf( '%s%03d', $prefix, $n );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password( 24 ),
			'first_name' => ucfirst( $prefix ) . 'First' . $n,
			'last_name'  => 'Demo',
			'role'       => 'subscriber',
		)
	);
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
	$log['users'][] = $id;
	return $id;
}

function demo_post( string $type, string $title, array $meta = array(), string $status = 'publish', string $content = '' ): int {
	global $log;
	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_status'  => $status,
			'post_content' => '' !== $content ? $content : 'Demo content for the migration test.<!--more-->Premium part.',
		)
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	$log['posts'][] = $id;
	return $id;
}

function demo_term( string $name, string $taxonomy = 'category' ): int {
	global $log;
	$term           = wp_insert_term( $name, $taxonomy );
	$id             = (int) $term['term_id'];
	$log['terms'][] = array( $id, $taxonomy );
	return $id;
}

function demo_option( string $name, $value ): void {
	global $log;
	if ( ! array_key_exists( $name, $log['options'] ) ) {
		$log['options'][ $name ] = get_option( $name, null );
	}
	update_option( $name, $value );
}

function utc( string $relative ): string {
	return gmdate( 'Y-m-d H:i:s', strtotime( $relative ) );
}

function local( string $relative ): string {
	return wp_date( 'Y-m-d H:i:s', strtotime( $relative ) );
}

$what = $argv[3];

if ( 'clean' === $what ) {
	foreach ( $log['rows'] as $table => $ids ) {
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')' );
		}
	}
	foreach ( array( 'pmpro_discount_codes_levels' => 'code_id', 'pmpro_memberships_pages' => 'page_id', 'pmpro_memberships_categories' => 'category_id' ) as $table => $col ) {
		if ( ! empty( $log['composite'][ $table ] ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE {$col} IN (" . implode( ',', array_map( 'intval', $log['composite'][ $table ] ) ) . ')' );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $log['users'] as $id ) {
		wp_delete_user( (int) $id );
	}
	foreach ( $log['posts'] as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $log['terms'] as [ $id, $tax ] ) {
		wp_delete_term( (int) $id, $tax );
	}
	foreach ( $log['files'] as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	$demo_dir = wp_get_upload_dir()['basedir'] . '/memberkin-demo-source';
	if ( is_dir( $demo_dir ) && ! glob( $demo_dir . '/*' ) ) {
		rmdir( $demo_dir );
	}
	foreach ( $log['options'] as $name => $old ) {
		null === $old ? delete_option( $name ) : update_option( $name, $old );
	}
	delete_option( 'memberkin_demo_seed' );
	printf( "Removed %d users, %d posts, %d terms and the seeded source rows.\n", count( $log['users'] ), count( $log['posts'] ), count( $log['terms'] ) );
	exit( 0 );
}

if ( 'pmpro' === $what ) {
	demo_option( 'pmpro_currency', 'EUR' );
	$plans  = demo_insert( 'pmpro_groups', array( 'name' => 'Membership Plans', 'allow_multiple_selections' => 0, 'displayorder' => 2 ) );
	$addons = demo_insert( 'pmpro_groups', array( 'name' => 'Add-ons', 'allow_multiple_selections' => 1, 'displayorder' => 3 ) );
	$base   = array( 'description' => '', 'confirmation' => '', 'initial_payment' => '0', 'billing_amount' => '0', 'cycle_number' => 0, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => '0', 'trial_limit' => 0, 'allow_signups' => 1, 'expiration_number' => 0, 'expiration_period' => '' );
	$levels = array(
		'basic'    => array( $plans, array( 'name' => 'Basic', 'initial_payment' => '9', 'billing_amount' => '9', 'cycle_number' => 1, 'confirmation' => 'Welcome to Basic!' ) ),
		'pro'      => array( $plans, array( 'name' => 'Pro', 'initial_payment' => '19', 'billing_amount' => '19', 'cycle_number' => 1, 'trial_amount' => '1', 'trial_limit' => 1 ) ),
		'annual'   => array( $plans, array( 'name' => 'Annual', 'initial_payment' => '190', 'billing_amount' => '190', 'cycle_number' => 1, 'cycle_period' => 'Year' ) ),
		'lifetime' => array( $plans, array( 'name' => 'Lifetime', 'initial_payment' => '299' ) ),
		'free'     => array( $plans, array( 'name' => 'Free', 'description' => 'Free tier' ) ),
		'workshop' => array( $addons, array( 'name' => 'Workshop', 'initial_payment' => '49', 'expiration_number' => 1, 'expiration_period' => 'Year', 'allow_signups' => 0 ) ),
	);
	$ids = array();
	foreach ( $levels as $key => [ $group, $level ] ) {
		$ids[ $key ] = demo_insert( 'pmpro_membership_levels', array_merge( $base, $level ) );
		demo_insert( 'pmpro_membership_levels_groups', array( 'level' => $ids[ $key ], 'group' => $group ) );
	}
	$codes = array(
		demo_insert( 'pmpro_discount_codes', array( 'code' => 'WELCOME20', 'starts' => '2025-01-01', 'expires' => '2030-12-31', 'uses' => 0, 'discount_type' => 'percentage', 'discount_value' => '20', 'apply_to_initial' => 1, 'apply_to_recurring' => 0 ) ),
		demo_insert( 'pmpro_discount_codes', array( 'code' => 'SUMMER5', 'starts' => '2025-06-01', 'expires' => '2025-09-30', 'uses' => 100, 'discount_type' => 'fixed', 'discount_value' => '5', 'apply_to_initial' => 1, 'apply_to_recurring' => 1 ) ),
		demo_insert( 'pmpro_discount_codes', array( 'code' => 'VIPPRO', 'starts' => '2025-01-01', 'expires' => '2030-12-31', 'uses' => 10, 'one_use_per_user' => 1, 'discount_type' => 'set_price' ) ),
	);
	$log['composite']['pmpro_discount_codes_levels'] = $codes;
	$wpdb->insert( $wpdb->prefix . 'pmpro_discount_codes_levels', array( 'code_id' => $codes[0], 'level_id' => $ids['basic'], 'billing_limit' => 0, 'expiration_number' => 0, 'expiration_period' => '' ) );
	$wpdb->insert( $wpdb->prefix . 'pmpro_discount_codes_levels', array( 'code_id' => $codes[0], 'level_id' => $ids['pro'], 'billing_limit' => 0, 'expiration_number' => 0, 'expiration_period' => '' ) );
	$wpdb->insert( $wpdb->prefix . 'pmpro_discount_codes_levels', array( 'code_id' => $codes[1], 'level_id' => $ids['annual'], 'billing_limit' => 0, 'expiration_number' => 0, 'expiration_period' => '' ) );
	$wpdb->insert( $wpdb->prefix . 'pmpro_discount_codes_levels', array( 'code_id' => $codes[2], 'level_id' => $ids['pro'], 'initial_payment' => '9', 'billing_amount' => '9', 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 0, 'expiration_number' => 0, 'expiration_period' => '' ) );

	$gateways = array_merge( array_fill( 0, 80, 'stripe' ), array_fill( 0, 15, 'paypal' ), array_fill( 0, 10, 'paypalexpress' ), array_fill( 0, 10, 'authorizenet' ), array_fill( 0, 5, 'check' ) );
	$prices   = array( 'basic' => 9, 'pro' => 19, 'annual' => 190 );
	$counts   = array( 'users' => 0, 'orders' => 0 );
	$order_no = 0;
	$order    = static function ( int $user, int $level, string $status, float $total, string $gateway, string $txn, string $when, float $tax = 0 ) use ( &$order_no, &$counts ): int {
		++$order_no;
		++$counts['orders'];
		return demo_insert( 'pmpro_membership_orders', array( 'code' => sprintf( 'DEMO%06d', $order_no ), 'user_id' => $user, 'membership_id' => $level, 'billing_name' => 'Demo Member', 'billing_street' => 'Demo Street 1', 'billing_city' => 'Vienna', 'billing_state' => '', 'billing_zip' => '1010', 'billing_country' => 'AT', 'billing_phone' => '', 'subtotal' => number_format( $total - $tax, 2, '.', '' ), 'tax' => number_format( $tax, 2, '.', '' ), 'total' => number_format( $total, 2, '.', '' ), 'cardtype' => 'check' === $gateway ? '' : 'Visa', 'accountnumber' => 'check' === $gateway ? '' : 'XXXXXXXXXXXX' . mt_rand( 1000, 9999 ), 'status' => $status, 'gateway' => $gateway, 'gateway_environment' => 'sandbox', 'payment_transaction_id' => 'success' === $status || 'refunded' === $status ? 'txn_' . $order_no : '', 'subscription_transaction_id' => $txn, 'timestamp' => utc( $when ), 'affiliate_id' => '', 'affiliate_subid' => '', 'notes' => '' ) );
	};

	// 120 active recurring members.
	foreach ( $gateways as $i => $gateway ) {
		$user   = demo_user( 'pmp', ++$counts['users'] );
		$key    = array( 'basic', 'pro', 'annual' )[ $i % 3 ];
		$months = 'annual' === $key ? 1 : mt_rand( 1, 14 );
		$start  = 'annual' === $key ? '-' . mt_rand( 30, 300 ) . ' days' : "-{$months} months";
		$next   = 'annual' === $key ? '+' . mt_rand( 60, 330 ) . ' days' : '+' . mt_rand( 1, 29 ) . ' days';
		$txn    = match ( $gateway ) {
			'stripe'                  => 'sub_demo' . $user,
			'paypal', 'paypalexpress' => 'I-DEMO' . $user,
			'authorizenet'            => (string) ( 9000000 + $user ),
			default                   => '',
		};
		if ( 'stripe' === $gateway ) {
			update_user_meta( $user, 'pmpro_stripe_customerid', 'cus_demo' . $user );
		}
		$total = (float) $prices[ $key ];
		$tax   = round( $total * 0.2 / 1.2, 2 );
		demo_insert( 'pmpro_subscriptions', array( 'user_id' => $user, 'membership_level_id' => $ids[ $key ], 'gateway' => $gateway, 'gateway_environment' => 'sandbox', 'subscription_transaction_id' => '' !== $txn ? $txn : 'CHECK' . $user, 'status' => 'active', 'startdate' => utc( $start ), 'enddate' => null, 'next_payment_date' => utc( $next ), 'billing_amount' => $total, 'cycle_number' => 1, 'cycle_period' => 'annual' === $key ? 'Year' : 'Month', 'billing_limit' => 0 ) );
		demo_insert( 'pmpro_memberships_users', array( 'user_id' => $user, 'membership_id' => $ids[ $key ], 'code_id' => 0 === $i % 9 ? $codes[0] : 0, 'initial_payment' => $total, 'billing_amount' => $total, 'cycle_number' => 1, 'cycle_period' => 'annual' === $key ? 'Year' : 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => local( $start ), 'enddate' => null ) );
		$sub_txn = '' !== $txn ? $txn : 'CHECK' . $user;
		for ( $m = $months; $m >= 1; $m-- ) {
			$status = 0 === $user % 23 && 2 === $m ? 'refunded' : ( 0 === $user % 17 && 1 === $m ? 'error' : 'success' );
			$order( $user, $ids[ $key ], $status, $total, $gateway, $sub_txn, "-{$m} months", $tax );
		}
		if ( 0 === $i % 12 ) {
			$order( $user, $ids['basic'], 'token', 9, $gateway, '', '-2 days' ); // Abandoned checkout.
		}
		if ( 0 === $i % 10 ) {
			demo_insert( 'pmpro_memberships_users', array( 'user_id' => $user, 'membership_id' => $ids['workshop'], 'code_id' => 0, 'initial_payment' => 49, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => local( '-20 days' ), 'enddate' => local( '+345 days' ) ) );
			$order( $user, $ids['workshop'], 'success', 49, $gateway, '', '-20 days' );
		}
	}
	// 30 lifetime, 20 free, 30 former members.
	for ( $i = 0; $i < 30; $i++ ) {
		$user = demo_user( 'pmp', ++$counts['users'] );
		demo_insert( 'pmpro_memberships_users', array( 'user_id' => $user, 'membership_id' => $ids['lifetime'], 'code_id' => 0, 'initial_payment' => 299, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => local( '-' . mt_rand( 10, 700 ) . ' days' ), 'enddate' => null ) );
		$order( $user, $ids['lifetime'], 0 === $i % 15 ? 'refunded' : 'success', 299, 0 === $i % 5 ? 'check' : 'stripe', '', '-' . mt_rand( 10, 700 ) . ' days', 49.83 );
	}
	for ( $i = 0; $i < 20; $i++ ) {
		$user = demo_user( 'pmp', ++$counts['users'] );
		demo_insert( 'pmpro_memberships_users', array( 'user_id' => $user, 'membership_id' => $ids['free'], 'code_id' => 0, 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => local( '-' . mt_rand( 1, 200 ) . ' days' ), 'enddate' => null ) );
	}
	for ( $i = 0; $i < 30; $i++ ) {
		$user   = demo_user( 'pmp', ++$counts['users'] );
		$status = array( 'cancelled', 'expired', 'admin_cancelled', 'changed' )[ $i % 4 ];
		$key    = array( 'basic', 'pro' )[ $i % 2 ];
		demo_insert( 'pmpro_subscriptions', array( 'user_id' => $user, 'membership_level_id' => $ids[ $key ], 'gateway' => 'stripe', 'gateway_environment' => 'sandbox', 'subscription_transaction_id' => 'sub_old' . $user, 'status' => 'cancelled', 'startdate' => utc( '-8 months' ), 'enddate' => utc( '-2 months' ), 'next_payment_date' => null, 'billing_amount' => $prices[ $key ], 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 0 ) );
		demo_insert( 'pmpro_memberships_users', array( 'user_id' => $user, 'membership_id' => $ids[ $key ], 'code_id' => 0, 'initial_payment' => $prices[ $key ], 'billing_amount' => $prices[ $key ], 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => $status, 'startdate' => local( '-8 months' ), 'enddate' => local( '-2 months' ) ) );
		for ( $m = 8; $m > 2; $m-- ) {
			$order( $user, $ids[ $key ], 'success', $prices[ $key ], 'stripe', 'sub_old' . $user, "-{$m} months" );
		}
		if ( 'changed' === $status ) {
			demo_insert( 'pmpro_memberships_users', array( 'user_id' => $user, 'membership_id' => $ids['annual'], 'code_id' => 0, 'initial_payment' => 190, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => local( '-2 months' ), 'enddate' => local( '+10 months' ) ) );
		}
	}
	// Restricted content.
	$pages = array();
	for ( $i = 1; $i <= 10; $i++ ) {
		$pages[] = demo_post( 0 === $i % 3 ? 'page' : 'post', "Demo premium content {$i}" );
	}
	$log['composite']['pmpro_memberships_pages'] = $pages;
	foreach ( $pages as $i => $page ) {
		$wpdb->insert( $wpdb->prefix . 'pmpro_memberships_pages', array( 'membership_id' => $ids['pro'], 'page_id' => $page ) );
		if ( $i % 2 ) {
			$wpdb->insert( $wpdb->prefix . 'pmpro_memberships_pages', array( 'membership_id' => $ids['annual'], 'page_id' => $page ) );
		}
	}
	$cat                                             = demo_term( 'Demo members-only category' );
	$log['composite']['pmpro_memberships_categories'] = array( $cat );
	$wpdb->insert( $wpdb->prefix . 'pmpro_memberships_categories', array( 'membership_id' => $ids['basic'], 'category_id' => $cat ) );
	$wpdb->insert( $wpdb->prefix . 'pmpro_memberships_categories', array( 'membership_id' => $ids['pro'], 'category_id' => $cat ) );

	// User fields (settings groups are stdClass objects, as the settings screen saves them).
	demo_option(
		'pmpro_user_fields_settings',
		array(
			(object) array(
				'name'     => 'About you',
				'checkout' => 'yes',
				'levels'   => array(),
				'fields'   => array(
					(object) array( 'label' => 'Company', 'name' => 'company', 'type' => 'text', 'required' => 'no', 'profile' => '', 'hint' => 'For your invoices', 'options' => '', 'default' => '' ),
					(object) array( 'label' => 'T-shirt size', 'name' => 'tshirt', 'type' => 'select', 'profile' => 'yes', 'options' => "s:Small\nm:Medium\nl:Large" ),
					(object) array( 'label' => 'Interests', 'name' => 'interests', 'type' => 'checkbox_grouped', 'profile' => 'yes', 'options' => "News\nEvents\nCourses" ), // Stored by position: 0, 1, 2.
					(object) array( 'label' => 'Newsletter', 'name' => 'newsletter', 'type' => 'checkbox', 'profile' => 'yes' ),
					(object) array( 'label' => 'Birthday', 'name' => 'birthday', 'type' => 'date', 'profile' => 'no' ),
				),
			),
			(object) array(
				'name'     => 'Pro details',
				'checkout' => 'yes',
				'levels'   => array( (string) $ids['pro'], (string) $ids['annual'] ),
				'fields'   => array(
					(object) array( 'label' => 'Certificate', 'name' => 'certificate', 'type' => 'file', 'profile' => 'yes', 'allowed_file_types' => 'pdf' ),
					(object) array( 'label' => 'Referred by', 'name' => 'referred_by', 'type' => 'text', 'profile' => 'admins' ),
				),
			),
		)
	);
	$sizes = array( 's', 'm', 'l' );
	$tags  = array( '0', '1', '2' );
	foreach ( $log['users'] as $n => $user ) {
		if ( 0 === $n % 3 ) {
			update_user_meta( $user, 'company', "Company {$n}" );
			demo_expect( $user, 'company', "Company {$n}" );
		}
		if ( 0 === $n % 2 ) {
			update_user_meta( $user, 'tshirt', $sizes[ $n % 3 ] );
			demo_expect( $user, 'tshirt', $sizes[ $n % 3 ] );
			$picked = array_slice( $tags, 0, 1 + $n % 3 );
			update_user_meta( $user, 'interests', $picked );
			demo_expect( $user, 'interests', $picked );
		}
		update_user_meta( $user, 'newsletter', 0 === $n % 4 ? '1' : '0' );
		demo_expect( $user, 'newsletter', 0 === $n % 4 ? '1' : '' );
		if ( 0 === $n % 5 ) {
			$day = gmdate( 'Y-m-d', strtotime( '1980-01-01 +' . ( $n * 97 ) . ' days' ) );
			update_user_meta( $user, 'birthday', $day );
			demo_expect( $user, 'birthday', $day );
		}
		if ( 0 === $n % 25 ) {
			$pdf  = "%PDF-1.4\n% Demo certificate {$n}\n1 0 obj<<>>endobj\n%%EOF";
			$path = demo_file( "cert-{$user}.pdf", $pdf );
			update_user_meta( $user, 'certificate', array( 'original_filename' => "Certificate {$n}.pdf", 'filename' => "cert-{$user}.pdf", 'fullpath' => $path, 'fullurl' => '', 'size' => strlen( $pdf ) ) );
			demo_expect( $user, 'certificate', array( 'file' => sha1( $pdf ), 'name' => "Certificate-{$n}.pdf" ) );
		}
		if ( 7 === $n % 50 ) {
			update_user_meta( $user, 'tshirt', 'xxl' ); // Not a choice: reported, not imported.
			demo_expect( $user, 'tshirt', '' );
		}
	}
	update_option( 'memberkin_demo_seed', $log, false );
	printf( "PMPro demo: %d users, %d levels, %d orders (incl. abandoned/refunded/failed), %d restricted posts.\n", $counts['users'], count( $ids ), $counts['orders'], count( $pages ) );
	exit( 0 );
}

if ( 'memberpress' === $what ) {
	$options                  = (array) get_option( 'mepr_options', array() );
	$options['currency_code'] = 'USD';
	$options['integrations']  = array(
		'gw-stripe' => array( 'gateway' => 'MeprStripeGateway', 'id' => 'gw-stripe', 'test_mode' => 'on' ),
		'gw-pps'    => array( 'gateway' => 'MeprPayPalStandardGateway', 'id' => 'gw-pps' ),
		'gw-off'    => array( 'gateway' => 'MeprArtificialGateway', 'id' => 'gw-off' ),
	);
	$options['custom_fields'] = array(
		array( 'field_key' => 'mepr_company', 'field_name' => 'Company', 'field_type' => 'text', 'default_value' => '', 'show_on_signup' => true, 'show_in_account' => true, 'required' => false, 'options' => array() ),
		array( 'field_key' => 'mepr_phone', 'field_name' => 'Phone', 'field_type' => 'tel', 'default_value' => '', 'show_on_signup' => true, 'show_in_account' => true, 'required' => false, 'options' => array() ),
		array( 'field_key' => 'mepr_topics', 'field_name' => 'Topics', 'field_type' => 'checkboxes', 'default_value' => '', 'show_on_signup' => true, 'show_in_account' => true, 'required' => false, 'options' => array( array( 'option_name' => 'Marketing', 'option_value' => 'marketing' ), array( 'option_name' => 'Design', 'option_value' => 'design' ) ) ),
		array( 'field_key' => 'mepr_level', 'field_name' => 'Experience', 'field_type' => 'radios', 'default_value' => '', 'show_on_signup' => false, 'show_in_account' => true, 'required' => false, 'options' => array( array( 'option_name' => 'Beginner', 'option_value' => 'beginner' ), array( 'option_name' => 'Expert', 'option_value' => 'expert' ) ) ),
		array( 'field_key' => 'mepr_joined', 'field_name' => 'Customer since', 'field_type' => 'date', 'default_value' => '', 'show_on_signup' => false, 'show_in_account' => false, 'required' => false, 'options' => array() ),
		array( 'field_key' => 'mepr_portfolio', 'field_name' => 'Portfolio', 'field_type' => 'file', 'default_value' => '', 'show_on_signup' => false, 'show_in_account' => true, 'required' => false, 'options' => array() ),
	);
	demo_option( 'mepr_options', $options );
	$group   = demo_post( 'memberpressgroup', 'Demo Plans', array( '_mepr_group_is_upgrade_path' => 1 ) );
	$free    = demo_post( 'memberpressproduct', 'Demo Free', array( '_mepr_product_price' => '0.00', '_mepr_product_period_type' => 'lifetime', '_mepr_group_id' => $group, '_mepr_group_order' => 0 ) );
	$starter = demo_post( 'memberpressproduct', 'Demo Starter', array( '_mepr_product_price' => '15.00', '_mepr_product_period' => 1, '_mepr_product_period_type' => 'months', '_mepr_group_id' => $group, '_mepr_group_order' => 1 ) );
	$premium = demo_post( 'memberpressproduct', 'Demo Premium', array( '_mepr_product_price' => '150.00', '_mepr_product_period' => 1, '_mepr_product_period_type' => 'years', '_mepr_product_trial' => 1, '_mepr_product_trial_days' => 14, '_mepr_product_trial_amount' => '0.00', '_mepr_group_id' => $group, '_mepr_group_order' => 2, '_mepr_thank_you_page_enabled' => 1, '_mepr_product_thank_you_message' => 'Welcome to Premium!' ) );
	$course  = demo_post( 'memberpressproduct', 'Demo Course', array( '_mepr_product_price' => '79.00', '_mepr_product_period_type' => 'lifetime', '_mepr_expire_type' => 'delay', '_mepr_expire_after' => 1, '_mepr_expire_unit' => 'years' ) );
	update_post_meta( $group, '_mepr_fallback_membership', $free );
	// The course asks only for Company at sign-up.
	update_post_meta( $course, '_mepr_customize_profile_fields', 1 );
	update_post_meta( $course, '_mepr_custom_profile_fields', array( 'mepr_company' ) );
	demo_post( 'memberpresscoupon', 'DEMOLAUNCH', array( '_mepr_coupons_discount_type' => 'percent', '_mepr_coupons_discount_mode' => 'standard', '_mepr_coupons_discount_amount' => '25', '_mepr_coupons_valid_products' => array( $starter, $premium ), '_mepr_coupons_usage_amount' => 500, '_mepr_coupons_usage_count' => 42 ) );
	demo_post( 'memberpresscoupon', 'DEMOTRIAL30', array( '_mepr_coupons_discount_type' => 'percent', '_mepr_coupons_discount_mode' => 'trial-override', '_mepr_coupons_discount_amount' => '0', '_mepr_coupons_trial_days' => 30, '_mepr_coupons_trial_amount' => '0', '_mepr_coupons_valid_products' => array( $premium ) ) );

	$users = 0;
	$txns  = 0;
	$txn   = static function ( int $user, int $product, int $sub, string $status, string $created, ?string $expires, float $amount, string $gateway, string $type = 'payment' ) use ( &$txns ): int {
		++$txns;
		return demo_insert( 'mepr_transactions', array( 'amount' => $amount, 'total' => $amount, 'tax_amount' => 0, 'user_id' => $user, 'product_id' => $product, 'trans_num' => 'mp-txn-' . $txns, 'status' => $status, 'txn_type' => $type, 'gateway' => $gateway, 'subscription_id' => $sub, 'created_at' => utc( $created ), 'expires_at' => null === $expires ? '0000-00-00 00:00:00' : utc( $expires ), 'refunded_at' => 'refunded' === $status ? utc( $created . ' +3 days' ) : null ) );
	};
	$plan = array_merge( array_fill( 0, 70, 'gw-stripe' ), array_fill( 0, 15, 'gw-pps' ), array_fill( 0, 5, 'gw-off' ) );
	foreach ( $plan as $i => $gw ) {
		$user    = demo_user( 'mpr', ++$users );
		$yearly  = 0 === $i % 4;
		$product = $yearly ? $premium : $starter;
		$price   = $yearly ? 150.0 : 15.0;
		$cycles  = $yearly ? 1 : mt_rand( 1, 10 );
		$status  = 0 === $i % 15 ? 'suspended' : 'active';
		$remote  = 'gw-stripe' === $gw ? 'sub_mp' . $user : ( 'gw-pps' === $gw ? 'I-MP' . $user : 'mp-off-' . $user );
		$sub     = demo_insert( 'mepr_subscriptions', array( 'user_id' => $user, 'product_id' => $product, 'subscr_id' => $remote, 'price' => $price, 'total' => $price, 'gateway' => $gw, 'period' => 1, 'period_type' => $yearly ? 'years' : 'months', 'status' => $status, 'created_at' => utc( $yearly ? '-200 days' : "-{$cycles} months" ), 'trial' => $yearly ? 1 : 0, 'trial_days' => 14 ) );
		if ( $yearly ) {
			$txn( $user, $product, $sub, 'confirmed', '-200 days', '-186 days', 0, $gw, 'subscription_confirmation' );
			$txn( $user, $product, $sub, 'complete', '-186 days', '+179 days', $price, $gw );
		} else {
			for ( $m = $cycles; $m >= 1; $m-- ) {
				$st = 0 === $user % 19 && 2 === $m ? 'refunded' : 'complete';
				$txn( $user, $product, $sub, $st, "-{$m} months", '-' . ( $m - 1 ) . ' months +1 month', $price, $gw );
			}
			if ( 0 === $i % 13 ) {
				$txn( $user, $product, $sub, 'failed', '-1 days', null, $price, $gw );
			}
		}
	}
	for ( $i = 0; $i < 20; $i++ ) { // Cancelled subscribers (history).
		$user = demo_user( 'mpr', ++$users );
		$sub  = demo_insert( 'mepr_subscriptions', array( 'user_id' => $user, 'product_id' => $starter, 'subscr_id' => 'sub_mpold' . $user, 'price' => 15, 'total' => 15, 'gateway' => 'gw-stripe', 'period' => 1, 'period_type' => 'months', 'status' => 'cancelled', 'created_at' => utc( '-9 months' ) ) );
		for ( $m = 9; $m > 3; $m-- ) {
			$txn( $user, $starter, $sub, 'complete', "-{$m} months", '-' . ( $m - 1 ) . ' months', 15, 'gw-stripe' );
		}
	}
	for ( $i = 0; $i < 20; $i++ ) { // Course buyers (one-time, expires after a year).
		$user = demo_user( 'mpr', ++$users );
		$txn( $user, $course, 0, 0 === $i % 10 ? 'refunded' : 'complete', '-' . ( 30 + 20 * $i ) . ' days', '-' . ( 30 + 20 * $i ) . ' days +1 year', 79, 'gw-stripe' );
	}
	for ( $i = 0; $i < 15; $i++ ) { // Free members.
		$user = demo_user( 'mpr', ++$users );
		$txn( $user, $free, 0, 'complete', '-' . mt_rand( 1, 300 ) . ' days', null, 0, 'free' );
	}
	// Rules.
	$cond  = static function ( int $rule, string $type, string $value ): void {
		demo_insert( 'mepr_rule_access_conditions', array( 'rule_id' => $rule, 'access_type' => $type, 'access_operator' => 'is', 'access_condition' => $value ) );
	};
	$posts = array();
	for ( $i = 1; $i <= 5; $i++ ) {
		$posts[] = demo_post( 'post', "Demo MP article {$i}" );
	}
	$tag = demo_term( 'demo-premium-tag', 'post_tag' );
	$r   = demo_post( 'memberpressrule', 'Demo: article 1 for Starter', array( '_mepr_rules_type' => 'single_post', '_mepr_rules_content' => (string) $posts[0] ) );
	$cond( $r, 'membership', (string) $starter );
	$cond( $r, 'membership', (string) $premium );
	$r = demo_post( 'memberpressrule', 'Demo: tagged premium, drip 7 days', array( '_mepr_rules_type' => 'tag', '_mepr_rules_content' => (string) $tag, '_mepr_rules_drip_enabled' => 1, '_mepr_rules_drip_amount' => 7, '_mepr_rules_drip_unit' => 'days', '_mepr_rules_drip_after' => 'registers', '_mepr_rules_unauth_message_type' => 'custom', '_mepr_rules_unath_message' => 'Premium members only.' ) );
	$cond( $r, 'membership', (string) $premium );
	$r = demo_post( 'memberpressrule', 'Demo: members area URL', array( '_mepr_rules_type' => 'custom', '_mepr_rules_content' => '/demo-members-area/' ) );
	$cond( $r, 'login_state', 'logged_in' );
	$r = demo_post( 'memberpressrule', 'Demo: partial', array( '_mepr_rules_type' => 'partial' ) );
	$cond( $r, 'membership', (string) $starter );
	$mp_users = array_values( array_filter( $log['users'], static fn( $id ) => str_starts_with( (string) get_userdata( $id )->user_login, 'mpr' ) ) );
	foreach ( $mp_users as $n => $user ) {
		if ( 0 === $n % 2 ) {
			update_user_meta( $user, 'mepr_company', "MP Co {$n}" );
			demo_expect( $user, 'company', "MP Co {$n}" );
		}
		if ( 0 === $n % 3 ) {
			update_user_meta( $user, 'mepr_phone', '+1 555 01' . sprintf( '%02d', $n % 100 ) );
			demo_expect( $user, 'phone', '+1 555 01' . sprintf( '%02d', $n % 100 ) );
			update_user_meta( $user, 'mepr_topics', 0 === $n % 2 ? array( 'marketing' => 'on', 'design' => 'on' ) : array( 'design' => 'on' ) );
			demo_expect( $user, 'topics', 0 === $n % 2 ? array( 'marketing', 'design' ) : array( 'design' ) );
		}
		if ( 1 === $n % 4 ) {
			update_user_meta( $user, 'mepr_level', 0 === $n % 8 ? 'expert' : 'beginner' );
			demo_expect( $user, 'level', 0 === $n % 8 ? 'expert' : 'beginner' );
			$when = strtotime( '2020-06-01 +' . ( $n * 11 ) . ' days' );
			update_user_meta( $user, 'mepr_joined', gmdate( 'F j, Y', $when ) ); // Display format, as typed in the date picker.
			demo_expect( $user, 'joined', gmdate( 'Y-m-d', $when ) );
		}
		if ( 0 === $n % 30 ) {
			$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
			$path = demo_file( "portfolio-{$user}.png", $png );
			update_user_meta( $user, 'mepr_portfolio', wp_get_upload_dir()['baseurl'] . '/memberkin-demo-source/' . basename( $path ) );
			demo_expect( $user, 'portfolio', array( 'file' => sha1( $png ), 'name' => basename( $path ) ) );
		}
	}
	update_option( 'memberkin_demo_seed', $log, false );
	printf( "MemberPress demo: %d users, 4 products, %d transactions, 4 rules.\n", $users, $txns );
	exit( 0 );
}

fwrite( STDERR, "Unknown action {$what}\n" );
exit( 2 );
