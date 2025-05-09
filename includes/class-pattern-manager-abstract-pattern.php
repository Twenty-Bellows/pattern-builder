<?php

class Abstract_Pattern
{
	public $name;
	public $title;
	public $description;
	public $content;

	public $categories;
	public $keywords;

	public $source;
	public $synced;
	public $inserter;

	public function __construct( $args = array() )
	{
		// required
		$this->name = $args['name'];
		$this->title = $args['title'];
		$this->description = $args['description'];
		$this->content = $args['content'];

		$this->source = $args['source'] ?? 'theme';
		$this->synced = $args['synced'] ?? false;
		$this->inserter = $args['inserter'] ?? true;
	}

	public static function from_registry( $pattern )
	{
		return new self(
			array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'],
				'description' => $pattern['description'],
				'content'     => $pattern['content'],
				'source'      => 'theme',
				'synced'      => false,
				'inserter'    => true,
			)
		);
	}

	public static function from_post( $post, $metadata )
	{
		return new self(
			array(
				'name'        => $post->post_name,
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'content'     => $post->post_content,
				'source'      => 'user',
				'synced'      => $metadata['wp_pattern_sync_status'][0] !== 'unsynced' ?? false,
				'inserter'    => true,
			)
		);
	}

}
