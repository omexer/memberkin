<?php
/**
 * Checkout without a level.
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var string $levels_url Levels page URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-notice">
	<p><?php esc_html_e( 'Please choose a membership level first.', 'memberkin' ); ?></p>
	<?php if ( $levels_url ) : ?>
		<p><a class="memberkin-button" href="<?php echo esc_url( $levels_url ); ?>"><?php esc_html_e( 'See membership levels', 'memberkin' ); ?></a></p>
	<?php endif; ?>
</div>
