<?php
/**
 * Blocks.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Blocks\Blocks;
use Memberkin\Core\Clock;
use Memberkin\Core\Settings;
use Memberkin\Frontend\PageSetup;

/**
 * @covers \Memberkin\Blocks\Blocks
 * @covers \Memberkin\Frontend\LevelsController
 */
final class BlocksTest extends BillingTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Block markup.
	 *
	 * @param string $name  Block.
	 * @param array  $attrs Attributes.
	 * @param string $inner Inner HTML (members content).
	 */
	private static function block( string $name, array $attrs = array(), string $inner = '' ): string {
		$json = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		return '' === $inner
			? '<!-- wp:memberkin/' . $name . $json . ' /-->'
			: '<!-- wp:memberkin/' . $name . $json . ' -->' . $inner . '<!-- /wp:memberkin/' . $name . ' -->';
	}

	/**
	 * Registration and metadata.
	 */
	public function test_registered(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array_keys( Blocks::SHORTCODES ) as $name ) {
			$type = $registry->get_registered( 'memberkin/' . $name );
			$this->assertNotNull( $type, $name );
			$this->assertSame( 'memberkin', $type->category );
			$this->assertContains( 'memberkin-blocks', $type->editor_script_handles );
			$this->assertIsCallable( $type->render_callback );
			$json = json_decode( (string) file_get_contents( MEMBERKIN_DIR . 'blocks/' . $name . '/block.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->assertSame( 3, $json['apiVersion'] );
			$this->assertSame( 'memberkin', $json['textdomain'] );
		}
		$this->assertContains( 'memberkin', array_column( apply_filters( 'block_categories_all', array(), null ), 'slug' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->level( array( 'name' => 'Listed in editor' ) );
		do_action( 'enqueue_block_editor_assets' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'memberkin-blocks', 'before' ) );
		$this->assertStringContainsString( 'window.memberkinBlocks', $inline );
		$this->assertStringContainsString( 'Listed in editor', $inline );
	}

	/**
	 * Pricing table and level button: same output as the shortcodes.
	 */
	public function test_levels_and_level_button(): void {
		$service = memberkin()->get( \Memberkin\Service\LevelService::class );
		$group   = $service->save_group( array( 'name' => 'Plans' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$other   = $service->save_group( array( 'name' => 'Other' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$gold    = $this->level( array( 'name' => 'Gold', 'group_id' => $group->id, 'billing_type' => 'one_time', 'initial_price' => '30', 'meta' => array( 'button_text' => 'Go gold' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->level( array( 'name' => 'Elsewhere', 'group_id' => $other->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$html = do_blocks( self::block( 'levels', array( 'group' => $group->id ) ) );
		$this->assertStringContainsString( 'Gold', $html );
		$this->assertStringNotContainsString( 'Elsewhere', $html );
		$this->assertStringContainsString( 'wp-block-memberkin-levels', $html );
		$this->assertStringContainsString( trim( do_shortcode( '[memberkin_levels group="' . $group->id . '"]' ) ), $html, 'same as the shortcode' );

		$button = do_blocks(
			self::block(
				'level',
				array(
					'level'     => $gold->id,
					'showPrice' => false,
				)
			)
		);
		$this->assertStringContainsString( 'Go gold', $button );
		$this->assertStringContainsString( 'level=' . $gold->slug, $button );
		$this->assertStringNotContainsString( '$30.00', $button );
		$this->assertStringContainsString( '$30.00', do_shortcode( '[memberkin_level level="' . $gold->slug . '" text="Buy"]' ) );
		$this->assertStringContainsString( '>Buy<', do_shortcode( '[memberkin_level level="' . $gold->id . '" text="Buy"]' ) );

		$this->assertSame( '', do_blocks( self::block( 'level', array( 'level' => 999999 ) ) ), 'nothing for visitors' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'Choose a membership level', do_blocks( self::block( 'level', array( 'level' => 999999 ) ) ) );

		$member = self::factory()->user->create();
		memberkin_grant_membership( $member, $gold->id );
		wp_set_current_user( $member );
		$this->assertStringContainsString( 'Your current membership', do_blocks( self::block( 'level', array( 'level' => $gold->id ) ) ) );
	}

	/**
	 * Members content: who sees the inner blocks, and what others see.
	 */
	public function test_members_content(): void {
		Clock::freeze( '2026-11-01 10:00:00' );
		$gold   = $this->level( array( 'name' => 'Gold' ) );
		$silver = $this->level( array( 'name' => 'Silver' ) );
		$inner  = '<!-- wp:paragraph --><p>Secret recipe</p><!-- /wp:paragraph -->';
		$member = self::factory()->user->create();
		$other  = self::factory()->user->create();
		memberkin_grant_membership( $member, $gold->id );
		memberkin_grant_membership( $other, $silver->id );

		$gold_only = self::block( 'members-content', array( 'levels' => array( $gold->id ) ), $inner );
		$this->assertStringNotContainsString( 'Secret recipe', do_blocks( $gold_only ), 'guests' );
		$this->assertStringContainsString( 'memberkin-restricted', do_blocks( $gold_only ), 'the standard message' );
		wp_set_current_user( $other );
		$this->assertStringNotContainsString( 'Secret recipe', do_blocks( $gold_only ) );
		wp_set_current_user( $member );
		$this->assertStringContainsString( 'Secret recipe', do_blocks( $gold_only ) );

		$non = self::block(
			'members-content',
			array(
				'showTo'  => 'non-members',
				'message' => '',
			),
			'<!-- wp:paragraph --><p>Join us!</p><!-- /wp:paragraph -->'
		);
		$this->assertSame( '', trim( do_blocks( $non ) ), 'members see nothing' );
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'Join us!', do_blocks( $non ) );
		$custom = self::block(
			'members-content',
			array(
				'showTo'  => 'logged-in',
				'message' => 'Log in to read <b>more</b>',
			),
			$inner
		);
		$this->assertStringContainsString( 'Log in to read <b>more</b>', do_blocks( $custom ) );
		$this->assertSame( array( 'levels' => '', 'show_to' => 'members', 'message' => 'default' ), Blocks::shortcode_atts( 'members-content', array( 'showTo' => 'everyone' ) ), 'unknown audiences fall back to members' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

}
