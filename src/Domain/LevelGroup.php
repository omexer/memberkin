<?php
/**
 * Level group entity.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Groups decide whether members may hold several levels at once and how plan changes work.
 */
final class LevelGroup implements \JsonSerializable {

	public const PRORATION_NONE      = 'none';
	public const PRORATION_IMMEDIATE = 'prorate_immediate';
	public const PRORATION_ANCHOR    = 'keep_anchor';

	/**
	 * Constructor.
	 *
	 * @param int    $id                Id.
	 * @param string $name              Name.
	 * @param bool   $allow_multiple    Members may hold several levels of this group.
	 * @param bool   $is_upgrade_path   Levels form an upgrade path (change plan offered between them).
	 * @param int    $fallback_level_id Level granted when a membership in this group ends (0 = none).
	 * @param string $proration_mode    none|prorate_immediate|keep_anchor.
	 * @param string $downgrade_timing  immediate|period_end.
	 * @param int    $display_order     Sort order.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public bool $allow_multiple = false,
		public bool $is_upgrade_path = false,
		public int $fallback_level_id = 0,
		public string $proration_mode = self::PRORATION_IMMEDIATE,
		public string $downgrade_timing = 'period_end',
		public int $display_order = 0
	) {}

	/**
	 * Proration modes with labels.
	 *
	 * @return array<string, string>
	 */
	public static function proration_modes(): array {
		return array(
			self::PRORATION_IMMEDIATE => __( 'Credit unused time and start the new plan now', 'memberkin' ),
			self::PRORATION_ANCHOR    => __( 'Charge the difference now and keep the renewal date', 'memberkin' ),
			self::PRORATION_NONE      => __( 'No proration (new plan starts now at full price)', 'memberkin' ),
		);
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return get_object_vars( $this );
	}
}
