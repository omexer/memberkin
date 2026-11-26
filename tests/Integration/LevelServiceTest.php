<?php
/**
 * LevelService tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\ValidationException;
use Memberkin\Domain\Level;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\LevelService;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Service\LevelService
 */
final class LevelServiceTest extends TestCase {

	private function service(): LevelService {
		return memberkin()->get( LevelService::class );
	}

	public function test_create_recurring_level_from_form_input(): void {
		$level = $this->service()->save(
			array(
				'name'             => 'Gold',
				'billing_type'     => 'recurring',
				'initial_price'    => '19.99',
				'recurring_price'  => '19.99',
				'billing_interval' => '1',
				'billing_period'   => 'month',
				'signup_fee_price' => '5',
				'meta'             => array(
					'benefits' => "One\nTwo\n",
					'roles'    => array( 'author', 'administrator' ),
				),
			)
		);
		$this->assertGreaterThan( 0, $level->id );
		$this->assertSame( 'gold', $level->slug );
		$this->assertSame( 1999, $level->schedule->recurring_amount );
		$this->assertSame( 2499, $level->schedule->first_payment()->amount );
		$repo = memberkin()->get( LevelRepository::class );
		$this->assertSame( array( 'One', 'Two' ), $repo->meta( $level->id, 'benefits' ) );
		$this->assertSame( array( 'author' ), $repo->meta( $level->id, 'roles' ), 'administrator must never be assignable' );
		$this->assertSame( 'Gold', $repo->find_by_slug( 'gold' )->name );
	}

	public function test_slug_is_unique_and_validation_errors(): void {
		$this->service()->save(
			array(
				'name'         => 'Basic',
				'billing_type' => 'free',
			)
		);
		$second = $this->service()->save(
			array(
				'name'         => 'Basic',
				'billing_type' => 'free',
			)
		);
		$this->assertSame( 'basic-2', $second->slug );

		try {
			$this->service()->save(
				array(
					'name'            => '',
					'billing_type'    => 'recurring',
					'recurring_price' => '0',
					'trial_length'    => 7,
				)
			);
			$this->fail( 'Expected validation error' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'name', $e->errors() );
		}
	}

	public function test_one_time_type_clears_recurring_fields(): void {
		$level = $this->service()->save(
			array(
				'name'            => 'Lifetime',
				'billing_type'    => 'one_time',
				'initial_price'   => '99',
				'recurring_price' => '10',
				'trial_length'    => 5,
			)
		);
		$this->assertFalse( $level->schedule->is_recurring() );
		$this->assertSame( 0, $level->schedule->trial_length );
	}

	public function test_duplicate_and_delete_unused_level(): void {
		$level = $this->service()->save(
			array(
				'name'          => 'Silver',
				'billing_type'  => 'one_time',
				'initial_price' => '10',
				'meta'          => array( 'cost_text' => 'Cheap' ),
			)
		);
		$copy  = $this->service()->duplicate( $level->id );
		$this->assertSame( Level::STATUS_HIDDEN, $copy->status );
		$this->assertSame( 'Cheap', memberkin()->get( LevelRepository::class )->meta( $copy->id, 'cost_text' ) );
		$this->assertSame( 'deleted', $this->service()->delete( $copy->id ) );
		$this->assertNull( memberkin()->get( LevelRepository::class )->find( $copy->id ) );
	}

	public function test_used_level_is_archived_instead_of_deleted(): void {
		$level = $this->service()->save(
			array(
				'name'         => 'Used',
				'billing_type' => 'free',
			)
		);
		memberkin_grant_membership( self::factory()->user->create(), $level->id );
		$this->assertSame( 'archived', $this->service()->delete( $level->id ) );
		$this->assertSame( Level::STATUS_ARCHIVED, memberkin()->get( LevelRepository::class )->find( $level->id )->status );
	}

	public function test_group_delete_moves_levels(): void {
		$g1    = $this->service()->default_group();
		$g2    = $this->service()->save_group(
			array(
				'name'           => 'Add-ons',
				'allow_multiple' => 1,
			)
		);
		$level = $this->service()->save(
			array(
				'name'         => 'Addon',
				'billing_type' => 'free',
				'group_id'     => $g2->id,
			)
		);
		$this->service()->delete_group( $g2->id );
		$this->assertSame( $g1->id, memberkin()->get( LevelRepository::class )->find( $level->id )->group_id );
		$this->expectException( ValidationException::class );
		$this->service()->delete_group( $g1->id );
	}
}
