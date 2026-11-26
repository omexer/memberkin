<?php
/**
 * Lost password form.
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var array|null $notice Notice.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-login">
	<?php if ( $notice ) : ?>
		<div class="memberkin-<?php echo 'error' === $notice['type'] ? 'errors' : 'success'; ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<form method="post" class="memberkin-form">
		<?php wp_nonce_field( 'memberkin_lostpassword', '_memberkin_nonce' ); ?>
		<input type="hidden" name="memberkin_login_action" value="lostpassword" />
		<p><?php esc_html_e( 'Enter your username or email address and we will send you a link to create a new password.', 'memberkin' ); ?></p>
		<p class="memberkin-field">
			<label for="memberkin-user-login"><?php esc_html_e( 'Username or email address', 'memberkin' ); ?></label>
			<input type="text" id="memberkin-user-login" name="user_login" autocomplete="username" required />
		</p>
		<?php
		/** This action is documented in templates/checkout/form.php */
		do_action( 'memberkin_form_fields', 'lostpassword' );
		?>
		<p><button type="submit" class="memberkin-button"><?php esc_html_e( 'Send reset link', 'memberkin' ); ?></button></p>
	</form>
</div>
