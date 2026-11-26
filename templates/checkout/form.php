<?php
/**
 * Checkout form.
 *
 * Override: yourtheme/memberkin/checkout/form.php
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var \Memberkin\Domain\Level[]                          $levels          Levels being bought.
 * @var \Memberkin\Domain\Pricing\Quote                    $quote           Price quote.
 * @var array<string,string>                                    $errors          Errors by field.
 * @var array<string,\Memberkin\Gateway\Contracts\Gateway> $gateways        Available gateways.
 * @var \WP_User|null                                           $user            Logged-in user.
 * @var string                                                  $coupon          Coupon code.
 * @var bool                                                    $coupons_enabled Coupons module on.
 * @var array                                                   $values          Previously submitted values.
 * @var string                                                  $token           Checkout session token.
 * @var array                                                   $settings        Checkout settings.
 * @var bool                                                    $need_address    Billing address required.
 * @var string                                                  $client_secret   3-D Secure secret.
 * @var string                                                  $login_url       Login URL.
 * @var bool                                                    $vat_field       Show the VAT number field.
 * @var string                                                  $vat_number      Submitted VAT number.
 * @var string                                                  $options         Own price / automatic renewal / add-ons (HTML, 1.1.0).
 */

use Memberkin\Core\Templates;

defined( 'ABSPATH' ) || exit;

$memberkin_error       = static function ( string $field ) use ( $errors ): string {
	return isset( $errors[ $field ] ) ? '<p class="memberkin-field-error" id="memberkin-error-' . esc_attr( $field ) . '">' . esc_html( $errors[ $field ] ) . '</p>' : '';
};
$memberkin_describedby = static function ( string $field ) use ( $errors ): string {
	return isset( $errors[ $field ] ) ? ' aria-describedby="memberkin-error-' . esc_attr( $field ) . '" aria-invalid="true"' : '';
};
?>
<div class="memberkin memberkin-checkout" data-memberkin-checkout data-levels="<?php echo esc_attr( implode( ',', wp_list_pluck( $levels, 'id' ) ) ); ?>" data-quote-url="<?php echo esc_url( rest_url( 'memberkin/v1/quote' ) ); ?>" data-client-secret="<?php echo esc_attr( $client_secret ); ?>">
	<?php if ( $errors ) : ?>
		<div class="memberkin-errors" role="alert" tabindex="-1">
			<p><strong><?php esc_html_e( 'Please check the following:', 'memberkin' ); ?></strong></p>
			<ul>
				<?php foreach ( $errors as $memberkin_message ) : ?>
					<li><?php echo esc_html( $memberkin_message ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" class="memberkin-form" enctype="multipart/form-data" novalidate>
		<?php wp_nonce_field( 'memberkin_checkout', '_memberkin_nonce' ); ?>
		<input type="hidden" name="memberkin_checkout" value="1" />
		<input type="hidden" name="memberkin_token" value="<?php echo esc_attr( $token ); ?>" />
		<?php foreach ( $levels as $memberkin_level ) : ?>
			<input type="hidden" name="level_ids[]" value="<?php echo (int) $memberkin_level->id; ?>" />
		<?php endforeach; ?>
		<p class="memberkin-hp" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'memberkin' ); ?><input type="text" name="memberkin_website" tabindex="-1" autocomplete="off" /></label></p>

		<section class="memberkin-section memberkin-section--levels">
			<h2><?php echo esc_html( _n( 'Your membership', 'Your memberships', count( $levels ), 'memberkin' ) ); ?></h2>
			<?php foreach ( $levels as $memberkin_level ) : ?>
				<div class="memberkin-level-summary">
					<h3><?php echo esc_html( $memberkin_level->name ); ?></h3>
					<?php if ( '' !== $memberkin_level->description ) : ?>
						<div class="memberkin-level-description"><?php echo wp_kses_post( wpautop( $memberkin_level->description ) ); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<?php echo $options ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by the checkout/options template. ?>
			<div class="memberkin-summary-wrap" aria-live="polite">
				<?php echo Templates::render( 'checkout/summary', array( 'quote' => $quote ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes. ?>
			</div>
			<?php if ( $coupons_enabled ) : ?>
				<p class="memberkin-coupon">
					<label for="memberkin-coupon"><?php esc_html_e( 'Coupon code', 'memberkin' ); ?></label>
					<input type="text" id="memberkin-coupon" name="coupon_code" value="<?php echo esc_attr( $coupon ); ?>" autocomplete="off"<?php echo $memberkin_describedby( 'coupon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<button type="submit" name="memberkin_apply_coupon" value="1" class="memberkin-button memberkin-button--secondary"><?php esc_html_e( 'Apply', 'memberkin' ); ?></button>
					<?php echo $memberkin_error( 'coupon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( ! $user ) : ?>
			<section class="memberkin-section memberkin-section--account">
				<h2><?php esc_html_e( 'Account', 'memberkin' ); ?></h2>
				<p class="memberkin-muted">
					<?php
					/* translators: %s: login link. */
					printf( esc_html__( 'Already have an account? %s', 'memberkin' ), '<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'memberkin' ) . '</a>' );
					?>
				</p>
									<div class="memberkin-row">
						<p class="memberkin-field">
							<label for="memberkin-first-name"><?php esc_html_e( 'First name', 'memberkin' ); ?><?php echo ! empty( $settings['require_name'] ) ? ' <span class="memberkin-required" aria-hidden="true">*</span>' : ''; ?></label>
							<input type="text" id="memberkin-first-name" name="account[first_name]" autocomplete="given-name" value="<?php echo esc_attr( $values['account']['first_name'] ?? '' ); ?>"<?php echo ! empty( $settings['require_name'] ) ? ' required' : ''; ?><?php echo $memberkin_describedby( 'first_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php echo $memberkin_error( 'first_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</p>
						<p class="memberkin-field">
							<label for="memberkin-last-name"><?php esc_html_e( 'Last name', 'memberkin' ); ?><?php echo ! empty( $settings['require_name'] ) ? ' <span class="memberkin-required" aria-hidden="true">*</span>' : ''; ?></label>
							<input type="text" id="memberkin-last-name" name="account[last_name]" autocomplete="family-name" value="<?php echo esc_attr( $values['account']['last_name'] ?? '' ); ?>"<?php echo ! empty( $settings['require_name'] ) ? ' required' : ''; ?><?php echo $memberkin_describedby( 'last_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<?php echo $memberkin_error( 'last_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</p>
					</div>
				<p class="memberkin-field">
					<label for="memberkin-email"><?php esc_html_e( 'Email address', 'memberkin' ); ?> <span class="memberkin-required" aria-hidden="true">*</span></label>
					<input type="email" id="memberkin-email" name="account[email]" autocomplete="email" required value="<?php echo esc_attr( $values['account']['email'] ?? '' ); ?>"<?php echo $memberkin_describedby( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<?php echo $memberkin_error( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<?php if ( empty( $settings['username_is_email'] ) ) : ?>
					<p class="memberkin-field">
						<label for="memberkin-username"><?php esc_html_e( 'Username', 'memberkin' ); ?> <span class="memberkin-required" aria-hidden="true">*</span></label>
						<input type="text" id="memberkin-username" name="account[username]" autocomplete="username" required value="<?php echo esc_attr( $values['account']['username'] ?? '' ); ?>"<?php echo $memberkin_describedby( 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<?php echo $memberkin_error( 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
				<?php endif; ?>
				<p class="memberkin-field">
					<label for="memberkin-password"><?php esc_html_e( 'Password', 'memberkin' ); ?> <span class="memberkin-required" aria-hidden="true">*</span></label>
					<input type="password" id="memberkin-password" name="account[password]" autocomplete="new-password" required minlength="8" data-memberkin-strength<?php echo $memberkin_describedby( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
					<?php echo $memberkin_error( 'password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
			</section>
		<?php endif; ?>

		<?php
		/**
		 * Fires after the account section (custom fields add inputs here).
		 *
		 * @param \Memberkin\Domain\Pricing\Quote $quote  Quote.
		 * @param array                                $errors Errors.
		 */
		do_action( 'memberkin_checkout_after_account', $quote, $errors );
		?>

		<?php if ( $need_address ) : ?>
			<section class="memberkin-section memberkin-section--billing">
				<h2><?php esc_html_e( 'Billing address', 'memberkin' ); ?></h2>
				<?php
				$memberkin_billing_fields = array(
					'name'     => array( __( 'Full name', 'memberkin' ), 'name', true ),
					'company'  => array( __( 'Company (optional)', 'memberkin' ), 'organization', false ),
					'line1'    => array( __( 'Street address', 'memberkin' ), 'address-line1', true ),
					'line2'    => array( __( 'Apartment, suite, etc. (optional)', 'memberkin' ), 'address-line2', false ),
					'city'     => array( __( 'City', 'memberkin' ), 'address-level2', true ),
					'region'   => array( __( 'State / region', 'memberkin' ), 'address-level1', false ),
					'postcode' => array( __( 'Postcode', 'memberkin' ), 'postal-code', true ),
				);
				foreach ( $memberkin_billing_fields as $memberkin_key => $memberkin_field ) :
					?>
					<p class="memberkin-field">
						<label for="memberkin-billing-<?php echo esc_attr( $memberkin_key ); ?>"><?php echo esc_html( $memberkin_field[0] ); ?><?php echo $memberkin_field[2] ? ' <span class="memberkin-required" aria-hidden="true">*</span>' : ''; ?></label>
						<input type="text" id="memberkin-billing-<?php echo esc_attr( $memberkin_key ); ?>" name="billing[<?php echo esc_attr( $memberkin_key ); ?>]" autocomplete="<?php echo esc_attr( $memberkin_field[1] ); ?>" value="<?php echo esc_attr( $values['billing'][ $memberkin_key ] ?? '' ); ?>"<?php echo $memberkin_field[2] ? ' required' : ''; ?><?php echo $memberkin_describedby( 'billing_' . $memberkin_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<?php echo $memberkin_error( 'billing_' . $memberkin_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
				<?php endforeach; ?>
				<p class="memberkin-field">
					<label for="memberkin-billing-country"><?php esc_html_e( 'Country', 'memberkin' ); ?> <span class="memberkin-required" aria-hidden="true">*</span></label>
					<select id="memberkin-billing-country" name="billing[country]" autocomplete="country" required data-memberkin-requote<?php echo $memberkin_describedby( 'billing_country' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( '— Select —', 'memberkin' ); ?></option>
						<?php foreach ( \Memberkin\Core\Countries::all() as $memberkin_code => $memberkin_name ) : ?>
							<option value="<?php echo esc_attr( $memberkin_code ); ?>" <?php selected( $values['billing']['country'] ?? '', $memberkin_code ); ?>><?php echo esc_html( $memberkin_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php echo $memberkin_error( 'billing_country' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<?php if ( ! empty( $vat_field ) ) : ?>
					<p class="memberkin-field">
						<label for="memberkin-vat-number"><?php esc_html_e( 'VAT number (businesses, optional)', 'memberkin' ); ?></label>
						<input type="text" id="memberkin-vat-number" name="vat_number" autocomplete="off" value="<?php echo esc_attr( $vat_number ); ?>" placeholder="DE123456789" data-memberkin-requote />
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $quote->requires_payment() ) : ?>
			<section class="memberkin-section memberkin-section--payment">
				<h2><?php esc_html_e( 'Payment', 'memberkin' ); ?></h2>
				<?php echo $memberkin_error( 'gateway' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $memberkin_error( 'payment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( ! $gateways ) : ?>
					<p class="memberkin-notice"><?php esc_html_e( 'No payment method is available for this purchase. Please contact us.', 'memberkin' ); ?></p>
				<?php else : ?>
					<?php $memberkin_gateway_errors = trim( ( isset( $errors['gateway'] ) ? 'memberkin-error-gateway ' : '' ) . ( isset( $errors['payment'] ) ? 'memberkin-error-payment' : '' ) ); ?>
					<fieldset class="memberkin-gateways"<?php echo '' !== $memberkin_gateway_errors ? ' aria-describedby="' . esc_attr( $memberkin_gateway_errors ) . '"' : ''; ?>>
						<legend class="screen-reader-text"><?php esc_html_e( 'Payment method', 'memberkin' ); ?></legend>
						<?php $memberkin_selected = isset( $gateways[ $values['gateway'] ] ) ? $values['gateway'] : (string) array_key_first( $gateways ); ?>
						<?php foreach ( $gateways as $memberkin_id => $memberkin_gateway ) : ?>
							<div class="memberkin-gateway">
								<label><input type="radio" name="gateway" value="<?php echo esc_attr( $memberkin_id ); ?>" <?php checked( $memberkin_selected, $memberkin_id ); ?> /> <?php echo esc_html( $memberkin_gateway->checkout_label() ); ?></label>
								<div class="memberkin-gateway__fields" data-gateway="<?php echo esc_attr( $memberkin_id ); ?>">
									<?php echo $memberkin_gateway->render_payment_fields( $quote ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gateways escape their own markup. ?>
								</div>
							</div>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $settings['terms_page'] ) || ! empty( $settings['privacy_consent'] ) ) : ?>
			<section class="memberkin-section memberkin-section--consent">
				<?php if ( ! empty( $settings['terms_page'] ) ) : ?>
					<p class="memberkin-field memberkin-field--checkbox">
						<label><input type="checkbox" name="consent[terms]" value="1" required<?php echo $memberkin_describedby( 'terms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<?php
						/* translators: %s: link to the terms page. */
						printf( esc_html__( 'I agree to the %s.', 'memberkin' ), '<a href="' . esc_url( (string) get_permalink( (int) $settings['terms_page'] ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( (int) $settings['terms_page'] ) ) . '</a>' );
						?>
						</label>
						<?php echo $memberkin_error( 'terms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $settings['privacy_consent'] ) ) : ?>
					<p class="memberkin-field memberkin-field--checkbox">
						<label><input type="checkbox" name="consent[privacy]" value="1" required<?php echo $memberkin_describedby( 'privacy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
						<?php
						$memberkin_privacy = get_privacy_policy_url();
						if ( $memberkin_privacy ) {
							/* translators: %s: link to the privacy policy. */
							printf( esc_html__( 'I have read the %s.', 'memberkin' ), '<a href="' . esc_url( $memberkin_privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'memberkin' ) . '</a>' );
						} else {
							esc_html_e( 'I agree that my data is processed to provide my membership.', 'memberkin' );
						}
						?>
						</label>
						<?php echo $memberkin_error( 'privacy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php
		/**
		 * Fires before the submit button of the plugin's forms (the Security module adds its check here).
		 *
		 * @param string $form checkout|lostpassword|signup.
		 */
		do_action( 'memberkin_form_fields', 'checkout' );
		?>
		<p class="memberkin-submit">
			<button type="submit" class="memberkin-button" data-memberkin-submit>
				<?php echo $quote->total()->is_positive() ? esc_html( sprintf( /* translators: %s: amount. */ __( 'Pay %s', 'memberkin' ), $quote->total()->format() ) ) : esc_html__( 'Complete signup', 'memberkin' ); ?>
			</button>
		</p>
	</form>
</div>
