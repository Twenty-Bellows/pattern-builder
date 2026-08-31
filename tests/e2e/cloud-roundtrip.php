<?php
/**
 * The cloud round trip, over the wire: upload a theme pattern with an image,
 * re-upload it to take the update path, download it back as a user pattern,
 * and check what landed.
 *
 * This is a manual utility, not part of the PHPUnit suite, because it needs
 * something PHPUnit cannot provide: a second WordPress. Every automated test
 * of this path mocks `pre_http_request`, so nothing else exercises the real
 * multipart upload, the service's own sanitization and asset rehosting, or
 * the download that fetches those assets back. Run it after touching the
 * porter, the cloud controller, or anything in the service's store.
 *
 * Run it on the CLIENT site, with a token from the service:
 *
 *   wp eval-file tests/e2e/cloud-roundtrip.php <token> [pattern-id]
 *
 * `pattern-id` is a theme pattern's namespaced name and defaults to one with
 * a local image, which is the case worth checking. The site must already
 * point at the service (`PATTERN_BUILDER_CLOUD_URL`, or the option).
 *
 * Exits non-zero on the first thing that does not hold.
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
update_user_meta( 1, Pattern_Builder_Cloud::META_ACCOUNT, array( 'id' => 0, 'name' => 'e2e' ) );

$controller = new Pattern_Builder_Cloud_Controller();
$out        = array();

/**
 * Runs a cloud endpoint, stopping the script on a WP_Error.
 *
 * @param string $step   What is being attempted, for the failure message.
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
 * @param string $route  Route under /pattern-builder/v1/cloud.
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

// 0. The connection itself — a live /me round trip.
$out['status'] = $controller->status()->get_data();
if ( empty( $out['status']['connected'] ) ) {
	WP_CLI::error( 'Not connected: ' . wp_json_encode( $out['status'] ) );
}

// 1. Upload. The pattern's images travel with it as package assets.
$out['upload'] = $attempt(
	'Upload',
	$controller->upload(
		$request(
			'upload',
			array(
				'patternType' => 'theme',
				'patternId'   => $pattern_id,
				'categories'  => array( 'E2E Roundtrip' ),
			)
		)
	)
);
$cloud_id = $out['upload']['pattern']['id'];

// 2. Upload again. The link map should send this down the update path
// rather than creating a second cloud copy.
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

// 3. Download it back, this time as a user pattern.
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

// 4. What actually landed.
$post    = get_post( $out['download']['id'] );
$uploads = wp_get_upload_dir();

preg_match( '/src="([^"]+)"/', $post->post_content, $src );
$image_url  = isset( $src[1] ) ? $src[1] : '';
$image_path = str_replace( $uploads['baseurl'], $uploads['basedir'], strtok( $image_url, '?' ) );

$checks = array(
	'landed as a user pattern'      => 'wp_block' === $post->post_type,
	'took the update path'          => ! empty( $out['reupload']['updated'] ) && $out['reupload']['sameId'],
	'no placeholders left behind'   => false === strpos( $post->post_content, 'pbp-asset:' ),
	'image points at this site'     => $image_url && 0 === strpos( $image_url, $uploads['baseurl'] ),
	'image file was fetched'        => $image_path && file_exists( $image_path ),
	'image names its attachment'    => (bool) preg_match( '/wp-image-\d+/', $post->post_content ),
	'categories came across'        => ! empty( wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'names' ) ) ),
);

$out['checks'] = $checks;
WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT ) );

$failed = array_keys( array_filter( $checks, static function ( $passed ) { return ! $passed; } ) );

if ( $failed ) {
	WP_CLI::error( 'Round trip broke: ' . implode( '; ', $failed ) );
}

WP_CLI::success( sprintf( 'Round trip intact (cloud pattern %d → local post %d).', $cloud_id, $post->ID ) );
