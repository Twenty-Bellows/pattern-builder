<?php
/**
 * Replaces the content inside an HTML element.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_HTML_Processor;
use WP_HTML_Span;
use WP_HTML_Text_Replacement;

/**
 * Adds inner-HTML replacement to the HTML API.
 *
 * @see WP_Block::replace_html()
 */
class Inner_HTML_Processor extends WP_HTML_Processor {
	/**
	 * Name of the bookmark used to record tag positions.
	 */
	const BOOKMARK = 'pattern_builder_inner_html';

	/**
	 * Replaces the content of the first element matching one of the selectors.
	 *
	 * @param string   $html HTML to update.
	 * @param string[] $selectors Tag names to look for, in order.
	 * @param string   $replacement HTML to put inside the element.
	 * @return string|null The updated HTML, or null if nothing was replaced.
	 */
	public static function replace_inner_html( string $html, array $selectors, string $replacement ): ?string {
		foreach ( $selectors as $tag ) {
			$processor = static::create_fragment( $html );

			if ( ! $processor instanceof self ) {
				return null;
			}

			if ( $processor->next_tag( array( 'tag_name' => $tag ) ) && $processor->set_inner_html( $replacement ) ) {
				return $processor->get_updated_html();
			}
		}

		return null;
	}

	/**
	 * Removes the first element matching one of the selectors, tags and all.
	 *
	 * @param string   $html HTML to update.
	 * @param string[] $selectors Tag names to look for, in order.
	 * @return string|null The updated HTML, or null if nothing was removed.
	 */
	public static function remove_element( string $html, array $selectors ): ?string {
		foreach ( $selectors as $tag ) {
			$processor = static::create_fragment( $html );

			if ( ! $processor instanceof self ) {
				return null;
			}

			if ( $processor->next_tag( array( 'tag_name' => $tag ) ) && $processor->delete_element() ) {
				return $processor->get_updated_html();
			}
		}

		return null;
	}

	/**
	 * Replaces the content between the current tag and its matching closer.
	 *
	 * @param string $html HTML to put inside the element.
	 * @return bool Whether the content was replaced.
	 */
	private function set_inner_html( string $html ): bool {
		$bounds = $this->element_bounds();

		if ( null === $bounds ) {
			return false;
		}

		list( $opener, $closer ) = $bounds;

		$start = $opener->start + $opener->length;

		$this->lexical_updates[] = new WP_HTML_Text_Replacement( $start, $closer->start - $start, $html );

		return true;
	}

	/**
	 * Removes the current element, its opening and closing tags included.
	 *
	 * @return bool Whether the element was removed.
	 */
	private function delete_element(): bool {
		$bounds = $this->element_bounds();

		if ( null === $bounds ) {
			return false;
		}

		list( $opener, $closer ) = $bounds;

		$this->lexical_updates[] = new WP_HTML_Text_Replacement(
			$opener->start,
			( $closer->start + $closer->length ) - $opener->start,
			''
		);

		return true;
	}

	/**
	 * Finds where the current element opens and closes in the source HTML.
	 *
	 * @return WP_HTML_Span[]|null The opening and closing spans, or null when the current
	 * token is not an element with a closer of its own.
	 */
	private function element_bounds(): ?array {
		if ( $this->is_tag_closer() || ! $this->expects_closer() ) {
			return null;
		}

		$depth    = $this->get_current_depth();
		$tag_name = $this->get_tag();

		$opener = $this->mark_current_token();

		if ( null === $opener ) {
			return null;
		}
		while ( $this->next_token() && $this->get_current_depth() >= $depth ) {
			continue;
		}

		if ( ! $this->is_tag_closer() || $tag_name !== $this->get_tag() ) {
			return null;
		}

		$closer = $this->mark_current_token();

		if ( null === $closer ) {
			return null;
		}

		return array( $opener, $closer );
	}

	/**
	 * Returns the span the current token occupies in the source HTML.
	 *
	 * @return WP_HTML_Span|null The span, or null for a token that isn't in the source.
	 */
	private function mark_current_token(): ?WP_HTML_Span {
		if ( ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}
		$span = $this->bookmarks[ '_' . self::BOOKMARK ] ?? null;

		return $span instanceof WP_HTML_Span ? $span : null;
	}
}
