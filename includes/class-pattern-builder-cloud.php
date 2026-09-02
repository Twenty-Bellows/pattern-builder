<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;

/**
 * The patternbuilderwp.com connection: per-WP-user token storage, the
 * in-admin credential connect, and HTTP to the service. Tokens are only
 * ever used server-side; the browser never talks to the service.
 */
class Pattern_Builder_Cloud {

	const OPTION_URL   = 'pattern_builder_cloud_url';
	const OPTION_LINKS = 'pattern_builder_cloud_links';

	/**
	 * The local pattern categories that stand for cloud collections:
	 * slug => title. A pattern installed from a collection carries a
	 * category named for it, and this is how the inserter learns the title
	 * rather than showing the slug.
	 */
	const OPTION_COLLECTION_CATEGORIES = 'pattern_builder_collection_categories';

	const META_TOKEN   = '_pattern_builder_cloud_token';
	const META_ACCOUNT = '_pattern_builder_cloud_account';

	/**
	 * Register global hooks (called once at plugin boot).
	 */
	public static function register() {
		add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_service_host' ), 10, 3 );
		add_filter( 'http_allowed_safe_ports', array( __CLASS__, 'allow_service_port' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_collection_categories' ) );
	}

	/**
	 * The slug of the local pattern category that stands for a cloud
	 * collection: `pbwp-{owner}-{slug}`, unique across accounts the way the
	 * collection's own URL is.
	 *
	 * @param int    $owner Account id of the collection's owner.
	 * @param string $slug  The collection's plain slug.
	 * @return string
	 */
	public static function collection_category_slug( $owner, $slug ) {
		return 'pbwp-' . (int) $owner . '-' . sanitize_title( $slug );
	}

	/**
	 * Remember a collection's title for its local category, so the inserter
	 * can show it. Called whenever a pattern is installed from a collection.
	 *
	 * @param array $collection { owner, slug, title }.
	 */
	public static function remember_collection_category( $collection ) {
		if ( empty( $collection['slug'] ) || ! isset( $collection['owner'] ) ) {
			return;
		}
		$categories = get_option( self::OPTION_COLLECTION_CATEGORIES );
		$categories = is_array( $categories ) ? $categories : array();

		$slug                = self::collection_category_slug( $collection['owner'], $collection['slug'] );
		$categories[ $slug ] = sanitize_text_field( ! empty( $collection['title'] ) ? $collection['title'] : $collection['slug'] );

		update_option( self::OPTION_COLLECTION_CATEGORIES, $categories, false );
		self::register_collection_category( $slug, $categories[ $slug ] );
	}

	/**
	 * The remembered collection categories: slug => title.
	 *
	 * @return array
	 */
	public static function collection_categories() {
		$categories = get_option( self::OPTION_COLLECTION_CATEGORIES );
		return is_array( $categories ) ? $categories : array();
	}

	/**
	 * Register every remembered collection category with its title, on
	 * init, so the browse grid and the block inserter show the collection's
	 * name over a pattern installed from it rather than `pbwp-5-heroes`.
	 */
	public static function register_collection_categories() {
		foreach ( self::collection_categories() as $slug => $title ) {
			self::register_collection_category( $slug, $title );
		}
	}

	/**
	 * Register one collection category, unless something already did.
	 *
	 * @param string $slug  Category slug.
	 * @param string $title Category label.
	 */
	private static function register_collection_category( $slug, $title ) {
		$registry = \WP_Block_Pattern_Categories_Registry::get_instance();
		if ( ! $registry->is_registered( $slug ) ) {
			register_block_pattern_category( $slug, array( 'label' => $title ) );
		}
	}

	/**
	 * Add the service's port to core's safe-port list when the service runs
	 * on a nonstandard one (development instances).
	 *
	 * @param int[]  $ports Allowed ports.
	 * @param string $host  Host being validated.
	 * @return int[]
	 */
	public static function allow_service_port( $ports, $host ) {
		$service = wp_parse_url( self::service_url() );

		if ( ! empty( $service['port'] ) && ! empty( $service['host'] )
			&& strtolower( (string) $host ) === strtolower( $service['host'] )
			&& ! in_array( (int) $service['port'], $ports, true ) ) {
			$ports[] = (int) $service['port'];
		}

		return $ports;
	}

	/**
	 * Let core's hardened URL validation (which rejects loopback/private
	 * hosts) through for the admin-configured service origin — required for
	 * asset downloads from development service instances on localhost.
	 *
	 * @param bool   $external Whether the host is already considered external.
	 * @param string $host     Host being validated.
	 * @param string $url      Full URL being validated.
	 * @return bool
	 */
	public static function allow_service_host( $external, $host, $url ) {
		if ( $external ) {
			return $external;
		}

		$service = wp_parse_url( self::service_url() );
		$target  = wp_parse_url( $url );

		if ( empty( $service['host'] ) || empty( $target['host'] ) ) {
			return $external;
		}

		$same_host   = strtolower( $target['host'] ) === strtolower( $service['host'] );
		$same_port   = ( $target['port'] ?? null ) === ( $service['port'] ?? null );
		$same_scheme = ( $target['scheme'] ?? '' ) === ( $service['scheme'] ?? '' );

		return ( $same_host && $same_port && $same_scheme ) ? true : $external;
	}

	/**
	 * Base URL of the patternbuilderwp.com service.
	 *
	 * @return string
	 */
	public static function service_url() {
		$url = get_option( self::OPTION_URL );

		// The constant outranks the option so declarative dev setups survive DB resets.
		if ( defined( 'PATTERN_BUILDER_CLOUD_URL' ) && PATTERN_BUILDER_CLOUD_URL ) {
			$url = PATTERN_BUILDER_CLOUD_URL;
		}

		if ( ! $url ) {
			$url = 'https://patternbuilderwp.com';
		}

		/**
		 * Filters the patternbuilderwp.com service URL (development environments).
		 *
		 * @param string $url Service base URL.
		 */
		return untrailingslashit( apply_filters( 'pattern_builder_cloud_url', $url ) );
	}

	/**
	 * A REST endpoint URL on the service.
	 *
	 * Uses the ?rest_route= form so it works regardless of the service's
	 * permalink configuration.
	 *
	 * @param string $path Route path within pbwp/v1 (leading slash).
	 * @return string
	 */
	public static function endpoint( $path ) {
		return self::service_url() . '/?rest_route=' . rawurlencode( '/pbwp/v1' . $path );
	}

	/**
	 * The current user's stored token (empty string when disconnected).
	 *
	 * @return string
	 */
	public static function token() {
		return (string) get_user_meta( get_current_user_id(), self::META_TOKEN, true );
	}

	/**
	 * The current user's cached account info.
	 *
	 * @return array
	 */
	public static function account() {
		$account = get_user_meta( get_current_user_id(), self::META_ACCOUNT, true );
		return is_array( $account ) ? $account : array();
	}

	/**
	 * Whether the current user has a stored connection.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::token();
	}

	/**
	 * Sign in to an existing service account with credentials.
	 *
	 * @param string $email    Email (or username) on the service.
	 * @param string $password Password.
	 * @return array|WP_Error Account info.
	 */
	public static function login( $email, $password ) {
		return self::credential_connect(
			'/auth/login',
			array(
				'email'    => $email,
				'password' => $password,
			)
		);
	}

	/**
	 * Create a service account and connect as it.
	 *
	 * @param string $email     Email address.
	 * @param string $password  Password.
	 * @param string $handle    The account's permanent handle: the first
	 *                          segment of every pattern name it publishes.
	 * @param string $name      Display name (optional).
	 * @param bool   $marketing Whether the person said yes to news and offers.
	 * @return array|WP_Error Account info.
	 */
	public static function signup( $email, $password, $handle = '', $name = '', $marketing = false ) {
		return self::credential_connect(
			'/auth/signup',
			array(
				'email'     => $email,
				'password'  => $password,
				'handle'    => $handle,
				'name'      => $name,
				'marketing' => $marketing ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Relay credentials to the service and store the returned grant.
	 * Credentials pass through unlogged and unstored; only the token is kept.
	 *
	 * @param string $path   Service auth route.
	 * @param array  $fields Credential fields.
	 * @return array|WP_Error Account info.
	 */
	private static function credential_connect( $path, $fields ) {
		$fields['site']      = home_url();
		$fields['site_user'] = wp_get_current_user()->user_login;

		$response = wp_remote_post(
			self::endpoint( $path ),
			array(
				'timeout' => 30,
				'body'    => $fields,
			)
		);

		$data = self::parse_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['token'] ) ) {
			return new WP_Error( 'pb_cloud_no_token', __( 'The service did not return a connection token.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		update_user_meta( get_current_user_id(), self::META_TOKEN, $data['token'] );
		update_user_meta( get_current_user_id(), self::META_ACCOUNT, isset( $data['account'] ) ? (array) $data['account'] : array() );

		return self::account();
	}

	/**
	 * Ask the service to email a password reset link.
	 *
	 * Unauthenticated on purpose — the person cannot sign in — and the
	 * service answers the same whether or not the address is an account.
	 *
	 * @param string $email Email address.
	 * @return array|WP_Error The service's message.
	 */
	public static function forgot_password( $email ) {
		$response = wp_remote_post(
			self::endpoint( '/auth/password/forgot' ),
			array(
				'timeout' => 30,
				'body'    => array( 'email' => $email ),
			)
		);

		return self::parse_response( $response );
	}

	/**
	 * Ask the service for a fresh email-confirmation link.
	 *
	 * @return array|WP_Error
	 */
	public static function resend_verification() {
		return self::request( 'POST', '/auth/verify/resend' );
	}

	/**
	 * Tell the service the overlay checkout completed a purchase, so the
	 * licence is granted now rather than when the webhook lands.
	 *
	 * @param int $license_id Licence ID the checkout reported.
	 * @return array|WP_Error The account as the service now describes it.
	 */
	public static function sync_billing( $license_id ) {
		return self::request( 'POST', '/billing/sync', array( 'body' => array( 'license_id' => (int) $license_id ) ) );
	}

	/**
	 * Disconnect: revoke remotely (best effort) and forget the token.
	 */
	public static function disconnect() {
		if ( self::is_connected() ) {
			self::request( 'POST', '/connect/revoke' );
		}
		delete_user_meta( get_current_user_id(), self::META_TOKEN );
		delete_user_meta( get_current_user_id(), self::META_ACCOUNT );
	}

	/**
	 * Authenticated request to the service.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path within pbwp/v1.
	 * @param array  $args   { query?: array, body?: array (JSON) }.
	 * @return array|WP_Error Decoded response.
	 */
	public static function request( $method, $path, $args = array() ) {
		$url = self::endpoint( $path );
		if ( ! empty( $args['query'] ) ) {
			$url .= '&' . http_build_query( $args['query'] );
		}

		$request = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => self::auth_headers(),
		);

		if ( isset( $args['body'] ) ) {
			$request['headers']['Content-Type'] = 'application/json';
			$request['body']                    = wp_json_encode( $args['body'] );
		}

		$response = wp_remote_request( $url, $request );
		return self::parse_response( $response );
	}

	/**
	 * Multipart upload request (the PBP JSON plus asset files).
	 *
	 * @param string $method HTTP method (POST/PUT).
	 * @param string $path   Route path within pbwp/v1.
	 * @param array  $pbp    Package array.
	 * @param array  $files  key => absolute file path.
	 * @param array  $fields Plain fields sent beside the package, e.g. the
	 *                       collection the pattern goes into.
	 * @return array|WP_Error Decoded response.
	 */
	public static function upload( $method, $path, $pbp, $files, $fields = array() ) {
		$boundary = 'pbcloud' . bin2hex( random_bytes( 12 ) );
		$body     = '';

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"pbp\"\r\n\r\n";
		$body .= wp_json_encode( $pbp ) . "\r\n";

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		foreach ( $files as $key => $file_path ) {
			$contents = file_get_contents( $file_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			if ( false === $contents ) {
				continue;
			}
			$filename = basename( $file_path );
			$mime     = wp_check_filetype( $filename )['type'];
			$body    .= "--{$boundary}\r\n";
			$body    .= "Content-Disposition: form-data; name=\"asset_{$key}\"; filename=\"{$filename}\"\r\n";
			$body    .= 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) . "\r\n\r\n";
			$body    .= $contents . "\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		$headers                 = self::auth_headers();
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		$response = wp_remote_request(
			self::endpoint( $path ),
			array(
				'method'  => $method,
				'timeout' => 60,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		return self::parse_response( $response );
	}

	/**
	 * Generic multipart form request (plain fields plus named file fields).
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path within pbwp/v1.
	 * @param array  $fields field => string value.
	 * @param array  $files  field => { path, name, type }.
	 * @return array|WP_Error Decoded response.
	 */
	public static function form_request( $method, $path, $fields, $files = array() ) {
		$boundary = 'pbcloud' . bin2hex( random_bytes( 12 ) );
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		foreach ( $files as $name => $file ) {
			$contents = file_get_contents( $file['path'] ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			if ( false === $contents ) {
				continue;
			}
			$filename = sanitize_file_name( $file['name'] );
			$mime     = ! empty( $file['type'] ) ? $file['type'] : 'application/octet-stream';
			$body    .= "--{$boundary}\r\n";
			$body    .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
			$body    .= "Content-Type: {$mime}\r\n\r\n";
			$body    .= $contents . "\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		$headers                 = self::auth_headers();
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		$response = wp_remote_request(
			self::endpoint( $path ),
			array(
				'method'  => $method,
				'timeout' => 60,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		return self::parse_response( $response );
	}

	/**
	 * The cloud-link map: which local patterns are linked to cloud copies.
	 *
	 * @return array localKey => { cloudId: int, account: int, hash: string, uploadedAt: int }
	 */
	public static function links() {
		$links = get_option( self::OPTION_LINKS );
		return is_array( $links ) ? $links : array();
	}

	/**
	 * Remember (or forget) a local pattern's cloud copy.
	 *
	 * @param string   $local_key    "theme:{name}" or "user:{postId}".
	 * @param int|null $cloud_id     Cloud pattern ID, or null to forget.
	 * @param string   $content_hash md5 of the raw content at upload time;
	 *                               empty reads as changed.
	 * @param bool     $owned        Whether the cloud copy is this account's
	 *                               to update. False for a pattern downloaded
	 *                               from somebody else's.
	 * @param array    $collection   The cloud collection the pattern is in,
	 *                               as { owner, slug, title } — what
	 *                               "already installed from this collection"
	 *                               reads. Empty when unknown.
	 */
	public static function set_link( $local_key, $cloud_id, $content_hash = '', $owned = true, $collection = array() ) {
		$links = self::links();
		if ( null === $cloud_id ) {
			unset( $links[ $local_key ] );
		} else {
			$links[ $local_key ] = array(
				'cloudId'    => (int) $cloud_id,
				'account'    => (int) ( self::account()['id'] ?? 0 ),
				'hash'       => (string) $content_hash,
				'uploadedAt' => time(),
				'owned'      => (bool) $owned,
				'collection' => self::describe_collection( $collection ),
			);
		}
		update_option( self::OPTION_LINKS, $links, false );
	}

	/**
	 * The three things the link map keeps about a collection.
	 *
	 * @param mixed $collection A collection summary from the service, or a
	 *                          request's { owner, slug, title }.
	 * @return array { owner: int, slug: string, title: string }, or empty.
	 */
	public static function describe_collection( $collection ) {
		if ( ! is_array( $collection ) || empty( $collection['slug'] ) ) {
			return array();
		}
		return array(
			'owner' => (int) ( $collection['owner'] ?? 0 ),
			'slug'  => sanitize_title( (string) $collection['slug'] ),
			'title' => sanitize_text_field( (string) ( $collection['title'] ?? $collection['slug'] ) ),
		);
	}

	/**
	 * Record that a linked cloud pattern is not this account's to update.
	 *
	 * The link itself stays: it is what recognizes the pattern as already
	 * installed. Only the offer to update it goes away.
	 *
	 * @param string $local_key "theme:{name}" or "user:{postId}".
	 */
	public static function disown_link( $local_key ) {
		$links = self::links();

		if ( ! isset( $links[ $local_key ] ) ) {
			return;
		}

		$links[ $local_key ]['owned'] = false;
		update_option( self::OPTION_LINKS, $links, false );
	}

	/**
	 * Bearer headers (plus the fallback header some hosts require).
	 *
	 * @return array
	 */
	private static function auth_headers() {
		$token = self::token();
		if ( ! $token ) {
			return array();
		}
		return array(
			'Authorization'        => 'Bearer ' . $token,
			'X-Pbwp-Authorization' => 'Bearer ' . $token,
		);
	}

	/**
	 * Decode a service response; surface service errors as WP_Error.
	 *
	 * @param array|WP_Error $response HTTP API response.
	 * @return array|WP_Error
	 */
	private static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'pb_cloud_unreachable',
				sprintf(
					/* translators: %s: connection error detail. */
					__( 'Could not reach patternbuilderwp.com: %s', 'pattern-builder' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message    = is_array( $data ) && ! empty( $data['message'] )
				? $data['message']
				: __( 'The pattern service returned an error.', 'pattern-builder' );
			$error_code = is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : 'pb_cloud_error';

			// An invalid token means the connection is gone; forget it.
			if ( 401 === $code && self::is_connected() ) {
				delete_user_meta( get_current_user_id(), self::META_TOKEN );
				delete_user_meta( get_current_user_id(), self::META_ACCOUNT );
			}

			$error_data = array( 'status' => $code );
			if ( is_array( $data ) && ! empty( $data['data']['upgrade_url'] ) ) {
				$error_data['upgrade_url'] = $data['data']['upgrade_url'];
			}
			// What exactly the service objected to — a rejection that names
			// nothing is a rejection nobody can act on.
			if ( is_array( $data ) && ! empty( $data['data']['violations'] ) ) {
				$error_data['violations'] = array_map( 'sanitize_text_field', (array) $data['data']['violations'] );
			}

			return new WP_Error( $error_code, $message, $error_data );
		}

		return is_array( $data ) ? $data : array();
	}
}
