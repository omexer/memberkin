<?php
/**
 * Member account dashboard.
 *
 * Override: yourtheme/memberkin/account/dashboard.php
 *
 * @version 1.1.0
 * @package Memberkin
 *
 * @var \WP_User                                           $user         User.
 * @var array|null                                         $notice       Notice.
 * @var \Memberkin\Domain\Membership[]                $memberships  Memberships.
 * @var \Memberkin\Domain\Subscription[]              $subs         Live subscriptions.
 * @var \Memberkin\Domain\Order[]                     $orders       Orders.
 * @var \Memberkin\Repository\LevelRepository         $levels       Levels.
 * @var \Memberkin\Repository\PaymentMethodRepository $methods      Payment methods.
 * @var \Memberkin\Gateway\GatewayRegistry            $gateways     Gateways.
 * @var array<int,string>                                  $change_plan  Membership id => change plan URL.
 * @var bool                                               $allow_cancel Members may cancel.
 * @var int                                                $max_pause    Max pause days.
 * @var string                                             $profile_url  Profile page URL.
 * @var string                                             $levels_url   Levels page URL.
 * @var string                                             $update_form  On-site payment method form HTML.
 * @var int                                                $update_id    Subscription being updated.
 */

use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Period;
use Memberkin\Domain\Subscription;
use Memberkin\Frontend\AccountController;
use Memberkin\Gateway\Contracts\Capability;

defined( 'ABSPATH' ) || exit;

$memberkin_date = static function ( $d ): string {
	return $d instanceof \DateTimeImmutable ? (string) wp_date( get_option( 'date_format' ), $d->getTimestamp() ) : '—';
};
?>
<div class="memberkin memberkin-account">
	<?php if ( $notice ) : ?>
		<div class="memberkin-<?php echo 'error' === $notice['type'] ? 'errors' : 'success'; ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>

	<p class="memberkin-account__hello">
		<?php
		/* translators: %s: display name. */
		printf( esc_html__( 'Hello, %s.', 'memberkin' ), esc_html( $user->display_name ) );
		?>
		<?php if ( $profile_url ) : ?>
			<a href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Edit profile', 'memberkin' ); ?></a> ·
		<?php endif; ?>
		<a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'memberkin' ); ?></a>
	</p>

	<section class="memberkin-section">
		<h2><?php esc_html_e( 'Memberships', 'memberkin' ); ?></h2>
		<?php if ( ! $memberships ) : ?>
			<p><?php esc_html_e( 'You do not have a membership yet.', 'memberkin' ); ?>
			<?php if ( $levels_url ) : ?>
				<a href="<?php echo esc_url( $levels_url ); ?>"><?php esc_html_e( 'See membership levels', 'memberkin' ); ?></a>
			<?php endif; ?></p>
		<?php else : ?>
			<table class="memberkin-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Level', 'memberkin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'memberkin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Since', 'memberkin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Until', 'memberkin' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'memberkin' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $memberships as $memberkin_m ) : ?>
					<?php $memberkin_level = $levels->find( $memberkin_m->level_id ); ?>
					<tr>
						<td><?php echo esc_html( $memberkin_level ? $memberkin_level->name : '' ); ?>
							<?php $memberkin_msg = $memberkin_level ? (string) $levels->meta( $memberkin_level->id, 'account_message', '' ) : ''; ?>
							<?php if ( '' !== $memberkin_msg && $memberkin_m->grants_access() ) : ?>
								<div class="memberkin-muted"><?php echo wp_kses_post( $memberkin_msg ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Membership::statuses()[ $memberkin_m->status ] ?? $memberkin_m->status ); ?></td>
						<td><?php echo esc_html( $memberkin_date( $memberkin_m->starts_at ) ); ?></td>
						<td><?php echo $memberkin_m->ends_at ? esc_html( $memberkin_date( $memberkin_m->ends_at ) ) : esc_html__( 'Ongoing', 'memberkin' ); ?></td>
						<td>
							<?php if ( isset( $change_plan[ $memberkin_m->id ] ) ) : ?>
								<a href="<?php echo esc_url( $change_plan[ $memberkin_m->id ] ); ?>"><?php esc_html_e( 'Change plan', 'memberkin' ); ?></a>
							<?php endif; ?>
							<?php if ( $allow_cancel && ! $memberkin_m->subscription_id && $memberkin_m->grants_access() && Membership::CANCELLING !== $memberkin_m->status ) : ?>
								<form method="post" class="memberkin-inline-form">
									<?php echo AccountController::nonce_field( 'cancel_membership', $memberkin_m->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in nonce_field(). ?>
									<button type="submit" class="memberkin-link-button" onclick="return confirm('<?php echo esc_js( __( 'Cancel this membership?', 'memberkin' ) ); ?>');"><?php esc_html_e( 'Cancel', 'memberkin' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<?php if ( $subs ) : ?>
		<section class="memberkin-section">
			<h2><?php esc_html_e( 'Subscriptions', 'memberkin' ); ?></h2>
			<?php foreach ( $subs as $memberkin_sub ) : ?>
				<?php
				$memberkin_level   = $levels->find( $memberkin_sub->level_id );
				$memberkin_gateway = $gateways->get( $memberkin_sub->gateway );
				$memberkin_method  = $memberkin_sub->payment_method_id ? $methods->find( $memberkin_sub->payment_method_id ) : null;
				?>
				<div class="memberkin-subscription">
					<h3><?php echo esc_html( $memberkin_level ? $memberkin_level->name : '' ); ?></h3>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: amount, 2: frequency. */
								__( '%1$s every %2$s', 'memberkin' ),
								\Memberkin\Core\Money::of( $memberkin_sub->recurring_amount + $memberkin_sub->tax_amount, $memberkin_sub->currency )->format(),
								( $memberkin_sub->billing_interval > 1 ? $memberkin_sub->billing_interval . ' ' : '' ) . Period::label( $memberkin_sub->billing_period, $memberkin_sub->billing_interval )
							)
						);
						?>
						· <?php echo esc_html( Subscription::statuses()[ $memberkin_sub->status ] ?? $memberkin_sub->status ); ?>
					</p>
					<p>
						<?php if ( $memberkin_sub->cancel_at_period_end ) : ?>
							<?php
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Cancelled — your access ends on %s.', 'memberkin' ), $memberkin_date( $memberkin_sub->current_period_end ) ) );
							?>
						<?php elseif ( Subscription::PAUSED === $memberkin_sub->status ) : ?>
							<?php
							/* translators: %s: date. */
							echo $memberkin_sub->resume_at ? esc_html( sprintf( __( 'Paused until %s.', 'memberkin' ), $memberkin_date( $memberkin_sub->resume_at ) ) ) : esc_html__( 'Paused.', 'memberkin' );
							?>
						<?php elseif ( in_array( $memberkin_sub->status, array( Subscription::PAST_DUE, Subscription::UNPAID ), true ) ) : ?>
							<strong><?php esc_html_e( 'Your last payment failed. Please update your payment method.', 'memberkin' ); ?></strong>
						<?php elseif ( $memberkin_sub->next_payment_at ) : ?>
							<?php
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Next payment on %s.', 'memberkin' ), $memberkin_date( $memberkin_sub->next_payment_at ) ) );
							?>
						<?php endif; ?>
						<?php if ( $memberkin_method ) : ?>
							<br /><?php echo esc_html( $memberkin_method->label() ); ?>
						<?php endif; ?>
					</p>
					<div class="memberkin-actions">
						<?php if ( $memberkin_gateway && $memberkin_gateway->supports( Capability::PAYMENT_METHOD_UPDATE ) && Subscription::PAUSED !== $memberkin_sub->status ) : ?>
							<form method="post" class="memberkin-inline-form"><?php echo AccountController::nonce_field( 'update_card', $memberkin_sub->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button type="submit" class="memberkin-button memberkin-button--secondary"><?php esc_html_e( 'Update payment method', 'memberkin' ); ?></button></form>
						<?php endif; ?>
						<?php if ( $memberkin_sub->cancel_at_period_end ) : ?>
							<form method="post" class="memberkin-inline-form"><?php echo AccountController::nonce_field( 'reactivate', $memberkin_sub->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button type="submit" class="memberkin-button"><?php esc_html_e( 'Keep my subscription', 'memberkin' ); ?></button></form>
						<?php elseif ( Subscription::PAUSED === $memberkin_sub->status ) : ?>
							<form method="post" class="memberkin-inline-form"><?php echo AccountController::nonce_field( 'resume', $memberkin_sub->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button type="submit" class="memberkin-button"><?php esc_html_e( 'Resume now', 'memberkin' ); ?></button></form>
						<?php else : ?>
							<?php if ( AccountController::can_pause( $memberkin_sub ) ) : ?>
								<form method="post" class="memberkin-inline-form">
									<?php echo AccountController::nonce_field( 'pause', $memberkin_sub->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<label for="memberkin-pause-<?php echo (int) $memberkin_sub->id; ?>"><?php esc_html_e( 'Pause for', 'memberkin' ); ?></label>
									<select id="memberkin-pause-<?php echo (int) $memberkin_sub->id; ?>" name="days">
										<?php foreach ( array( 7, 14, 30, 60, 90, 180 ) as $memberkin_days ) : ?>
											<?php
											if ( $memberkin_days > $max_pause ) {
												continue;
											}
											?>
											<option value="<?php echo (int) $memberkin_days; ?>">
												<?php
												echo esc_html(
													sprintf(
														/* translators: 1: number of days, 2: date. */
														_n( '%1$d day (until %2$s)', '%1$d days (until %2$s)', $memberkin_days, 'memberkin' ),
														$memberkin_days,
														wp_date( (string) get_option( 'date_format' ), \Memberkin\Core\Clock::now()->modify( '+' . $memberkin_days . ' days' )->getTimestamp() )
													)
												);
												?>
											</option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="memberkin-button memberkin-button--secondary"><?php esc_html_e( 'Pause', 'memberkin' ); ?></button>
									<span class="memberkin-field-description"><?php esc_html_e( 'No payments and no access while paused; billing continues automatically on the date shown, and you can resume earlier.', 'memberkin' ); ?></span>
								</form>
							<?php endif; ?>
							<?php if ( $allow_cancel ) : ?>
								<form method="post" class="memberkin-inline-form"><?php echo AccountController::nonce_field( 'cancel', $memberkin_sub->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><button type="submit" class="memberkin-link-button" onclick="return confirm('<?php echo esc_js( __( 'Cancel your subscription? You keep access until the end of the paid period.', 'memberkin' ) ); ?>');"><?php esc_html_e( 'Cancel subscription', 'memberkin' ); ?></button></form>
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<?php if ( $update_id === $memberkin_sub->id && '' !== $update_form ) : ?>
						<div class="memberkin-update-card"><?php echo $update_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gateway-rendered markup. ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<section class="memberkin-section">
		<h2><?php esc_html_e( 'Payment history', 'memberkin' ); ?></h2>
		<?php if ( ! $orders ) : ?>
			<p><?php esc_html_e( 'No payments yet.', 'memberkin' ); ?></p>
		<?php else : ?>
			<table class="memberkin-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Date', 'memberkin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order', 'memberkin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'memberkin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'memberkin' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Documents', 'memberkin' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $orders as $memberkin_order ) : ?>
					<tr>
						<td><?php echo esc_html( $memberkin_date( $memberkin_order->created_at ) ); ?></td>
						<td><?php echo esc_html( $memberkin_order->number ); ?></td>
						<td><?php echo esc_html( $memberkin_order->total()->format() ); ?></td>
						<td><?php echo esc_html( Order::statuses()[ $memberkin_order->status ] ?? $memberkin_order->status ); ?></td>
						<td>
							<?php
							/**
							 * Prints links next to an order in the payment history (e.g. the PDF invoice).
							 *
							 * @param Order $memberkin_order Order.
							 */
							do_action( 'memberkin_account_order_links', $memberkin_order );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php
	/**
	 * Fires at the end of the account dashboard (modules add sections).
	 *
	 * @param \WP_User $user User.
	 */
	do_action( 'memberkin_account_sections', $user );
	?>
</div>
