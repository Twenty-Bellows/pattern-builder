<?php
/**
 * Opt-in usage telemetry.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Anonymous usage reporting, only ever with the site administrator's
 * explicit say-so.
 *
 * WordPress.org's guidelines forbid tracking without opt-in, and this is
 * built to make the rule easy to keep: nothing is sent until an
 * administrator has clicked Allow on the prompt the pattern browser shows
 * once, and one click on the prompt (or the connect panel, which offers
 * it again to a site that declined) turns it off. The answer is a site
 * option — one decision per site, recorded with who made it and when.
 *
 * What is sent: a random install id minted at opt-in (never the site's
 * URL, name or address), the environment (WordPress, PHP and plugin
 * versions, locale, theme slug, multisite, environment type), and named
 * events — the browser opened, a pattern created, the community browsed,
 * an upload — each with a small fixed set of properties. Nothing about
 * content, ever. It goes to patternbuilderwp.com, which relays it to the
 * analytics project: the plugin therefore names one service, and never
 * loads a script from anyone.
 *
 * Events recorded during a request are buffered and posted once, on
 * shutdown, without waiting for the answer. A lost batch is lost; this is
 * analytics, not accounting.
 */
class Pattern_Builder_Telemetry {

	const OPTION = 'pattern_builder_telemetry';

	const ALLOWED  = 'allowed';
	const DECLINED = 'declined';

	const NS = 'pattern-builder/v1';

	/**
	 * Events recorded during this request, sent on shutdown.
	 *
	 * @var array
	 */
	private static $buffer = array();

	/**
	 * Whether the shutdown flush is already hooked.
	 *
	 * @var bool
	 */
	private static $flush_hooked = false;

	/**
	 * Hook the component into WordPress.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * The stored decision.
	 *
	 * @return array { consent: ''|allowed|declined, install_id, decided_by, decided_at }
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'consent'    => '',
				'install_id' => '',
				'decided_by' => 0,
				'decided_at' => 0,
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Whether the site has said yes.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$state = self::state();
		return self::ALLOWED === $state['consent'] && '' !== $state['install_id'];
	}

	/**
	 * Whether anybody has been asked yet.
	 *
	 * @return bool
	 */
	public static function is_decided() {
		return '' !== self::state()['consent'];
	}

	/**
	 * Record the decision.
	 *
	 * Allowing mints the install id if there is none; declining keeps it,
	 * so a site that allows again later is the same site in the numbers.
	 * Each change is itself the last (or first) event sent.
	 *
	 * @param bool $allow The answer.
	 * @return array The new state.
	 */
	public static function set_consent( $allow ) {
		$state = self::state();
		$was   = self::is_enabled();

		$state['consent']    = $allow ? self::ALLOWED : self::DECLINED;
		$state['decided_by'] = get_current_user_id();
		$state['decided_at'] = time();

		if ( $allow && '' === $state['install_id'] ) {
			$state['install_id'] = wp_generate_uuid4();
		}

		if ( ! $allow && $was ) {
			self::record( 'telemetry_disabled' ); // Buffered while still allowed.
		}

		update_option( self::OPTION, $state, false );

		if ( $allow && ! $was ) {
			self::record( 'telemetry_enabled' );
		}

		return $state;
	}

	/**
	 * Record an event, if the site allows it.
	 *
	 * @param string $event      Event name, from the service's fixed list.
	 * @param array  $properties Event properties, from the service's fixed list.
	 */
	public static function record( $event, $properties = array() ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::$buffer[] = array(
			'event'      => sanitize_key( $event ),
			'install_id' => self::state()['install_id'],
			'properties' => array_merge( self::environment(), self::account(), $properties ),
			'timestamp'  => time(),
		);

		if ( ! self::$flush_hooked ) {
			self::$flush_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Send what was recorded, without waiting.
	 */
	public static function flush() {
		if ( ! self::$buffer ) {
			return;
		}

		$events       = self::$buffer;
		self::$buffer = array();

		/**
		 * Filters whether a batch is sent, and lets tests see it.
		 *
		 * @param bool  $send   Whether to send.
		 * @param array $events The batch.
		 */
		if ( ! apply_filters( 'pattern_builder_telemetry_send', true, $events ) ) {
			return;
		}

		wp_remote_post(
			Pattern_Builder_Cloud::endpoint( '/telemetry' ),
			array(
				'timeout'  => 3,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'events' => $events ) ),
			)
		);
	}

	/**
	 * What was buffered and not yet sent (tests).
	 *
	 * @return array
	 */
	public static function pending() {
		return self::$buffer;
	}

	/**
	 * Forget the buffer (tests).
	 */
	public static function reset() {
		self::$buffer = array();
	}

	/**
	 * The environment, as every event describes it.
	 *
	 * @return array
	 */
	public static function environment() {
		return array(
			'plugin_version' => PATTERN_BUILDER_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'         => get_locale(),
			'theme'          => get_stylesheet(),
			'multisite'      => is_multisite(),
			'environment'    => wp_get_environment_type(),
		);
	}

	/**
	 * The connected account, when there is one, so cloud usage joins the
	 * website's events under the same account.
	 *
	 * @return array
	 */
	private static function account() {
		$account = Pattern_Builder_Cloud::account();
		if ( empty( $account['id'] ) ) {
			return array();
		}

		return array(
			'account_id' => (int) $account['id'],
			'tier'       => isset( $account['tier'] ) ? (string) $account['tier'] : '',
		);
	}

	/**
	 * What the browse app needs to know: whether to ask, and whether to send.
	 *
	 * @return array
	 */
	public static function client_state() {
		return array(
			'consent' => self::state()['consent'],
			'enabled' => self::is_enabled(),
		);
	}

	/**
	 * Plugin activation: a site that allowed before counts itself again.
	 */
	public static function on_activation() {
		self::record( 'plugin_activated' );
		self::flush(); // No shutdown to wait for on an activation request.
	}

	/**
	 * Plugin deactivation: the last thing an allowing site says.
	 */
	public static function on_deactivation() {
		self::record( 'plugin_deactivated' );
		self::flush();
	}

	/**
	 * REST routes for the browse app: read the state, answer the prompt,
	 * report an event. Gated like every other management route.
	 */
	public function register_routes() {
		$can_manage = function () {
			return current_user_can( 'edit_theme_options' )
				? true
				: new WP_Error( 'pb_forbidden', __( 'You cannot manage patterns on this site.', 'pattern-builder' ), array( 'status' => rest_authorization_required_code() ) );
		};

		register_rest_route(
			self::NS,
			'/telemetry',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_manage,
				'callback'            => function () {
					return rest_ensure_response( self::client_state() );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/telemetry/consent',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_manage,
				'callback'            => array( $this, 'consent' ),
			)
		);

		register_rest_route(
			self::NS,
			'/telemetry/event',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_manage,
				'callback'            => array( $this, 'event' ),
			)
		);
	}

	/**
	 * POST /telemetry/consent — the answer to the prompt.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function consent( $request ) {
		self::set_consent( rest_sanitize_boolean( $request->get_param( 'allow' ) ) );
		return rest_ensure_response( self::client_state() );
	}

	/**
	 * POST /telemetry/event — the browse app saw something happen.
	 *
	 * The service keeps its own allowlist; this passes only string and
	 * scalar properties through, so the wire never carries content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function event( $request ) {
		$properties = $request->get_param( 'properties' );
		$clean      = array();

		if ( is_array( $properties ) ) {
			foreach ( $properties as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$clean[ sanitize_key( (string) $key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
		}

		self::record( (string) $request->get_param( 'event' ), $clean );

		return rest_ensure_response( array( 'recorded' => self::is_enabled() ) );
	}
}
