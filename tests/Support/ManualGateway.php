<?php
/**
 * Manual payments (check, bank transfer, cash).
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Support;

use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\OperationResult;
use Memberkin\Gateway\Contracts\PaymentContext;
use Memberkin\Gateway\Contracts\PaymentResult;

defined( 'ABSPATH' ) || exit;

/**
 * Orders stay pending until an admin marks them paid. Renewals issue invoices to pay manually.
 */
final class ManualGateway extends AbstractGateway {

	/**
	 * Capabilities.
	 *
	 * @var string[]
	 */
	protected array $capabilities = array(
		Capability::ONE_TIME,
		Capability::RECURRING_SITE,
		Capability::MANUAL_CONFIRMATION,
		Capability::REFUND_FULL,
		Capability::REFUND_PARTIAL,
		Capability::CANCEL,
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'manual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Manual payment (test)', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mode(): string {
		return 'live';
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		$fields   = array_values( array_filter( $this->common_fields(), static fn( $f ) => 'mode' !== $f['key'] ) );
		$fields[] = array(
			'tab'         => 'payments',
			'section'     => $this->section(),
			'key'         => 'instructions',
			'label'       => __( 'Payment instructions', 'memberkin' ),
			'type'        => 'html',
			'description' => __( 'Shown at checkout, on the confirmation page and in emails. Include bank details or where to send a check. Merge tag: {order_number}.', 'memberkin' ),
		);
		$fields[] = array(
			'tab'         => 'payments',
			'section'     => $this->section(),
			'key'         => 'grant_access',
			'label'       => __( 'Give access before payment arrives', 'memberkin' ),
			'type'        => 'checkbox',
			'description' => __( 'When off, the membership stays pending until you mark the order as paid.', 'memberkin' ),
		);
		return $fields;
	}

	/**
	 * Whether members get access while the order is unpaid.
	 */
	public function grants_access_before_payment(): bool {
		return (bool) $this->setting( 'grant_access', false );
	}

	/**
	 * Instructions with merge tags replaced.
	 *
	 * @param Order|null $order Order.
	 */
	public function instructions( ?Order $order = null ): string {
		$text = (string) $this->setting( 'instructions', '' );
		return str_replace( '{order_number}', $order ? $order->number : '', $text );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Quote $quote Quote.
	 */
	public function render_payment_fields( Quote $quote ): string {
		$html = parent::render_payment_fields( $quote );
		$text = $this->instructions();
		return $html . ( '' !== $text ? '<div class="memberkin-manual-instructions">' . wp_kses_post( wpautop( $text ) ) . '</div>' : '' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 */
	public function start_payment( PaymentContext $context ): PaymentResult {
		if ( 0 === $context->order->total ) {
			return new PaymentResult( PaymentResult::COMPLETED );
		}
		return new PaymentResult( PaymentResult::PENDING, '', null, null, null, null, null, null, null, __( 'Manual payment', 'memberkin' ) );
	}

	/**
	 * Refunds are recorded only — return the money yourself.
	 *
	 * @param Order  $order  Order.
	 * @param Money  $amount Amount.
	 * @param string $reason Reason.
	 */
	public function refund( Order $order, Money $amount, string $reason ): OperationResult {
		return OperationResult::ok( null, array( 'manual' => true ) );
	}

	/**
	 * Nothing to cancel remotely.
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end At period end.
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end ): OperationResult {
		return OperationResult::ok();
	}
}
