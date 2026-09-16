<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Pattern Builder Localization Class
 */
class Pattern_Builder_Localization {
	/**
	 * Localizes the pattern content.
	 *
	 * @param Abstract_Pattern $pattern The pattern to localize.
	 * @return Abstract_Pattern
	 */
	public static function localize_pattern_content( $pattern ) {
		$blocks           = parse_blocks( $pattern->content );
		$blocks           = self::localize_blocks( $blocks );
		$pattern->content = serialize_blocks( $blocks );
		$pattern->content = str_replace( '\u003c', '<', $pattern->content );
		$pattern->content = str_replace( '\u003e', '>', $pattern->content );

		return $pattern;
	}

	/**
	 * Recursively localizes blocks and their content.
	 *
	 * @param array $blocks Array of blocks to localize.
	 * @return array Localized blocks.
	 */
	private static function localize_blocks( $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( ! isset( $block['blockName'] ) || null === $block['blockName'] ) {
				continue;
			}
			switch ( $block['blockName'] ) {
				case 'core/paragraph':
				case 'core/heading':
				case 'core/list':
				case 'core/list-item':
				case 'core/quote':
				case 'core/verse':
				case 'core/preformatted':
					$block = self::localize_text_block( $block );
					break;

				case 'core/pullquote':
					$block = self::localize_pullquote_block( $block );
					break;

				case 'core/button':
					$block = self::localize_button_block( $block );
					break;

				case 'core/image':
					$block = self::localize_image_block( $block );
					break;

				case 'core/cover':
				case 'core/media-text':
					$block = self::localize_media_block( $block );
					break;

				case 'core/table':
					$block = self::localize_table_block( $block );
					break;

				case 'core/query-pagination-next':
				case 'core/query-pagination-previous':
				case 'core/comments-pagination-previous':
				case 'core/comments-pagination-next':
					$block = self::localize_query_pagination_block( $block );
					break;

				case 'core/post-excerpt':
					$block = self::localize_post_excerpt_block( $block );
					break;

				case 'core/details':
					$block = self::localize_details_block( $block );
					break;

				case 'core/search':
					$block = self::localize_search_block( $block );
					break;

			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::localize_blocks( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Localizes text content blocks (paragraph, heading, etc.).
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_text_block( $block ) {
		if ( ! empty( $block['innerHTML'] ) && ! empty( trim( wp_strip_all_tags( $block['innerHTML'] ) ) ) ) {
			$content = self::extract_text_content( $block['innerHTML'] );

			if ( ! empty( $content ) ) {
				$localized             = self::create_localized_string( $content );
				$block['innerHTML']    = str_replace( $content, $localized, $block['innerHTML'] );
				$block['innerContent'] = array( $block['innerHTML'] );
			}
		}

		return $block;
	}

	/**
	 * Localizes pullquote blocks with special handling for paragraph and citation content.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_pullquote_block( $block ) {
		if ( ! empty( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
			$html = preg_replace_callback(
				'/<p[^>]*>([^<]+)<\/p>/',
				function ( $matches ) {
					$paragraph_content = trim( $matches[1] );
					if ( ! empty( $paragraph_content ) ) {
						$localized = self::create_localized_string( $paragraph_content );
						return str_replace( $matches[1], $localized, $matches[0] );
					}
					return $matches[0];
				},
				$html
			);
			$html = preg_replace_callback(
				'/<cite[^>]*>([^<]+)<\/cite>/',
				function ( $matches ) {
					$cite_content = trim( $matches[1] );
					if ( ! empty( $cite_content ) ) {
						$localized = self::create_localized_string( $cite_content );
						return str_replace( $matches[1], $localized, $matches[0] );
					}
					return $matches[0];
				},
				$html
			);

			$block['innerHTML']    = $html;
			$block['innerContent'] = array( $html );
		}

		return $block;
	}

	/**
	 * Localizes button blocks.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_button_block( $block ) {
		if ( ! empty( $block['innerHTML'] ) ) {
			if ( preg_match( '/<a[^>]*>(.*?)<\/a>/s', $block['innerHTML'], $matches ) ) {
				$button_text = wp_strip_all_tags( $matches[1] );
				if ( ! empty( trim( $button_text ) ) ) {
					$localized             = self::create_localized_string( $button_text );
					$block['innerHTML']    = str_replace( '>' . $matches[1] . '<', '>' . $localized . '<', $block['innerHTML'] );
					$block['innerContent'] = array( $block['innerHTML'] );
				}
			}
		}

		return $block;
	}

	/**
	 * Localizes image blocks.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_image_block( $block ) {
		if ( ! empty( $block['innerHTML'] ) && strpos( $block['innerHTML'], '<figcaption' ) !== false ) {
			if ( preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/s', $block['innerHTML'], $matches ) ) {
				$caption = wp_strip_all_tags( $matches[1] );
				if ( ! empty( trim( $caption ) ) ) {
					$localized             = self::create_localized_string( $caption );
					$block['innerHTML']    = str_replace( '>' . $matches[1] . '<', '>' . $localized . '<', $block['innerHTML'] );
					$block['innerContent'] = array( $block['innerHTML'] );
				}
			}
		}
		if ( ! empty( $block['innerHTML'] ) && preg_match( '/alt="([^"]*)"/', $block['innerHTML'], $matches ) ) {
			$alt_text = $matches[1];
			if ( ! empty( trim( $alt_text ) ) ) {
				$localized_alt         = self::create_localized_string( $alt_text, 'esc_attr__' );
				$block['innerHTML']    = str_replace( 'alt="' . $alt_text . '"', 'alt="' . $localized_alt . '"', $block['innerHTML'] );
				$block['innerContent'] = array( $block['innerHTML'] );
			}
		}

		return $block;
	}

	/**
	 * Localizes media blocks (cover, media-text).
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_media_block( $block ) {
		if ( ! empty( $block['attrs']['alt'] ) ) {
			$block['attrs']['alt'] = self::create_localized_string( $block['attrs']['alt'], 'esc_attr__' );
		}

		return $block;
	}

	/**
	 * Localizes table blocks.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_table_block( $block ) {
		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML']    = preg_replace_callback(
				'/<t[dh]>([^<]+)<\/t[dh]>/',
				function ( $matches ) {
					if ( ! empty( trim( $matches[1] ) ) ) {
						return str_replace( $matches[1], self::create_localized_string( $matches[1] ), $matches[0] );
					}
					return $matches[0];
				},
				$block['innerHTML']
			);
			$block['innerContent'] = array( $block['innerHTML'] );
		}

		return $block;
	}

	/**
	 * Localizes query pagination blocks (next/previous).
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_query_pagination_block( $block ) {
		if ( ! empty( $block['attrs']['label'] ) ) {
			$label                   = $block['attrs']['label'];
			$localized_label         = self::create_localized_string( $label, 'esc_attr__' );
			$block['attrs']['label'] = $localized_label;
		}

		return $block;
	}

	/**
	 * Localizes post excerpt blocks.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_post_excerpt_block( $block ) {
		if ( ! empty( $block['attrs']['moreText'] ) ) {
			$more_text                  = $block['attrs']['moreText'];
			$localized_more_text        = self::create_localized_string( $more_text, 'esc_attr__' );
			$block['attrs']['moreText'] = $localized_more_text;
		}

		return $block;
	}

	/**
	 * Localizes details blocks with special handling for summary content.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_details_block( $block ) {
		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = preg_replace_callback(
				'/<summary[^>]*>([^<]+)<\/summary>/',
				function ( $matches ) {
					$summary_content = trim( $matches[1] );
					if ( ! empty( $summary_content ) ) {
						$localized = self::create_localized_string( $summary_content );
						return str_replace( $matches[1], $localized, $matches[0] );
					}
					return $matches[0];
				},
				$block['innerHTML']
			);
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $index => $content ) {
					if ( is_string( $content ) && strpos( $content, '<summary' ) !== false ) {
						$block['innerContent'][ $index ] = preg_replace_callback(
							'/<summary[^>]*>([^<]+)<\/summary>/',
							function ( $matches ) {
								$summary_content = trim( $matches[1] );
								if ( ! empty( $summary_content ) ) {
									$localized = self::create_localized_string( $summary_content );
									return str_replace( $matches[1], $localized, $matches[0] );
								}
								return $matches[0];
							},
							$content
						);
						break;
					}
				}
			}
		}

		return $block;
	}

	/**
	 * Localizes search blocks.
	 *
	 * @param array $block Block to localize.
	 * @return array Localized block.
	 */
	private static function localize_search_block( $block ) {
		$localizable_attributes = array( 'label', 'placeholder', 'buttonText' );

		foreach ( $localizable_attributes as $attribute ) {
			if ( ! empty( $block['attrs'][ $attribute ] ) ) {
				$text                         = $block['attrs'][ $attribute ];
				$localized_text               = self::create_localized_string( $text, 'esc_attr__' );
				$block['attrs'][ $attribute ] = $localized_text;
			}
		}

		return $block;
	}

	/**
	 * Extracts text content from HTML, preserving the structure.
	 *
	 * @param string $html HTML to extract text from.
	 * @return string Extracted text content.
	 */
	private static function extract_text_content( $html ) {
		$html    = preg_replace( '/^<[^>]+>/', '', trim( $html ) );
		$html    = preg_replace( '/<\/[^>]+>$/', '', $html );
		$content = trim( $html );
		return ! empty( $content ) ? $html : '';
	}

	/**
	 * Creates a localized string with proper escaping.
	 *
	 * @param string $text Text to localize.
	 * @param string $escape_function WordPress escape/localization function to use (e.g.
	 * 'wp_kses_post', 'esc_attr__').
	 * @return string Localized string in PHP format.
	 */
	private static function create_localized_string( $text, $escape_function = 'wp_kses_post' ) {
		$escaped_text = str_replace( "'", "\\'", $text );
		$text_domain  = get_stylesheet();
		return "<?php echo {$escape_function}( '{$escaped_text}', '{$text_domain}' ); ?>";
	}
}
