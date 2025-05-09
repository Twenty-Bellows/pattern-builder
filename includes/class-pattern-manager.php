<?php

require_once __DIR__ . '/class-pattern-manager-api.php';
require_once __DIR__ . '/class-pattern-manager-admin.php';
require_once __DIR__ . '/class-pattern-manager-editor.php';

class Twenty_Bellows_Pattern_Manager
{
	public function __construct()
	{
		$api = new Twenty_Bellows_Pattern_Manager_API();
		$admin = new Twenty_Bellows_Pattern_Manager_Admin();
		$editor = new Twenty_Bellows_Pattern_Manager_Editor();
	}
}

$pattern_manager = new Twenty_Bellows_Pattern_Manager();
