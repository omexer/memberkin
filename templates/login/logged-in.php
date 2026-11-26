<?php
/**
 * Login shortcode while logged in.
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var \WP_User $user        User.
 * @var string   $account_url Account URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-login">
	<p>
		<?php
		/* translators: %s: display name. */
		printf( esc_html__( 'You are logged in as %s.', 'memberkin' ), '<strong>' . esc_html( $user->display_name ) . '</strong>' );
		?>
	</p>
	<p>
		<?php if ( $account_url ) : ?>
			<a class="memberkin-button" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Your account', 'memberkin' ); ?></a>
		<?php endif; ?>
		<a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'memberkin' ); ?></a>
	</p>
</div>
