<?php
/**
 * Reset password form.
 *
 * @version 1.1.0
 * @package Memberkin
 *
 * @var array|null $notice Notice.
 * @var string     $key    Reset key.
 * @var string     $login  Login.
 * @var bool       $valid  Whether the key is valid.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-login">
	<?php if ( $notice ) : ?>
		<div class="memberkin-<?php echo 'error' === $notice['type'] ? 'errors' : 'success'; ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! $valid ) : ?>
		<p><?php esc_html_e( 'This password reset link is invalid or has expired.', 'memberkin' ); ?> <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Request a new link', 'memberkin' ); ?></a></p>
	<?php else : ?>
		<form method="post" class="memberkin-form">
			<?php wp_nonce_field( 'memberkin_resetpass', '_memberkin_nonce' ); ?>
			<input type="hidden" name="memberkin_login_action" value="resetpass" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>" />
			<p class="memberkin-field">
				<label for="memberkin-pass1"><?php esc_html_e( 'New password', 'memberkin' ); ?></label>
				<input type="password" id="memberkin-pass1" name="pass1" autocomplete="new-password" minlength="8" required data-memberkin-strength />
			</p>
			<p class="memberkin-field">
				<label for="memberkin-pass2"><?php esc_html_e( 'Confirm new password', 'memberkin' ); ?></label>
				<input type="password" id="memberkin-pass2" name="pass2" autocomplete="new-password" minlength="8" required />
			</p>
			<p><button type="submit" class="memberkin-button"><?php esc_html_e( 'Save password', 'memberkin' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
