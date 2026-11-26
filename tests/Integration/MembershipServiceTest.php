<?php
/**
 * MembershipService tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Service\LevelService;
use Memberkin\Service\MembershipService;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Service\MembershipService
 */
final class MembershipServiceTest extends TestCase {

	private function levels(): LevelService {
		return memberkin()->get( LevelService::class );
	}

	private function service(): MembershipService {
		return memberkin()->get( MembershipService::class );
	}

	public function test_grant_and_access(): void {
		$level = $this->levels()->save(
			array(
				'name'              => 'Pro',
				'billing_type'      => 'one_time',
				'initial_price'     => '50',
				'expiration_type'   => 'duration',
				'expiration_length' => 1,
				'expiration_period' => 'month',
			)
		);
		$user  = self::factory()->user->create();
		Clock::freeze( '2026-01-31 12:00:00' );
		$m = $this->service()->grant( $user, $level->id, array( 'source' => 'admin' ) );
		$this->assertSame( Membership::ACTIVE, $m->status );
		$this->assertSame( '2026-02-28 12:00:00', $m->ends_at->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
		$this->assertTrue( memberkin_has_level( 'pro', $user ) );
		$this->assertTrue( memberkin_has_level( null, $user ) );

		Clock::freeze( '2026-03-01 00:00:00' );
		$this->assertFalse( memberkin_has_level( $level->id, $user ), 'Access ends at ends_at even before the expiry job runs' );
		$this->assertSame( 1, $this->service()->expire_due() );
		$ended = memberkin()->get( MembershipRepository::class )->find( $m->id );
		$this->assertSame( Membership::ENDED, $ended->status );
		$this->assertSame( 'expired', $ended->end_reason );
	}

	public function test_single_selection_group_replaces_old_level(): void {
		$basic = $this->levels()->save(
			array(
				'name'         => 'Basic',
				'billing_type' => 'free',
			)
		);
		$pro   = $this->levels()->save(
			array(
				'name'         => 'Pro2',
				'billing_type' => 'free',
			)
		);
		$user  = self::factory()->user->create();
		$first = $this->service()->grant( $user, $basic->id );
		$this->service()->grant( $user, $pro->id );
		$this->assertSame( Membership::ENDED, memberkin()->get( MembershipRepository::class )->find( $first->id )->status );
		$this->assertSame( 'changed', memberkin()->get( MembershipRepository::class )->find( $first->id )->end_reason );
		$this->assertSame( array( $pro->id ), $this->service()->active_level_ids( $user ) );

		$this->expectException( ValidationException::class );
		$this->service()->grant( $user, $basic->id, array( 'replace' => false ) );
	}

	public function test_multiple_selection_group_allows_concurrent_levels(): void {
		$group = $this->levels()->save_group(
			array(
				'name'           => 'Courses',
				'allow_multiple' => 1,
			)
		);
		$a     = $this->levels()->save(
			array(
				'name'         => 'Course A',
				'billing_type' => 'free',
				'group_id'     => $group->id,
			)
		);
		$b     = $this->levels()->save(
			array(
				'name'         => 'Course B',
				'billing_type' => 'free',
				'group_id'     => $group->id,
			)
		);
		$user  = self::factory()->user->create();
		$this->service()->grant( $user, $a->id );
		$this->service()->grant( $user, $b->id );
		$this->assertEqualsCanonicalizing( array( $a->id, $b->id ), $this->service()->active_level_ids( $user ) );
	}

	public function test_suspend_pause_resume_and_transitions(): void {
		$level = $this->levels()->save(
			array(
				'name'              => 'Fixed',
				'billing_type'      => 'one_time',
				'initial_price'     => '10',
				'expiration_type'   => 'duration',
				'expiration_length' => 30,
				'expiration_period' => 'day',
			)
		);
		$user  = self::factory()->user->create();
		Clock::freeze( '2026-01-01 00:00:00' );
		$m = $this->service()->grant( $user, $level->id );

		$this->service()->suspend( $m->id, 'abuse' );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		$this->service()->unsuspend( $m->id );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );

		$this->service()->pause( $m->id );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		Clock::freeze( '2026-01-11 00:00:00' );
		$resumed = $this->service()->resume( $m->id );
		$this->assertSame( '2026-02-10', $resumed->ends_at->format( 'Y-m-d' ), 'Pause length is added to the end date' );

		$this->expectException( ValidationException::class );
		$this->service()->transition( $m->id, Membership::PENDING_APPROVAL );
	}

	public function test_cancel_keeps_access_until_end_then_fallback(): void {
		$free  = $this->levels()->save(
			array(
				'name'         => 'Free tier',
				'billing_type' => 'free',
			)
		);
		$group = $this->levels()->default_group();
		$this->levels()->save_group(
			array(
				'id'                => $group->id,
				'fallback_level_id' => $free->id,
			)
		);
		$paid = $this->levels()->save(
			array(
				'name'          => 'Paid',
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$user = self::factory()->user->create();

		Clock::freeze( '2026-01-01 00:00:00' );
		$m = $this->service()->grant( $user, $paid->id );
		$m = $this->service()->cancel( $m->id, new \DateTimeImmutable( '2026-01-31 00:00:00', new \DateTimeZone( 'UTC' ) ) );
		$this->assertSame( Membership::CANCELLING, $m->status );
		$this->assertTrue( memberkin_has_level( $paid->id, $user ) );

		Clock::freeze( '2026-02-01 00:00:00' );
		$this->service()->expire_due();
		$this->assertSame( 'cancelled', memberkin()->get( MembershipRepository::class )->find( $m->id )->end_reason );
		$this->assertSame( array( $free->id ), $this->service()->active_level_ids( $user ), 'Fallback level granted' );
	}

	public function test_past_due_grace(): void {
		$level = $this->levels()->save(
			array(
				'name'            => 'Monthly',
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$user  = self::factory()->user->create();
		Clock::freeze( '2026-01-01 00:00:00' );
		$m = $this->service()->grant( $user, $level->id );
		$this->service()->mark_past_due( $m->id, new \DateTimeImmutable( '2026-01-04 00:00:00', new \DateTimeZone( 'UTC' ) ) );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
		Clock::freeze( '2026-01-05 00:00:00' );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		$this->service()->expire_due();
		$this->assertSame( 'failed_payment', memberkin()->get( MembershipRepository::class )->find( $m->id )->end_reason );
	}

	public function test_roles_are_synced(): void {
		$level = $this->levels()->save(
			array(
				'name'         => 'Authors',
				'billing_type' => 'free',
				'meta'         => array(
					'roles'               => array( 'author' ),
					'remove_roles_on_end' => 1,
				),
			)
		);
		$user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$m     = $this->service()->grant( $user, $level->id );
		$this->assertContains( 'author', get_userdata( $user )->roles );
		$this->service()->end( $m->id, 'admin' );
		$this->assertNotContains( 'author', get_userdata( $user )->roles );
	}

	public function test_hooks_fire_and_history_recorded(): void {
		$level = $this->levels()->save(
			array(
				'name'         => 'Hooked',
				'billing_type' => 'free',
			)
		);
		$user  = self::factory()->user->create();
		$seen  = array();
		add_action(
			'memberkin_membership_status_changed',
			static function ( $m, $from, $to ) use ( &$seen ) {
				$seen[] = $from . '>' . $to;
			},
			10,
			3
		);
		$m = $this->service()->grant( $user, $level->id );
		$this->service()->end( $m->id );
		$this->assertSame( array( '>active', 'active>ended' ), $seen );
		$events = array_map( static fn( $e ) => $e->event, \Memberkin\Core\Events::for_user( $user ) );
		$this->assertContains( 'membership.created', $events );
		$this->assertContains( 'membership.ended', $events );
	}

	public function test_deleting_user_ends_memberships(): void {
		$level = $this->levels()->save(
			array(
				'name'         => 'Del',
				'billing_type' => 'free',
			)
		);
		$user  = self::factory()->user->create();
		$m     = $this->service()->grant( $user, $level->id );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user );
		$this->assertSame( Membership::ENDED, memberkin()->get( MembershipRepository::class )->find( $m->id )->status );
	}

	/**
	 * Deleting a user never fails because a membership record is already gone.
	 */
	public function test_user_deletion_survives_missing_membership(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$level = memberkin()->get( \Memberkin\Service\LevelService::class )->save( array( 'name' => 'Gone' ) );
		$user  = self::factory()->user->create();
		$m     = memberkin_grant_membership( $user, $level->id );
		memberkin()->get( \Memberkin\Repository\MembershipRepository::class )->current_for_user( $user ); // Cached.
		global $wpdb;
		$wpdb->delete( \Memberkin\Core\Db::table_name( 'memberships' ), array( 'id' => $m->id ) );
		$this->assertTrue( wp_delete_user( $user ) );
		if ( is_multisite() ) {
			$this->assertFalse( is_user_member_of_blog( $user ), 'multisite: removed from this site' );
		} else {
			$this->assertFalse( get_userdata( $user ) );
		}
	}
}
