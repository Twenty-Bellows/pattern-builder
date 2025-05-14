<?php

class Abstract_Pattern
{
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

	public function __construct( $args = array() )
	{
		$this->title = $args['title'];

		$this->name = $args['name'] ?? sanitize_title( $args['title'] );
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

	public static function from_registry( $pattern )
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

	public static function from_post( $post, $metadata, $categories )
	{
		$categories = array_map(
			function ( $category ) {
				// return $category->slug;
				return [
					'id'   => $category->term_id,
					'name' => $category->name,
					'slug' => $category->slug,
				];
			},
			$categories
		);
		return new self(
			array(
				'name'        => $post->post_name,
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'content'     => $post->post_content,
				'source'      => 'user',
				'synced'      => $metadata['wp_pattern_sync_status'][0] !== 'unsynced' ?? false,
				'categories'  => $categories,
				'inserter'    => true,
			)
		);
	}

}
