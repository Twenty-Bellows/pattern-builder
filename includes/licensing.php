<?php

if ( ! function_exists( 'pb_fs' ) ) {

	function pb_fs() {

		global $pb_fs;

		if ( ! isset( $pb_fs ) ) {
			$pb_fs = fs_dynamic_init(
				array(
					'id'                  => '19056',
					'slug'                => 'pattern-builder',
					'type'                => 'plugin',
					'public_key'          => 'pk_d2b48b9343ac970336f401830cd52',
					'is_premium'          => true,
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'menu'                => array(
						'slug'    => 'pattern-builder',
						'contact' => false,
						'support' => false,
						'parent'  => array(
							'slug' => 'themes.php',
						),
					),
				)
			);
		}

		return $pb_fs;
	}

	pb_fs();
	do_action( 'pb_fs_loaded' );
}

if ( ! function_exists( 'pb_fs_testing' ) ) {
	function pb_fs_testing() {
		if ( ! defined( 'WP_FS__pattern-builder_SECRET_KEY' ) ) {
			return false;
		}
		$expected_value = '793c8c98fa1abc0e90d6bb664e29887a0a7fdd66bdef92e3bd013e92880ddc67';
		return $expected_value === hash( 'sha256', constant( 'WP_FS__pattern-builder_SECRET_KEY' ) );
	}
}
