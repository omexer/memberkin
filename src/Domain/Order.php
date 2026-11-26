<?php
/**
 * Order entity.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A payment record (initial purchase, renewal, upgrade, manual …). Orders never grant access themselves.
 */
final class Order implements \JsonSerializable {

	public const PENDING            = 'pending';
	public const PAID               = 'paid';
	public const FAILED             = 'failed';
	public const CANCELLED          = 'cancelled';
	public const REFUNDED           = 'refunded';
	public const PARTIALLY_REFUNDED = 'partially_refunded';
	public const DISPUTED           = 'disputed';
	public const REVIEW             = 'review';

	public const TRANSITIONS = array(
		self::PENDING            => array( self::PAID, self::FAILED, self::CANCELLED, self::REVIEW ),
		self::REVIEW             => array( self::PAID, self::FAILED, self::CANCELLED ),
		self::FAILED             => array( self::PAID, self::CANCELLED ),
		self::PAID               => array( self::PARTIALLY_REFUNDED, self::REFUNDED, self::DISPUTED ),
		self::PARTIALLY_REFUNDED => array( self::PARTIALLY_REFUNDED, self::REFUNDED, self::DISPUTED ),
		self::DISPUTED           => array( self::PAID, self::REFUNDED, self::PARTIALLY_REFUNDED ),
		self::REFUNDED           => array(),
		self::CANCELLED          => array(),
	);

	/**
	 * Line items.
	 *
	 * @var OrderItem[]
	 */
	public array $items = array();

	/**
	 * Constructor.
	 *
	 * @param int                     $id                     Id.
	 * @param string                  $number                 Public order number.
	 * @param int                     $user_id                User.
	 * @param string                  $type                   initial|renewal|upgrade|manual|gift|import.
	 * @param string                  $status                 Status.
	 * @param string                  $currency               Currency.
	 * @param int                     $subtotal               Subtotal (minor units, before discount and tax).
	 * @param int                     $discount_total         Discounts and credits.
	 * @param int                     $tax_total              Tax.
	 * @param int                     $total                  Total charged.
	 * @param int                     $amount_refunded        Refunded so far.
	 * @param string                  $gateway                Gateway id.
	 * @param string                  $gateway_mode           live|test.
	 * @param int|null                $subscription_id        Subscription.
	 * @param string|null             $invoice_number         Sequential invoice number (set when paid).
	 * @param int|null                $coupon_id              Coupon.
	 * @param string|null             $coupon_code            Coupon code.
	 * @param string|null             $gateway_transaction_id Gateway payment id.
	 * @param string|null             $gateway_customer_id    Gateway customer id.
	 * @param string|null             $payment_method_label   E.g. "Visa •••• 4242".
	 * @param array                   $billing                Billing snapshot (name, company, email, line1, line2, city, region, postcode, country, phone).
	 * @param array                   $tax                    Tax snapshot (country, region, vat_number, vat_number_valid, reverse_charge, prices_include_tax).
	 * @param string|null             $ip_address             Customer IP (tax evidence).
	 * @param int|null                $checkout_session_id    Checkout session.
	 * @param \DateTimeImmutable|null $paid_at                Paid at.
	 * @param \DateTimeImmutable|null $refunded_at            Fully refunded at.
	 * @param \DateTimeImmutable|null $created_at             Created.
	 * @param string                  $notes                  Admin notes.
	 */
	public function __construct(
		public int $id,
		public string $number,
		public int $user_id,
		public string $type,
		public string $status,
		public string $currency,
		public int $subtotal = 0,
		public int $discount_total = 0,
		public int $tax_total = 0,
		public int $total = 0,
		public int $amount_refunded = 0,
		public string $gateway = '',
		public string $gateway_mode = 'live',
		public ?int $subscription_id = null,
		public ?string $invoice_number = null,
		public ?int $coupon_id = null,
		public ?string $coupon_code = null,
		public ?string $gateway_transaction_id = null,
		public ?string $gateway_customer_id = null,
		public ?string $payment_method_label = null,
		public array $billing = array(),
		public array $tax = array(),
		public ?string $ip_address = null,
		public ?int $checkout_session_id = null,
		public ?\DateTimeImmutable $paid_at = null,
		public ?\DateTimeImmutable $refunded_at = null,
		public ?\DateTimeImmutable $created_at = null,
		public string $notes = ''
	) {}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::PENDING            => __( 'Pending', 'memberkin' ),
			self::PAID               => __( 'Paid', 'memberkin' ),
			self::FAILED             => __( 'Failed', 'memberkin' ),
			self::CANCELLED          => __( 'Cancelled', 'memberkin' ),
			self::REFUNDED           => __( 'Refunded', 'memberkin' ),
			self::PARTIALLY_REFUNDED => __( 'Partially refunded', 'memberkin' ),
			self::DISPUTED           => __( 'Disputed', 'memberkin' ),
			self::REVIEW             => __( 'On hold for review', 'memberkin' ),
		);
	}

	/**
	 * Type labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'initial' => __( 'Signup', 'memberkin' ),
			'renewal' => __( 'Renewal', 'memberkin' ),
			'upgrade' => __( 'Plan change', 'memberkin' ),
			'manual'  => __( 'Manual', 'memberkin' ),
			'gift'    => __( 'Gift', 'memberkin' ),
			'import'  => __( 'Imported', 'memberkin' ),
		);
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * Whether money was received (and not fully returned).
	 */
	public function is_paid(): bool {
		return in_array( $this->status, array( self::PAID, self::PARTIALLY_REFUNDED, self::DISPUTED ), true );
	}

	/**
	 * Total.
	 */
	public function total(): Money {
		return Money::of( $this->total, $this->currency );
	}

	/**
	 * Amount that can still be refunded.
	 */
	public function refundable(): Money {
		return Money::of( max( 0, $this->total - $this->amount_refunded ), $this->currency );
	}

	/**
	 * Level ids on the order.
	 *
	 * @return int[]
	 */
	public function level_ids(): array {
		$ids = array();
		foreach ( $this->items as $item ) {
			if ( 'level' === $item->type && $item->level_id ) {
				$ids[] = $item->level_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$data          = get_object_vars( $this );
		$data['items'] = array_map( static fn( OrderItem $i ) => $i->jsonSerialize(), $this->items );
		foreach ( array( 'paid_at', 'refunded_at', 'created_at' ) as $key ) {
			$data[ $key ] = $data[ $key ]?->format( DATE_ATOM );
		}
		unset( $data['ip_address'], $data['notes'] );
		return $data;
	}
}
