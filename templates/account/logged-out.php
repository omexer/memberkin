<?php
/**
 * Account pages for logged-out visitors.
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var string $login_url Login URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-notice">
	<p><?php esc_html_e( 'Please log in to see your account.', 'memberkin' ); ?></p>
	<p><a class="memberkin-button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'memberkin' ); ?></a></p>
</div>
