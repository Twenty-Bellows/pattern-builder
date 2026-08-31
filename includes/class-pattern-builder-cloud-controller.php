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

	/**
	 * Hook the routes into the REST API.
	 */
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
			'/cloud/status'        => array( 'GET', 'status' ),
			'/cloud/login'         => array( 'POST', 'login' ),
			'/cloud/signup'        => array( 'POST', 'signup' ),
			'/cloud/disconnect'    => array( 'POST', 'disconnect' ),
			'/cloud/library'       => array( 'GET', 'library' ),
			'/cloud/categories'    => array( 'GET', 'categories' ),
			'/cloud/directory'     => array( 'GET', 'directory' ),
			'/cloud/collections'   => array( 'GET', 'collections' ),
			'/cloud/links'         => array( 'GET', 'links' ),
			'/cloud/pattern-state' => array( 'GET', 'pattern_state' ),
			'/cloud/upload'        => array( 'POST', 'upload' ),
			'/cloud/download'      => array( 'POST', 'download' ),
			'/cloud/generate'      => array( 'POST', 'generate' ),
			'/cloud/tokens/check'  => array( 'POST', 'tokens_check' ),
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

		register_rest_route(
			self::NS,
			'/cloud/generate/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_manage,
				'callback'            => array( $this, 'generate_status' ),
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
				'upgradeUrl' => isset( $me['upgrade_url'] ) ? $me['upgrade_url'] : '',
				'ai'         => isset( $me['ai'] ) ? $me['ai'] : array( 'enabled' => false ),
			)
		);
	}

	/**
	 * POST /cloud/login — sign in to the service without leaving wp-admin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( $request ) {
		$result = Pattern_Builder_Cloud::login(
			(string) $request->get_param( 'email' ),
			(string) $request->get_param( 'password' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->status();
	}

	/**
	 * POST /cloud/signup — create a service account without leaving wp-admin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function signup( $request ) {
		$result = Pattern_Builder_Cloud::signup(
			(string) $request->get_param( 'email' ),
			(string) $request->get_param( 'password' ),
			(string) $request->get_param( 'name' )
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
	 * GET /cloud/pattern-state — one pattern's cloud standing, answered
	 * from the link map and a local content hash (no service round trip).
	 * Params: patternType + patternId, or cloudId for the reverse lookup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pattern_state( $request ) {
		$cloud_id = (int) $request->get_param( 'cloudId' );
		if ( $cloud_id ) {
			return rest_ensure_response( array( 'installed' => $this->find_installed( $cloud_id ) ) );
		}

		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return rest_ensure_response( array( 'connected' => false ) );
		}

		$type = 'user' === $request->get_param( 'patternType' ) ? 'user' : 'theme';
		$id   = 'user' === $type ? (int) $request->get_param( 'patternId' ) : (string) $request->get_param( 'patternId' );

		$porter = new Pattern_Builder_Cloud_Porter();
		$hash   = $porter->content_hash( $type, $id );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}

		$links = Pattern_Builder_Cloud::links();
		$key   = Pattern_Builder_Cloud_Porter::local_key( $type, $id );

		if ( empty( $links[ $key ] ) ) {
			return rest_ensure_response(
				array(
					'connected' => true,
					'linked'    => false,
				)
			);
		}

		$link = $links[ $key ];

		return rest_ensure_response(
			array(
				'connected'  => true,
				'linked'     => true,
				'cloudId'    => (int) $link['cloudId'],
				// A link with no stored hash predates change tracking; treat
				// it as changed so the panel offers an update.
				'changed'    => empty( $link['hash'] ) || $link['hash'] !== $hash,
				// Only the account that owns a cloud pattern can update it.
				// A link made before this was recorded reads as ours, which
				// is what one almost always was; a refused update corrects
				// the record.
				'owned'      => ! isset( $link['owned'] ) || (bool) $link['owned'],
				'uploadedAt' => isset( $link['uploadedAt'] ) ? (int) $link['uploadedAt'] : 0,
			)
		);
	}

	/**
	 * Which local pattern (if any) a cloud pattern is installed as; a
	 * deleted local copy reads as not installed.
	 *
	 * @param int $cloud_id Cloud pattern ID.
	 * @return array|null { type: string, id: string|int, title: string }
	 */
	private function find_installed( $cloud_id ) {
		$porter = new Pattern_Builder_Cloud_Porter();

		foreach ( Pattern_Builder_Cloud::links() as $key => $link ) {
			if ( (int) $link['cloudId'] !== $cloud_id ) {
				continue;
			}

			$parts = explode( ':', (string) $key, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$type  = 'user' === $parts[0] ? 'user' : 'theme';
			$id    = 'user' === $type ? (int) $parts[1] : $parts[1];
			$local = $porter->describe_local( $type, $id );
			if ( $local ) {
				return $local;
			}
		}

		return null;
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
			// POST, not PUT: PHP only parses multipart bodies on POST.
			$result = Pattern_Builder_Cloud::upload( 'POST', '/library/patterns/' . $existing, $exported['pbp'], $exported['files'] );
			// The cloud copy may have been deleted remotely; fall through to create.
			if ( is_wp_error( $result ) && 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
				$existing = 0;
			} elseif ( is_wp_error( $result ) ) {
				/*
				 * Somebody else's pattern: this link was made by downloading
				 * it, not by uploading it. Remember that, so the panel stops
				 * offering an update that can only ever be refused.
				 */
				if ( 'pbwp_forbidden' === $result->get_error_code() ) {
					Pattern_Builder_Cloud::disown_link( $exported['localKey'] );
				}

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
			Pattern_Builder_Cloud::set_link( $exported['localKey'], (int) $result['id'], $exported['contentHash'] );
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
	 * Params: source (library|directory), cloudId, destination (user|theme),
	 * addTokens (whether to add the design tokens the site is missing, which
	 * go to the same destination as the pattern).
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

		/*
		 * Missing design tokens land wherever the pattern lands — the
		 * destination is the one the user already chose, never a second
		 * question — and only the missing ones: apply() re-checks, so a
		 * token this site already defines keeps its own value (§4a).
		 */
		$tokens_written = array();
		if ( ! empty( $pbp['tokens'] ) && $request->get_param( 'addTokens' ) ) {
			$tokens_written = Pattern_Builder_Cloud_Tokens::apply( $pbp['tokens'], $destination );
			if ( is_wp_error( $tokens_written ) ) {
				return $tokens_written;
			}
		}

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, $destination );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The imported content matches its cloud copy, so the stored hash reads as up to date.
		$hash = $porter->content_hash( $result['type'], $result['id'] );

		/*
		 * Whether the cloud copy is this account's to update later. One from
		 * the account's own library always is; one from the directory only if
		 * the service said so when it listed it. The service is the authority
		 * either way — this is what keeps an Update button off a pattern
		 * somebody else published, rather than what enforces it.
		 */
		$owned = 'library' === $source || (bool) $request->get_param( 'mine' );

		Pattern_Builder_Cloud::set_link(
			Pattern_Builder_Cloud_Porter::local_key( $result['type'], $result['id'] ),
			$cloud_id,
			is_wp_error( $hash ) ? '' : $hash,
			$owned
		);

		$result['tokensWritten'] = $tokens_written;
		return rest_ensure_response( $result );
	}

	/**
	 * POST /cloud/tokens/check — which of a pattern's tokens this site
	 * lacks, so the download flow knows whether to ask where to put them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function tokens_check( $request ) {
		$tokens = $request->get_param( 'tokens' );
		return rest_ensure_response(
			array(
				'missing' => Pattern_Builder_Cloud_Tokens::missing( is_array( $tokens ) ? $tokens : array() ),
			)
		);
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

		foreach ( Pattern_Builder_Cloud::links() as $key => $link ) {
			if ( (int) $link['cloudId'] === (int) $request['id'] ) {
				Pattern_Builder_Cloud::set_link( $key, null );
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /cloud/generate — submit an AI generation (prompt and/or image).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return new WP_Error( 'pb_cloud_disconnected', __( 'Connect your patternbuilderwp.com account to generate patterns.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$prompt = trim( (string) $request->get_param( 'prompt' ) );
		$files  = $request->get_file_params();

		if ( ! empty( $files['image']['tmp_name'] ) && empty( $files['image']['error'] ) ) {
			$result = Pattern_Builder_Cloud::form_request(
				'POST',
				'/ai/generations',
				array( 'prompt' => $prompt ),
				array(
					'image' => array(
						'path' => $files['image']['tmp_name'],
						'name' => isset( $files['image']['name'] ) ? $files['image']['name'] : 'screenshot.png',
						'type' => isset( $files['image']['type'] ) ? $files['image']['type'] : '',
					),
				)
			);
		} else {
			$result = Pattern_Builder_Cloud::request( 'POST', '/ai/generations', array( 'body' => array( 'prompt' => $prompt ) ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * GET /cloud/generate/{id} — poll a generation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_status( $request ) {
		$result = Pattern_Builder_Cloud::request( 'GET', '/ai/generations/' . (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
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
