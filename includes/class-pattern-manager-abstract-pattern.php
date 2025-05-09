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



}
