<?php
/**
 * The cloud round trip, over the wire: upload a theme pattern with an image, re-upload it
 * to take the update path, download it back as a user pattern, and check what landed.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Controller;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$token      = isset( $args[0] ) ? $args[0] : '';
$pattern_id = isset( $args[1] ) ? $args[1] : 'simple-theme/theme-image-test';

if ( ! $token ) {
	WP_CLI::error( 'Usage: wp eval-file tests/e2e/cloud-roundtrip.php <token> [pattern-id]' );
}

wp_set_current_user( 1 );
update_user_meta( 1, Pattern_Builder_Cloud::META_TOKEN, $token );
$me = Pattern_Builder_Cloud::request( 'GET', '/me' );
if ( is_wp_error( $me ) ) {
	WP_CLI::error( 'Could not read the account: ' . $me->get_error_message() );
}
update_user_meta( 1, Pattern_Builder_Cloud::META_ACCOUNT, isset( $me['account'] ) ? (array) $me['account'] : array() );

$controller = new Pattern_Builder_Cloud_Controller();
$out        = array();

/**
 * Runs a cloud endpoint, stopping the script on a WP_Error.
 *
 * @param string $step What is being attempted, for the failure message.
 * @param mixed  $result Response or WP_Error.
 * @return array The response data.
 */
$attempt = function ( $step, $result ) {
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $step . ' failed: ' . $result->get_error_message() );
	}
	return $result->get_data();
};

/**
 * Builds a cloud request.
 *
 * @param string $route Route under /pattern-builder/v1/cloud.
 * @param array  $params Request parameters.
 * @return WP_REST_Request
 */
$request = function ( $route, $params ) {
	$req = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/' . $route );
	foreach ( $params as $key => $value ) {
		$req->set_param( $key, $value );
	}
	return $req;
};
$out['status'] = $controller->status()->get_data();
if ( empty( $out['status']['connected'] ) ) {
	WP_CLI::error( 'Not connected: ' . wp_json_encode( $out['status'] ) );
}
if ( 'install' === $pattern_id ) {
	$target = isset( $args[2] ) ? explode( '/', $args[2], 2 ) : array();
	if ( 2 !== count( $target ) ) {
		WP_CLI::error( 'Usage: wp eval-file tests/e2e/cloud-roundtrip.php <token> install <owner>/<slug>' );
	}

	$porter  = new \TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter();
	$install = $porter->install_collection( (int) $target[0], $target[1], 'user', 'add' );
	if ( is_wp_error( $install ) ) {
		WP_CLI::error( 'Install failed: ' . $install->get_error_message() );
	}
	WP_CLI::log( wp_json_encode( $install, JSON_PRETTY_PRINT ) );

	$landed   = array_filter( $install['results'], static function ( $r ) { return 'installed' === $r['status']; } );
	$category = \TwentyBellows\PatternBuilder\Pattern_Builder_Cloud::collection_category_slug( (int) $target[0], $target[1] );
	$filed    = true;
	foreach ( $landed as $r ) {
		$filed = $filed && in_array( $category, wp_get_object_terms( $r['id'], 'wp_pattern_category', array( 'fields' => 'slugs' ) ), true );
	}

	if ( $install['failed'] || ! $filed ) {
		WP_CLI::error( sprintf( 'Collection install broke: %d failed, filed under %s: %s', $install['failed'], $category, $filed ? 'yes' : 'no' ) );
	}
	$store    = new \TwentyBellows\PatternBuilder\Pattern_File_Store();
	$dangling = array();
	foreach ( $landed as $r ) {
		$content = 'user' === $r['type']
			? (string) get_post_field( 'post_content', $r['id'] )
			: (string) ( $store->find_theme_pattern( $r['id'] )->content ?? '' );

		foreach ( \TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter::references_of( $content ) as $reference ) {
			if ( ! $store->find_theme_pattern( $reference ) ) {
				$dangling[] = $reference;
			}
		}
	}

	if ( $dangling ) {
		WP_CLI::error( 'Installed patterns reference patterns that are not here: ' . implode( ', ', array_unique( $dangling ) ) );
	}
	$variations = \TwentyBellows\PatternBuilder\Pattern_Builder_Block_Style_Variations::class;
	$unstyled   = array();
	foreach ( $landed as $r ) {
		$content = 'user' === $r['type']
			? (string) get_post_field( 'post_content', $r['id'] )
			: (string) ( $store->find_theme_pattern( $r['id'] )->content ?? '' );

		foreach ( $variations::used_in( $content ) as $slug ) {
			if ( \WP_Block_Styles_Registry::get_instance()->is_registered( 'core/button', $slug ) ) {
				continue;
			}
			if ( null === $variations::definition( $slug ) ) {
				$unstyled[] = $slug;
			}
		}
	}

	if ( $unstyled ) {
		WP_CLI::error( 'Installed patterns apply block styles that are not here: ' . implode( ', ', array_unique( $unstyled ) ) );
	}

	WP_CLI::success( sprintf( 'Collection installed: %d landed, %d already here, all under %s, every reference resolves.', $install['installed'], $install['skipped'], $category ) );
	return;
}
$collections   = $attempt( 'List collections', $controller->library_collections() );
$e2e           = null;
foreach ( $collections as $candidate ) {
	if ( 'E2E Roundtrip' === $candidate['title'] ) {
		$e2e = $candidate;
	}
}
if ( ! $e2e ) {
	$e2e = $attempt(
		'Create collection',
		$controller->create_collection(
			$request(
				'library/collections',
				array(
					'name'        => 'E2E Roundtrip',
					'slug'        => 'e2e-roundtrip',
					'description' => 'Made by tests/e2e/cloud-roundtrip.php.',
				)
			)
		)
	);
}
$out['collection'] = sprintf( '%d/%s', $e2e['owner'], $e2e['slug'] );

$out['upload'] = $attempt(
	'Upload',
	$controller->upload(
		$request(
			'upload',
			array(
				'patternType' => 'theme',
				'patternId'   => $pattern_id,
				'collection'  => $e2e['id'],
			)
		)
	)
);
$cloud_id = $out['upload']['pattern']['id'];
$reupload        = $attempt(
	'Re-upload',
	$controller->upload(
		$request(
			'upload',
			array(
				'patternType' => 'theme',
				'patternId'   => $pattern_id,
			)
		)
	)
);
$out['reupload'] = array(
	'updated' => $reupload['updated'],
	'sameId'  => $reupload['pattern']['id'] === $cloud_id,
);
$out['download'] = $attempt(
	'Download',
	$controller->download(
		$request(
			'download',
			array(
				'source'      => 'library',
				'cloudId'     => $cloud_id,
				'destination' => 'user',
			)
		)
	)
);
$post    = get_post( $out['download']['id'] );
$uploads = wp_get_upload_dir();

preg_match( '/src="([^"]+)"/', $post->post_content, $src );
$image_url  = isset( $src[1] ) ? $src[1] : '';
$image_path = str_replace( $uploads['baseurl'], $uploads['basedir'], strtok( $image_url, '?' ) );

$checks = array(
	'landed as a user pattern'      => 'wp_block' === $post->post_type,
	'took the update path'          => ! empty( $out['reupload']['updated'] ) && $out['reupload']['sameId'],
	'carries its cloud name'        => ( new \TwentyBellows\PatternBuilder\Pattern_File_Store() )->find_theme_pattern( $pattern_id )->cloud === $out['upload']['cloud'],
	'no placeholders left behind'   => false === strpos( $post->post_content, 'pbp-asset:' ),
	'image points at this site'     => $image_url && 0 === strpos( $image_url, $uploads['baseurl'] ),
	'image file was fetched'        => $image_path && file_exists( $image_path ),
	'image names its attachment'    => (bool) preg_match( '/wp-image-\d+/', $post->post_content ),
	'filed in the collection'       => isset( $out['upload']['pattern']['collection']['slug'] ) && $out['upload']['pattern']['collection']['slug'] === $e2e['slug'],
);
$tree_pattern = isset( $args[2] ) ? $args[2] : 'simple-theme/e2e-page-home';
$store        = new \TwentyBellows\PatternBuilder\Pattern_File_Store();

if ( $store->find_theme_pattern( $tree_pattern ) ) {
	$out['tree'] = $attempt(
		'Upload tree',
		$controller->upload(
			$request(
				'upload',
				array(
					'patternType' => 'theme',
					'patternId'   => $tree_pattern,
					'collection'  => $e2e['id'],
				)
			)
		)
	);

	$members = isset( $out['tree']['members'] ) ? $out['tree']['members'] : array();
	$root      = Pattern_Builder_Cloud::request( 'GET', '/library/patterns/' . (int) ( $out['tree']['pattern']['id'] ?? 0 ) );
	$namespace = is_wp_error( $root ) ? '' : (string) ( $root['namespace'] ?? '' );
	$stored    = is_wp_error( $root ) ? '' : (string) ( $root['content'] ?? '' );
	$prefix    = implode( '/', array_slice( explode( '/', $namespace ), 0, 2 ) );

	$checks['tree went up leaves first'] = count( $members ) > 1 && end( $members ) === $tree_pattern;
	$checks['references were rewritten'] = $prefix && false !== strpos( $stored, '"slug":"' . $prefix . '/' )
		&& false === strpos( $stored, '"slug":"simple-theme/' );
	$out['tree']                         = array(
		'members'   => $members,
		'namespace' => $namespace,
	);
}
$variations = \TwentyBellows\PatternBuilder\Pattern_Builder_Block_Style_Variations::class;
$made       = $variations::add(
	array(
		'slug'       => 'e2e-inset',
		'title'      => 'E2E Inset',
		'blockTypes' => array( 'core/group' ),
		'styles'     => array(
			'border' => array( 'radius' => '999px' ),
			'css'    => 'position: relative; & > * { z-index: 1; } &::before { content: ""; inset: 0; background: rgba(0, 0, 0, 0.04); } & .is-style-e2e-pill { color: inherit; }',
		),
	)
);

$made_inner = $variations::add(
	array(
		'slug'       => 'e2e-pill',
		'title'      => 'E2E Pill',
		'blockTypes' => array( 'core/group' ),
		'styles'     => array( 'border' => array( 'radius' => '999px' ) ),
	)
);

if ( ! is_wp_error( $made ) && ! is_wp_error( $made_inner ) ) {
	$styled = $store->update_theme_pattern(
		new \TwentyBellows\PatternBuilder\Abstract_Pattern(
			array(
				'id'      => 'simple-theme/e2e-styled',
				'name'    => 'simple-theme/e2e-styled',
				'title'   => 'E2E Styled',
				'source'  => 'theme',
				'content' => '<!-- wp:group {"className":"is-style-e2e-inset"} -->' . "\n"
					. '<div class="wp-block-group is-style-e2e-inset">' . "\n"
					. '<!-- wp:group {"className":"is-style-e2e-pill"} -->' . "\n"
					. '<div class="wp-block-group is-style-e2e-pill"></div>' . "\n" . '<!-- /wp:group -->' . "\n"
					. '</div>' . "\n" . '<!-- /wp:group -->',
			)
		)
	);

	if ( ! is_wp_error( $styled ) ) {
		$out['variation'] = $attempt(
			'Upload styled',
			$controller->upload(
				$request(
					'upload',
					array(
						'patternType' => 'theme',
						'patternId'   => 'simple-theme/e2e-styled',
						'collection'  => $e2e['id'],
					)
				)
			)
		);

		$stored_styled = Pattern_Builder_Cloud::request( 'GET', '/library/patterns/' . (int) ( $out['variation']['pattern']['id'] ?? 0 ) );
		$carried       = is_wp_error( $stored_styled ) ? array() : (array) ( $stored_styled['variations'] ?? array() );
		$markup        = is_wp_error( $stored_styled ) ? '' : (string) ( $stored_styled['content'] ?? '' );
		$own      = is_wp_error( $stored_styled ) ? '' : (string) ( $stored_styled['namespace'] ?? '' );
		$expected = implode( '-', array_slice( explode( '/', $own ), 0, 2 ) ) . '-e2e-inset';

		$slugs       = wp_list_pluck( $carried, 'slug' );
		$at          = array_search( $expected, $slugs, true );
		$carried_css = false === $at ? '' : (string) ( $carried[ $at ]['styles']['css'] ?? '' );
		$pill        = str_replace( '-e2e-inset', '-e2e-pill', $expected );

		$checks['variation travelled']     = 2 === count( $carried ) && in_array( $expected, $slugs, true ) && in_array( $pill, $slugs, true );
		$checks['its class was rewritten'] = false !== strpos( $markup, 'is-style-' . $expected )
			&& false === strpos( $markup, '"is-style-e2e-inset"' );
		$checks['its css travelled']        = false !== strpos( $carried_css, '&::before' )
			&& false !== strpos( $carried_css, 'rgba(0, 0, 0, 0.04)' );
		$checks['its css was renamespaced'] = false !== strpos( $carried_css, 'is-style-' . $pill )
			&& false === strpos( $carried_css, ' .is-style-e2e-pill ' );
	}
}

$out['checks'] = $checks;
WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT ) );

$failed = array_keys( array_filter( $checks, static function ( $passed ) { return ! $passed; } ) );

if ( $failed ) {
	WP_CLI::error( 'Round trip broke: ' . implode( '; ', $failed ) );
}

WP_CLI::success( sprintf( 'Round trip intact (cloud pattern %d → local post %d). Install the collection on a second site with: install %s', $cloud_id, $post->ID, $out['collection'] ) );
