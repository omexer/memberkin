<?php
/**
 * Membership level entity.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A level members can hold. Pricing lives in $schedule.
 */
final class Level implements \JsonSerializable {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_HIDDEN   = 'hidden';
	public const STATUS_ARCHIVED = 'archived';

	/**
	 * Constructor.
	 *
	 * @param int                     $id            Id (0 when new).
	 * @param string                  $name          Name.
	 * @param string                  $slug          Slug.
	 * @param string                  $description   Description (HTML).
	 * @param int                     $group_id      Level group id.
	 * @param string                  $status        active|hidden|archived.
	 * @param bool                    $allow_signups New signups allowed.
	 * @param int                     $display_order Sort order.
	 * @param string                  $tax_class     Tax class.
	 * @param PriceSchedule           $schedule      Pricing.
	 * @param \DateTimeImmutable|null $created_at    Created.
	 * @param \DateTimeImmutable|null $updated_at    Updated.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $slug,
		public string $description,
		public int $group_id,
		public string $status,
		public bool $allow_signups,
		public int $display_order,
		public string $tax_class,
		public PriceSchedule $schedule,
		public ?\DateTimeImmutable $created_at = null,
		public ?\DateTimeImmutable $updated_at = null
	) {}

	/**
	 * Statuses with labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_ACTIVE   => __( 'Active', 'memberkin' ),
			self::STATUS_HIDDEN   => __( 'Hidden (direct link only)', 'memberkin' ),
			self::STATUS_ARCHIVED => __( 'Archived', 'memberkin' ),
		);
	}

	/**
	 * Whether a visitor may start a checkout for this level.
	 */
	public function is_purchasable(): bool {
		return $this->allow_signups && self::STATUS_ARCHIVED !== $this->status;
	}

	/**
	 * Whether shown on the public levels page.
	 */
	public function is_listed(): bool {
		return self::STATUS_ACTIVE === $this->status && $this->allow_signups;
	}

	/**
	 * Public JSON (no internal fields).
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'id'            => $this->id,
			'name'          => $this->name,
			'slug'          => $this->slug,
			'description'   => $this->description,
			'group_id'      => $this->group_id,
			'status'        => $this->status,
			'allow_signups' => $this->allow_signups,
			'display_order' => $this->display_order,
			'tax_class'     => $this->tax_class,
			'pricing'       => $this->schedule->jsonSerialize(),
			'price_text'    => $this->schedule->describe(),
		);
	}
}
