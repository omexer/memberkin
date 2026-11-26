<?php
/**
 * Profile edit form.
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var \WP_User   $user        User.
 * @var array|null $notice      Notice.
 * @var string     $account_url Account URL.
 */

use Memberkin\Frontend\AccountController;

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-profile">
	<?php if ( $notice ) : ?>
		<div class="memberkin-<?php echo 'error' === $notice['type'] ? 'errors' : 'success'; ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<form method="post" class="memberkin-form" enctype="multipart/form-data">
		<?php echo AccountController::nonce_field( 'profile', 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="memberkin-row">
			<p class="memberkin-field"><label for="memberkin-p-first"><?php esc_html_e( 'First name', 'memberkin' ); ?></label><input type="text" id="memberkin-p-first" name="first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" /></p>
			<p class="memberkin-field"><label for="memberkin-p-last"><?php esc_html_e( 'Last name', 'memberkin' ); ?></label><input type="text" id="memberkin-p-last" name="last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" /></p>
		</div>
		<p class="memberkin-field"><label for="memberkin-p-display"><?php esc_html_e( 'Display name', 'memberkin' ); ?></label><input type="text" id="memberkin-p-display" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" /></p>
		<p class="memberkin-field"><label for="memberkin-p-email"><?php esc_html_e( 'Email address', 'memberkin' ); ?></label><input type="email" id="memberkin-p-email" name="email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" /></p>
		<fieldset class="memberkin-fieldset">
			<legend><?php esc_html_e( 'Change password', 'memberkin' ); ?></legend>
			<p class="memberkin-field"><label for="memberkin-p-pass1"><?php esc_html_e( 'New password', 'memberkin' ); ?></label><input type="password" id="memberkin-p-pass1" name="pass1" autocomplete="new-password" minlength="8" data-memberkin-strength /></p>
			<p class="memberkin-field"><label for="memberkin-p-pass2"><?php esc_html_e( 'Confirm new password', 'memberkin' ); ?></label><input type="password" id="memberkin-p-pass2" name="pass2" autocomplete="new-password" minlength="8" /></p>
		</fieldset>
		<p class="memberkin-field"><label for="memberkin-p-current"><?php esc_html_e( 'Current password (needed to change email or password)', 'memberkin' ); ?></label><input type="password" id="memberkin-p-current" name="current_password" autocomplete="current-password" /></p>
		<?php
		/**
		 * Fires inside the profile form (custom fields add inputs here).
		 *
		 * @param \WP_User $user User.
		 */
		do_action( 'memberkin_profile_form', $user );
		?>
		<p><button type="submit" class="memberkin-button"><?php esc_html_e( 'Save profile', 'memberkin' ); ?></button>
		<?php if ( $account_url ) : ?>
			<a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Back to your account', 'memberkin' ); ?></a>
		<?php endif; ?></p>
	</form>
</div>
