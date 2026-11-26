<?php
/**
 * Content access control.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Settings;
use Memberkin\Domain\Access\AccessDecision;
use Memberkin\Modules\Access\ContentGuard;
use Memberkin\Repository\AccessRepository;
use Memberkin\Service\AccessRuleService;
use Memberkin\Service\AccessService;
use Memberkin\Service\LevelService;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Service\AccessService
 * @covers \Memberkin\Modules\Access\ContentGuard
 * @covers \Memberkin\Modules\Access\QueryFilter
 * @covers \Memberkin\Modules\Access\RestrictShortcode
 */
final class AccessTest extends TestCase {

	private int $gold;
	private int $silver;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		$levels       = memberkin()->get( LevelService::class );
		$this->gold   = $levels->save(
			array(
				'name'         => 'Gold',
				'billing_type' => 'free',
			)
		)->id;
		$this->silver = $levels->save(
			array(
				'name'         => 'Silver',
				'billing_type' => 'free',
				'group_id'     => $levels->save_group(
					array(
						'name'           => 'Other',
						'allow_multiple' => 1,
					)
				)->id,
			)
		)->id;
		$this->member = self::factory()->user->create();
		memberkin_grant_membership( $this->member, $this->gold );
		memberkin()->get( AccessService::class )->flush();
	}

	private function access(): AccessService {
		return memberkin()->get( AccessService::class );
	}

	private function restrict( int $post_id, array $levels ): void {
		memberkin()->get( AccessRepository::class )->set_levels( 'post', $post_id, $levels );
		$this->access()->flush();
	}

	public function test_public_post_has_no_rules(): void {
		$post = self::factory()->post->create();
		$this->assertSame( AccessDecision::NO_RULES, $this->access()->decide_post( $post, 0 )->reason );
	}

	public function test_direct_restriction(): void {
		$post = self::factory()->post->create();
		$this->restrict( $post, array( $this->gold => null ) );
		$this->assertSame( AccessDecision::NOT_LOGGED_IN, $this->access()->decide_post( $post, 0 )->reason );
		$this->assertSame( AccessDecision::NO_ACCESS, $this->access()->decide_post( $post, self::factory()->user->create() )->reason );
		$this->assertTrue( $this->access()->decide_post( $post, $this->member )->granted );
		$this->assertSame( AccessDecision::BYPASS, $this->access()->decide_post( $post, self::factory()->user->create( array( 'role' => 'administrator' ) ) )->reason );
		$this->assertSame( array( $this->gold ), $this->access()->decide_post( $post, 0 )->required_levels );
	}

	public function test_category_restriction_and_attachment_inherits(): void {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		memberkin()->get( AccessRepository::class )->set_levels( 'term', $cat, array( $this->silver => null ) );
		$this->access()->flush();
		$this->assertFalse( $this->access()->decide_post( $post, $this->member )->granted );
		memberkin_grant_membership( $this->member, $this->silver );
		$this->assertTrue( $this->access()->decide_post( $post, $this->member )->granted );
		$attachment = self::factory()->attachment->create( array( 'post_parent' => $post ) );
		$this->assertFalse( $this->access()->decide_post( $attachment, 0 )->granted );
	}

	public function test_drip_delay(): void {
		Clock::freeze( '2026-01-01 00:00:00' );
		$user = self::factory()->user->create();
		memberkin_grant_membership( $user, $this->gold );
		$post = self::factory()->post->create();
		$this->restrict( $post, array( $this->gold => 10 ) );
		$decision = $this->access()->decide_post( $post, $user );
		$this->assertSame( AccessDecision::DRIP_PENDING, $decision->reason );
		$this->assertSame( '2026-01-11', $decision->available_at->format( 'Y-m-d' ) );
		Clock::freeze( '2026-01-11 00:00:01' );
		$this->access()->flush();
		$this->assertTrue( $this->access()->decide_post( $post, $user )->granted );
	}

	public function test_content_excerpt_feed_and_comments_are_protected(): void {
		$post = self::factory()->post->create( array( 'post_content' => 'Public intro words here.<!--more-->SECRET body text', 'post_excerpt' => '' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_content' => 'secret comment' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->restrict( $post, array( $this->gold => null ) );
		$this->go_to( get_permalink( $post ) );
		the_post();

		$html = apply_filters( 'the_content', get_post( $post )->post_content );
		$this->assertStringNotContainsString( 'SECRET', $html );
		$this->assertStringContainsString( 'Public intro words', $html, 'teaser before the more tag' );
		$this->assertStringContainsString( 'memberkin-restricted', $html );
		$this->assertStringNotContainsString( 'SECRET', get_the_excerpt( $post ) );
		$this->assertStringNotContainsString( 'SECRET', apply_filters( 'the_content_feed', 'SECRET body text', 'rss2' ) );
		$this->assertSame( array(), apply_filters( 'comments_array', array( 'x' ), $post ) );
		$this->assertFalse( comments_open( $post ) );

		wp_set_current_user( $this->member );
		$this->access()->flush();
		$this->assertStringContainsString( 'SECRET', apply_filters( 'the_content', get_post( $post )->post_content ) );
	}

	public function test_oembed_and_xmlrpc_are_protected(): void {
		$post = self::factory()->post->create( array( 'post_content' => 'Open part.<!--more-->SECRET embed body', 'post_excerpt' => '' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->restrict( $post, array( $this->gold => null ) );

		// oEmbed: the embed template prints the_excerpt_embed().
		$this->go_to( get_post_embed_url( $post ) );
		the_post();
		ob_start();
		the_excerpt_embed();
		$embed = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'SECRET', $embed );
		$this->assertNotSame( '', trim( wp_strip_all_tags( $embed ) ), 'a teaser and message are shown instead' );

		// XML-RPC: wp.getPost output passes through xmlrpc_prepare_post.
		$raw  = get_post( $post, ARRAY_A );
		$data = apply_filters( 'xmlrpc_prepare_post', array( 'post_content' => $raw['post_content'], 'post_excerpt' => '' ), $raw, array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'SECRET', $data['post_content'] );
		$this->assertStringContainsString( 'Open part', $data['post_excerpt'] );

		wp_set_current_user( $this->member );
		$this->access()->flush();
		$data = apply_filters( 'xmlrpc_prepare_post', array( 'post_content' => $raw['post_content'], 'post_excerpt' => '' ), $raw, array() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'SECRET', $data['post_content'], 'members get the full post' );
	}

	public function test_listings_rest_and_sitemaps_hide_restricted_posts(): void {
		$public = self::factory()->post->create( array( 'post_title' => 'Findme public' ) );
		$secret = self::factory()->post->create( array( 'post_title' => 'Findme secret' ) );
		$cat    = self::factory()->category->create();
		$in_cat = self::factory()->post->create( array( 'post_title' => 'Findme category', 'post_category' => array( $cat ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->restrict( $secret, array( $this->gold => null ) );
		memberkin()->get( AccessRepository::class )->set_levels( 'term', $cat, array( $this->silver => null ) );

		$this->go_to( home_url( '/?s=Findme' ) );
		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		$this->assertContains( $public, $ids );
		$this->assertNotContains( $secret, $ids );
		$this->assertNotContains( $in_cat, $ids );

		wp_set_current_user( $this->member );
		$this->go_to( home_url( '/?s=Findme' ) );
		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		$this->assertContains( $secret, $ids, 'gold member sees gold post' );
		$this->assertNotContains( $in_cat, $ids );

		// REST single post: redacted for guests.
		wp_set_current_user( 0 );
		do_action( 'rest_api_init' );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $secret ) );
		$data     = $response->get_data();
		$this->assertTrue( $data['memberkin_restricted'] );
		$this->assertFalse( $data['memberkin_access']['granted'] );
		$this->assertStringNotContainsString( 'Findme secret body', $data['content']['rendered'] );

		// REST collection: excluded.
		$list = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts' ) )->get_data();
		$this->assertNotContains( $secret, wp_list_pluck( $list, 'id' ) );

		// Sitemaps.
		$args = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'post' ), 'post' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$q    = new \WP_Query( array_merge( $args, array( 'posts_per_page' => 50, 'fields' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertContains( $public, $q->posts );
		$this->assertNotContains( $secret, $q->posts );
	}

	public function test_restrict_shortcode(): void {
		$sc    = '[memberkin_restrict levels="gold"]Members only[/memberkin_restrict][memberkin_restrict show_to="non-members"]Join us[/memberkin_restrict][memberkin_restrict show_to="logged-out"]Hi guest[/memberkin_restrict]';
		$guest = do_shortcode( $sc );
		$this->assertStringNotContainsString( 'Members only', $guest );
		$this->assertStringContainsString( 'Join us', $guest );
		$this->assertStringContainsString( 'Hi guest', $guest );
		wp_set_current_user( $this->member );
		$member = do_shortcode( $sc );
		$this->assertStringContainsString( 'Members only', $member );
		$this->assertStringNotContainsString( 'Join us', $member );
		$this->assertStringContainsString( 'For members', do_shortcode( '[memberkin_restrict levels="silver" message="For members"]x[/memberkin_restrict]' ) );
	}

	public function test_meta_box_saves_levels(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post  = self::factory()->post->create();
		$_POST = array(
			'memberkin_access_present' => '1',
			'_memberkin_access_nonce'  => wp_create_nonce( 'memberkin_access_post_' . $post ),
			'memberkin_access_levels'  => array( (string) $this->gold, '99999' ),
		);
		( new \Memberkin\Modules\Access\Admin\ContentMetaBox( memberkin() ) )->save( $post, get_post( $post ) );
		$_POST = array();
		$this->assertSame( array( $this->gold => null ), memberkin()->get( AccessRepository::class )->levels_for( 'post', $post ) );
	}

	public function test_message_merge_tags(): void {
		Settings::update( 'access', array( 'restricted_message' => 'Join {levels} at {levels_url}' ) );
		$decision = new AccessDecision( false, AccessDecision::NO_ACCESS, array( $this->gold, $this->silver ) );
		$text     = memberkin()->get( ContentGuard::class )->message( $decision );
		$this->assertStringContainsString( 'Gold and Silver', $text );
		$this->assertStringNotContainsString( '{levels_url}', $text );
	}

}
