<?php
/**
 * [memberkin_restrict] partial content.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Access;

use Memberkin\Core\Capabilities;
use Memberkin\Domain\Access\AccessDecision;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Shows or hides enclosed content.
 *
 * [memberkin_restrict levels="gold,3" show_to="members" message="…"]…[/memberkin_restrict]
 * - show_to: members (default) | non-members | logged-in | logged-out.
 * - levels: ids or slugs; empty = any level (for members/non-members).
 * - message: shown when hidden; "default" uses the standard restricted box; empty shows nothing.
 */
final class RestrictShortcode {

	/**
	 * Constructor.
	 *
	 * @param ContentGuard $guard Guard (for the default message box).
	 */
	public function __construct( private ContentGuard $guard ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_shortcode( 'memberkin_restrict', array( $this, 'render' ) );
	}

	/**
	 * Whether the current user sees the content.
	 *
	 * @param array $atts Attributes.
	 */
	public function visible( array $atts ): bool {
		$user_id = get_current_user_id();
		$show_to = (string) $atts['show_to'];
		if ( 'logged-in' === $show_to ) {
			return (bool) $user_id;
		}
		if ( 'logged-out' === $show_to ) {
			return ! $user_id;
		}
		if ( $user_id && user_can( $user_id, Capabilities::BYPASS_RESTRICTIONS ) && 'members' === $show_to ) {
			return true;
		}
		$wanted = array();
		$repo   = memberkin()->get( LevelRepository::class );
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['levels'] ) ) ) as $value ) {
			$level = is_numeric( $value ) ? $repo->find( (int) $value ) : $repo->find_by_slug( $value );
			if ( $level ) {
				$wanted[] = $level->id;
			}
		}
		$is_in   = false;
		$members = $user_id ? memberkin()->get( MembershipService::class )->active_for_user( $user_id ) : array();
		foreach ( $members as $m ) {
			/**
			 * Whether a membership satisfies [memberkin_restrict] (add-ons add conditions such as a delay).
			 *
			 * @param bool                         $matches Whether it matches.
			 * @param \Memberkin\Domain\Membership $m       Active membership of a wanted level.
			 * @param array                        $atts    Shortcode attributes.
			 */
			if ( ( ! $wanted || in_array( $m->level_id, $wanted, true ) ) && apply_filters( 'memberkin_restrict_membership_matches', true, $m, $atts ) ) {
				$is_in = true;
				break;
			}
		}
		return 'non-members' === $show_to ? ! $is_in : $is_in;
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array|string $atts    Attributes.
	 * @param string|null  $content Enclosed content.
	 */
	public function render( $atts, $content = null ): string {
		$atts = shortcode_atts(
			array(
				'levels'  => '',
				'show_to' => 'members',
				'message' => '',
			),
			(array) $atts,
			'memberkin_restrict'
		);
		if ( $this->visible( $atts ) ) {
			return do_shortcode( (string) $content );
		}
		if ( 'default' === $atts['message'] && in_array( $atts['show_to'], array( 'members', 'logged-in' ), true ) ) {
			$repo     = memberkin()->get( LevelRepository::class );
			$required = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['levels'] ) ) ) as $value ) {
				$level = is_numeric( $value ) ? $repo->find( (int) $value ) : $repo->find_by_slug( $value );
				if ( $level ) {
					$required[] = $level->id;
				}
			}
			$decision = new AccessDecision( false, is_user_logged_in() ? AccessDecision::NO_ACCESS : AccessDecision::NOT_LOGGED_IN, $required );
			return $this->guard->restricted_html( (int) get_the_ID(), $decision );
		}
		return '' !== $atts['message'] && 'default' !== $atts['message'] ? '<div class="memberkin-restricted memberkin-restricted--inline">' . wp_kses_post( $atts['message'] ) . '</div>' : '';
	}
}
