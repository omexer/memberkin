<?php
/**
 * Levels / pricing table.
 *
 * Override: yourtheme/memberkin/levels.php
 *
 * @version 1.1.0
 * @package Memberkin
 *
 * @var array  $cards       Each: level, price, benefits, highlight, button, is_current, url, change (1.1.0: null or
 *                          array today, note — what switching to this plan costs the member today).
 * @var string $account_url Account page URL.
 * @var bool   $switching   The member is changing plan within this group (1.1.0).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-levels">
	<?php if ( ! empty( $switching ) ) : ?>
		<p class="memberkin-levels__intro"><?php esc_html_e( 'Choose your new plan. The price shown for today already takes your current plan into account.', 'memberkin' ); ?></p>
	<?php endif; ?>
	<?php if ( ! $cards ) : ?>
		<p><?php esc_html_e( 'No membership levels are available right now.', 'memberkin' ); ?></p>
	<?php endif; ?>
	<ul class="memberkin-levels__list">
		<?php foreach ( $cards as $memberkin_card ) : ?>
			<li class="memberkin-level<?php echo $memberkin_card['highlight'] ? ' memberkin-level--highlight' : ''; ?><?php echo $memberkin_card['is_current'] ? ' memberkin-level--current' : ''; ?>">
				<h3 class="memberkin-level__name"><?php echo esc_html( $memberkin_card['level']->name ); ?></h3>
				<p class="memberkin-level__price"><?php echo wp_kses_post( $memberkin_card['price'] ); ?></p>
				<?php if ( '' !== $memberkin_card['level']->description ) : ?>
					<div class="memberkin-level__description"><?php echo wp_kses_post( wpautop( $memberkin_card['level']->description ) ); ?></div>
				<?php endif; ?>
				<?php if ( $memberkin_card['benefits'] ) : ?>
					<ul class="memberkin-level__benefits">
						<?php foreach ( $memberkin_card['benefits'] as $memberkin_benefit ) : ?>
							<li><?php echo esc_html( $memberkin_benefit ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $memberkin_card['is_current'] ) : ?>
					<p class="memberkin-level__current"><?php esc_html_e( 'Your current membership', 'memberkin' ); ?></p>
					<?php if ( $account_url ) : ?>
						<a class="memberkin-button memberkin-button--secondary" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Your account', 'memberkin' ); ?></a>
					<?php endif; ?>
				<?php else : ?>
					<?php if ( ! empty( $memberkin_card['change'] ) ) : ?>
						<p class="memberkin-level__switch">
							<?php
							/* translators: %s: amount. */
							echo esc_html( sprintf( __( 'Today: %s', 'memberkin' ), $memberkin_card['change']['today'] ) );
							?>
							<?php if ( '' !== $memberkin_card['change']['note'] ) : ?>
								<br /><span class="memberkin-muted"><?php echo esc_html( $memberkin_card['change']['note'] ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<a class="memberkin-button" href="<?php echo esc_url( $memberkin_card['url'] ); ?>"><?php echo esc_html( '' !== $memberkin_card['button'] ? $memberkin_card['button'] : __( 'Select', 'memberkin' ) ); ?><span class="screen-reader-text"> <?php echo esc_html( $memberkin_card['level']->name ); ?></span></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
