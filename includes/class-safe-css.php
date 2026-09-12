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
 *
 * A theme.json `styles` tree can hold a `css` property carrying literal CSS,
 * and it is the only way a block style variation expresses a pseudo-element, a
 * descendant rule or a hover state — which is most of what a variation is for.
 * WordPress does not sanitize that property at all: core gates it on the
 * `edit_css` capability instead and says so in a comment beside the check. So
 * a string arriving over the wire cannot be trusted to core, and this is the
 * check that decides whether one may be written, carried or installed.
 *
 * It is a **grammar**, not a filter. Nothing here strips, repairs or escapes:
 * a string either fits the subset and is written through unchanged, or it is
 * refused with the rule it broke and the fragment that broke it. Repairing is
 * how a checker and a browser come to disagree, and a disagreement is the
 * whole vulnerability.
 *
 * ### The shape
 *
 * Declarations first, then nested rules anchored on `&`:
 *
 *     position: relative; overflow: hidden;
 *     & > * { z-index: 1; }
 *     &::before { content: ""; inset: 0; }
 *
 * That shape is not a style choice — it is the most core's own parser can
 * read. `WP_Theme_JSON::process_blocks_custom_css()` splits the string on `&`,
 * strips every `}`, then `explode( '{', … )` and skips any part that does not
 * yield exactly two pieces. Everything this class accepts is a strict subset
 * of what that method parses **correctly**, so that what was validated is what
 * core emits. Three consequences a reader will otherwise find surprising:
 *
 * - A top-level declaration may not follow a nested rule. Core folds it into
 *   the rule above it (`&:hover { a } b: c;` emits `b: c` inside `:hover`),
 *   so the two would disagree about what was approved.
 * - `&` may appear only as the character that opens a nested rule. Core splits
 *   on every one of them, including one inside a quoted string.
 * - A nested rule may not contain another. Core drops the outer rule's body
 *   and promotes the inner one, silently.
 *
 * ### The bans
 *
 * `<` is refused everywhere, quoted strings included, because the HTML
 * tokenizer ends a `<style>` element at `</style` whatever CSS thinks a string
 * is. `@` is refused everywhere: core's parser cannot place an at-rule here in
 * any case, so a media query in variation CSS does not work today. `/*` is
 * refused rather than stripped, because a checker that strips comments must
 * strip them exactly as a browser does and that is a worse bet than refusing.
 * A backslash is refused outside a quoted string, where it could spell
 * `u\72l(` past a name check, and allowed inside one, where `content: "\2713"`
 * is how an icon glyph is written.
 *
 * Every `name(` in a value is checked against an allow list. Unknown is
 * refused, never ignored — that is what keeps `url()`, `image-set()` and
 * `expression()` out without having to enumerate them.
 *
 * ### Two copies, on purpose
 *
 * This class exists twice: here, and in patternbuilderwp.com as
 * `includes/patterns/class-safe-css.php`. The check runs three times over a
 * pattern's life — on the authoring site when the CSS is written, on the
 * service when a package arrives, and on the destination site when a package
 * is installed — and the third is the one that matters, because it is the
 * machine that will execute the CSS. The two files must stay logic-identical;
 * a disagreement between them is the vulnerability. They differ in their
 * namespace and nothing else, which is why the refusals below carry no text
 * domain: a translated message is the one thing the two copies could not
 * share, and these name a grammar rule and a CSS fragment for whoever wrote
 * them rather than addressing a site's visitors.
 */
class Safe_Css {

	/**
	 * How much CSS one variation may carry, in bytes.
	 *
	 * The longest of this design system's own is under 600.
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

		/*
		 * Control bytes, which nothing legitimate writes and which a CSS
		 * tokenizer substitutes or ignores rather than reading literally —
		 * the one way a checker and a browser can be looking at different
		 * text. Tab, newline and carriage return are the whitespace a
		 * pretty-printed string has, and stay.
		 */
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css, $matches, PREG_OFFSET_CAPTURE ) ) {
			return self::refuse(
				sprintf( 'a control character (byte 0x%02X) is not allowed in variation CSS', ord( $matches[0][0] ) ),
				self::around( $css, $matches[0][1] )
			);
		}

		/*
		 * The three bans that hold inside quoted strings as well, so they are
		 * answered here rather than anywhere the parser has to reason about
		 * quoting. `<` is the one rule with no exceptions at all.
		 */
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

				++$offset; // The anchor itself.
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

			/*
			 * Core folds a declaration written after a nested rule into that
			 * rule, so accepting one would approve something other than what
			 * gets emitted. Move it above the first `&` instead.
			 */
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
	 * @param string $css    The CSS.
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
	 * The cursor is left **on** a closing `}` rather than past it, so the rule
	 * that opened the block is the thing that closes it.
	 *
	 * @param string $css    The CSS.
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
			return true; // An empty declaration — a stray or doubled `;`.
		}

		/*
		 * Quoted or not: core's parser is not quote-aware, so any of these
		 * inside a string would still be read as structure and split the
		 * emitted CSS somewhere nobody intended.
		 */
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

		/*
		 * One expression for all three shapes a property name takes: a plain
		 * one (`color`), a vendor-prefixed one (`-webkit-mask-image`) and a
		 * custom property (`--accent`).
		 */
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
		/*
		 * Quoted runs are blanked before the rest is read, because what is
		 * inside them is text rather than syntax: `content: "\75 rl(x)"` is a
		 * string that spells "url(x)" and fetches nothing, and the checks
		 * below would otherwise read it as a function call.
		 */
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

		/*
		 * `!important` and nothing else: a lone `!` is either a typo or an
		 * attempt at something this grammar has not thought of.
		 */
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

		/*
		 * Every parenthesis has to belong to one of those calls. A `(` with no
		 * function name in front of it is either nonsense or a name spelled in
		 * a way the scan above did not read as one, and neither is worth
		 * guessing at.
		 */
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
	 * Everything after the `&`, which core appends to — or scopes under — the
	 * variation's own selector.
	 *
	 * @param string $selector The selector, without its anchoring `&`.
	 * @return true|WP_Error
	 */
	private static function selector( $selector ) {
		/*
		 * A flat allow list, so what is missing is as deliberate as what is
		 * here. `,` because core's `scope_selector()` distributes over a
		 * comma and `body` written after one lands at the top level, styling
		 * the destination's whole document. `+` and `~` because a sibling
		 * combinator reaches outside the block the pattern's markup carries,
		 * which is the guarantee that makes a variation portable at all. `&`
		 * because core splits on every one of them. The rest — `{`, `}`, `@`,
		 * `\`, `<`, `!` — because they are structure, and a selector is not
		 * the place to write any.
		 */
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
	 * Held lower-cased because CSS function names are case-insensitive and the
	 * comparison is made that way; the mixed-case spellings are
	 * `translateX`/`Y`/`Z`, `translate3d`, `scaleX`/`Y`, `rotateX`/`Y`/`Z`,
	 * `skewX`/`Y` and `matrix3d`.
	 *
	 * A token grammar cannot stand in for this list: these variations
	 * legitimately use `var()`, `radial-gradient()`, `rgba()`, multi-value
	 * `box-shadow`, `transition` and `transform: translateY()`.
	 *
	 * `url`, `image`, `image-set`, `element`, `expression`, `attr` and `src`
	 * are absent on purpose, and so is everything else: the list is an allow
	 * list precisely so that a function nobody here has considered is refused
	 * rather than waved through.
	 *
	 * @return string[]
	 */
	private static function value_functions() {
		return array(
			// References and arithmetic.
			'var',
			'calc',
			'min',
			'max',
			'clamp',
			'env',
			// Colour.
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
			// Gradients.
			'linear-gradient',
			'radial-gradient',
			'conic-gradient',
			'repeating-linear-gradient',
			'repeating-radial-gradient',
			'repeating-conic-gradient',
			// Transforms.
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
			// Timing.
			'cubic-bezier',
			'steps',
			// Sizing and grids.
			'fit-content',
			'minmax',
			'repeat',
			// Filters.
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
	 * The ban on `,` makes the multi-argument forms unusable, which is a known
	 * limitation rather than an oversight: a comma in this position escapes
	 * the variation's scope entirely, and that costs more than `:is(a, b)` is
	 * worth.
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
	 * @param string $text  The text to scan.
	 * @param int    $from  Where to start.
	 * @param string $stops The characters to stop at.
	 * @return int|null|WP_Error The offset, null at the end of the text, or an unterminated string.
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
	 * A backslash takes the next byte with it, which is what keeps `\'` from
	 * reading as the end of a string and what lets `content: "\2713"` through.
	 *
	 * @param string $text  The text to scan.
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
	 * Each run between quotes, and the quotes themselves, become spaces, so
	 * offsets and word boundaries survive and the contents stop being read as
	 * syntax.
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
	 * @param string $text   The text.
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
	 * An agent told "raw CSS is not accepted" learns nothing; one told which
	 * function it reached for, in which declaration, fixes it and moves on.
	 *
	 * @param string $reason   What the rule is.
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
	 * An excerpt is cut out of the CSS by byte offset, so it can start or end
	 * half-way through a character — and a message that is not valid UTF-8
	 * cannot be JSON-encoded, which would turn a clear refusal into an empty
	 * response. `preg_match( '//u', … )` returns false rather than 0 on
	 * invalid UTF-8, which is what makes this a validity test.
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
