<?php
/**
 * [memberkin_levels] pricing table.
 *
 * @package Memberkin
 */

namespace Memberkin\Frontend;

use Memberkin\Core\Templates;
use Memberkin\Domain\Level;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\MembershipService;
use Memberkin\Service\PricingService;

defined( 'ABSPATH' ) || exit;

/**
 * Lists purchasable levels with prices and buttons.
 */
final class LevelsController {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_shortcode( 'memberkin_levels', array( $this, 'render' ) );
		add_shortcode( 'memberkin_level', array( $this, 'render_level' ) );
	}

	/**
	 * [memberkin_level level="gold" text="Join now" show_name="1" show_price="1"]: one level's name, price and a
	 * button to its checkout (or "Your current membership").
	 *
	 * @param array|string $atts Attributes.
	 */
	public function render_level( $atts = array() ): string {
		$atts  = shortcode_atts(
			array(
				'level'      => '',
				'text'       => '',
				'show_name'  => '1',
				'show_price' => '1',
			),
			(array) $atts,
			'memberkin_level'
		);
		$repo  = memberkin()->get( LevelRepository::class );
		$value = trim( (string) $atts['level'] );
		$level = '' === $value ? null : ( is_numeric( $value ) ? $repo->find( (int) $value ) : $repo->find_by_slug( $value ) );
		if ( ! $level || ! $level->is_purchasable() ) {
			return current_user_can( 'edit_posts' ) ? '<p class="memberkin-notice">' . esc_html__( 'Choose a membership level that is open for sign-ups.', 'memberkin' ) . '</p>' : '';
		}
		wp_enqueue_style( 'memberkin-frontend' );
		$user_id = get_current_user_id();
		$current = $user_id && in_array( $level->id, memberkin()->get( MembershipService::class )->active_level_ids( $user_id ), true );
		$text    = '' !== trim( (string) $atts['text'] ) ? (string) $atts['text'] : (string) $repo->meta( $level->id, 'button_text', '' );
		return Templates::render(
			'level',
			array(
				'level'      => $level,
				'price'      => self::price_text( $level ),
				'url'        => memberkin_checkout_url( $level ),
				'button'     => '' !== $text ? $text : __( 'Join now', 'memberkin' ),
				'is_current' => $current,
				'show_name'  => ! in_array( (string) $atts['show_name'], array( '0', 'false', 'no' ), true ),
				'show_price' => ! in_array( (string) $atts['show_price'], array( '0', 'false', 'no' ), true ),
			)
		);
	}

	/**
	 * Price text of a level (custom text or generated).
	 *
	 * @param Level $level Level.
	 */
	public static function price_text( Level $level ): string {
		$custom = (string) memberkin()->get( LevelRepository::class )->meta( $level->id, 'cost_text', '' );
		/**
		 * Filters the price text shown for a level.
		 *
		 * @param string $text  Text.
		 * @param Level  $level Level.
		 */
		return (string) apply_filters( 'memberkin_level_cost_text', '' !== $custom ? $custom : $level->schedule->describe(), $level );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array|string $atts levels="1,2" (ids or slugs), group="3".
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'levels' => '',
				'group'  => '',
			),
			(array) $atts,
			'memberkin_levels'
		);
		if ( '' === (string) $atts['group'] && '' === (string) $atts['levels'] && isset( $_GET['group'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter ("Change plan" link).
			$atts['group'] = (string) absint( $_GET['group'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$repo   = memberkin()->get( LevelRepository::class );
		$levels = array();
		if ( '' !== $atts['levels'] ) {
			foreach ( explode( ',', (string) $atts['levels'] ) as $value ) {
				$value = trim( $value );
				$level = is_numeric( $value ) ? $repo->find( (int) $value ) : $repo->find_by_slug( $value );
				if ( $level && $level->is_purchasable() ) {
					$levels[] = $level;
				}
			}
		} else {
			$filters = array( 'status' => Level::STATUS_ACTIVE );
			if ( '' !== $atts['group'] ) {
				$filters['group_id'] = (int) $atts['group'];
			}
			$levels = array_values( array_filter( $repo->query( $filters ), static fn( Level $l ) => $l->is_listed() ) );
		}

		$user_id = get_current_user_id();
		$current = $user_id ? memberkin()->get( MembershipService::class )->active_level_ids( $user_id ) : array();
		wp_enqueue_style( 'memberkin-frontend' );

		// Changing plan inside a group the member already belongs to: show what switching costs today.
		$switching = $user_id && '' !== (string) $atts['group'] && array_intersect( $current, array_map( static fn( Level $l ) => $l->id, $levels ) );

		$cards = array();
		foreach ( $levels as $level ) {
			$change  = $switching && ! in_array( $level->id, $current, true ) ? self::switch_preview( $level, $user_id ) : null;
			$cards[] = array(
				'change'     => $change,
				'level'      => $level,
				'price'      => self::price_text( $level ),
				'benefits'   => (array) $repo->meta( $level->id, 'benefits', array() ),
				'highlight'  => (bool) $repo->meta( $level->id, 'highlight', false ),
				'button'     => $change ? __( 'Switch to this plan', 'memberkin' ) : (string) $repo->meta( $level->id, 'button_text', '' ),
				'is_current' => in_array( $level->id, $current, true ),
				'url'        => memberkin_checkout_url( $level ),
			);
		}
		return Templates::render(
			'levels',
			array(
				'cards'       => $cards,
				'account_url' => memberkin_page_url( 'account' ),
				'switching'   => (bool) $switching,
			)
		);
	}

	/**
	 * What switching to a level costs the member today (null when the switch is not possible).
	 *
	 * @param Level $level   Target level.
	 * @param int   $user_id Member.
	 * @return array{today: string, note: string}|null
	 */
	public static function switch_preview( Level $level, int $user_id ): ?array {
		try {
			$quote = memberkin()->get( PricingService::class )->quote(
				array(
					'level_ids' => array( $level->id ),
					'user_id'   => $user_id,
				)
			);
		} catch ( \Memberkin\Core\ValidationException $e ) {
			return null;
		}
		$plan = $quote->proration[ $level->id ] ?? null;
		$note = '';
		if ( $plan && \Memberkin\Domain\Pricing\ProrationPlan::AT_PERIOD_END === $plan->mode && $plan->anchor ) {
			/* translators: %s: date. */
			$note = sprintf( __( 'Starts when your current period ends, on %s.', 'memberkin' ), wp_date( (string) get_option( 'date_format' ), $plan->anchor->getTimestamp() ) );
		} elseif ( $plan && $plan->credit->is_positive() ) {
			/* translators: %s: amount. */
			$note = sprintf( __( 'Includes a credit of %s for the unused part of your current plan.', 'memberkin' ), $plan->credit->format() );
		}
		return array(
			'today' => $quote->total()->format(),
			'note'  => $note,
		);
	}
}
