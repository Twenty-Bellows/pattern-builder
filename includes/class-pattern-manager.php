<?php

if (!class_exists('Twenty_Bellows_Pattern_Manager')) {

	/**
	 * Render the query filter block
	 *
	 * @package TwentyBellows
	 */
	class Twenty_Bellows_Pattern_Manager
	{
		/**
		 * Constructor
		 */
		public function __construct()
		{
			add_action('init', array($this, 'init'));
		}

		public function init()
		{
		}

	}
}

$pattern_manager = new Twenty_Bellows_Pattern_Manager();
