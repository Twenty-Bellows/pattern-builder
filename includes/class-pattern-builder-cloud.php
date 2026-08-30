<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;

/**
 * The patternbuilderwp.com connection: token storage, the PKCE connect
 * flow, and HTTP to the service.
 *
 * The connection is per WordPress user: each user links their own
 * patternbuilderwp.com account, and their bearer token lives in their user
 * meta — it is only ever used server-side (the browser talks to this site's
 * proxy endpoints, never to the service directly).
 */
class Pattern_Builder_Cloud {

	const OPTION_URL   = 'pattern_builder_cloud_url';
	const OPTION_LINKS = 'pattern_builder_cloud_links';

	const META_TOKEN   = '_pattern_builder_cloud_token';
	const META_ACCOUNT = '_pattern_builder_cloud_account';
	const META_PKCE    = '_pattern_builder_cloud_pkce';

	const PKCE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Base URL of the patternbuilderwp.com service.
	 *
	 * @return string
	 */
	public static function service_url() {
		$url = get_option( self::OPTION_URL );
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
	 * Begin the connect flow: mint PKCE state and build the authorize URL.
	 *
	 * @return array { authorizeUrl: string }
	 */
	public static function start_connect() {
		$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$state    = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		update_user_meta(
			get_current_user_id(),
			self::META_PKCE,
			array(
				'verifier' => $verifier,
				'state'    => $state,
				'created'  => time(),
			)
		);

		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$authorize_url = add_query_arg(
			array(
				'pbwp_authorize'        => 1,
				'client'                => rawurlencode( home_url() ),
				'site_user'             => rawurlencode( wp_get_current_user()->user_login ),
				'redirect_uri'          => rawurlencode( self::callback_url() ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			self::service_url() . '/'
		);

		return array( 'authorizeUrl' => $authorize_url );
	}

	/**
	 * The wp-admin URL the service redirects back to with the code.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return admin_url( 'admin.php?page=pattern-builder&pbcloud-callback=1' );
	}

	/**
	 * Complete the connect flow: verify state, exchange the code, store the token.
	 *
	 * @param string $code  Authorization code from the service.
	 * @param string $state State returned by the service.
	 * @return array|WP_Error Account info.
	 */
	public static function complete_connect( $code, $state ) {
		$pkce = get_user_meta( get_current_user_id(), self::META_PKCE, true );
		delete_user_meta( get_current_user_id(), self::META_PKCE );

		if ( ! is_array( $pkce ) || empty( $pkce['verifier'] ) || empty( $pkce['state'] ) ) {
			return new WP_Error( 'pb_cloud_no_pkce', __( 'The connection attempt expired. Start the connection again.', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		if ( ( time() - (int) $pkce['created'] ) > self::PKCE_TTL || ! hash_equals( $pkce['state'], (string) $state ) ) {
			return new WP_Error( 'pb_cloud_bad_state', __( 'The connection attempt could not be verified. Start the connection again.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			self::endpoint( '/connect/token' ),
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'code_verifier' => $pkce['verifier'],
				),
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
	 * @param array  $args   { query?: array, body?: array (JSON) }
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
	 * @return array|WP_Error Decoded response.
	 */
	public static function upload( $method, $path, $pbp, $files ) {
		$boundary = 'pbcloud' . bin2hex( random_bytes( 12 ) );
		$body     = '';

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"pbp\"\r\n\r\n";
		$body .= wp_json_encode( $pbp ) . "\r\n";

		foreach ( $files as $key => $file_path ) {
			$contents = file_get_contents( $file_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file.
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
	 * The cloud-link map: which local patterns are linked to cloud copies.
	 *
	 * @return array localKey => { cloudId: int, account: int }
	 */
	public static function links() {
		$links = get_option( self::OPTION_LINKS );
		return is_array( $links ) ? $links : array();
	}

	/**
	 * Remember (or forget) a local pattern's cloud copy.
	 *
	 * @param string   $local_key "theme:{name}" or "user:{postId}".
	 * @param int|null $cloud_id  Cloud pattern ID, or null to forget.
	 */
	public static function set_link( $local_key, $cloud_id ) {
		$links = self::links();
		if ( null === $cloud_id ) {
			unset( $links[ $local_key ] );
		} else {
			$links[ $local_key ] = array(
				'cloudId' => (int) $cloud_id,
				'account' => (int) ( self::account()['id'] ?? 0 ),
			);
		}
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
			$message = is_array( $data ) && ! empty( $data['message'] )
				? $data['message']
				: __( 'The pattern service returned an error.', 'pattern-builder' );
			$error_code = is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : 'pb_cloud_error';

			// An invalid token means the connection is gone; forget it.
			if ( 401 === $code && self::is_connected() ) {
				delete_user_meta( get_current_user_id(), self::META_TOKEN );
				delete_user_meta( get_current_user_id(), self::META_ACCOUNT );
			}

			return new WP_Error( $error_code, $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
