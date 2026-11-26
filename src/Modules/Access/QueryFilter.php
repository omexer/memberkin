<?php
/**
 * Hides restricted content from listings.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Access;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Db;
use Memberkin\Core\Settings;
use Memberkin\Repository\AccessRepository;
use Memberkin\Service\AccessService;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Adds NOT EXISTS sub-queries (indexed on content_access) to searches, archives, feeds, REST collections,
 * sitemaps and comment queries. Drip-delayed posts stay listed (they show a teaser).
 *
 * Add-ons add their own exclusions through the "memberkin_listing_restriction_sql" filter.
 */
final class QueryFilter {

	/**
	 * Constructor.
	 *
	 * @param AccessRepository  $repo        Repository.
	 * @param MembershipService $memberships Memberships.
	 */
	public function __construct(
		private AccessRepository $repo,
		private MembershipService $memberships
	) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'posts_where', array( $this, 'posts_where' ), 10, 2 );
		add_filter( 'comments_clauses', array( $this, 'comments_clauses' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_args' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Flags REST collection queries.
	 */
	public function register_rest(): void {
		foreach ( AccessService::restrictable_post_types() as $type ) {
			add_filter(
				"rest_{$type}_query",
				static function ( array $args ): array {
					$args['memberkin_filter'] = true;
					return $args;
				}
			);
		}
	}

	/**
	 * Sitemaps never list restricted content.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function sitemap_args( $args ) {
		$args['memberkin_filter']  = true;
		$args['memberkin_user_id'] = 0;
		return $args;
	}

	/**
	 * Whether a query should be filtered.
	 *
	 * @param \WP_Query $query Query.
	 */
	private function should_filter( \WP_Query $query ): bool {
		if ( $query->get( 'memberkin_skip_filter' ) || ! Settings::get( 'access', 'filter_searches', true ) || ! $this->repo->has_any() ) {
			return false;
		}
		if ( $query->get( 'memberkin_filter' ) ) {
			return true;
		}
		if ( is_admin() || $query->is_singular() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		return $query->is_search() || $query->is_archive() || $query->is_home() || $query->is_feed();
	}

	/**
	 * Restricts WP_Query results.
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query $query Query.
	 */
	public function posts_where( $where, $query ) {
		if ( ! $query instanceof \WP_Query || ! $this->should_filter( $query ) ) {
			return $where;
		}
		global $wpdb;
		$user_id = $query->get( 'memberkin_user_id' );
		$user_id = '' === $user_id || null === $user_id ? get_current_user_id() : (int) $user_id;
		$sql     = $this->exclusion_sql( "{$wpdb->posts}.ID", "{$wpdb->posts}.post_type", $user_id );
		return '' === $sql ? $where : $where . ' AND ' . $sql;
	}

	/**
	 * Restricts comment queries (recent comments, comment feeds, REST lists).
	 *
	 * @param array             $clauses Clauses.
	 * @param \WP_Comment_Query $query   Query.
	 * @return array
	 */
	public function comments_clauses( $clauses, $query ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! Settings::get( 'access', 'filter_searches', true ) || ! $this->repo->has_any() ) {
			return $clauses;
		}
		if ( ! empty( $query->query_vars['post_id'] ) ) {
			return $clauses; // Single-post comment lists are handled by comments_array.
		}
		global $wpdb;
		$sql = $this->exclusion_sql( "{$wpdb->comments}.comment_post_ID", "(SELECT ep.post_type FROM {$wpdb->posts} ep WHERE ep.ID = {$wpdb->comments}.comment_post_ID)", get_current_user_id() );
		if ( '' !== $sql ) {
			$clauses['where'] .= ' AND ' . $sql;
		}
		return $clauses;
	}

	/**
	 * SQL condition that is true for posts the user may see in listings ('' = no restriction).
	 *
	 * @param string $id_column        Column with the post id.
	 * @param string $post_type_column Column/expression with the post type.
	 * @param int    $user_id          User.
	 */
	public function exclusion_sql( string $id_column, string $post_type_column, int $user_id ): string {
		if ( $user_id && user_can( $user_id, Capabilities::BYPASS_RESTRICTIONS ) ) {
			return '';
		}
		global $wpdb;
		$ca     = Db::table_name( 'content_access' );
		$levels = $user_id ? $this->memberships->active_level_ids( $user_id ) : array();
		$in     = $levels ? implode( ',', array_map( 'intval', $levels ) ) : '0';

		$has_direct = "EXISTS (SELECT 1 FROM {$ca} ca WHERE ca.object_type = 'post' AND ca.object_id = {$id_column})";
		$has_term   = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$ca} cat ON cat.object_type = 'term' AND cat.object_id = tt.term_id WHERE tr.object_id = {$id_column})";
		$ok_direct  = "EXISTS (SELECT 1 FROM {$ca} ca2 WHERE ca2.object_type = 'post' AND ca2.object_id = {$id_column} AND ca2.level_id IN ({$in}))";
		$ok_term    = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr2 INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id INNER JOIN {$ca} cat2 ON cat2.object_type = 'term' AND cat2.object_id = tt2.term_id AND cat2.level_id IN ({$in}) WHERE tr2.object_id = {$id_column})";

		$parts = array( "( NOT ( {$has_direct} OR {$has_term} ) OR {$ok_direct} OR {$ok_term} )" );
		/**
		 * Filters the listing restriction SQL parts (ANDed).
		 *
		 * Add-ons with their own restrictions add a fragment that is false for posts the user may not see.
		 *
		 * @param string[] $parts            SQL fragments.
		 * @param int      $user_id          User.
		 * @param string   $id_column        Column with the post id.
		 * @param string   $post_type_column Column/expression with the post type.
		 */
		$parts = (array) apply_filters( 'memberkin_listing_restriction_sql', $parts, $user_id, $id_column, $post_type_column );
		return '( ' . implode( ' AND ', $parts ) . ' )';
	}
}
