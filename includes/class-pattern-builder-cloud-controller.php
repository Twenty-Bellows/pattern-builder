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
			'/cloud/status'              => array( 'GET', 'status' ),
			'/cloud/login'               => array( 'POST', 'login' ),
			'/cloud/signup'              => array( 'POST', 'signup' ),
			'/cloud/disconnect'          => array( 'POST', 'disconnect' ),
			'/cloud/library'             => array( 'GET', 'library' ),
			'/cloud/library/collections' => array( array( 'GET', 'library_collections' ), array( 'POST', 'create_collection' ) ),
			'/cloud/directory'           => array( 'GET', 'directory' ),
			'/cloud/collections'         => array( 'GET', 'collections' ),
			'/cloud/links'               => array( 'GET', 'links' ),
			'/cloud/pattern-state'       => array( 'GET', 'pattern_state' ),
			'/cloud/pattern-tree'        => array( 'GET', 'pattern_tree' ),
			'/cloud/upload'              => array( 'POST', 'upload' ),
			'/cloud/download'            => array( 'POST', 'download' ),
			'/cloud/tokens/check'        => array( 'POST', 'tokens_check' ),
			'/cloud/password/forgot'     => array( 'POST', 'forgot_password' ),
			'/cloud/verify/resend'       => array( 'POST', 'resend_verification' ),
			'/cloud/billing/sync'        => array( 'POST', 'sync_billing' ),
		);

		foreach ( $routes as $route => $handlers ) {
			// One handler, or a list of (method, callback) pairs for a route
			// that answers more than one method.
			$handlers = is_array( $handlers[0] ) ? $handlers : array( $handlers );
			$args     = array();
			foreach ( $handlers as $handler ) {
				$args[] = array(
					'methods'             => $handler[0],
					'permission_callback' => $can_manage,
					'callback'            => array( $this, $handler[1] ),
				);
			}
			register_rest_route( self::NS, $route, $args );
		}

		register_rest_route(
			self::NS,
			'/cloud/library/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can_manage,
					'callback'            => array( $this, 'delete_library_pattern' ),
				),
				array(
					'methods'             => array( 'PUT', 'POST' ),
					'permission_callback' => $can_manage,
					'callback'            => array( $this, 'move_library_pattern' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cloud/library/collections/(?P<id>\d+)',
			array(
				array(
					'methods'             => array( 'PUT', 'POST' ),
					'permission_callback' => $can_manage,
					'callback'            => array( $this, 'update_collection' ),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can_manage,
					'callback'            => array( $this, 'delete_collection' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cloud/collections/(?P<owner>\d+)/(?P<slug>[a-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_manage,
				'callback'            => array( $this, 'collection' ),
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
				'connected'    => true,
				'serviceUrl'   => Pattern_Builder_Cloud::service_url(),
				'account'      => $me['account'],
				'tier'         => $me['tier'],
				'usage'        => isset( $me['usage'] ) ? $me['usage'] : array(),
				// The tiers as the service cuts them: the Personal cap, whether
				// a private collection may be made, the fair-use ceilings.
				'entitlements' => isset( $me['entitlements'] ) ? $me['entitlements'] : array(),
				// Personal's meter: { id, count, cap } with cap -1 for none.
				'personal'     => isset( $me['personal'] ) ? $me['personal'] : array(),
				// A lapsed Pro holding more than a free account may.
				'overPolicy'   => ! empty( $me['over_policy'] ),
				'upgradeUrl'   => isset( $me['upgrade_url'] ) ? $me['upgrade_url'] : '',
				// What the overlay checkout needs, or null once Pro (or
				// until the service's product is configured).
				'checkout'     => isset( $me['checkout'] ) ? $me['checkout'] : null,
				'portalUrl'    => isset( $me['portal_url'] ) ? $me['portal_url'] : '',
				'telemetry'    => Pattern_Builder_Telemetry::client_state(),
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
		Pattern_Builder_Telemetry::record( 'account_connected', array( 'kind' => 'login' ) );
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
			(string) $request->get_param( 'handle' ),
			(string) $request->get_param( 'name' ),
			rest_sanitize_boolean( $request->get_param( 'marketing' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Pattern_Builder_Telemetry::record( 'account_connected', array( 'kind' => 'signup' ) );
		return $this->status();
	}

	/**
	 * POST /cloud/password/forgot — have the service email a reset link.
	 *
	 * The link opens on patternbuilderwp.com; the plugin only starts it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function forgot_password( $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'pb_cloud_bad_email', __( 'Enter a valid email address.', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Pattern_Builder_Cloud::forgot_password( $email ) );
	}

	/**
	 * POST /cloud/verify/resend — a fresh confirmation email.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function resend_verification() {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return new WP_Error( 'pb_cloud_disconnected', __( 'Connect to patternbuilderwp.com first.', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Pattern_Builder_Cloud::resend_verification() );
	}

	/**
	 * POST /cloud/billing/sync — the overlay checkout reported a purchase.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_billing( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return new WP_Error( 'pb_cloud_disconnected', __( 'Connect to patternbuilderwp.com first.', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		$result = Pattern_Builder_Cloud::sync_billing( (int) $request->get_param( 'licenseId' ) );
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
		Pattern_Builder_Telemetry::record( 'account_disconnected' ); // While the account is still known.
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
	 * GET /cloud/library/collections — the account's collections, Personal
	 * first, with counts.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function library_collections() {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return self::disconnected();
		}
		return rest_ensure_response( Pattern_Builder_Cloud::request( 'GET', '/library/collections' ) );
	}

	/**
	 * POST /cloud/library/collections — create one. The service decides
	 * what an account may make (free: public only) and its refusal is
	 * relayed as it came, upgrade link included.
	 *
	 * Params: name, description, visibility (optional).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_collection( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return self::disconnected();
		}

		$body = array(
			'name'        => sanitize_text_field( (string) $request->get_param( 'name' ) ),
			// The collection's permanent slug. Its shape is the service's
			// to judge; this only stops obvious rubbish reaching it.
			'slug'        => sanitize_key( (string) $request->get_param( 'slug' ) ),
			'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
		);
		if ( null !== $request->get_param( 'visibility' ) ) {
			$body['visibility'] = sanitize_key( $request->get_param( 'visibility' ) );
		}

		return rest_ensure_response( Pattern_Builder_Cloud::request( 'POST', '/library/collections', array( 'body' => $body ) ) );
	}

	/**
	 * PUT /cloud/library/collections/{id} — rename, describe, set visibility.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_collection( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return self::disconnected();
		}

		$body = array();
		foreach ( array( 'name', 'description', 'visibility' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$body[ $field ] = 'description' === $field ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
			}
		}

		return rest_ensure_response( Pattern_Builder_Cloud::request( 'PUT', '/library/collections/' . (int) $request['id'], array( 'body' => $body ) ) );
	}

	/**
	 * DELETE /cloud/library/collections/{id}?patterns=delete|move
	 *
	 * A refused move (past the Personal cap) is relayed as it came, with
	 * the upgrade link; delete remains available. Links to patterns the
	 * service deleted are forgotten here too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_collection( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return self::disconnected();
		}

		$patterns = 'move' === $request->get_param( 'patterns' ) ? 'move' : 'delete';
		$result   = Pattern_Builder_Cloud::request(
			'DELETE',
			'/library/collections/' . (int) $request['id'],
			array( 'query' => array( 'patterns' => $patterns ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * PUT /cloud/library/{id} — move a cloud pattern into another of the
	 * account's collections. Params: collection (an id, or `personal`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function move_library_pattern( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return self::disconnected();
		}

		$collection = $request->get_param( 'collection' );
		if ( null === $collection || '' === $collection ) {
			return new WP_Error( 'pb_cloud_bad_request', __( 'Which collection?', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			Pattern_Builder_Cloud::request(
				'PUT',
				'/library/patterns/' . (int) $request['id'],
				array( 'body' => array( 'collection' => is_numeric( $collection ) ? (int) $collection : sanitize_key( $collection ) ) )
			)
		);
	}

	/**
	 * Every write to the cloud needs a connection.
	 *
	 * @return WP_Error
	 */
	private static function disconnected() {
		return new WP_Error( 'pb_cloud_disconnected', __( 'Connect to patternbuilderwp.com first.', 'pattern-builder' ), array( 'status' => 400 ) );
	}

	/**
	 * GET /cloud/directory — the public directory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function directory( $request ) {
		$gate = self::require_connection();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return $this->proxy_list( '/directory/patterns', $request );
	}

	/**
	 * The community is browsed as an account.
	 *
	 * The service lists its directory to anyone; this plugin asks people
	 * to sign in first, so what is downloaded onto a site is downloaded
	 * by somebody. The proxy enforces it, not just the tab.
	 *
	 * @return true|WP_Error
	 */
	private static function require_connection() {
		if ( Pattern_Builder_Cloud::is_connected() ) {
			return true;
		}
		return new WP_Error( 'pb_cloud_disconnected', __( 'Sign in to patternbuilderwp.com to browse community patterns.', 'pattern-builder' ), array( 'status' => 401 ) );
	}

	/**
	 * GET /cloud/collections — public + premium collections: search, page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function collections( $request ) {
		$gate = self::require_connection();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return $this->proxy_list( '/directory/collections', $request );
	}

	/**
	 * GET /cloud/collections/{owner}/{slug} — one collection with its
	 * pattern summaries (tokens included, so the union check needs no
	 * second pass), each marked with whether it is installed here already.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function collection( $request ) {
		$gate = self::require_connection();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$collection = Pattern_Builder_Cloud::request( 'GET', '/directory/collections/' . (int) $request['owner'] . '/' . sanitize_title( $request['slug'] ) );
		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		$porter = new Pattern_Builder_Cloud_Porter();
		if ( ! empty( $collection['patterns'] ) && is_array( $collection['patterns'] ) ) {
			foreach ( $collection['patterns'] as &$pattern ) {
				$pattern['installed'] = isset( $pattern['id'] ) ? $porter->find_installed( (int) $pattern['id'] ) : null;
			}
			unset( $pattern );
		}

		return rest_ensure_response( $collection );
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
	 * GET /cloud/pattern-tree — what an upload of this pattern would carry.
	 *
	 * The panel has to say "this uploads five patterns" before it uploads
	 * five patterns, and it has to run the block-validity gate over every
	 * one of them, so each member comes back with its markup. Answered
	 * locally: the walk is over pattern files on this site and asks the
	 * service nothing.
	 *
	 * Params: patternType + patternId.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pattern_tree( $request ) {
		$type = 'user' === $request->get_param( 'patternType' ) ? 'user' : 'theme';
		$id   = 'user' === $type ? (int) $request->get_param( 'patternId' ) : (string) $request->get_param( 'patternId' );

		$porter = new Pattern_Builder_Cloud_Porter();
		$tree   = $porter->local_tree( $type, $id );

		// A missing dependency or a loop is the answer, not a failure: the
		// panel says what is wrong instead of offering an upload that cannot
		// work.
		if ( is_wp_error( $tree ) ) {
			return rest_ensure_response(
				array(
					'members' => array(),
					'problem' => $tree->get_error_message(),
					'code'    => $tree->get_error_code(),
				)
			);
		}

		$members = array();
		foreach ( $tree['order'] as $member ) {
			$pattern = $porter->local_pattern( $member['type'], $member['id'] );
			if ( is_wp_error( $pattern ) ) {
				continue;
			}

			$members[] = array(
				'type'    => $member['type'],
				'id'      => $member['id'],
				'name'    => $member['name'],
				'title'   => (string) $pattern->title,
				'content' => (string) $pattern->content,
			);
		}

		return rest_ensure_response(
			array(
				'members' => $members,
				'problem' => '',
				'code'    => '',
			)
		);
	}

	/**
	 * GET /cloud/pattern-state — one pattern's cloud standing, answered    /**
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
			return rest_ensure_response( array( 'installed' => ( new Pattern_Builder_Cloud_Porter() )->find_installed( $cloud_id ) ) );
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
				// The cloud collection the copy is in, as last recorded.
				'collection' => isset( $link['collection'] ) && is_array( $link['collection'] ) ? $link['collection'] : array(),
			)
		);
	}

	/**
	 * POST /cloud/upload — send a local pattern to the account's library.
	 *
	 * Params: patternType (theme|user), patternId, collection (a collection
	 * id or `personal`; `personal` when left out — the one case nothing
	 * asks), asNew (bool — force a new cloud copy even when linked).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $request ) {
		if ( ! Pattern_Builder_Cloud::is_connected() ) {
			return self::disconnected();
		}

		$type = 'user' === $request->get_param( 'patternType' ) ? 'user' : 'theme';
		$id   = 'user' === $type ? (int) $request->get_param( 'patternId' ) : (string) $request->get_param( 'patternId' );

		$collection = $request->get_param( 'collection' );
		$result     = self::upload_pattern(
			$type,
			$id,
			( null === $collection || '' === $collection ) ? null : $collection,
			(bool) $request->get_param( 'asNew' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Send a local pattern to the account's library: the work behind the
	 * upload route, shared with the upload-pattern ability.
	 *
	 * A pattern brings its dependencies with it (D38). A page pattern is
	 * `core/pattern` references to the sections it is built out of, and a
	 * collection is a closed world, so uploading one uploads the tree below
	 * it — leaves first, with every reference rewritten to name the
	 * collection they are all going into.
	 *
	 * Each member already linked to a cloud copy updates it (the root
	 * unless `as_new`); each one not yet linked is created in the named
	 * collection, or Personal when none is — the one case nothing asks. The
	 * link map records the cloud id, the content hash and the collection,
	 * per member.
	 *
	 * @param string     $type       'theme' or 'user'.
	 * @param string|int $id         Local identifier.
	 * @param mixed      $collection A collection id or `personal`, or null to leave it unsaid.
	 * @param bool       $as_new     Force a new cloud copy even when linked.
	 * @return array|WP_Error { pattern, updated, localKey }
	 */
	public static function upload_pattern( $type, $id, $collection = null, $as_new = false ) {
		$porter = new Pattern_Builder_Cloud_Porter();

		$tree = $porter->local_tree( $type, $id );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$named            = null !== $collection;
		$collection_param = ! $named ? 'personal' : ( is_numeric( $collection ) ? (int) $collection : sanitize_key( $collection ) );

		/*
		 * A pattern that references nothing needs no namespace: there is
		 * nothing to rewrite, so an ordinary upload costs exactly what it
		 * always did. A tree has to ask the service where it is going,
		 * because only the service knows the account's handle and the
		 * collection's slug.
		 */
		$target_namespace = '';
		if ( count( $tree['order'] ) > 1 ) {
			$target = self::resolve_upload_collection( $collection_param );
			if ( is_wp_error( $target ) ) {
				return $target;
			}

			$room = self::room_for_tree( $tree['order'], $target );
			if ( is_wp_error( $room ) ) {
				return $room;
			}

			$target_namespace = $target['namespace'];
		}

		/*
		 * Leaves first, so every reference resolves by the time the pattern
		 * making it arrives. A member that fails stops the upload and is
		 * reported; the members already up are left where they are, since
		 * each is a valid pattern on its own and deleting somebody's work
		 * over a network blip is worse than leaving it.
		 */
		$members = array();
		$result  = null;
		$last    = count( $tree['order'] ) - 1;

		foreach ( $tree['order'] as $index => $member ) {
			// `as_new` is the root's business. A dependency already linked
			// is updated, never duplicated.
			$uploaded = self::upload_one(
				$porter,
				$member['type'],
				$member['id'],
				$collection_param,
				$target_namespace,
				$as_new && $index === $last
			);

			if ( is_wp_error( $uploaded ) ) {
				$uploaded->add_data(
					array_merge(
						(array) $uploaded->get_error_data(),
						array(
							'pattern'  => $member['name'],
							'uploaded' => $members,
						)
					)
				);
				return $uploaded;
			}

			$members[] = $member['name'];
			$result    = $uploaded;
		}

		Pattern_Builder_Telemetry::record(
			'pattern_uploaded',
			array(
				'source' => $type,
				'kind'   => $result['updated'] ? 'update' : 'new',
			)
		);

		return array(
			'pattern'  => $result['pattern'],
			'updated'  => $result['updated'],
			'localKey' => $result['localKey'],
			// Everything that went up, the root last. A page pattern is
			// several patterns and the panel says so.
			'members'  => $members,
		);
	}

	/**
	 * Upload one member of a tree: update its cloud copy, or create it.
	 *
	 * @param Pattern_Builder_Cloud_Porter $porter     The porter.
	 * @param string                       $type       'theme' or 'user'.
	 * @param string|int                   $id         Local identifier.
	 * @param string|int                   $collection The collection a create lands in.
	 * @param string                       $target_namespace  What to point references at, or ''.
	 * @param bool                         $as_new     Force a new cloud copy even when linked.
	 * @return array|WP_Error { pattern, updated, localKey }
	 */
	private static function upload_one( $porter, $type, $id, $collection, $target_namespace, $as_new ) {
		$exported = $porter->export_local( $type, $id, $target_namespace );
		if ( is_wp_error( $exported ) ) {
			return $exported;
		}

		$links    = Pattern_Builder_Cloud::links();
		$existing = isset( $links[ $exported['localKey'] ] ) ? (int) $links[ $exported['localKey'] ]['cloudId'] : 0;
		$result   = null;

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
			$result = Pattern_Builder_Cloud::upload(
				'POST',
				'/library/patterns',
				$exported['pbp'],
				$exported['files'],
				array( 'collection' => $collection )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $result['id'] ) ) {
			Pattern_Builder_Cloud::set_link(
				$exported['localKey'],
				(int) $result['id'],
				$exported['contentHash'],
				true,
				isset( $result['collection'] ) ? $result['collection'] : array()
			);
		}

		return array(
			'pattern'  => $result,
			'updated'  => (bool) $existing && ! $as_new,
			'localKey' => $exported['localKey'],
		);
	}

	/**
	 * The collection an upload is going into, with the namespace its
	 * patterns will be named under.
	 *
	 * The namespace is what every reference in the tree is rewritten to
	 * point at, so it has to be known before anything is sent — which means
	 * asking the service, since only it knows the account's handle and the
	 * collection's slug.
	 *
	 * @param string|int $wanted A collection id, or `personal`.
	 * @return array|WP_Error { id, namespace, count, personal }
	 */
	private static function resolve_upload_collection( $wanted ) {
		$collections = Pattern_Builder_Cloud::request( 'GET', '/library/collections' );
		if ( is_wp_error( $collections ) ) {
			return $collections;
		}

		foreach ( (array) $collections as $candidate ) {
			$matches = 'personal' === $wanted
				? ! empty( $candidate['personal'] )
				: (int) ( $candidate['id'] ?? 0 ) === $wanted;

			if ( $matches ) {
				return array(
					'id'        => (int) $candidate['id'],
					'namespace' => (string) ( $candidate['namespace'] ?? '' ),
					'count'     => (int) ( $candidate['count'] ?? 0 ),
					'personal'  => ! empty( $candidate['personal'] ),
				);
			}
		}

		return new WP_Error(
			'pb_cloud_no_collection',
			__( 'That collection is not on your account any more.', 'pattern-builder' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Whether a whole tree fits where it is going.
	 *
	 * The service refuses past the cap anyway, but it refuses one pattern at
	 * a time — which for a tree means stopping half way. Counting first turns
	 * that into one refusal before anything is sent. The service is still the
	 * check that counts.
	 *
	 * @param array $order  The tree's members.
	 * @param array $target The collection they are going into.
	 * @return true|WP_Error
	 */
	private static function room_for_tree( $order, $target ) {
		if ( ! $target['personal'] || count( $order ) < 2 ) {
			return true;
		}

		$me = Pattern_Builder_Cloud::request( 'GET', '/me' );
		if ( is_wp_error( $me ) ) {
			return true; // Not our question to answer offline; let the upload try.
		}

		$cap = isset( $me['entitlements']['personal_cap'] ) ? (int) $me['entitlements']['personal_cap'] : -1;
		if ( -1 === $cap ) {
			return true;
		}

		// Only members that are not already linked will be created.
		$links = Pattern_Builder_Cloud::links();
		$new   = 0;
		foreach ( $order as $member ) {
			if ( empty( $links[ Pattern_Builder_Cloud_Porter::local_key( $member['type'], $member['id'] ) ] ) ) {
				++$new;
			}
		}

		if ( $target['count'] + $new <= $cap ) {
			return true;
		}

		return new WP_Error(
			'pb_cloud_personal_cap',
			sprintf(
				/* translators: 1: number of patterns the upload would add, 2: the cap. */
				__( 'This pattern brings %1$d patterns with it, which is more than the %2$d your Personal collection holds. Upload it into another collection, or go Pro.', 'pattern-builder' ),
				$new,
				$cap
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * POST /cloud/download — bring a cloud pattern onto this site.
	 *
	 * Params: source (library|directory), cloudId, destination (user|theme),
	 * addTokens (whether to add the design tokens the site is missing, which
	 * go to the same destination as the pattern), collection (the
	 * { owner, slug, title } the pattern is in, so the porter can file it
	 * under the collection's local category), mine (whose the cloud copy is,
	 * as the service reported it).
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
		$gate = self::require_connection();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$collection = $request->get_param( 'collection' );

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern(
			$cloud_id,
			$destination,
			(bool) $request->get_param( 'addTokens' ),
			is_array( $collection ) ? $collection : array(),
			(bool) $request->get_param( 'mine' ),
			$source
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Pattern_Builder_Telemetry::record(
			'pattern_downloaded',
			array(
				'source'      => $source,
				'destination' => $destination,
			)
		);

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
	 * Proxy a paged listing endpoint.
	 *
	 * @param string          $path    Service path.
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function proxy_list( $path, $request ) {
		$query = array();
		foreach ( array( 'page', 'per_page', 'search', 'collection' ) as $param ) {
			$value = $request->get_param( $param );
			if ( null !== $value && '' !== $value ) {
				$query[ $param ] = sanitize_text_field( (string) $value );
			}
		}

		return rest_ensure_response( Pattern_Builder_Cloud::request( 'GET', $path, array( 'query' => $query ) ) );
	}
}
