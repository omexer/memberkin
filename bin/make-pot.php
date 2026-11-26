<?php
/**
 * Generates languages/memberkin.pot from gettext calls in src/, templates/ and the main file.
 *
 * Usage: php bin/make-pot.php
 *
 * Recognises __, _e, _x, _ex, _n, _nx, esc_html__/_e/_x, esc_attr__/_e/_x with the text domain "memberkin",
 * and "translators:" comments right before a call.
 *
 * @package Memberkin
 */

// phpcs:disable -- developer tool, not loaded by WordPress.

const MEMBERKIN_POT_DOMAIN = 'memberkin';

// Function => [ argument roles ]: s = singular, p = plural, c = context, d = domain, n = number.
$functions = array(
	'__'         => array( 's', 'd' ),
	'_e'         => array( 's', 'd' ),
	'esc_html__' => array( 's', 'd' ),
	'esc_html_e' => array( 's', 'd' ),
	'esc_attr__' => array( 's', 'd' ),
	'esc_attr_e' => array( 's', 'd' ),
	'_x'         => array( 's', 'c', 'd' ),
	'_ex'        => array( 's', 'c', 'd' ),
	'esc_html_x' => array( 's', 'c', 'd' ),
	'esc_attr_x' => array( 's', 'c', 'd' ),
	'_n'         => array( 's', 'p', 'n', 'd' ),
	'_nx'        => array( 's', 'p', 'n', 'c', 'd' ),
);

$root  = dirname( __DIR__ );
$files = array( $root . '/memberkin.php' );
foreach ( array( 'src', 'templates' ) as $dir ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir ) ) as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}
sort( $files );

/**
 * PHP string literal → value.
 *
 * @param string $literal Literal with quotes.
 */
function memberkin_pot_unquote( string $literal ): string {
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

/**
 * Value → POT string.
 *
 * @param string $value Value.
 */
function memberkin_pot_quote( string $value ): string {
	return '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $value ) . '"';
}

$entries = array();
foreach ( $files as $path ) {
	$tokens  = token_get_all( file_get_contents( $path ) );
	$count   = count( $tokens );
	$comment = null;
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			if ( preg_match( '/translators:\s*(.*?)\s*(\*\/)?$/is', $t[1], $m ) ) {
				$comment = array( trim( preg_replace( '/\s*\n\s*\*?\s*/', ' ', $m[1] ) ), $t[2] + substr_count( $t[1], "\n" ) );
			}
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $functions[ $t[1] ] ) ) {
			continue;
		}
		$prev = $tokens[ $i - 1 ] ?? null;
		if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		// Collect top-level arguments (only plain string literals are usable).
		$args  = array();
		$cur   = array();
		$depth = 0;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$tok = $tokens[ $j ];
			$txt = is_array( $tok ) ? $tok[1] : $tok;
			if ( '(' === $txt || '[' === $txt ) {
				++$depth;
				if ( 1 === $depth && '(' === $txt ) {
					continue;
				}
			} elseif ( ')' === $txt || ']' === $txt ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
			} elseif ( ',' === $txt && 1 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
			if ( is_array( $tok ) && in_array( $tok[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				continue;
			}
			$cur[] = $tok;
		}
		$roles  = $functions[ $t[1] ];
		$values = array();
		foreach ( $roles as $k => $role ) {
			$arg = $args[ $k ] ?? array();
			if ( 'n' === $role ) {
				continue;
			}
			if ( 1 !== count( $arg ) || ! is_array( $arg[0] ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0][0] ) {
				$values = null;
				break;
			}
			$values[ $role ] = memberkin_pot_unquote( $arg[0][1] );
		}
		if ( null === $values || MEMBERKIN_POT_DOMAIN !== ( $values['d'] ?? '' ) ) {
			continue;
		}
		$key = ( $values['c'] ?? '' ) . "\4" . $values['s'];
		$ref = substr( $path, strlen( $root ) + 1 ) . ':' . $t[2];
		$entries[ $key ]               ??= array(
			'context'  => $values['c'] ?? null,
			'singular' => $values['s'],
			'plural'   => $values['p'] ?? null,
			'refs'     => array(),
			'comments' => array(),
		);
		$entries[ $key ]['refs'][]       = $ref;
		if ( $comment && $t[2] - $comment[1] <= 3 ) {
			$entries[ $key ]['comments'][] = 'translators: ' . $comment[0];
		}
		$comment = null;
	}
}

$version = '';
if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents( $root . '/memberkin.php' ), $m ) ) {
	$version = $m[1];
}
$pot  = "# Copyright (C) Memberkin contributors\n# This file is distributed under the GPL-2.0-or-later.\n";
$pot .= "msgid \"\"\nmsgstr \"\"\n";
$pot .= '"Project-Id-Version: Memberkin ' . $version . "\\n\"\n";
$pot .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
$pot .= "\"X-Domain: " . MEMBERKIN_POT_DOMAIN . "\\n\"\n\"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n\"\n\n";
foreach ( $entries as $e ) {
	foreach ( array_unique( $e['comments'] ) as $c ) {
		$pot .= '#. ' . $c . "\n";
	}
	foreach ( array_chunk( $e['refs'], 4 ) as $chunk ) {
		$pot .= '#: ' . implode( ' ', $chunk ) . "\n";
	}
	if ( preg_match( '/%(\d+\$)?[-+ 0]*\d*(\.\d+)?[bcdeEfFgGosuxX]/', $e['singular'] . (string) $e['plural'] ) ) {
		$pot .= "#, php-format\n";
	}
	if ( null !== $e['context'] ) {
		$pot .= 'msgctxt ' . memberkin_pot_quote( $e['context'] ) . "\n";
	}
	$pot .= 'msgid ' . memberkin_pot_quote( $e['singular'] ) . "\n";
	if ( null !== $e['plural'] ) {
		$pot .= 'msgid_plural ' . memberkin_pot_quote( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$pot .= "msgstr \"\"\n\n";
	}
}
if ( ! is_dir( $root . '/languages' ) ) {
	mkdir( $root . '/languages' );
}
file_put_contents( $root . '/languages/' . MEMBERKIN_POT_DOMAIN . '.pot', $pot );
printf( "%d strings written to languages/%s.pot\n", count( $entries ), MEMBERKIN_POT_DOMAIN );
