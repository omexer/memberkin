<?php
/**
 * Level business logic.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Currency;
use Memberkin\Core\Db;
use Memberkin\Core\Money;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Level;
use Memberkin\Domain\LevelGroup;
use Memberkin\Domain\Period;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, validates, duplicates and deletes levels and groups.
 */
class LevelService {

	/**
	 * Level meta keys editable from the level screen / API, with sanitisers.
	 *
	 * @return array<string, callable>
	 */
	public static function meta_fields(): array {
		$fields = array(
			'confirmation_message' => 'wp_kses_post',
			'account_message'      => 'wp_kses_post',
			'cost_text'            => 'wp_kses_post',
			'benefits'             => static fn( $v ) => array_values( array_filter( array_map( 'sanitize_text_field', is_array( $v ) ? $v : preg_split( '/\r\n|\r|\n/', (string) $v ) ) ) ),
			'highlight'            => static fn( $v ) => (bool) $v,
			'thank_you_page'       => 'absint',
			'login_redirect'       => 'esc_url_raw',
			'roles'                => static fn( $v ) => array_values( array_filter( array_map( 'sanitize_key', (array) $v ), static fn( $r ) => '' !== $r && 'administrator' !== $r ) ),
			'remove_roles_on_end'  => static fn( $v ) => (bool) $v,
			'gateways'             => static fn( $v ) => array_values( array_map( 'sanitize_key', (array) $v ) ),
			'trial_once'           => static fn( $v ) => (bool) $v,
			'allow_renewal'        => static fn( $v ) => (bool) $v,
			'button_text'          => 'sanitize_text_field',
		);
		/**
		 * Filters editable level meta fields (key => sanitiser).
		 *
		 * @param array<string, callable> $fields Fields.
		 */
		return (array) apply_filters( 'memberkin_level_meta_fields', $fields );
	}

	/**
	 * Constructor.
	 *
	 * @param LevelRepository      $levels Levels.
	 * @param LevelGroupRepository $groups Groups.
	 */
	public function __construct(
		private LevelRepository $levels,
		private LevelGroupRepository $groups
	) {}

	/**
	 * Default group (created on demand).
	 */
	public function default_group(): LevelGroup {
		$groups = $this->groups->all();
		if ( $groups ) {
			return reset( $groups );
		}
		$group     = new LevelGroup( 0, __( 'Main', 'memberkin' ) );
		$group->id = $this->groups->save( $group );
		return $group;
	}

	/**
	 * Creates or updates a level from raw input (already unslashed).
	 *
	 * Accepted keys: id, name, slug, description, group_id, status, allow_signups, display_order, tax_class,
	 * pricing keys (amounts as decimal strings initial_price, signup_fee_price, recurring_price, trial_price,
	 * or minor units initial_amount, signup_fee, recurring_amount, trial_amount), billing_type (free|one_time|recurring),
	 * billing_interval, billing_period, billing_cycles, trial_length, trial_period, expiration_type,
	 * expiration_length, expiration_period, expiration_date (Y-m-d), meta (array of meta_fields()).
	 *
	 * @param array $input Input.
	 * @throws ValidationException On invalid input.
	 */
	public function save( array $input ): Level {
		$existing = ! empty( $input['id'] ) ? $this->levels->find( (int) $input['id'] ) : null;
		if ( ! empty( $input['id'] ) && ! $existing ) {
			throw new ValidationException( array( 'id' => esc_html__( 'Level not found.', 'memberkin' ) ) );
		}

		$errors = array();
		$name   = sanitize_text_field( (string) ( $input['name'] ?? ( $existing->name ?? '' ) ) );
		if ( '' === $name ) {
			$errors['name'] = __( 'Enter a level name.', 'memberkin' );
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = $existing ? $existing->slug : sanitize_title( $name );
		}
		$slug = $this->unique_slug( '' === $slug ? 'level' : $slug, $existing->id ?? 0 );

		$status = (string) ( $input['status'] ?? ( $existing->status ?? Level::STATUS_ACTIVE ) );
		if ( ! array_key_exists( $status, Level::statuses() ) ) {
			$errors['status'] = __( 'Invalid status.', 'memberkin' );
		}

		$group_id = (int) ( $input['group_id'] ?? ( $existing->group_id ?? 0 ) );
		if ( ! $group_id || ! $this->groups->find( $group_id ) ) {
			$group_id = $this->default_group()->id;
		}

		try {
			$schedule = $this->schedule_from_input( $input, $existing?->schedule );
			$errors   = array_merge( $errors, $schedule->validate() );
		} catch ( \InvalidArgumentException $e ) {
			$errors['pricing'] = __( 'Enter valid amounts.', 'memberkin' );
			$schedule          = $existing?->schedule ?? new PriceSchedule( Currency::default() );
		}

		$tax_class = sanitize_key( (string) ( $input['tax_class'] ?? ( $existing->tax_class ?? 'standard' ) ) );

		if ( $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}

		$level = new Level(
			$existing->id ?? 0,
			$name,
			$slug,
			wp_kses_post( (string) ( $input['description'] ?? ( $existing->description ?? '' ) ) ),
			$group_id,
			$status,
			array_key_exists( 'allow_signups', $input ) ? (bool) $input['allow_signups'] : ( $existing->allow_signups ?? true ),
			(int) ( $input['display_order'] ?? ( $existing->display_order ?? count( $this->levels->all() ) ) ),
			'' === $tax_class ? 'standard' : $tax_class,
			$schedule
		);
		$this->levels->save( $level );

		if ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) {
			$this->save_meta( $level->id, $input['meta'] );
		}

		/**
		 * Fires after a level was created or updated.
		 *
		 * @param Level $level   Level.
		 * @param bool  $created Whether it is new.
		 */
		do_action( 'memberkin_level_saved', $level, null === $existing );
		return $level;
	}

	/**
	 * Saves known meta fields.
	 *
	 * @param int   $level_id Level id.
	 * @param array $meta     Raw values.
	 */
	public function save_meta( int $level_id, array $meta ): void {
		foreach ( self::meta_fields() as $key => $sanitize ) {
			if ( array_key_exists( $key, $meta ) ) {
				$this->levels->set_meta( $level_id, $key, $sanitize( $meta[ $key ] ) );
			}
		}
	}

	/**
	 * Builds a price schedule from input, falling back to an existing schedule.
	 *
	 * @param array              $input    Input.
	 * @param PriceSchedule|null $existing Existing schedule.
	 * @throws \InvalidArgumentException On unparsable amounts.
	 */
	public function schedule_from_input( array $input, ?PriceSchedule $existing = null ): PriceSchedule {
		$currency = Currency::default();
		$base     = $existing ? $existing->to_row() : ( new PriceSchedule( $currency ) )->to_row();
		$row      = $base;

		$amount = static function ( string $decimal_key, string $minor_key ) use ( $input, $currency, &$row ): void {
			if ( array_key_exists( $minor_key, $input ) ) {
				$row[ $minor_key ] = (int) $input[ $minor_key ];
			} elseif ( array_key_exists( $decimal_key, $input ) ) {
				$row[ $minor_key ] = Money::from_decimal( (string) $input[ $decimal_key ], $currency, self::decimal_separator() )->amount;
			}
		};
		$amount( 'initial_price', 'initial_amount' );
		$amount( 'signup_fee_price', 'signup_fee' );
		$amount( 'recurring_price', 'recurring_amount' );
		$amount( 'trial_price', 'trial_amount' );

		foreach ( array( 'billing_interval', 'billing_cycles', 'trial_length', 'expiration_length' ) as $int_key ) {
			if ( array_key_exists( $int_key, $input ) ) {
				$row[ $int_key ] = max( 0, (int) $input[ $int_key ] );
			}
		}
		foreach ( array( 'billing_period', 'trial_period', 'expiration_period', 'expiration_type' ) as $str_key ) {
			if ( array_key_exists( $str_key, $input ) ) {
				$row[ $str_key ] = sanitize_key( (string) $input[ $str_key ] );
			}
		}
		if ( array_key_exists( 'expiration_date', $input ) ) {
			$date                   = trim( (string) $input['expiration_date'] );
			$row['expiration_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date )
				? ( new \DateTimeImmutable( $date . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )
				: null;
		}

		// A billing type selector simplifies the admin form.
		$type = isset( $input['billing_type'] ) ? sanitize_key( (string) $input['billing_type'] ) : '';
		if ( 'free' === $type ) {
			$row['initial_amount']   = 0;
			$row['signup_fee']       = 0;
			$row['recurring_amount'] = 0;
			$row['trial_length']     = 0;
		} elseif ( 'one_time' === $type ) {
			$row['recurring_amount'] = 0;
			$row['trial_length']     = 0;
			$row['billing_cycles']   = 0;
		}
		if ( 0 === (int) $row['recurring_amount'] ) {
			$row['trial_length'] = 0;
			if ( 'after_cycles' === $row['expiration_type'] ) {
				$row['expiration_type'] = 'never';
			}
		}
		if ( 'fixed_date' !== $row['expiration_type'] ) {
			$row['expiration_date'] = null;
		}
		if ( ! in_array( $row['expiration_type'], array( 'never', 'duration', 'fixed_date', 'after_cycles' ), true ) ) {
			$row['expiration_type'] = 'never';
		}
		if ( ! Period::is_valid( (string) $row['billing_period'] ) ) {
			$row['billing_period'] = 'month';
		}
		$row['billing_interval'] = max( 1, (int) $row['billing_interval'] );
		$row['currency']         = $existing ? $existing->currency : $currency;
		return PriceSchedule::from_row( $row );
	}

	/**
	 * Decimal separator of the site locale.
	 */
	public static function decimal_separator(): string {
		global $wp_locale;
		$sep = isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
		return ',' === $sep ? ',' : '.';
	}

	/**
	 * Unique slug.
	 *
	 * @param string $slug       Desired slug.
	 * @param int    $exclude_id Level being edited.
	 */
	private function unique_slug( string $slug, int $exclude_id ): string {
		$candidate = $slug;
		$i         = 2;
		while ( $this->levels->slug_exists( $candidate, $exclude_id ) ) {
			$candidate = $slug . '-' . $i;
			++$i;
		}
		return $candidate;
	}

	/**
	 * Duplicates a level (with meta). The copy is hidden until reviewed.
	 *
	 * @param int $id Level id.
	 * @throws ValidationException When not found.
	 */
	public function duplicate( int $id ): Level {
		$source = $this->levels->find( $id );
		if ( ! $source ) {
			throw new ValidationException( array( esc_html__( 'Level not found.', 'memberkin' ) ) );
		}
		$copy = new Level(
			0,
			/* translators: %s: level name. */
			sprintf( __( '%s (copy)', 'memberkin' ), $source->name ),
			$this->unique_slug( $source->slug . '-copy', 0 ),
			$source->description,
			$source->group_id,
			Level::STATUS_HIDDEN,
			$source->allow_signups,
			$source->display_order + 1,
			$source->tax_class,
			$source->schedule
		);
		$this->levels->save( $copy );
		foreach ( array_keys( self::meta_fields() ) as $key ) {
			$value = $this->levels->meta( $source->id, $key, null );
			if ( null !== $value ) {
				$this->levels->set_meta( $copy->id, $key, $value );
			}
		}
		return $copy;
	}

	/**
	 * Number of memberships (any status) on a level.
	 *
	 * @param int $id Level id.
	 */
	public function membership_count( int $id ): int {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes a level that was never used; used levels are archived instead (history must stay intact).
	 * Returns "deleted" or "archived".
	 *
	 * @param int $id Level id.
	 * @throws ValidationException When not found.
	 */
	public function delete( int $id ): string {
		$level = $this->levels->find( $id );
		if ( ! $level ) {
			throw new ValidationException( array( esc_html__( 'Level not found.', 'memberkin' ) ) );
		}
		if ( $this->membership_count( $id ) > 0 ) {
			$level->status        = Level::STATUS_ARCHIVED;
			$level->allow_signups = false;
			$this->levels->save( $level );
			return 'archived';
		}
		$this->levels->delete( $id );
		global $wpdb;
		$wpdb->delete( Db::table_name( 'content_access' ), array( 'level_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		/**
		 * Fires after a level was deleted.
		 *
		 * @param int $id Level id.
		 */
		do_action( 'memberkin_level_deleted', $id );
		return 'deleted';
	}

	/**
	 * Creates or updates a group.
	 *
	 * @param array $input id, name, allow_multiple, is_upgrade_path, fallback_level_id, proration_mode, downgrade_timing, display_order.
	 * @throws ValidationException On invalid input.
	 */
	public function save_group( array $input ): LevelGroup {
		$existing = ! empty( $input['id'] ) ? $this->groups->find( (int) $input['id'] ) : null;
		$name     = sanitize_text_field( (string) ( $input['name'] ?? ( $existing->name ?? '' ) ) );
		if ( '' === $name ) {
			throw new ValidationException( array( 'name' => esc_html__( 'Enter a group name.', 'memberkin' ) ) );
		}
		$mode = (string) ( $input['proration_mode'] ?? ( $existing->proration_mode ?? LevelGroup::PRORATION_IMMEDIATE ) );
		if ( ! array_key_exists( $mode, LevelGroup::proration_modes() ) ) {
			$mode = LevelGroup::PRORATION_IMMEDIATE;
		}
		$timing   = (string) ( $input['downgrade_timing'] ?? ( $existing->downgrade_timing ?? 'period_end' ) );
		$fallback = (int) ( $input['fallback_level_id'] ?? ( $existing->fallback_level_id ?? 0 ) );
		if ( $fallback && ! $this->levels->find( $fallback ) ) {
			$fallback = 0;
		}
		$group = new LevelGroup(
			$existing->id ?? 0,
			$name,
			array_key_exists( 'allow_multiple', $input ) ? (bool) $input['allow_multiple'] : ( $existing->allow_multiple ?? false ),
			array_key_exists( 'is_upgrade_path', $input ) ? (bool) $input['is_upgrade_path'] : ( $existing->is_upgrade_path ?? false ),
			$fallback,
			$mode,
			in_array( $timing, array( 'immediate', 'period_end' ), true ) ? $timing : 'period_end',
			(int) ( $input['display_order'] ?? ( $existing->display_order ?? count( $this->groups->all() ) ) )
		);
		$this->groups->save( $group );
		return $group;
	}

	/**
	 * Deletes a group; its levels move to another group.
	 *
	 * @param int $id Group id.
	 * @throws ValidationException When it is the only group.
	 */
	public function delete_group( int $id ): void {
		$others = array_filter( $this->groups->all(), static fn( LevelGroup $g ) => $g->id !== $id );
		if ( ! $others ) {
			throw new ValidationException( array( esc_html__( 'At least one level group is required.', 'memberkin' ) ) );
		}
		$target = reset( $others );
		foreach ( $this->levels->query( array( 'group_id' => $id ) ) as $level ) {
			$level->group_id = $target->id;
			$this->levels->save( $level );
		}
		$this->groups->delete( $id );
	}
}
