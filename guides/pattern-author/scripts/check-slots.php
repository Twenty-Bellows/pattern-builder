<?php
/**
 * Check that a page pattern's slot values actually reach the design pattern.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run this through WP-CLI: wp eval-file check-slots.php <file>\n";
	exit( 1 );
}

/**
 * Pull every `wp:pattern` reference that carries content out of some markup.
 *
 * @param string $markup Page pattern markup.
 * @return array List of [ slug, content array ].
 */
function pb_slot_refs( $markup ) {
	$refs = array();
	if ( preg_match_all( '/<!--\s*wp:pattern\s+(\{.*?\})\s*\/-->/s', $markup, $matches ) ) {
		foreach ( $matches[1] as $raw ) {
			$attrs = json_decode( $raw, true );
			if ( ! is_array( $attrs ) || empty( $attrs['slug'] ) ) {
				$refs[] = array( null, null, $raw );
				continue;
			}
			if ( ! empty( $attrs['content'] ) && is_array( $attrs['content'] ) ) {
				$refs[] = array( $attrs['slug'], $attrs['content'], $raw );
			}
		}
	}
	return $refs;
}

/**
 * Every value a slot was given, flattened to the strings that should appear.
 *
 * @param array $content The `content` attribute.
 * @return array slot name => list of expected strings.
 */
function pb_expected( $content ) {
	$out = array();
	foreach ( $content as $slot => $value ) {
		$strings = array();
		if ( is_array( $value ) ) {
			foreach ( array( 'content', 'text', 'alt' ) as $key ) {
				if ( ! empty( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
					$strings[] = $value[ $key ];
				}
			}
		} elseif ( is_string( $value ) ) {
			$strings[] = $value;
		}
		if ( $strings ) {
			$out[ $slot ] = $strings;
		}
	}
	return $out;
}
$args   = $args ?? array();
$checks = array();

if ( empty( $args[0] ) ) {
	WP_CLI::error( 'Usage: wp eval-file check-slots.php <page-pattern.php>   |   <pattern/slug> \'{"slot":{"content":"…"}}\'' );
}

if ( file_exists( $args[0] ) ) {
	$checks = pb_slot_refs( file_get_contents( $args[0] ) );
	if ( ! $checks ) {
		WP_CLI::warning( 'No wp:pattern references with content found — nothing to check.' );
		exit( 0 );
	}
} else {
	if ( empty( $args[1] ) ) {
		WP_CLI::error( 'Give the slot values as JSON: wp eval-file check-slots.php <slug> \'{"slot":{"content":"…"}}\'' );
	}
	$content = json_decode( $args[1], true );
	if ( ! is_array( $content ) ) {
		WP_CLI::error( 'That JSON did not parse.' );
	}
	$checks[] = array( $args[0], $content, '' );
}

$registry = WP_Block_Patterns_Registry::get_instance();
$problems = 0;

foreach ( $checks as list( $slug, $content, $raw ) ) {
	if ( null === $slug ) {
		WP_CLI::log( "MALFORMED reference — its attributes do not parse, so the slug is lost with them and the whole section renders as nothing:\n  " . substr( $raw, 0, 120 ) );
		++$problems;
		continue;
	}

	WP_CLI::log( "\n" . $slug );

	if ( ! $registry->is_registered( $slug ) ) {
		WP_CLI::log( '  NOT REGISTERED on this site — the reference renders as nothing at all.' );
		++$problems;
		continue;
	}

	$pattern     = $registry->get_registered( $slug );
	$placeholder = isset( $pattern['content'] ) ? $pattern['content'] : '';

	$markup   = sprintf(
		'<!-- wp:pattern %s /-->',
		wp_json_encode(
			array(
				'slug'    => $slug,
				'content' => $content,
			)
		)
	);
	$rendered = do_blocks( $markup );

	if ( '' === trim( wp_strip_all_tags( $rendered ) ) ) {
		WP_CLI::log( '  rendered EMPTY — is the pattern runtime active on this site?' );
		++$problems;
		continue;
	}

	foreach ( pb_expected( $content ) as $slot => $strings ) {
		$found = false;
		foreach ( $strings as $string ) {
			if ( false !== strpos( $rendered, $string ) ) {
				$found = true;
				break;
			}
		}

		if ( $found ) {
			WP_CLI::log( "  ok      {$slot}" );
			continue;
		}

		++$problems;
		$hint = '';
		if ( $placeholder && false === strpos( $placeholder, '"' . $slot . '"' ) ) {
			$hint = ' — no slot by that name in the design pattern (typo?)';
		}
		WP_CLI::log( "  MISSED  {$slot}{$hint}" );
	}
	if ( $placeholder ) {
		preg_match_all( '/>([^<>]{12,})</', $placeholder, $phrases );
		foreach ( array_slice( array_unique( $phrases[1] ), 0, 12 ) as $phrase ) {
			$phrase = trim( $phrase );
			if ( '' !== $phrase && false !== strpos( $rendered, $phrase ) ) {
				WP_CLI::log( '  placeholder still showing: "' . substr( $phrase, 0, 60 ) . '"' );
			}
		}
	}
}

WP_CLI::log( '' );
if ( $problems ) {
	WP_CLI::error( $problems . ' slot problem(s). Every one of these renders without an error anywhere — the page just ships the wrong words.' );
}
WP_CLI::success( 'Every slot resolved.' );
