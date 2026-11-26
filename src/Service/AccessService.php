<?php
/**
 * Access decisions (ARCHITECTURE.md §9).
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Clock;
use Memberkin\Core\Settings;
use Memberkin\Domain\Access\AccessDecision;
use Memberkin\Domain\Access\Restriction;
use Memberkin\Domain\Membership;
use Memberkin\Repository\AccessRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a user may see a post or URL.
 *
 * Restrictions on a post:
 * - "Direct": levels assigned to the post itself or to any of its terms (one restriction; any listed level grants access;
 *   an optional per-level delay in days after the membership started).
 * - Extra restrictions added by add-ons through the "memberkin_access_restrictions" filter (Restriction objects).
 * Several restrictions are combined with AND (default) or OR (Settings → Content access).
 */
class AccessService {

	/**
	 * Request cache: "user:post" => decision.
	 *
	 * @var array<string, AccessDecision>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param AccessRepository  $repo        Assignments and rules.
	 * @param MembershipService $memberships Memberships.
	 */
	public function __construct(
		private AccessRepository $repo,
		private MembershipService $memberships
	) {
		add_action( 'memberkin_user_memberships_flushed', array( $this, 'flush' ) );
		add_action( 'memberkin_access_changed', array( $this, 'flush' ) );
	}

	/**
	 * Post types that can be restricted.
	 *
	 * @return string[]
	 */
	public static function restrictable_post_types(): array {
		$types = array_values( array_intersect( array( 'post', 'page' ), get_post_types( array( 'public' => true ) ) ) );
		/**
		 * Filters restrictable post types.
		 *
		 * @param string[] $types Post types.
		 */
		return (array) apply_filters( 'memberkin_restrictable_post_types', $types );
	}

	/**
	 * Taxonomies whose terms can be restricted.
	 *
	 * @return string[]
	 */
	public static function restrictable_taxonomies(): array {
		$taxonomies = array_values( array_intersect( array( 'category', 'post_tag' ), get_taxonomies( array( 'public' => true ) ) ) );
		/**
		 * Filters restrictable taxonomies.
		 *
		 * @param string[] $taxonomies Taxonomies.
		 */
		return (array) apply_filters( 'memberkin_restrictable_taxonomies', $taxonomies );
	}

	/**
	 * Ids of the plugin's own pages (never restricted by broad rules).
	 *
	 * @return int[]
	 */
	public static function member_page_ids(): array {
		return array_values( array_filter( array_map( 'intval', Settings::section( 'pages' ) ) ) );
	}

	/**
	 * Clears the request cache.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * Decision for a post.
	 *
	 * @param int      $post_id Post.
	 * @param int|null $user_id User (current user when null).
	 */
	public function decide_post( int $post_id, ?int $user_id = null ): AccessDecision {
		$user_id = $user_id ?? get_current_user_id();
		$key     = $user_id . ':' . $post_id;
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new AccessDecision( true, AccessDecision::NO_RULES );
		}
		// Attachments with their own levels (protected files) use them; others follow their parent.
		if ( 'attachment' === $post->post_type ) {
			$own = $this->repo->levels_for( 'post', $post->ID );
			if ( $own ) {
				// phpcs:disable Squiz.PHP.DisallowMultipleAssignments.Found
				return $this->cache[ $key ] = $this->evaluate( $own, array(), $user_id, $post ); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			}
			if ( $post->post_parent ) {
				return $this->cache[ $key ] = $this->decide_post( (int) $post->post_parent, $user_id );
				// phpcs:enable
			}
		}
		if ( ! in_array( $post->post_type, self::restrictable_post_types(), true ) || ! $this->repo->has_any() ) {
			return $this->cache[ $key ] = new AccessDecision( true, AccessDecision::NO_RULES ); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		}

		$direct              = $this->direct_levels( $post );
		$extra               = $this->restrictions_for_post( $post );
		$this->cache[ $key ] = $this->evaluate( $direct, $extra, $user_id, $post ); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		return $this->cache[ $key ];
	}

	/**
	 * Decision for a URL path (restrictions added by add-ons through "memberkin_access_path_restrictions").
	 *
	 * @param string   $path    Path with optional query.
	 * @param int|null $user_id User.
	 */
	public function decide_uri( string $path, ?int $user_id = null ): AccessDecision {
		$user_id = $user_id ?? get_current_user_id();
		/**
		 * Filters the restrictions that apply to a URL path (for URLs that are not a single post).
		 *
		 * @param Restriction[] $restrictions Restrictions.
		 * @param string        $path         Path with optional query.
		 */
		$extra = self::only_restrictions( apply_filters( 'memberkin_access_path_restrictions', array(), $path ) );
		return $this->evaluate( array(), $extra, $user_id, null );
	}

	/**
	 * Keeps Restriction objects only.
	 *
	 * @param mixed $value Filtered value.
	 * @return Restriction[]
	 */
	private static function only_restrictions( mixed $value ): array {
		return array_values( array_filter( (array) $value, static fn( $r ) => $r instanceof Restriction ) );
	}

	/**
	 * Levels assigned directly to the post or its terms: level_id => delay days (min).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, int|null>
	 */
	private function direct_levels( \WP_Post $post ): array {
		$levels = $this->repo->levels_for( 'post', $post->ID );
		foreach ( self::restrictable_taxonomies() as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				foreach ( $this->repo->levels_for( 'term', (int) $term->term_id ) as $level_id => $delay ) {
					if ( ! array_key_exists( $level_id, $levels ) ) {
						$levels[ $level_id ] = $delay;
					} elseif ( null === $delay || ( null !== $levels[ $level_id ] && $delay < $levels[ $level_id ] ) ) {
						$levels[ $level_id ] = $delay;
					}
				}
			}
		}
		return $levels;
	}

	/**
	 * Extra restrictions on a post, added by add-ons.
	 *
	 * @param \WP_Post $post Post.
	 * @return Restriction[]
	 */
	private function restrictions_for_post( \WP_Post $post ): array {
		/**
		 * Filters the extra restrictions that apply to a post (in addition to the levels assigned to it and its terms).
		 *
		 * @param Restriction[] $restrictions Restrictions.
		 * @param \WP_Post      $post         Post.
		 */
		return self::only_restrictions( apply_filters( 'memberkin_access_restrictions', array(), $post ) );
	}

	/**
	 * Evaluates restrictions for a user.
	 *
	 * @param array<int,int|null> $direct  Direct levels.
	 * @param Restriction[]       $rules   Extra restrictions.
	 * @param int                 $user_id User.
	 * @param \WP_Post|null       $post    Post (for filters).
	 */
	private function evaluate( array $direct, array $rules, int $user_id, ?\WP_Post $post ): AccessDecision {
		if ( ! $direct && ! $rules ) {
			return new AccessDecision( true, AccessDecision::NO_RULES );
		}
		$required = array_keys( $direct );
		foreach ( $rules as $rule ) {
			$required = array_merge( $required, $rule->level_ids() );
		}
		$required = array_values( array_unique( array_map( 'intval', $required ) ) );

		/**
		 * Whether a user with the "View all restricted content" capability bypasses this restriction.
		 *
		 * @param bool          $bypass  Bypass (default true).
		 * @param int           $user_id User.
		 * @param \WP_Post|null $post    Post being checked (null for URL rules).
		 */
		$bypass = $user_id && user_can( $user_id, Capabilities::BYPASS_RESTRICTIONS ) && apply_filters( 'memberkin_bypass_restrictions', true, $user_id, $post );
		if ( $bypass ) {
			$decision = new AccessDecision( true, AccessDecision::BYPASS, $required );
		} else {
			$memberships = $user_id ? $this->memberships->active_for_user( $user_id ) : array();
			$results     = array();
			if ( $direct ) {
				$results[] = $this->check_levels( $direct, $memberships );
			}
			foreach ( $rules as $rule ) {
				$result    = array_values( $rule->check( $user_id, $memberships ) );
				$results[] = array( (bool) ( $result[0] ?? false ), (string) ( $result[1] ?? AccessDecision::NO_ACCESS ), $result[2] ?? null, $rule );
			}
			$match_all = (bool) Settings::get( 'access', 'match_all_rules', true ) && '0' !== Settings::get( 'access', 'match_all_rules', true );
			$granted   = $match_all ? ! in_array( false, array_column( $results, 0 ), true ) : in_array( true, array_column( $results, 0 ), true );

			if ( $granted ) {
				$decision = new AccessDecision( true, AccessDecision::MEMBER, $required );
			} else {
				$failed = null;
				foreach ( $results as $index => $result ) {
					if ( ! $result[0] ) {
						$failed = $result;
						break;
					}
				}
				$reason = $failed[1] ?? AccessDecision::NO_ACCESS;
				if ( ! $user_id && AccessDecision::NO_ACCESS === $reason ) {
					$reason = AccessDecision::NOT_LOGGED_IN;
				}
				$decision = new AccessDecision( false, $reason, $required, $failed[2] ?? null, $failed[3] ?? null );
			}
		}
		/**
		 * Filters an access decision.
		 *
		 * @param AccessDecision $decision Decision.
		 * @param int            $user_id  User.
		 * @param \WP_Post|null  $post     Post (null for URL checks).
		 */
		return apply_filters( 'memberkin_access_decision', $decision, $user_id, $post );
	}

	/**
	 * Direct level restriction.
	 *
	 * @param array<int,int|null> $levels      level_id => delay days.
	 * @param Membership[]        $memberships Active memberships.
	 * @return array{0: bool, 1: string, 2: ?\DateTimeImmutable, 3: null}
	 */
	private function check_levels( array $levels, array $memberships ): array {
		$now      = Clock::now();
		$earliest = null;
		foreach ( $memberships as $m ) {
			if ( ! array_key_exists( $m->level_id, $levels ) ) {
				continue;
			}
			$delay = $levels[ $m->level_id ];
			if ( null === $delay || 0 === $delay ) {
				return array( true, AccessDecision::MEMBER, null, null );
			}
			$available = $m->starts_at->modify( '+' . $delay . ' days' );
			if ( $available <= $now ) {
				return array( true, AccessDecision::MEMBER, null, null );
			}
			$earliest = null === $earliest || $available < $earliest ? $available : $earliest;
		}
		return $earliest ? array( false, AccessDecision::DRIP_PENDING, $earliest, null ) : array( false, AccessDecision::NO_ACCESS, null, null );
	}
}
