<?php

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

global $pb_fs;

class Pattern_Builder_Controller
{
	public function format_pattern_slug_for_post($slug) {
		$new_slug = str_replace('/', '-x-x-', $slug);
		return $new_slug;
	}

	public static function format_pattern_slug_from_post($slug) {
		$new_slug = str_replace('-x-x-', '/', $slug);
		return $new_slug;
	}

	public function get_pb_block_post_for_pattern($pattern)
	{
		$path = $this->format_pattern_slug_for_post($pattern->name);

		$posts = get_posts( array(
  			'name' => $path,
  			'post_type' => 'pb_block'
		) );
		$pattern_post = $posts ? $posts[0] : null;

		if ($pattern_post) {
			$pattern_post->post_name = $pattern->name;
			return $pattern_post;
		}

		return $this->create_pb_block_post_for_pattern($pattern);
	}

	public function create_pb_block_post_for_pattern($pattern)
	{
		$existing_post = get_page_by_path($this->format_pattern_slug_for_post($pattern->name), OBJECT, ['pb_block']);

		$post_id = $existing_post ? $existing_post->ID : null;

		$meta = [];

		if ( ! $pattern->synced ) {
			$meta['wp_pattern_sync_status'] = "unsynced";
		}
		else {
			delete_post_meta($post_id, 'wp_pattern_sync_status');
		}

		if ( $pattern->blockTypes ) {
			$meta['wp_pattern_block_types'] = implode(',', $pattern->blockTypes);
		}
		else {
			delete_post_meta($post_id, 'wp_pattern_block_types');
		}

		if ( $pattern->templateTypes ) {
			$meta['wp_pattern_template_types'] = implode(',', $pattern->templateTypes);
		}
		else {
			delete_post_meta($post_id, 'wp_pattern_template_types');
		}

		if ( $pattern->postTypes ) {
			$meta['wp_pattern_post_types'] = implode(',', $pattern->postTypes);
		}
		else {
			delete_post_meta($post_id, 'wp_pattern_post_types');
		}

		if ( $pattern->keywords ) {
			$meta['wp_pattern_keywords'] = implode(',', $pattern->keywords);
		}
		else {
			delete_post_meta($post_id, 'wp_pattern_keywords');
		}

		if ( $pattern->inserter === false ) {
			$meta['wp_pattern_inserter'] = "no";
		}
		else {
			delete_post_meta($post_id, 'wp_pattern_inserter');
		}

		$post_id = wp_insert_post(array(
			'ID' => $post_id,
			'post_title' => $pattern->title,
			'post_name' =>$this->format_pattern_slug_for_post($pattern->name),
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
		$post = get_post($post_id);
		$post->post_name = $pattern->name;

		return $post;
	}

	public function update_theme_pattern(Abstract_Pattern $pattern)
	{
		if (pb_fs()->can_use_premium_code__premium_only() || pb_fs_testing()) {

			// get the pb_block post if it already exists
			$post = get_page_by_path($this->format_pattern_slug_for_post($pattern->name), OBJECT, ['pb_block', 'wp_block']);

			if ( $post && $post->post_type === 'wp_block' ) {
				// this is being converted to theme patterns, change the slug to include the theme domain
				$pattern->name = get_stylesheet() . '/' . $pattern->name;
			}

			$pattern = $this->import_pattern_image_assets($pattern);

			// update the pattern file
			$this->update_theme_pattern_file($pattern);

			// rebuild the pattern from the file (so that the content has no PHP tags)
			$pattern = Abstract_Pattern::from_file($this->get_pattern_filepath($pattern));

			$post_id = wp_update_post([
				'ID'           => $post ? $post->ID : null,
				'post_title'   => $pattern->title,
				'post_name'    => $this->format_pattern_slug_for_post($pattern->name),
				'post_excerpt' => $pattern->description,
				'post_content' => $pattern->content,
				'post_type'    => 'pb_block',
			]);

			if ($pattern->synced) {
				delete_post_meta($post_id, 'wp_pattern_sync_status');
			} else {
				update_post_meta($post_id, 'wp_pattern_sync_status', 'unsynced');
			}

			if ($pattern->keywords) {
				update_post_meta($post_id, 'wp_pattern_keywords', implode(',', $pattern->keywords));
			} else {
				delete_post_meta($post_id, 'wp_pattern_keywords');
			}

			if ($pattern->blockTypes) {
				update_post_meta($post_id, 'wp_pattern_block_types', implode(',', $pattern->blockTypes));
			} else {
				delete_post_meta($post_id, 'wp_pattern_block_types');
			}

			if ($pattern->templateTypes) {
				update_post_meta($post_id, 'wp_pattern_template_types', implode(',', $pattern->templateTypes));
			} else {
				delete_post_meta($post_id, 'wp_pattern_template_types');
			}

			if ($pattern->postTypes) {
				update_post_meta($post_id, 'wp_pattern_post_types', implode(',', $pattern->postTypes));
			} else {
				delete_post_meta($post_id, 'wp_pattern_post_types');
			}

			// store categories
			wp_set_object_terms($post_id, $pattern->categories, 'wp_pattern_category', false);

			return $pattern;
		}

		return new WP_Error('premium_required', 'Saving Theme Patterns requires the premium version of Pattern Builder.', ['status' => 403]);
	}

	private function export_pattern_image_assets( $pattern ) {

		$home_url = home_url();

		// Helper function to download and save image
		$upload_image = function($url) use ($home_url) {

			// skip if the asset isn't an image
			if (!preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url)) {
				return false;
			}

			$download_file = false;

			// convert the URL to a local file path
			$file_path = str_replace($home_url, ABSPATH, $url);
			if (file_exists($file_path)) {

				$temp_file = wp_tempnam(basename($file_path));

				// copy the image to a temporary location
				if (copy($file_path, $temp_file)) {
					$download_file = $temp_file;
				}
			}

			if (!$download_file) {
				$download_file = download_url($url);
			}

			if (is_wp_error($download_file)) {
				//we're going to try again with a new URL
				//we might be running this in a docker container
				//and if that's the case let's try again on port 80
				$parsed_url = parse_url($url);
				if ('localhost' === $parsed_url['host'] && '80' !== $parsed_url['port']) {
					$download_file = download_url(str_replace('localhost:' . $parsed_url['port'], 'localhost:80', $url));
				}
			}

			if (is_wp_error($download_file)) {
				return false;
			}

			// upload to the media library
			$upload_dir = wp_upload_dir();
			if (!is_dir($upload_dir['path'])) {
				wp_mkdir_p($upload_dir['path']);
			}

			$upload_file = $upload_dir['path'] . '/' . basename($url);

			// check to see if the file is already in the uploads directory
			if (file_exists($upload_file)) {
				$uploaded_file_url = $upload_dir['url'] . '/' . basename($upload_file);
				return $uploaded_file_url;
			}

			// Move the downloaded file to the uploads directory
			if (!rename($download_file, $upload_file)) {
				return false;
			}

			// Get the file type and create an attachment
			$filetype = wp_check_filetype(basename($upload_file), null);
			$attachment = [
				'guid'           => $upload_dir['url'] . '/' . basename($upload_file),
				'post_mime_type' => $filetype['type'],
				'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload_file)),
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			// Insert the attachment into the media library
			$attachment_id = wp_insert_attachment($attachment, $upload_file);
			if (is_wp_error($attachment_id)) {
				return false;
			}

			// Generate attachment metadata and update the attachment
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata($attachment_id, $upload_file);
			wp_update_attachment_metadata($attachment_id, $metadata);

			$url = wp_get_attachment_url($attachment_id);

			return $url;
		};

		// First, handle HTML attributes (src and href)
		$pattern->content = preg_replace_callback(
			'/(src|href)="(' . preg_quote($home_url, '/') . '[^"]+)"/',
			function ($matches) use ($upload_image) {
				$new_url = $upload_image($matches[2]);
				if ($new_url) {
					return $matches[1] . '="' . $new_url . '"';
				}
				return $matches[0];
			},
			$pattern->content
		);

		// Second, handle JSON-encoded URLs
		$pattern->content = preg_replace_callback(
			'/"url"\s*:\s*"(' . preg_quote($home_url, '/') . '[^"]+)"/',
			function ($matches) use ($upload_image) {
				$url = $matches[1];
				$new_url = $upload_image($url);
				if ($new_url) {
					return '"url":"' . $new_url . '"';
				}
				return $matches[0];
			},
			$pattern->content
		);

		return $pattern;
	}

	private function import_pattern_image_assets( $pattern ) {

		$home_url = home_url();

		// Helper function to download and save image
		$download_and_save_image = function($url) use ($home_url) {
			// continue if the asset isn't an image
			if (!preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url)) {
				return false;
			}

			$download_file = download_url($url);

			if (is_wp_error($download_file)) {
				//we're going to try again with a new URL
				//we might be running this in a docker container
				//and if that's the case let's try again on port 80
				$parsed_url = parse_url($url);
				if ('localhost' === $parsed_url['host'] && '80' !== $parsed_url['port']) {
					$download_file = download_url(str_replace('localhost:' . $parsed_url['port'], 'localhost:80', $url));
				}
			}

			if (is_wp_error($download_file)) {
				return false;
			}

			$filename = basename($url);
			$asset_dir = get_stylesheet_directory() . '/assets/images/';
			if (!is_dir($asset_dir)) {
				wp_mkdir_p($asset_dir);
			}

			rename($download_file, $asset_dir . $filename);

			return '/assets/images/' . $filename;
		};

		// First, handle HTML attributes (src and href)
		$pattern->content = preg_replace_callback(
			'/(src|href)="(' . preg_quote($home_url, '/') . '[^"]+)"/',
			function ($matches) use ($download_and_save_image) {
				$new_url = $download_and_save_image($matches[2]);
				if ($new_url) {
					return $matches[1] . '="<?php echo get_stylesheet_directory_uri() . \'' . $new_url . '\'; ?>"';
				}
				return $matches[0];
			},
			$pattern->content
		);

		// Second, handle JSON-encoded URLs
		$pattern->content = preg_replace_callback(
			'/"url"\s*:\s*"(' . preg_quote($home_url, '/') . '[^"]+)"/',
			function ($matches) use ($download_and_save_image) {
				$new_url = $download_and_save_image($matches[1]);
				if ($new_url) {
					return '"url":"<?php echo get_stylesheet_directory_uri() . \'' . $new_url . '\'; ?>"';
				}
				return $matches[0];
			},
			$pattern->content
		);

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
			// this is for any user patterns that are being converted from theme patterns
			// It will be converted to a wp_block post when it is updated
			$slug = $this->format_pattern_slug_for_post($pattern->name);
			$post = get_page_by_path($slug, OBJECT, 'pb_block');
			$convert_from_theme_pattern = true;
		}

		if ( $convert_from_theme_pattern && !pb_fs()->can_use_premium_code() && !pb_fs_testing() ) {
			return new WP_Error('premium_required', 'Converting Theme Patterns to User Patterns requires the premium version of Pattern Builder.', ['status' => 403]);
		}

		// upload any assets from the theme
		$pattern = $this->export_pattern_image_assets($pattern);

		if (empty($post)) {
			$post_id = wp_insert_post([
				'post_title'   => $pattern->title,
				'post_name'    => basename($pattern->name),
				'post_content' => $pattern->content,
				'post_excerpt' => $pattern->description,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			]);
		} else {
			$post_id = wp_update_post([
				'ID'           => $post->ID,
				'post_title'   => $pattern->title,
				'post_name'    => basename($pattern->name),
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

	public function get_pattern_filepath($pattern)
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
		if (pb_fs()->can_use_premium_code__premium_only() || pb_fs_testing()) {

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

		return new WP_Error('premium_required', 'Deleting Theme Patterns requires the premium version of Pattern Builder.', ['status' => 403]);
	}

	public function update_theme_pattern_file(Abstract_Pattern $pattern)
	{
		$path = $this->get_pattern_filepath($pattern);

		if (!$path) {
			$filename = str_replace('-', '_', basename($pattern->name));
			$path = get_stylesheet_directory() . '/patterns/' . $filename . '.php';
		}

		$file_content = $this->build_pattern_file_metadata($pattern) . $pattern->content;
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

		$categories = $pattern->categories ? "\n * Categories: " . implode(', ', $pattern->categories) : '';
		$keywords = $pattern->keywords ? "\n * Keywords: " . implode(', ', $pattern->keywords) : '';
		$blockTypes = $pattern->blockTypes ? "\n * Block Types: " . implode(', ', $pattern->blockTypes) : '';
		$postTypes = $pattern->postTypes ? "\n * Post Types: " . implode(', ', $pattern->postTypes) : '';
		$templateTypes = $pattern->templateTypes ? "\n * Template Types: " . implode(', ', $pattern->templateTypes) : '';
		$inserter = $pattern->inserter ? '' : "\n * Inserter: no";
		$synced = $pattern->synced ? "\n * Synced: yes" : '';

		return <<<METADATA
	<?php
	/**
	 * Title: $pattern->title
	 * Slug: $pattern->name
	 * Description: $pattern->description$categories$keywords$blockTypes$postTypes$templateTypes$inserter$synced
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

						$pattern_slug = $pattern_post->post_name;

						// TODO: Optimize this
						// NOTE: Because the name of the post is the slug, but the slug has /'s removed, we have to find the ACTUALY slug from the file.
						$all_patterns = $this->get_block_patterns_from_theme_files();
						$pattern = array_find( $all_patterns, function ($p) use ($pattern_slug) {
							return sanitize_title($p->name) === sanitize_title($pattern_slug);
						});

						if ( $pattern ) {

							unset($attributes['ref']);
							$attributes['slug'] = $pattern->name;

							return 'wp:pattern ' . json_encode($attributes, JSON_UNESCAPED_SLASHES) . ' /-->';
						}


					}
				}

				return 'wp:block ' . $matches[1] . ' /-->';
			},

			$pattern->content
		);

		return $pattern;
	}
}
