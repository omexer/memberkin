<?php
/**
 * Money tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Unit;

use Memberkin\Core\Money;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Core\Money
 */
final class MoneyTest extends TestCase {

	public function test_from_decimal_parses_and_rounds(): void {
		$this->assertSame( 1999, Money::from_decimal( '19.99', 'USD' )->amount );
		$this->assertSame( 2000, Money::from_decimal( '19.995', 'USD' )->amount );
		$this->assertSame( 123450, Money::from_decimal( '1,234.5', 'USD' )->amount );
		$this->assertSame( 123450, Money::from_decimal( '1.234,50', 'EUR', ',' )->amount );
		$this->assertSame( 500, Money::from_decimal( '500', 'JPY' )->amount );
		$this->assertSame( 1234, Money::from_decimal( '1.234', 'KWD' )->amount );
		$this->assertSame( -550, Money::from_decimal( '-5.5', 'USD' )->amount );
		$this->assertSame( 0, Money::from_decimal( '', 'USD' )->amount );
	}

	public function test_from_decimal_rejects_garbage(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::from_decimal( '12abc', 'USD' );
	}

	public function test_to_decimal(): void {
		$this->assertSame( '19.99', Money::of( 1999, 'USD' )->to_decimal() );
		$this->assertSame( '0.05', Money::of( 5, 'USD' )->to_decimal() );
		$this->assertSame( '-0.05', Money::of( -5, 'USD' )->to_decimal() );
		$this->assertSame( '500', Money::of( 500, 'JPY' )->to_decimal() );
		$this->assertSame( '1.234', Money::of( 1234, 'BHD' )->to_decimal() );
	}

	public function test_arithmetic_and_currency_guard(): void {
		$a = Money::of( 1000, 'USD' );
		$this->assertSame( 1500, $a->add( Money::of( 500, 'USD' ) )->amount );
		$this->assertSame( 500, $a->subtract( Money::of( 500, 'USD' ) )->amount );
		$this->assertSame( 0, Money::of( -1, 'USD' )->non_negative()->amount );
		$this->expectException( \LogicException::class );
		$a->add( Money::of( 1, 'EUR' ) );
	}

	public function test_bankers_rounding(): void {
		$this->assertSame( 2, Money::round_half_even_div( 5, 2 ) );  // 2.5 -> 2.
		$this->assertSame( 4, Money::round_half_even_div( 7, 2 ) );  // 3.5 -> 4.
		$this->assertSame( -2, Money::round_half_even_div( -5, 2 ) );
		$this->assertSame( 3, Money::round_half_even_div( 8, 3 ) );  // 2.67 -> 3.
		$this->assertSame( 0, Money::round_half_even_div( 1, 2 ) );  // 0.5 -> 0.
	}

	public function test_percentage(): void {
		$this->assertSame( 210, Money::of( 1000, 'EUR' )->percentage( '21' )->amount );
		$this->assertSame( 72, Money::of( 999, 'USD' )->percentage( '7.25' )->amount ); // 72.4275 -> 72.
	}

	public function test_format(): void {
		$this->assertSame( '$19.99', Money::of( 1999, 'USD' )->format() );
		$this->assertSame( "19.99\u{00A0}€", Money::of( 1999, 'EUR' )->format() );
		$this->assertSame( '¥500', Money::of( 500, 'JPY' )->format() );
	}
}
