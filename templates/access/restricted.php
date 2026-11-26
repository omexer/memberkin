<?php
/**
 * Shown instead of restricted content.
 *
 * Override: yourtheme/memberkin/access/restricted.php
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var \Memberkin\Domain\Access\AccessDecision $decision   Decision.
 * @var string                                       $message    Message (HTML, merge tags replaced).
 * @var string                                       $teaser     Plain-text teaser ('' when disabled).
 * @var \Memberkin\Domain\Level[]               $levels     Listed levels that grant access.
 * @var string                                       $levels_url Levels page URL.
 * @var string                                       $login_url  Login URL returning here.
 * @var bool                                         $show_login Show an inline login form.
 * @var int                                          $post_id    Post id.
 */

use Memberkin\Domain\Access\AccessDecision;

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-restricted" data-reason="<?php echo esc_attr( $decision->reason ); ?>">
	<?php if ( '' !== $teaser ) : ?>
		<p class="memberkin-restricted__teaser"><?php echo esc_html( $teaser ); ?></p>
	<?php endif; ?>
	<div class="memberkin-restricted__message"><?php echo wp_kses_post( wpautop( $message ) ); ?></div>
	<?php if ( AccessDecision::DRIP_PENDING !== $decision->reason && AccessDecision::EXPIRED !== $decision->reason ) : ?>
		<p class="memberkin-restricted__actions">
			<?php if ( 1 === count( $levels ) ) : ?>
				<a class="memberkin-button" href="<?php echo esc_url( memberkin_checkout_url( $levels[0] ) ); ?>">
					<?php
					/* translators: %s: level name. */
					echo esc_html( sprintf( __( 'Join %s', 'memberkin' ), $levels[0]->name ) );
					?>
				</a>
			<?php elseif ( $levels_url ) : ?>
				<a class="memberkin-button" href="<?php echo esc_url( $levels_url ); ?>"><?php esc_html_e( 'See membership options', 'memberkin' ); ?></a>
			<?php endif; ?>
			<?php if ( ! is_user_logged_in() && ! $show_login ) : ?>
				<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'memberkin' ); ?></a>
			<?php endif; ?>
		</p>
		<?php if ( $show_login ) : ?>
			<?php wp_login_form( array( 'redirect' => (string) get_permalink( $post_id ) ) ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
