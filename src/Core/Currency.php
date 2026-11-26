<?php
/**
 * Currency metadata.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * ISO 4217 currencies with minor-unit exponents and display symbols.
 */
final class Currency {

	/**
	 * Currencies without decimals.
	 */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Currencies with three decimals.
	 */
	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Supported currencies: code => [ name, symbol, symbol position ].
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function all(): array {
		$list = array(
			'USD' => array( __( 'US Dollar', 'memberkin' ), '$', 'left' ),
			'EUR' => array( __( 'Euro', 'memberkin' ), '€', 'right_space' ),
			'GBP' => array( __( 'Pound Sterling', 'memberkin' ), '£', 'left' ),
			'CAD' => array( __( 'Canadian Dollar', 'memberkin' ), 'CA$', 'left' ),
			'AUD' => array( __( 'Australian Dollar', 'memberkin' ), 'A$', 'left' ),
			'NZD' => array( __( 'New Zealand Dollar', 'memberkin' ), 'NZ$', 'left' ),
			'CHF' => array( __( 'Swiss Franc', 'memberkin' ), 'CHF', 'left_space' ),
			'SEK' => array( __( 'Swedish Krona', 'memberkin' ), 'kr', 'right_space' ),
			'NOK' => array( __( 'Norwegian Krone', 'memberkin' ), 'kr', 'right_space' ),
			'DKK' => array( __( 'Danish Krone', 'memberkin' ), 'kr.', 'right_space' ),
			'PLN' => array( __( 'Polish Złoty', 'memberkin' ), 'zł', 'right_space' ),
			'CZK' => array( __( 'Czech Koruna', 'memberkin' ), 'Kč', 'right_space' ),
			'HUF' => array( __( 'Hungarian Forint', 'memberkin' ), 'Ft', 'right_space' ),
			'RON' => array( __( 'Romanian Leu', 'memberkin' ), 'lei', 'right_space' ),
			'BGN' => array( __( 'Bulgarian Lev', 'memberkin' ), 'лв', 'right_space' ),
			'ISK' => array( __( 'Icelandic Króna', 'memberkin' ), 'kr', 'right_space' ),
			'JPY' => array( __( 'Japanese Yen', 'memberkin' ), '¥', 'left' ),
			'CNY' => array( __( 'Chinese Yuan', 'memberkin' ), '¥', 'left' ),
			'HKD' => array( __( 'Hong Kong Dollar', 'memberkin' ), 'HK$', 'left' ),
			'SGD' => array( __( 'Singapore Dollar', 'memberkin' ), 'S$', 'left' ),
			'KRW' => array( __( 'South Korean Won', 'memberkin' ), '₩', 'left' ),
			'INR' => array( __( 'Indian Rupee', 'memberkin' ), '₹', 'left' ),
			'IDR' => array( __( 'Indonesian Rupiah', 'memberkin' ), 'Rp', 'left_space' ),
			'MYR' => array( __( 'Malaysian Ringgit', 'memberkin' ), 'RM', 'left' ),
			'PHP' => array( __( 'Philippine Peso', 'memberkin' ), '₱', 'left' ),
			'THB' => array( __( 'Thai Baht', 'memberkin' ), '฿', 'left' ),
			'VND' => array( __( 'Vietnamese Dong', 'memberkin' ), '₫', 'right_space' ),
			'ILS' => array( __( 'Israeli New Shekel', 'memberkin' ), '₪', 'left' ),
			'AED' => array( __( 'UAE Dirham', 'memberkin' ), 'AED', 'left_space' ),
			'SAR' => array( __( 'Saudi Riyal', 'memberkin' ), 'SAR', 'left_space' ),
			'KWD' => array( __( 'Kuwaiti Dinar', 'memberkin' ), 'KD', 'left_space' ),
			'BHD' => array( __( 'Bahraini Dinar', 'memberkin' ), 'BD', 'left_space' ),
			'TRY' => array( __( 'Turkish Lira', 'memberkin' ), '₺', 'left' ),
			'ZAR' => array( __( 'South African Rand', 'memberkin' ), 'R', 'left' ),
			'NGN' => array( __( 'Nigerian Naira', 'memberkin' ), '₦', 'left' ),
			'GHS' => array( __( 'Ghanaian Cedi', 'memberkin' ), 'GH₵', 'left' ),
			'KES' => array( __( 'Kenyan Shilling', 'memberkin' ), 'KSh', 'left_space' ),
			'EGP' => array( __( 'Egyptian Pound', 'memberkin' ), 'E£', 'left' ),
			'BRL' => array( __( 'Brazilian Real', 'memberkin' ), 'R$', 'left_space' ),
			'MXN' => array( __( 'Mexican Peso', 'memberkin' ), 'MX$', 'left' ),
			'ARS' => array( __( 'Argentine Peso', 'memberkin' ), 'AR$', 'left' ),
			'CLP' => array( __( 'Chilean Peso', 'memberkin' ), 'CLP$', 'left' ),
			'COP' => array( __( 'Colombian Peso', 'memberkin' ), 'COL$', 'left' ),
			'PEN' => array( __( 'Peruvian Sol', 'memberkin' ), 'S/', 'left' ),
		);
		/**
		 * Filters supported currencies.
		 *
		 * @param array $list code => [ name, symbol, position ].
		 */
		return (array) apply_filters( 'memberkin_currencies', $list );
	}

	/**
	 * Whether a code is supported.
	 *
	 * @param string $code ISO code.
	 */
	public static function is_valid( string $code ): bool {
		return isset( self::all()[ strtoupper( $code ) ] );
	}

	/**
	 * Number of decimals of the minor unit.
	 *
	 * @param string $code ISO code.
	 */
	public static function exponent( string $code ): int {
		$code = strtoupper( $code );
		if ( in_array( $code, self::ZERO_DECIMAL, true ) ) {
			return 0;
		}
		return in_array( $code, self::THREE_DECIMAL, true ) ? 3 : 2;
	}

	/**
	 * Display symbol.
	 *
	 * @param string $code ISO code.
	 */
	public static function symbol( string $code ): string {
		return self::all()[ strtoupper( $code ) ][1] ?? strtoupper( $code );
	}

	/**
	 * Symbol position: left, left_space, right, right_space.
	 *
	 * @param string $code ISO code.
	 */
	public static function position( string $code ): string {
		$override = (string) Settings::get( 'general', 'currency_position', '' );
		if ( '' !== $override ) {
			return $override;
		}
		return self::all()[ strtoupper( $code ) ][2] ?? 'left';
	}

	/**
	 * Site default currency.
	 */
	public static function default(): string {
		$code = strtoupper( (string) Settings::get( 'general', 'currency', 'USD' ) );
		return self::is_valid( $code ) ? $code : 'USD';
	}
}
