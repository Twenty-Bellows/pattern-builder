<?php

namespace TwentyBellows\PatternBuilder;

class Abstract_Pattern
{
	public $id;

	public $name;
	public $title;
	public $description;
	public $content;

	public $categories;
	public $keywords;

	public $blockTypes;
	public $templateTypes;
	public $postTypes;

	public $source;
	public $synced;
	public $inserter;
	public $filePath;

	public function __construct($args = array())
	{
		$this->id = $args['id'] ?? null;

		$this->title = $args['title'];

		$this->name = $args['name'] ?? sanitize_title($args['title']);

		$this->description = $args['description'] ?? '';
		$this->content = $args['content'] ?? '';

		$this->source = $args['source'] ?? 'theme';
		$this->synced = $args['synced'] ?? false;
		$this->inserter = $args['inserter'] ?? true;

		$this->categories = $args['categories'] ?? array();
		$this->keywords = $args['keywords'] ?? array();

		$this->blockTypes = $args['blockTypes'] ?? array();
		$this->templateTypes = $args['templateTypes'] ?? array();
		$this->postTypes = $args['postTypes'] ?? array();

		$this->filePath = $args['filePath'] ?? null;
	}

	private static function render_pattern($pattern_file)
	{
		ob_start();
		include $pattern_file;
		return ob_get_clean();
	}


	public static function from_file($pattern_file)
	{
		$pattern_data = get_file_data($pattern_file, array(
			'title'         => 'Title',
			'slug'          => 'Slug',
			'description'   => 'Description',
			'viewportWidth' => 'Viewport Width',
			'inserter'      => 'Inserter',
			'categories'    => 'Categories',
			'keywords'      => 'Keywords',
			'blockTypes'    => 'Block Types',
			'postTypes'     => 'Post Types',
			'templateTypes' => 'Template Types',
			'synced'	=> 'Synced',
		));

		$new = new self([
			'name' => $pattern_data['slug'],
			'title' => $pattern_data['title'],
			'description' => $pattern_data['description'],
			'content' => self::render_pattern($pattern_file),
			'filePath' => $pattern_file,
			'categories' => $pattern_data['categories'] === '' ? array() : explode(',', $pattern_data['categories']),
			'keywords' => $pattern_data['keywords'] === '' ? array() : explode(',', $pattern_data['keywords']),
			'blockTypes' => $pattern_data['blockTypes'] === '' ? array() : array_map('trim', explode(',', $pattern_data['blockTypes'])),
			'postTypes' => $pattern_data['postTypes'] === '' ? array() : explode(',', $pattern_data['postTypes']),
			'templateTypes' => $pattern_data['templateTypes'] === '' ? array() : explode(',', $pattern_data['templateTypes']),
			'source' => 'theme',
			'synced' => $pattern_data['synced'] === 'yes' ? true : false,
			'inserter' => $pattern_data['inserter'] !== 'no' ? true : false,
		]);

		return $new;
	}

	public static function from_registry($pattern)
	{
		return new self(
			array(
				'name'          => $pattern['name'],
				'title'         => $pattern['title'],
				'description'   => $pattern['description'],
				'content'       => $pattern['content'],
				'categories'    => $pattern['categories'],
				'keywords'      => $pattern['keywords'],
				'source'        => 'theme',
				'synced'        => false,
				'blockTypes'    => $pattern['blockTypes'],
				'templateTypes' => $pattern['templateTypes'],
				'postTypes'     => $pattern['postTypes'],
				'inserter'      => $pattern['inserter'],
				'filePath'      => $pattern['filePath'],
			)
		);
	}

	public static function from_post($post)
	{
		$metadata = get_post_meta($post->ID);
		$categories = wp_get_object_terms($post->ID, 'wp_pattern_category');
		$categories = array_map(
			function ($category) {
				return $category->slug;
			},
			$categories
		);

		$slug = Pattern_Builder_Controller::format_pattern_slug_from_post($post->post_name);

		return new self(
			array(
				'id'          => $post->ID,
				'name'        => $slug,
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'content'     => $post->post_content,
				'source'      => ($post->post_type === 'pb_block') ? 'theme' : 'user',
				'synced'      => ($metadata['wp_pattern_sync_status'][0] ?? 'synced') !== 'unsynced',

				'blockTypes' => isset($metadata['wp_pattern_block_types'][0]) ? explode(',', $metadata['wp_pattern_block_types'][0]) : [],
				'templateTypes' => isset($metadata['wp_pattern_template_types'][0]) ? explode(',', $metadata['wp_pattern_template_types'][0]) : [],
				'postTypes'   => isset($metadata['wp_pattern_post_types'][0]) ? explode(',', $metadata['wp_pattern_post_types'][0]) : [],

				'keywords' => isset($metadata['wp_pattern_keywords'][0]) ? explode(',', $metadata['wp_pattern_keywords'][0]) : [],
				'categories'  => $categories,
				'inserter' => isset($metadata['wp_pattern_inserter'][0]) ? ($metadata['wp_pattern_inserter'][0] === 'no' ? false : true) : true,
			)
		);
	}
}
