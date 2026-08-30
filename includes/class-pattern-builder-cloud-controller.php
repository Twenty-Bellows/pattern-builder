<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST proxy for patternbuilderwp.com: `/pattern-builder/v1/cloud/*`.
 *
 * The browser only ever talks to these site endpoints (cookie + nonce, the
 * standard REST auth); this site's PHP talks to the service with the current
 * user's stored token. The token never reaches the browser and no CORS
 * surface exists.
 */
class Pattern_Builder_Cloud_Controller {

	const NS = 'pattern-builder/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the proxy routes.
	 */
	public function register_routes() {
		$can_manage = function () {
			return current_user_can( 'edit_theme_options' )
				? true
				: new WP_Error( 'pb_cloud_forbidden', __( 'You cannot manage patterns on this site.', 'pattern-builder' ), array( 'status' => rest_authorization_required_code() ) );
		};

		$routes = array(
			'/cloud/status'           => array( 'GET', 'status' ),
			'/cloud/connect'          => array( 'POST', 'connect' ),
			'/cloud/connect/complete' => array( 'POST', 'connect_complete' ),
			'/cloud/disconnect'       => array( 'POST', 'disconnect' ),
			'/cloud/library'          => array( 'GET', 'library' ),
			'/cloud/categories'       => array( 'GET', 'categories' ),
			'/cloud/directory'        => array( 'GET', 'directory' ),
			'/cloud/collections'      => array( 'GET', 'collections' ),
			'/cloud/links'            => array( 'GET', 'links' ),
			'/cloud/upload'           => array( 'POST', 'upload' ),
			'/cloud/download'         => array( 'POST', 'download' ),
		);

		foreach ( $routes as $route => $handler ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $handler[0],
					'permission_callback' => $can_manage,
					'callback'            => array( $this, $handler[1] ),
				)
			);
		}

		register_rest_route(
			self::NS,
			'/cloud/library/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can_manage,
				'callback'            => array( $this, 'delete_library_pattern' ),
			)
		);
	}

	/**
	 * GET /cloud/status — connection state plus live account/usage.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return rest_ensure_response(
				array(
					'connected'  => false,
					'serviceUrl' => Pattern_Builder_Cloud::service_url(),
				)
			);
		}

		$me = Pattern_Builder_Cloud::request( 'GET', '/me' );
		if ( is_wp_error( $me ) ) {
			// A dead token was already forgotten by the client; report state.
			return rest_ensure_response(
				array(
					'connected'  => Pattern_Builder_Cloud::is_connected(),
					'serviceUrl' => Pattern_Builder_Cloud::service_url(),
					'error'      => $me->get_error_message(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'connected'  => true,
				'serviceUrl' => Pattern_Builder_Cloud::service_url(),
				'account'    => $me['account'],
				'tier'       => $me['tier'],
				'usage'      => $me['usage'],
			)
		);
	}

	/**
	 * POST /cloud/connect — begin the PKCE flow.
	 *
	 * @return WP_REST_Response
	 */
	public function connect() {
		return rest_ensure_response( Pattern_Builder_Cloud::start_connect() );
	}

	/**
	 * POST /cloud/connect/complete — exchange the returned code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect_complete( $request ) {
		$result = Pattern_Builder_Cloud::complete_connect(
			(string) $request->get_param( 'code' ),
			(string) $request->get_param( 'state' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->status();
	}

	/**
	 * POST /cloud/disconnect
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect() {
		Pattern_Builder_Cloud::disconnect();
		return rest_ensure_response( array( 'connected' => false ) );
	}

	/**
	 * GET /cloud/library — the account's cloud patterns.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function library( $request ) {
		return $this->proxy_list( '/library/patterns', $request );
	}

	/**
	 * GET /cloud/categories — the account's cloud categories.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function categories() {
		return rest_ensure_response( Pattern_Builder_Cloud::request( 'GET', '/library/categories' ) );
	}

	/**
	 * GET /cloud/directory — the public directory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function directory( $request ) {
		return $this->proxy_list( '/directory/patterns', $request );
	}

	/**
	 * GET /cloud/collections — public + premium collections.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function collections() {
		return rest_ensure_response( Pattern_Builder_Cloud::request( 'GET', '/directory/collections' ) );
	}

	/**
	 * GET /cloud/links — which local patterns have cloud copies.
	 *
	 * @return WP_REST_Response
	 */
	public function links() {
		return rest_ensure_response( Pattern_Builder_Cloud::links() );
	}

	/**
	 * POST /cloud/upload — send a local pattern to the account's library.
	 *
	 * Params: patternType (theme|user), patternId, categories (string[]),
	 * asNew (bool — force a new cloud copy even when linked).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return new WP_Error( 'pb_cloud_disconnected', __( 'Connect to patternbuilderwp.com first.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$type = 'user' === $request->get_param( 'patternType' ) ? 'user' : 'theme';
		$id   = 'user' === $type ? (int) $request->get_param( 'patternId' ) : (string) $request->get_param( 'patternId' );

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( $type, $id );
		if ( is_wp_error( $exported ) ) {
			return $exported;
		}

		$categories = $request->get_param( 'categories' );
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			$exported['pbp']['categories'] = array_map( 'sanitize_text_field', $categories );
		}

		$links    = Pattern_Builder_Cloud::links();
		$existing = isset( $links[ $exported['localKey'] ] ) ? (int) $links[ $exported['localKey'] ]['cloudId'] : 0;
		$as_new   = (bool) $request->get_param( 'asNew' );

		if ( $existing && ! $as_new ) {
			$result = Pattern_Builder_Cloud::upload( 'PUT', '/library/patterns/' . $existing, $exported['pbp'], $exported['files'] );
			// The cloud copy may have been deleted remotely; fall through to create.
			if ( is_wp_error( $result ) && 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
				$existing = 0;
			} elseif ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! $existing || $as_new ) {
			$result = Pattern_Builder_Cloud::upload( 'POST', '/library/patterns', $exported['pbp'], $exported['files'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $result['id'] ) ) {
			Pattern_Builder_Cloud::set_link( $exported['localKey'], (int) $result['id'] );
		}

		return rest_ensure_response(
			array(
				'pattern'  => $result,
				'updated'  => (bool) $existing && ! $as_new,
				'localKey' => $exported['localKey'],
			)
		);
	}

	/**
	 * POST /cloud/download — bring a cloud pattern onto this site.
	 *
	 * Params: source (library|directory), cloudId, destination (user|theme).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download( $request ) {
		$source      = 'library' === $request->get_param( 'source' ) ? 'library' : 'directory';
		$cloud_id    = (int) $request->get_param( 'cloudId' );
		$destination = 'theme' === $request->get_param( 'destination' ) ? 'theme' : 'user';

		if ( ! $cloud_id ) {
			return new WP_Error( 'pb_cloud_bad_request', __( 'Which pattern?', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		if ( 'library' === $source && ! Pattern_Builder_Cloud::is_connected() ) {
			return new WP_Error( 'pb_cloud_disconnected', __( 'Connect to patternbuilderwp.com first.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$pbp = Pattern_Builder_Cloud::request( 'POST', "/{$source}/patterns/{$cloud_id}/download" );
		if ( is_wp_error( $pbp ) ) {
			return $pbp;
		}

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, $destination );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Remember the linkage so re-uploads offer "update".
		Pattern_Builder_Cloud::set_link( Pattern_Builder_Cloud_Porter::local_key( $result['type'], $result['id'] ), $cloud_id );

		return rest_ensure_response( $result );
	}

	/**
	 * DELETE /cloud/library/{id} — remove a cloud pattern (frees a slot).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_library_pattern( $request ) {
		$result = Pattern_Builder_Cloud::request( 'DELETE', '/library/patterns/' . (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Forget any local links pointing at it.
		foreach ( Pattern_Builder_Cloud::links() as $key => $link ) {
			if ( (int) $link['cloudId'] === (int) $request['id'] ) {
				Pattern_Builder_Cloud::set_link( $key, null );
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Proxy a paged listing endpoint.
	 *
	 * @param string          $path    Service path.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function proxy_list( $path, $request ) {
		$query = array();
		foreach ( array( 'page', 'per_page', 'search', 'category' ) as $param ) {
			$value = $request->get_param( $param );
			if ( null !== $value && '' !== $value ) {
				$query[ $param ] = sanitize_text_field( (string) $value );
			}
		}

		return rest_ensure_response( Pattern_Builder_Cloud::request( 'GET', $path, array( 'query' => $query ) ) );
	}
}
