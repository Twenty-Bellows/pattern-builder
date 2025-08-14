<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Pattern Builder Localization Class
 *
 * Handles localization of pattern content by wrapping translatable strings
 * with appropriate WordPress localization functions.
 */
class Pattern_Builder_Localization {

	/**
	 * Localizes the pattern content.
	 *
	 * Parse the pattern content. Loop through the blocks recursively and replace any
	 * text or html content with PHP to escape and localize the content.
	 * The PHP would be in a format like <?php echo esc_html__( 'Your text here', 'text-domain' ); ?>
	 * Some blocks have attributes that need to be localized as well, such as the alt text for images.
	 *
	 * @param Abstract_Pattern $pattern The pattern to localize.
	 * @return Abstract_Pattern
	 */
	public static function localize_pattern_content( $pattern ) {
		// Parse the pattern content into blocks.
		$blocks = parse_blocks( $pattern->content );

		// Process blocks recursively to localize content.
		$blocks = self::localize_blocks( $blocks );

		// Serialize blocks back to content.
		$pattern->content = serialize_blocks( $blocks );

		// Fix encoded PHP tags that get encoded during serialization.
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
			// Skip null blocks or blocks without a name.
			if ( ! isset( $block['blockName'] ) || $block['blockName'] === null ) {
				continue;
			}

			// Process block based on its type.
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

			// Process inner blocks recursively.
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
			// Extract the text content from innerHTML.
			$content = self::extract_text_content( $block['innerHTML'] );

			if ( ! empty( $content ) ) {
				// Replace the content with localized version.
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

			// Localize paragraph content(s) within the blockquote
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

			// Localize citation content
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
			// Extract button text from the anchor tag.
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
		// Note: For attributes, we don't localize them directly in the attrs array
		// because they get HTML-encoded when serialized. Instead, we handle them in innerHTML.

		// Localize caption if present.
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

		// Localize alt text in the HTML if present.
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
		// Localize alt text for media blocks.
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
			// Extract and localize table cell contents.
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
		// For query pagination blocks, we need to handle the label attribute.
		// These blocks are self-closing and the label should be localized within the attribute.

		// Check if there's a label attribute to localize.
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
		// For post excerpt blocks, we need to handle the moreText attribute.
		// These blocks are self-closing and the moreText should be localized within the attribute.

		// Check if there's a moreText attribute to localize.
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
			// Localize summary content in innerHTML
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

			// Update innerContent if it exists and has been split
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				// For details blocks with inner blocks, innerContent typically has:
				// [0] = opening part with summary, [1] = null (for inner blocks), [2] = closing </details>
				
				// Find the opening part that contains the summary and update it with localized content
				foreach ( $block['innerContent'] as $index => $content ) {
					if ( is_string( $content ) && strpos( $content, '<summary' ) !== false ) {
						// Apply the same localization to this part
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
		// For search blocks, we need to handle multiple text attributes:
		// label, placeholder, and buttonText
		// These blocks are self-closing and the attributes should be localized within the JSON.

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
		// Remove opening and closing tags to get inner content.
		$html = preg_replace( '/^<[^>]+>/', '', trim( $html ) );
		$html = preg_replace( '/<\/[^>]+>$/', '', $html );

		// Return the content if it's not empty after trimming.
		$content = trim( $html );
		return ! empty( $content ) ? $html : '';
	}

	/**
	 * Creates a localized string with proper escaping.
	 *
	 * @param string $text Text to localize.
	 * @param string $function Localization function to use.
	 * @return string Localized string in PHP format.
	 */
	private static function create_localized_string( $text, $function = 'wp_kses_post' ) {
		// Escape single quotes in the text.
		$escaped_text = str_replace( "'", "\\'", $text );

		// Get the text domain from the pattern or use default.
		$text_domain = get_stylesheet();

		// Create the PHP localization string.
		return "<?php echo {$function}( '{$escaped_text}', '{$text_domain}' ); ?>";
	}
}
