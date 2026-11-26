<?php
/**
 * One level: name, price and button ([memberkin_level], "Level button" block).
 *
 * Override: yourtheme/memberkin/level.php
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var \Memberkin\Domain\Level $level      Level.
 * @var string                       $price      Price text (HTML allowed by wp_kses_post).
 * @var string                       $url        Checkout URL.
 * @var string                       $button     Button text.
 * @var bool                         $is_current The visitor has this level now.
 * @var bool                         $show_name  Show the name.
 * @var bool                         $show_price Show the price.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-level-card">
	<?php if ( $show_name ) : ?>
		<p class="memberkin-level-card__name"><strong><?php echo esc_html( $level->name ); ?></strong></p>
	<?php endif; ?>
	<?php if ( $show_price ) : ?>
		<p class="memberkin-level-card__price"><?php echo wp_kses_post( $price ); ?></p>
	<?php endif; ?>
	<?php if ( $is_current ) : ?>
		<p class="memberkin-level__current"><?php esc_html_e( 'Your current membership', 'memberkin' ); ?></p>
	<?php else : ?>
		<?php
		$memberkin_label = esc_html( $button ) . ( $show_name ? '' : '<span class="screen-reader-text"> ' . esc_html( $level->name ) . '</span>' );
		echo '<a class="memberkin-button" href="' . esc_url( $url ) . '">' . $memberkin_label . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		?>
	<?php endif; ?>
</div>
