<?php
/**
 * Money value object.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable amount in integer minor units with a currency. No floats are used in arithmetic.
 */
final class Money implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency ISO 4217 code.
	 */
	private function __construct( public readonly int $amount, public readonly string $currency ) {}

	/**
	 * From minor units.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	public static function of( int $amount, string $currency ): self {
		return new self( $amount, strtoupper( $currency ) );
	}

	/**
	 * Zero amount.
	 *
	 * @param string $currency Currency.
	 */
	public static function zero( string $currency ): self {
		return new self( 0, strtoupper( $currency ) );
	}

	/**
	 * Parses a decimal string like "19.99", "1,234.5" or "1.234,50" (when $decimal_separator is ",").
	 * Extra decimals are rounded half-up.
	 *
	 * @param string|int|float $value             Decimal value.
	 * @param string           $currency          Currency.
	 * @param string           $decimal_separator "." or ",".
	 * @throws \InvalidArgumentException On unparsable input.
	 */
	public static function from_decimal( string|int|float $value, string $currency, string $decimal_separator = '.' ): self {
		$exp = Currency::exponent( $currency );
		if ( is_float( $value ) ) {
			$value = number_format( $value, $exp + 2, '.', '' );
		}
		$value = trim( (string) $value );
		if ( ',' === $decimal_separator ) {
			$value = str_replace( array( '.', ' ' ), '', $value );
			$value = str_replace( ',', '.', $value );
		} else {
			$value = str_replace( array( ',', ' ' ), '', $value );
		}
		if ( '' === $value ) {
			return self::zero( $currency );
		}
		if ( ! preg_match( '/^(-)?(\d*)(?:\.(\d*))?$/', $value, $m ) ) {
			throw new \InvalidArgumentException( 'Invalid amount: ' . esc_html( $value ) );
		}
		$negative = '-' === $m[1];
		$whole    = '' === $m[2] ? '0' : $m[2];
		$fraction = $m[3] ?? '';
		$kept     = substr( str_pad( $fraction, $exp, '0' ), 0, $exp );
		$next     = strlen( $fraction ) > $exp ? (int) $fraction[ $exp ] : 0;
		$minor    = (int) ( $whole . $kept );
		if ( $next >= 5 ) {
			++$minor;
		}
		return new self( $negative ? -$minor : $minor, strtoupper( $currency ) );
	}

	/**
	 * Decimal string with the currency's precision, e.g. "19.99".
	 */
	public function to_decimal(): string {
		$exp = Currency::exponent( $this->currency );
		$abs = (string) abs( $this->amount );
		if ( 0 === $exp ) {
			return ( $this->amount < 0 ? '-' : '' ) . $abs;
		}
		$abs = str_pad( $abs, $exp + 1, '0', STR_PAD_LEFT );
		return ( $this->amount < 0 ? '-' : '' ) . substr( $abs, 0, -$exp ) . '.' . substr( $abs, -$exp );
	}

	/**
	 * Sum.
	 *
	 * @param Money $other Other amount.
	 */
	public function add( Money $other ): self {
		$this->assert_same_currency( $other );
		return new self( $this->amount + $other->amount, $this->currency );
	}

	/**
	 * Difference.
	 *
	 * @param Money $other Other amount.
	 */
	public function subtract( Money $other ): self {
		$this->assert_same_currency( $other );
		return new self( $this->amount - $other->amount, $this->currency );
	}

	/**
	 * Multiplies by numerator/denominator with half-even rounding to the minor unit.
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator (> 0).
	 * @throws \InvalidArgumentException On zero denominator.
	 */
	public function multiply_ratio( int $numerator, int $denominator ): self {
		if ( $denominator <= 0 ) {
			throw new \InvalidArgumentException( 'Denominator must be positive.' );
		}
		return new self( self::round_half_even_div( $this->amount * $numerator, $denominator ), $this->currency );
	}

	/**
	 * Applies a percentage given as a decimal string (e.g. "21", "7.25"); returns the percentage part.
	 *
	 * @param string|int|float $percent Percent.
	 */
	public function percentage( string|int|float $percent ): self {
		$ppm = self::percent_to_ppm( $percent );
		return $this->multiply_ratio( $ppm, 100000000 );
	}

	/**
	 * Converts a percent decimal (max 6 decimals) to an integer of percent × 1,000,000.
	 *
	 * @param string|int|float $percent Percent.
	 */
	public static function percent_to_ppm( string|int|float $percent ): int {
		$str = is_float( $percent ) ? number_format( $percent, 6, '.', '' ) : trim( (string) $percent );
		if ( ! preg_match( '/^(-)?(\d*)(?:\.(\d*))?$/', $str, $m ) ) {
			return 0;
		}
		$value = (int) ( ( '' === $m[2] ? '0' : $m[2] ) . substr( str_pad( $m[3] ?? '', 6, '0' ), 0, 6 ) );
		return '-' === $m[1] ? -$value : $value;
	}

	/**
	 * Integer division rounding half to even.
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator (> 0).
	 */
	public static function round_half_even_div( int $numerator, int $denominator ): int {
		$quotient  = intdiv( $numerator, $denominator );
		$remainder = $numerator % $denominator;
		$twice     = 2 * abs( $remainder );
		// Round away from zero when past half, or exactly half and the quotient is odd (banker's rounding).
		if ( $twice > $denominator || ( $twice === $denominator && 0 !== $quotient % 2 ) ) {
			$quotient += $numerator < 0 ? -1 : 1;
		}
		return $quotient;
	}

	/**
	 * Negated amount.
	 */
	public function negate(): self {
		return new self( -$this->amount, $this->currency );
	}

	/**
	 * Max(this, 0).
	 */
	public function non_negative(): self {
		return $this->amount < 0 ? self::zero( $this->currency ) : $this;
	}

	/**
	 * Whether zero.
	 */
	public function is_zero(): bool {
		return 0 === $this->amount;
	}

	/**
	 * Whether negative.
	 */
	public function is_negative(): bool {
		return $this->amount < 0;
	}

	/**
	 * Whether positive.
	 */
	public function is_positive(): bool {
		return $this->amount > 0;
	}

	/**
	 * -1, 0 or 1.
	 *
	 * @param Money $other Other amount.
	 */
	public function compare( Money $other ): int {
		$this->assert_same_currency( $other );
		return $this->amount <=> $other->amount;
	}

	/**
	 * Equality.
	 *
	 * @param Money $other Other amount.
	 */
	public function equals( Money $other ): bool {
		return $this->currency === $other->currency && $this->amount === $other->amount;
	}

	/**
	 * Human formatted string, e.g. "$19.99" or "19,99 €" (localised separators).
	 *
	 * @param bool $html Wrap in spans for styling.
	 */
	public function format( bool $html = false ): string {
		$exp      = Currency::exponent( $this->currency );
		$decimal  = $this->to_decimal();
		$number   = number_format_i18n( (float) ltrim( $decimal, '-' ), $exp );
		$symbol   = Currency::symbol( $this->currency );
		$position = Currency::position( $this->currency );
		$sign     = $this->amount < 0 ? '-' : '';

		$formatted = match ( $position ) {
			'right'       => $number . $symbol,
			'right_space' => $number . "\u{00A0}" . $symbol,
			'left_space'  => $symbol . "\u{00A0}" . $number,
			default       => $symbol . $number,
		};

		/**
		 * Filters a formatted price.
		 *
		 * @param string $formatted Formatted price.
		 * @param Money  $money     Amount.
		 */
		$formatted = (string) apply_filters( 'memberkin_format_price', $sign . $formatted, $this );
		return $html ? '<span class="memberkin-price">' . esc_html( $formatted ) . '</span>' : $formatted;
	}

	/**
	 * JSON representation.
	 *
	 * @return array{amount:int,currency:string,decimal:string}
	 */
	public function jsonSerialize(): array {
		return array(
			'amount'   => $this->amount,
			'currency' => $this->currency,
			'decimal'  => $this->to_decimal(),
		);
	}

	/**
	 * Guards against mixing currencies.
	 *
	 * @param Money $other Other amount.
	 * @throws \LogicException On mismatch.
	 */
	private function assert_same_currency( Money $other ): void {
		if ( $other->currency !== $this->currency ) {
			throw new \LogicException( sprintf( 'Currency mismatch: %s vs %s', esc_html( $this->currency ), esc_html( $other->currency ) ) );
		}
	}
}
