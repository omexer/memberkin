<?php
/**
 * Checkout not possible (e.g. level closed, already a member).
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var array $errors Messages.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-errors" role="alert">
	<?php foreach ( $errors as $memberkin_message ) : ?>
		<p><?php echo esc_html( $memberkin_message ); ?></p>
	<?php endforeach; ?>
	<?php $memberkin_account = memberkin_page_url( 'account' ); ?>
	<?php if ( is_user_logged_in() && $memberkin_account ) : ?>
		<p><a href="<?php echo esc_url( $memberkin_account ); ?>"><?php esc_html_e( 'Go to your account', 'memberkin' ); ?></a></p>
	<?php endif; ?>
</div>
