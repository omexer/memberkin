<?php
/**
 * Login form.
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var array|null $notice   Notice (type, text).
 * @var string     $redirect Redirect after login ('' = default member redirect).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-login">
	<?php if ( $notice ) : ?>
		<div class="memberkin-<?php echo 'error' === $notice['type'] ? 'errors' : 'success'; ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<?php
	wp_login_form(
		array(
			'redirect'       => '' !== $redirect ? $redirect : '',
			'label_username' => __( 'Username or email address', 'memberkin' ),
			'remember'       => true,
		)
	);
	?>
	<p class="memberkin-login__links">
		<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Lost your password?', 'memberkin' ); ?></a>
		<?php $memberkin_levels = memberkin_page_url( 'levels' ); ?>
		<?php if ( $memberkin_levels ) : ?>
			· <a href="<?php echo esc_url( $memberkin_levels ); ?>"><?php esc_html_e( 'Become a member', 'memberkin' ); ?></a>
		<?php endif; ?>
	</p>
</div>
