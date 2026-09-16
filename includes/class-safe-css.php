<?php
/**
 * The safe subset of CSS a block style variation may carry.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a `css` property may contain, and why so little of it.
 */
class Safe_Css {
	/**
	 * How much CSS one variation may carry, in bytes.
	 */
	const MAX_LENGTH = 2000;

	/**
	 * How many nested rules one variation may carry.
	 */
	const MAX_RULES = 20;

	/**
	 * How many declarations one rule — or the top-level list — may carry.
	 */
	const MAX_DECLARATIONS = 40;

	/**
	 * How much of an offending fragment a refusal quotes back.
	 */
	const MAX_EXCERPT = 80;

	/**
	 * Whether a string is CSS this subset accepts.
	 *
	 * @param mixed $css The `css` property's value.
	 * @return true|WP_Error True, or the rule it broke and the fragment that broke it.
	 */
	public static function check( $css ) {
		if ( ! is_string( $css ) ) {
			return self::refuse( 'a "css" property must be a string' );
		}

		if ( strlen( $css ) > self::MAX_LENGTH ) {
			return self::refuse(
				sprintf( 'this CSS is %1$d characters and the limit is %2$d', strlen( $css ), self::MAX_LENGTH )
			);
		}

		/*
		 * Text a byte at a time from here on, so it has to *be* text. An
		 * invalid sequence is not a security hole in itself — a browser reads
		 * one as U+FFFD — but `wp_json_encode()` silently drops it on the way
		 * into the partial file, so what was checked would not be what got
		 * written, and that gap is the thing this class exists to close.
		 */
		if ( 1 !== preg_match( '//u', $css ) ) {
			return self::refuse( 'this CSS is not valid UTF-8' );
		}
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css, $matches, PREG_OFFSET_CAPTURE ) ) {
			return self::refuse(
				sprintf( 'a control character (byte 0x%02X) is not allowed in variation CSS', ord( $matches[0][0] ) ),
				self::around( $css, $matches[0][1] )
			);
		}
		foreach ( self::banned_everywhere() as $fragment => $reason ) {
			$at = strpos( $css, $fragment );
			if ( false !== $at ) {
				return self::refuse( $reason, self::around( $css, $at ) );
			}
		}

		return self::parse( $css );
	}

	/**
	 * Fragments no variation CSS may contain, anywhere, and why.
	 *
	 * @return array Fragment => reason.
	 */
	private static function banned_everywhere() {
		return array(
			'<'  => '"<" is not allowed anywhere in variation CSS, because "</style" ends the style element whatever CSS thinks a quoted string is',
			'@'  => '"@" is not allowed: an at-rule cannot be placed here — core\'s own parser mangles one — so @media, @import and the rest are outside this subset',
			'/*' => 'a comment is not allowed: comments are refused rather than stripped, because stripping them correctly means stripping them exactly as a browser does',
		);
	}

	/**
	 * Walk the whole string as a sequence of declarations and nested rules.
	 *
	 * @param string $css The CSS.
	 * @return true|WP_Error
	 */
	private static function parse( $css ) {
		$length       = strlen( $css );
		$offset       = 0;
		$rules        = 0;
		$declarations = 0;
		$seen_rule    = false;

		while ( $offset < $length ) {
			if ( ctype_space( $css[ $offset ] ) ) {
				++$offset;
				continue;
			}

			if ( '&' === $css[ $offset ] ) {
				++$rules;
				if ( $rules > self::MAX_RULES ) {
					return self::refuse(
						sprintf( 'this CSS has more than %d nested rules', self::MAX_RULES )
					);
				}

				++$offset;
				$rule = self::rule( $css, $offset );
				if ( is_wp_error( $rule ) ) {
					return $rule;
				}

				$seen_rule = true;
				continue;
			}

			if ( '}' === $css[ $offset ] ) {
				return self::refuse(
					'there is a "}" here with no rule open — a declaration cannot close a block',
					self::around( $css, $offset )
				);
			}
			if ( $seen_rule ) {
				return self::refuse(
					'a declaration must come before the first nested rule — WordPress folds a later one into the rule above it',
					substr( $css, $offset )
				);
			}

			++$declarations;
			if ( $declarations > self::MAX_DECLARATIONS ) {
				return self::refuse(
					sprintf( 'this CSS has more than %d declarations before its first nested rule', self::MAX_DECLARATIONS )
				);
			}

			$declaration = self::declaration( $css, $offset );
			if ( is_wp_error( $declaration ) ) {
				return $declaration;
			}
		}

		return true;
	}

	/**
	 * One nested rule, from just past its `&` to just past its `}`.
	 *
	 * @param string $css The CSS.
	 * @param int    $offset Cursor, advanced past the rule.
	 * @return true|WP_Error
	 */
	private static function rule( $css, &$offset ) {
		$length = strlen( $css );
		$opens  = self::scan( $css, $offset, '{' );
		if ( is_wp_error( $opens ) ) {
			return $opens;
		}
		if ( null === $opens ) {
			return self::refuse(
				'a nested rule needs a { … } body',
				substr( $css, $offset )
			);
		}

		$selector = self::selector( substr( $css, $offset, $opens - $offset ) );
		if ( is_wp_error( $selector ) ) {
			return $selector;
		}

		$offset       = $opens + 1;
		$declarations = 0;

		while ( $offset < $length ) {
			if ( ctype_space( $css[ $offset ] ) ) {
				++$offset;
				continue;
			}

			if ( '}' === $css[ $offset ] ) {
				++$offset;
				return true;
			}

			if ( '&' === $css[ $offset ] || '{' === $css[ $offset ] ) {
				return self::refuse(
					'a nested rule may not contain another rule — WordPress reads one level and drops the rest',
					self::around( $css, $offset )
				);
			}

			++$declarations;
			if ( $declarations > self::MAX_DECLARATIONS ) {
				return self::refuse(
					sprintf( 'a nested rule has more than %d declarations', self::MAX_DECLARATIONS )
				);
			}

			$declaration = self::declaration( $css, $offset );
			if ( is_wp_error( $declaration ) ) {
				return $declaration;
			}
		}

		return self::refuse( 'a nested rule was never closed with a "}"' );
	}

	/**
	 * One declaration, from its property to just past its `;`.
	 *
	 * @param string $css The CSS.
	 * @param int    $offset Cursor, advanced past the declaration.
	 * @return true|WP_Error
	 */
	private static function declaration( $css, &$offset ) {
		$start = $offset;
		$stop  = self::scan( $css, $offset, ';{}' );
		if ( is_wp_error( $stop ) ) {
			return $stop;
		}

		if ( null === $stop ) {
			$text   = substr( $css, $start );
			$offset = strlen( $css );
		} else {
			$text = substr( $css, $start, $stop - $start );

			if ( '{' === $css[ $stop ] ) {
				return self::refuse(
					'a nested rule\'s selector must start with "&", which is what scopes it to the blocks carrying this variation\'s class',
					substr( $css, $start, ( $stop - $start ) + 1 )
				);
			}

			$offset = ( ';' === $css[ $stop ] ) ? $stop + 1 : $stop;
		}

		return self::declares( $text );
	}

	/**
	 * Whether one `property: value` pair is one this subset accepts.
	 *
	 * @param string $text The declaration, without its terminating `;`.
	 * @return true|WP_Error
	 */
	private static function declares( $text ) {
		if ( '' === trim( $text ) ) {
			return true;
		}
		foreach ( array( '&', '{', '}', ';' ) as $character ) {
			if ( false !== strpos( $text, $character ) ) {
				return self::refuse(
					sprintf( '"%s" is not allowed inside a declaration, in a quoted string or out of one', $character ),
					$text
				);
			}
		}

		$colon = self::scan( $text, 0, ':' );
		if ( is_wp_error( $colon ) ) {
			return $colon;
		}
		if ( null === $colon ) {
			return self::refuse( 'this is not a declaration — a declaration is "property: value"', $text );
		}

		$property = trim( substr( $text, 0, $colon ) );
		$value    = trim( substr( $text, $colon + 1 ) );
		if ( ! preg_match( '/^(?:--?)?[A-Za-z][A-Za-z0-9_-]*$/', $property ) ) {
			return self::refuse( 'this is not a CSS property name', $property );
		}

		if ( '' === $value ) {
			return self::refuse( 'this property has no value', $text );
		}

		return self::value( $value );
	}

	/**
	 * Whether one declaration value is one this subset accepts.
	 *
	 * @param string $value The value, trimmed.
	 * @return true|WP_Error
	 */
	private static function value( $value ) {
		$bare = self::blank_strings( $value );
		if ( is_wp_error( $bare ) ) {
			return $bare;
		}

		$backslash = strpos( $bare, '\\' );
		if ( false !== $backslash ) {
			return self::refuse(
				'a backslash is not allowed outside a quoted string, where an escape can spell a banned name past a check — "u\\72l(" is "url("',
				$value
			);
		}
		if ( preg_match( '/!(?!\s*important\b)/i', $bare ) ) {
			return self::refuse( '"!" is only allowed as "!important"', $value );
		}

		$allowed = self::value_functions();
		$found   = 0;

		if ( preg_match_all( '/([A-Za-z][A-Za-z0-9_-]*)\s*\(/', $bare, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				++$found;
				if ( ! in_array( strtolower( $match[1] ), $allowed, true ) ) {
					return self::refuse(
						sprintf( '%s( is not on the list of CSS functions a variation may use', $match[1] ),
						$value
					);
				}
			}
		}
		$opens  = substr_count( $bare, '(' );
		$closes = substr_count( $bare, ')' );
		if ( $opens !== $found || $opens !== $closes ) {
			return self::refuse( 'the parentheses here do not spell a CSS function call', $value );
		}

		return true;
	}

	/**
	 * Whether one nested rule's selector is one this subset accepts.
	 *
	 * @param string $selector The selector, without its anchoring `&`.
	 * @return true|WP_Error
	 */
	private static function selector( $selector ) {
		if ( preg_match( '/[^A-Za-z0-9_\-\s>.#:*\[\]=\'"()]/', $selector, $matches, PREG_OFFSET_CAPTURE ) ) {
			return self::refuse(
				sprintf( '"%s" is not allowed in a nested rule\'s selector', $matches[0][0] ),
				'&' . $selector
			);
		}

		$allowed = self::selector_functions();
		$offset  = 0;

		while ( true ) {
			$paren = strpos( $selector, '(', $offset );
			if ( false === $paren ) {
				break;
			}

			if ( ! preg_match( '/:([A-Za-z-]+)$/', substr( $selector, 0, $paren ), $matches ) || ! in_array( strtolower( $matches[1] ), $allowed, true ) ) {
				return self::refuse(
					'a parenthesis in a selector has to belong to one of the selector functions this subset allows',
					'&' . $selector
				);
			}

			$offset = $paren + 1;
		}

		if ( substr_count( $selector, '(' ) !== substr_count( $selector, ')' ) ) {
			return self::refuse( 'the parentheses in this selector are not balanced', '&' . $selector );
		}

		return true;
	}

	/**
	 * The CSS functions a value may call.
	 *
	 * @return string[]
	 */
	private static function value_functions() {
		return array(
			'var',
			'calc',
			'min',
			'max',
			'clamp',
			'env',
			'rgb',
			'rgba',
			'hsl',
			'hsla',
			'hwb',
			'lab',
			'lch',
			'oklab',
			'oklch',
			'color-mix',
			'linear-gradient',
			'radial-gradient',
			'conic-gradient',
			'repeating-linear-gradient',
			'repeating-radial-gradient',
			'repeating-conic-gradient',
			'translate',
			'translatex',
			'translatey',
			'translatez',
			'translate3d',
			'scale',
			'scalex',
			'scaley',
			'rotate',
			'rotatex',
			'rotatey',
			'rotatez',
			'skew',
			'skewx',
			'skewy',
			'matrix',
			'matrix3d',
			'perspective',
			'cubic-bezier',
			'steps',
			'fit-content',
			'minmax',
			'repeat',
			'blur',
			'brightness',
			'contrast',
			'drop-shadow',
			'grayscale',
			'hue-rotate',
			'invert',
			'opacity',
			'saturate',
			'sepia',
		);
	}

	/**
	 * The selector functions a nested rule may use.
	 *
	 * @return string[]
	 */
	private static function selector_functions() {
		return array(
			'not',
			'is',
			'where',
			'has',
			'nth-child',
			'nth-last-child',
			'nth-of-type',
			'nth-last-of-type',
		);
	}

	/**
	 * The offset of the first of these characters that is not inside a string.
	 *
	 * @param string $text The text to scan.
	 * @param int    $from Where to start.
	 * @param string $stops The characters to stop at.
	 * @return int|null|WP_Error The offset, null at the end of the text, or an unterminated
	 * string.
	 */
	private static function scan( $text, $from, $stops ) {
		$length = strlen( $text );

		for ( $offset = $from; $offset < $length; $offset++ ) {
			$character = $text[ $offset ];

			if ( '"' === $character || "'" === $character ) {
				$closes = self::string_end( $text, $offset );
				if ( is_wp_error( $closes ) ) {
					return $closes;
				}
				$offset = $closes;
				continue;
			}

			if ( false !== strpos( $stops, $character ) ) {
				return $offset;
			}
		}

		return null;
	}

	/**
	 * The offset of the quote that closes the string opened at `$opens`.
	 *
	 * @param string $text The text to scan.
	 * @param int    $opens Offset of the opening quote.
	 * @return int|WP_Error
	 */
	private static function string_end( $text, $opens ) {
		$quote  = $text[ $opens ];
		$length = strlen( $text );

		for ( $offset = $opens + 1; $offset < $length; $offset++ ) {
			$character = $text[ $offset ];

			if ( '\\' === $character ) {
				++$offset;
				continue;
			}

			if ( "\n" === $character || "\r" === $character ) {
				return self::refuse(
					'a quoted string may not run across a line break',
					substr( $text, $opens )
				);
			}

			if ( $quote === $character ) {
				return $offset;
			}
		}

		return self::refuse( 'a quoted string was never closed', substr( $text, $opens ) );
	}

	/**
	 * The same text with every quoted string blanked out.
	 *
	 * @param string $text The text.
	 * @return string|WP_Error
	 */
	private static function blank_strings( $text ) {
		$length = strlen( $text );
		$bare   = '';

		for ( $offset = 0; $offset < $length; $offset++ ) {
			$character = $text[ $offset ];

			if ( '"' !== $character && "'" !== $character ) {
				$bare .= $character;
				continue;
			}

			$closes = self::string_end( $text, $offset );
			if ( is_wp_error( $closes ) ) {
				return $closes;
			}

			$bare  .= str_repeat( ' ', ( $closes - $offset ) + 1 );
			$offset = $closes;
		}

		return $bare;
	}

	/**
	 * A window of text around an offset, for a refusal to quote back.
	 *
	 * @param string $text The text.
	 * @param int    $offset Where the trouble is.
	 * @return string
	 */
	private static function around( $text, $offset ) {
		$from = max( 0, $offset - 20 );

		return substr( $text, $from, self::MAX_EXCERPT );
	}

	/**
	 * A refusal naming the rule that was broken and the fragment that broke it.
	 *
	 * @param string $reason What the rule is.
	 * @param string $fragment The offending CSS, if there is a useful piece of it.
	 * @return WP_Error
	 */
	private static function refuse( $reason, $fragment = '' ) {
		$excerpt = trim( (string) preg_replace( '/\s+/', ' ', (string) $fragment ) );

		if ( strlen( $excerpt ) > self::MAX_EXCERPT ) {
			$excerpt = substr( $excerpt, 0, self::MAX_EXCERPT - 1 );
			$excerpt = self::whole_characters( $excerpt ) . '…';
		} else {
			$excerpt = self::whole_characters( $excerpt );
		}

		return new WP_Error(
			'pb_unsafe_css',
			'' === $excerpt ? $reason . '.' : $reason . ': ' . $excerpt,
			array( 'status' => 400 )
		);
	}

	/**
	 * The same text with any partial multi-byte character at either end gone.
	 *
	 * @param string $text The excerpt.
	 * @return string
	 */
	private static function whole_characters( $text ) {
		$text = (string) preg_replace( '/^[\x80-\xBF]+/', '', $text );

		while ( '' !== $text && 1 !== preg_match( '//u', $text ) ) {
			$text = substr( $text, 0, -1 );
		}

		return $text;
	}
}
