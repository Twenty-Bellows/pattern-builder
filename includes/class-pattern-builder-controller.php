<?php

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';

class Pattern_Builder_Controller
{
	public function get_pb_block_post_for_pattern($pattern)
	{

		$pattern_post = get_page_by_path(sanitize_title($pattern->name), OBJECT, 'pb_block');

		if ($pattern_post) {
			return $pattern_post;
		}

		return $this->create_pb_block_post_for_pattern($pattern);
	}

	public function create_pb_block_post_for_pattern($pattern)
	{
		$meta = [];

		if ( ! $pattern->synced ) {
			$meta['wp_pattern_sync_status'] = "unsynced";
		}
		if ( $pattern->blockTypes ) {
			$meta['wp_pattern_block_types'] = implode(',', $pattern->blockTypes);
		}
		if ( $pattern->templateTypes ) {
			$meta['wp_pattern_template_types'] = implode(',', $pattern->templateTypes);
		}
		if ( $pattern->postTypes ) {
			$meta['wp_pattern_post_types'] = implode(',', $pattern->postTypes);
		}
		if ( $pattern->keywords ) {
			$meta['wp_pattern_keywords'] = implode(',', $pattern->keywords);
		}

		$post_id = wp_insert_post(array(
			'post_title' => $pattern->title,
			'post_name' => $pattern->name,
			'post_content' => $pattern->content,
			'post_excerpt' => $pattern->description,
			'post_type' => 'pb_block',
			'post_status' => 'publish',
			'ping_status' => 'closed',
			'comment_status' => 'closed',
			'meta_input' => $meta,
		));

		// store categories
		wp_set_object_terms($post_id, $pattern->categories, 'wp_pattern_category', false);

		//return the post by post id
		return get_post($post_id);
	}

	public function update_theme_pattern(Abstract_Pattern $pattern)
	{
		// get the pb_block post if it already exists
		$post = get_page_by_path(sanitize_title($pattern->name), OBJECT, 'pb_block');

		if (empty($post)) {
			// if it doesn't exist, check if a wp_block post exists
			// this is for any user patterns that are being converted to theme patterns
			// It will be converted to a pb_block post when it is updated
			$post = get_page_by_path(sanitize_title($pattern->name), OBJECT, 'wp_block');
		}

		if (empty($post)){
			// create a new post if it doesn't exist
			$post = $this->create_pb_block_post_for_pattern($pattern);
		}


		wp_update_post([
			'ID'           => $post->ID,
			'post_title'   => $pattern->title,
			'post_content' => $pattern->content,
			'post_excerpt' => $pattern->description,
			'post_type'    => 'pb_block',
		]);

		if ($pattern->synced) {
			delete_post_meta($post->ID, 'wp_pattern_sync_status');
		} else {
			update_post_meta($post->ID, 'wp_pattern_sync_status', 'unsynced');
		}

		if ($pattern->keywords) {
			update_post_meta($post->ID, 'wp_pattern_keywords', implode(',', $pattern->keywords));
		} else {
			delete_post_meta($post->ID, 'wp_pattern_keywords');
		}

		if ($pattern->blockTypes) {
			update_post_meta($post->ID, 'wp_pattern_block_types', implode(',', $pattern->blockTypes));
		} else {
			delete_post_meta($post->ID, 'wp_pattern_block_types');
		}

		if ($pattern->templateTypes) {
			update_post_meta($post->ID, 'wp_pattern_template_types', implode(',', $pattern->templateTypes));
		} else {
			delete_post_meta($post->ID, 'wp_pattern_template_types');
		}

		if ($pattern->postTypes) {
			update_post_meta($post->ID, 'wp_pattern_post_types', implode(',', $pattern->postTypes));
		} else {
			delete_post_meta($post->ID, 'wp_pattern_post_types');
		}

		// store categories
		wp_set_object_terms($post->ID, $pattern->categories, 'wp_pattern_category', false);

		// update the pattern file
		$this->update_theme_pattern_file($pattern);

		return $pattern;
	}

	/**
	 * Updates a user pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @return Abstract_Pattern|WP_Error
	 */
	public function update_user_pattern(Abstract_Pattern $pattern)
	{
		$post = get_page_by_path($pattern->name, OBJECT, 'wp_block');
		$convert_from_theme_pattern = false;

		if (empty($post)) {
			// check if the pattern exists in the database as a pb_block post
			// this is for any user patterns that are being converted to theme patterns
			// It will be converted to a wp_block post when it is updated
			$post = get_page_by_path($pattern->name, OBJECT, 'pb_block');
			$convert_from_theme_pattern = true;
		}

		if (empty($post)) {
			$post_id = wp_insert_post([
				'post_title'   => $pattern->title,
				'post_name'    => $pattern->name,
				'post_content' => $pattern->content,
				'post_excerpt' => $pattern->description,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			]);
		} else {
			$post_id = $post->ID;
			wp_update_post([
				'ID'           => $post->ID,
				'post_title'   => $pattern->title,
				'post_name'    => $pattern->name,
				'post_content' => $pattern->content,
				'post_excerpt' => $pattern->description,
				'post_type'    => 'wp_block',
			]);
		}

		// ensure the 'synced' meta key is set
		if ($pattern->synced) {
			delete_post_meta($post_id, 'wp_pattern_sync_status');
		} else {
			update_post_meta($post_id, 'wp_pattern_sync_status', 'unsynced');
		}

		// store categories
		wp_set_object_terms($post_id, $pattern->categories, 'wp_pattern_category', false);

		// if we are converting a theme pattern to a user pattern delete the theme pattern file
		if ( $convert_from_theme_pattern ) {
			$path = $this->get_pattern_filepath($pattern);
			if ($path) {
				$deleted = unlink($path);
			}
		}

		return $pattern;
	}

	public function get_block_patterns_from_theme_files()
	{
		$pattern_files = glob(get_stylesheet_directory() . '/patterns/*.php');
		$patterns = [];

		foreach ($pattern_files as $pattern_file) {
			$pattern = Abstract_Pattern::from_file($pattern_file);
			$patterns[] = $pattern;
		}

		return $patterns;
	}

	public function get_block_patterns_from_database(): array
	{
		$query = new WP_Query(['post_type' => 'wp_block']);
		$patterns = [];

		foreach ($query->posts as $post) {
			$patterns[] = Abstract_Pattern::from_post($post);
		}

		return $patterns;
	}

	public function delete_user_pattern(Abstract_Pattern $pattern)
	{
		$post = get_page_by_path($pattern->name, OBJECT, 'wp_block');

		if (empty($post)) {
			return new WP_Error('pattern_not_found', 'Pattern not found', ['status' => 404]);
		}

		$deleted = wp_delete_post($post->ID, true);

		if (!$deleted) {
			return new WP_Error('pattern_delete_failed', 'Failed to delete pattern', ['status' => 500]);
		}

		return ['message' => 'Pattern deleted successfully'];
	}

	private function get_pattern_filepath($pattern)
	{
		$path = $pattern->filePath ?? get_stylesheet_directory() . '/patterns/' . basename($pattern->name) . '.php';

		if (file_exists($path)) {
			return $path;
		}

		$patterns = $this->get_block_patterns_from_theme_files();
		$pattern = array_find( $patterns, function ($p) use ($pattern) {
			return $p->name === $pattern->name;
		});

		if ( $pattern && file_exists($pattern->filePath) ) {
			return $pattern->filePath;
		}

		return null;
	}

	public function delete_theme_pattern(Abstract_Pattern $pattern)
	{
		$path = $this->get_pattern_filepath($pattern);

		if (!$path) {
			return new WP_Error('pattern_not_found', 'Pattern not found', ['status' => 404]);
		}

		$deleted = unlink($path);

		if (!$deleted) {
			return new WP_Error('pattern_delete_failed', 'Failed to delete pattern', ['status' => 500]);
		}

		$pb_block_post = $this->get_pb_block_post_for_pattern($pattern);
		$deleted = wp_delete_post($pb_block_post->ID, true);

		if (!$deleted) {
			return new WP_Error('pattern_delete_failed', 'Failed to delete pattern', ['status' => 500]);
		}

		return ['message' => 'Pattern deleted successfully'];
	}

	public function update_theme_pattern_file(Abstract_Pattern $pattern)
	{
		$path = $this->get_pattern_filepath($pattern);

		if (!$path) {
			$path = get_stylesheet_directory() . '/patterns/' . basename($pattern->name) . '.php';
		}

		$file_content = $this->build_pattern_file_metadata($pattern) . $pattern->content . "\n";
		$response = file_put_contents($path, $file_content);

		if (!$response) {
			return new WP_Error('file_creation_failed', 'Failed to create pattern file', ['status' => 500]);
		}

		return $pattern;
	}

	/**
	 * Builds metadata for a pattern file.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return string
	 */
	private function build_pattern_file_metadata(Abstract_Pattern $pattern): string
	{

		$synced = $pattern->synced ? "\n * Synced: yes" : '';
		$inserter = $pattern->inserter ? '' : "\n * Inserter: no";
		$categories = $pattern->categories ? "\n * Categories: " . implode(', ', $pattern->categories) : '';
		$keywords = $pattern->keywords ? "\n * Keywords: " . implode(', ', $pattern->keywords) : '';
		$blockTypes = $pattern->blockTypes ? "\n * Block Types: " . implode(', ', $pattern->blockTypes) : '';
		$templateTypes = $pattern->templateTypes ? "\n * Template Types: " . implode(', ', $pattern->templateTypes) : '';
		$postTypes = $pattern->postTypes ? "\n * Post Types: " . implode(', ', $pattern->postTypes) : '';

		return <<<METADATA
	<?php
	/**
	 * Title: $pattern->title
	 * Slug: $pattern->name
	 * Description: $pattern->description$synced$inserter$categories$keywords$blockTypes$templateTypes$postTypes
	 */
	?>

	METADATA;
	}

	/**
	 * Remaps wp:block blocks that reference theme patterns to wp:pattern blocks.
	 *
	 * @param Abstract_Pattern $pattern The pattern to remap.
	 * @return Abstract_Pattern
	 */
	public function remap_patterns( Abstract_Pattern $pattern ) {
		// if this pattern's content contains wp:block blocks and they reference
		// theme patterns, remap them to wp:pattern blocks.

		$pattern->content = preg_replace_callback(
			'/wp:block\s+({.*})\s*\/?-->/sU',
			function ($matches) use ($pattern) {

				$attributes = json_decode($matches[1], true);

				if (isset($attributes['ref'])) {

					// get the post of the pattern
					$pattern_post = get_post( $attributes['ref'], OBJECT );

					// if the post is a pb_block post, we can convert it to a wp:pattern block
					if ( $pattern_post && $pattern_post->post_type === 'pb_block' ) {

						$pattern_slug = sanitize_title($pattern_post->post_name);

						$attributes = [
							'slug' => $pattern_slug,
						];

						return 'wp:pattern ' . json_encode($attributes) . ' /-->';

					}
				}

				return 'wp:block ' . $matches[1] . ' /-->';
			},

			$pattern->content
		);

		return $pattern;
	}
}
