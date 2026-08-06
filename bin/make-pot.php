<?php
/**
 * Minimal gettext template generator.
 *
 * Extracts translatable strings from the plugin PHP and JS sources into
 * languages/wc-tap-gateway.pot. Uses the PHP tokenizer rather than regular
 * expressions so that translation calls inside strings and comments are not
 * picked up.
 *
 * `wp i18n make-pot . languages/wc-tap-gateway.pot` produces a richer template
 * and should be preferred where WP-CLI is available; this script exists so the
 * template can be regenerated without it.
 *
 * Usage: php bin/make-pot.php
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

const TEXT_DOMAIN = 'wc-tap-gateway';

/** Functions whose first argument is a singular string. */
const SINGULAR_FUNCTIONS = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'wp_set_script_translations' );

/** Functions taking (singular, plural, ...). */
const PLURAL_FUNCTIONS = array( '_n', '_nx' );

/** Functions taking (text, context, domain). */
const CONTEXT_FUNCTIONS = array( '_x', 'esc_html_x', 'esc_attr_x' );

$root    = dirname( __DIR__ );
$strings = array();

/**
 * Record a string occurrence.
 *
 * @param array<string, array<string, mixed>> $strings   Accumulator.
 * @param string                              $text      Singular text.
 * @param string                              $plural    Plural text, if any.
 * @param string                              $context   Context, if any.
 * @param string                              $reference file:line.
 * @param string                              $comment   Translator comment.
 */
function record( array &$strings, string $text, string $plural, string $context, string $reference, string $comment ): void {
	if ( '' === $text ) {
		return;
	}

	$key = $context . "\4" . $text;

	if ( ! isset( $strings[ $key ] ) ) {
		$strings[ $key ] = array(
			'text'       => $text,
			'plural'     => $plural,
			'context'    => $context,
			'references' => array(),
			'comments'   => array(),
		);
	}

	$strings[ $key ]['references'][] = $reference;

	if ( '' !== $comment ) {
		$strings[ $key ]['comments'][ $comment ] = true;
	}
	if ( '' !== $plural ) {
		$strings[ $key ]['plural'] = $plural;
	}
}

/**
 * Escape a string for a PO file.
 *
 * @param string $value Raw value.
 * @return string
 */
function po_escape( string $value ): string {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\"', '\n', '\t', '\r' ),
		$value
	);
}

$directory = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

foreach ( $directory as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$path = $file->getPathname();

	if ( str_contains( $path, '/vendor/' ) || str_contains( $path, '/.git/' ) || str_contains( $path, '/bin/' ) ) {
		continue;
	}

	$relative = ltrim( str_replace( $root, '', $path ), '/' );
	$tokens   = token_get_all( (string) file_get_contents( $path ) );
	$count    = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$function = $token[1];
		$is_plural  = in_array( $function, PLURAL_FUNCTIONS, true );
		$is_context = in_array( $function, CONTEXT_FUNCTIONS, true );

		if ( ! in_array( $function, SINGULAR_FUNCTIONS, true ) && ! $is_plural && ! $is_context ) {
			continue;
		}

		// Skip method calls and declarations.
		$previous = $i > 0 ? $tokens[ $i - 1 ] : null;
		if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		// Collect the literal string arguments that follow.
		$arguments = array();
		$depth     = 0;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$argument_token = $tokens[ $j ];

			if ( '(' === $argument_token ) {
				++$depth;
				continue;
			}
			if ( ')' === $argument_token ) {
				--$depth;
				if ( 0 === $depth ) {
					break;
				}
				continue;
			}
			if ( 0 === $depth ) {
				break;
			}
			if ( is_array( $argument_token ) && T_CONSTANT_ENCAPSED_STRING === $argument_token[0] ) {
				$arguments[] = stripcslashes( substr( $argument_token[1], 1, -1 ) );
			}
		}

		if ( empty( $arguments ) ) {
			continue;
		}

		// Preceding translator comment, if any.
		$comment = '';
		for ( $k = $i - 1; $k >= 0 && $k > $i - 8; $k-- ) {
			if ( is_array( $tokens[ $k ] ) && T_COMMENT === $tokens[ $k ][0] && str_contains( $tokens[ $k ][1], 'translators:' ) ) {
				$comment = trim( str_replace( array( '/*', '*/', '//' ), '', $tokens[ $k ][1] ) );
				break;
			}
		}

		$line = (int) $token[2];

		if ( $is_plural ) {
			record( $strings, $arguments[0], $arguments[1] ?? '', '', "$relative:$line", $comment );
		} elseif ( $is_context ) {
			record( $strings, $arguments[0], '', $arguments[1] ?? '', "$relative:$line", $comment );
		} else {
			record( $strings, $arguments[0], '', '', "$relative:$line", $comment );
		}
	}
}

// JavaScript sources. The JS here only ever calls __( 'text', 'domain' ) with
// literal arguments, so a regular expression is sufficient; anything more
// elaborate should use `wp i18n make-pot`, which parses JS properly.
$js_files = glob( $root . '/assets/js/{,*/}*.js', GLOB_BRACE );

foreach ( (array) $js_files as $js_path ) {
	$relative = ltrim( str_replace( $root, '', (string) $js_path ), '/' );
	$source   = (string) file_get_contents( (string) $js_path );

	if ( ! preg_match_all( '/\b__\(\s*([\'"])(.*?)\1\s*,\s*([\'"])' . preg_quote( TEXT_DOMAIN, '/' ) . '\3\s*\)/s', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		continue;
	}

	foreach ( $matches as $match ) {
		$line = substr_count( substr( $source, 0, (int) $match[0][1] ), "\n" ) + 1;
		record( $strings, stripcslashes( $match[2][0] ), '', '', "$relative:$line", '' );
	}
}

ksort( $strings );

// Read the version from the plugin header rather than repeating it here, so
// the template cannot drift from the release it describes.
$plugin_version = '0.0.0';
if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents( $root . '/tap.php' ), $version_match ) ) {
	$plugin_version = $version_match[1];
}

$output  = "# Copyright (C) Tap Payments\n";
$output .= "# This file is distributed under the GPL-2.0-or-later license.\n";
$output .= "msgid \"\"\nmsgstr \"\"\n";
$output .= '"Project-Id-Version: WooCommerce Tap Payment Gateway ' . $plugin_version . "\\n\"\n";
$output .= "\"Report-Msgid-Bugs-To: https://github.com/Tap-Payments/Tap-WooCommerce-V2/issues\\n\"\n";
$output .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i' ) . "+0000\\n\"\n";
$output .= "\"MIME-Version: 1.0\\n\"\n";
$output .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$output .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$output .= "\"Plural-Forms: nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);\\n\"\n";
$output .= "\"X-Domain: " . TEXT_DOMAIN . "\\n\"\n\n";

foreach ( $strings as $entry ) {
	foreach ( array_keys( $entry['comments'] ) as $comment ) {
		$output .= '#. ' . trim( (string) $comment ) . "\n";
	}
	foreach ( array_unique( $entry['references'] ) as $reference ) {
		$output .= '#: ' . $reference . "\n";
	}
	if ( '' !== $entry['context'] ) {
		$output .= 'msgctxt "' . po_escape( $entry['context'] ) . "\"\n";
	}
	$output .= 'msgid "' . po_escape( $entry['text'] ) . "\"\n";
	if ( '' !== $entry['plural'] ) {
		$output .= 'msgid_plural "' . po_escape( $entry['plural'] ) . "\"\n";
		$output .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$output .= "msgstr \"\"\n\n";
	}
}

if ( ! is_dir( $root . '/languages' ) ) {
	mkdir( $root . '/languages', 0755, true );
}

file_put_contents( $root . '/languages/' . TEXT_DOMAIN . '.pot', $output );

echo 'Wrote ' . count( $strings ) . " strings to languages/" . TEXT_DOMAIN . ".pot\n";
