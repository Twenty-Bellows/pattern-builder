<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;

/**
 * Abilities for the cloud: what an agent can ask patternbuilderwp.com
 * through this site, and what it can put there.
 *
 * Agents reach the cloud through a connected site and nothing else (D33).
 * An agent authenticates to WordPress with an application password, which
 * makes it that WordPress user; these abilities run as that user and use
 * the connection that user made. Two people have vouched — the site admin
 * who issued the password, the account holder who connected — and the
 * agent never holds a cloud credential. Without a connection every one of
 * them refuses with `pattern_builder_not_connected`.
 *
 * Seven abilities: four reads (list collections, one collection, search
 * patterns, and the collections and patterns those return carry what an
 * agent needs to choose) and three writes (install a collection, install a
 * pattern, upload a pattern, create a collection). Nothing here makes a
 * collection public, changes a visibility, or deletes a collection: an
 * agent never publishes, so `create-collection` is always private and on a
 * free account the service refuses it with the upgrade message.
 *
 * Registration is conditional on core having the Abilities API, as the
 * local abilities' is.
 */
class Pattern_Builder_Cloud_Abilities {

	const NOT_CONNECTED = 'pattern_builder_not_connected';

	/**
	 * Hook the component into WordPress.
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * The names of every ability registered here.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array(
			'pattern-builder/list-collections',
			'pattern-builder/get-collection',
			'pattern-builder/search-cloud-patterns',
			'pattern-builder/install-collection',
			'pattern-builder/install-cloud-pattern',
			'pattern-builder/upload-pattern',
			'pattern-builder/create-collection',
		);
	}

	/**
	 * Register every ability.
	 */
	public function register_abilities() {
		$this->register_list_collections();
		$this->register_get_collection();
		$this->register_search_cloud_patterns();
		$this->register_install_collection();
		$this->register_install_cloud_pattern();
		$this->register_upload_pattern();
		$this->register_create_collection();
	}

	/**
	 * Reading the cloud through this site is the same authority as
	 * browsing it on the Pattern Builder screen.
	 *
	 * @return bool
	 */
	public function can_read() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Installing writes into the theme or the database, and uploading
	 * sends this site's work away: the same authority the proxy asks for.
	 *
	 * @return bool
	 */
	public function can_write() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * The refusal every ability shares when the WordPress user has no
	 * connection.
	 *
	 * @return true|WP_Error
	 */
	private static function require_connection() {
		if ( Pattern_Builder_Cloud::is_connected() ) {
			return true;
		}
		return new WP_Error(
			self::NOT_CONNECTED,
			__( 'Connect Pattern Builder to your patternbuilderwp.com account on this site first.', 'pattern-builder' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Meta for a read: GET, reachable over REST.
	 *
	 * @return array
	 */
	private function read_meta() {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Meta for a write: POST, reachable over REST. Never destructive —
	 * that word means delete-like here and would make the ability callable
	 * only over DELETE — and nothing here deletes anything.
	 *
	 * @param bool $idempotent Whether the same call twice leaves the same state.
	 * @return array
	 */
	private function write_meta( $idempotent ) {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => $idempotent,
			),
		);
	}

	/**
	 * The schema of a collection summary as the service returns it.
	 *
	 * @return array
	 */
	private function collection_schema() {
		return array(
			'type'        => 'object',
			'description' => 'A collection: id, owner (account id), ownerName, slug, title, description, visibility (private|public|premium), personal, count, cover, previews, url.',
		);
	}

	/**
	 * Where an install lands and whether missing design tokens follow it.
	 *
	 * @return array
	 */
	private function install_properties() {
		return array(
			'destination' => array(
				'type'        => 'string',
				'enum'        => array( 'theme', 'user' ),
				'description' => 'Where to land it: "theme" writes pattern files into the active theme, "user" creates reusable blocks. Defaults to user.',
			),
			'tokens'      => array(
				'type'        => 'string',
				'enum'        => array( 'add', 'skip' ),
				'description' => 'Whether to add the design tokens this site lacks (colors, spacing, type the patterns reference) to the destination — theme.json for theme, Global Styles for user. Defaults to add; tokens the site already defines keep their values either way.',
			),
		);
	}

	/**
	 * Public and premium collections, or the account's own.
	 */
	private function register_list_collections() {
		wp_register_ability(
			'pattern-builder/list-collections',
			array(
				'label'               => __( 'List cloud collections', 'pattern-builder' ),
				'description'         => __( 'Lists collections on patternbuilderwp.com through this site’s connection: the community’s public and premium collections (scope "community", the default), or the connected account’s own, Personal first (scope "mine"). Each carries its owner, count, visibility and, for the community, a public URL. Requires the WordPress user to have connected Pattern Builder to a patternbuilderwp.com account.', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scope'  => array(
							'type' => 'string',
							'enum' => array( 'community', 'mine' ),
						),
						'search' => array(
							'type'        => 'string',
							'description' => 'Community only: match collection titles and descriptions.',
						),
						'page'   => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'collections' => array(
							'type'  => 'array',
							'items' => $this->collection_schema(),
						),
						'total'       => array( 'type' => 'integer' ),
						'pages'       => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_collections' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_meta(),
			)
		);
	}

	/**
	 * List collections.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_list_collections( $input = array() ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		if ( isset( $input['scope'] ) && 'mine' === $input['scope'] ) {
			$mine = Pattern_Builder_Cloud::request( 'GET', '/library/collections' );
			if ( is_wp_error( $mine ) ) {
				return $mine;
			}
			return array(
				'collections' => array_values( (array) $mine ),
				'total'       => count( (array) $mine ),
				'pages'       => 1,
			);
		}

		$query = array( 'page' => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1 );
		if ( ! empty( $input['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $input['search'] );
		}

		$listed = Pattern_Builder_Cloud::request( 'GET', '/directory/collections', array( 'query' => $query ) );
		if ( is_wp_error( $listed ) ) {
			return $listed;
		}

		return array(
			'collections' => isset( $listed['items'] ) ? array_values( (array) $listed['items'] ) : array(),
			'total'       => isset( $listed['total'] ) ? (int) $listed['total'] : 0,
			'pages'       => isset( $listed['pages'] ) ? (int) $listed['pages'] : 1,
		);
	}

	/**
	 * One collection with its pattern summaries.
	 */
	private function register_get_collection() {
		wp_register_ability(
			'pattern-builder/get-collection',
			array(
				'label'               => __( 'Get a cloud collection', 'pattern-builder' ),
				'description'         => __( 'One collection on patternbuilderwp.com with its pattern summaries (no markup), newest first, each marked with whether it is already installed on this site. Name a community collection by its owner (account id) and slug, or one of the connected account’s own by id.', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'owner' => array(
							'type'        => 'integer',
							'description' => 'The owning account id, as list-collections reports it.',
						),
						'slug'  => array(
							'type'        => 'string',
							'description' => 'The collection’s slug.',
						),
						'id'    => array(
							'type'        => 'integer',
							'description' => 'One of the connected account’s own collections, by id.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'collection' => $this->collection_schema(),
						'patterns'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_collection' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_meta(),
			)
		);
	}

	/**
	 * Fetch one collection.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_get_collection( $input ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		if ( ! empty( $input['id'] ) ) {
			$collection = Pattern_Builder_Cloud::request( 'GET', '/library/collections/' . (int) $input['id'] );
		} elseif ( ! empty( $input['owner'] ) && ! empty( $input['slug'] ) ) {
			$collection = Pattern_Builder_Cloud::request( 'GET', '/directory/collections/' . (int) $input['owner'] . '/' . sanitize_title( (string) $input['slug'] ) );
		} else {
			return new WP_Error( 'pattern_builder_bad_request', __( 'Name the collection: owner and slug, or id.', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		$porter   = new Pattern_Builder_Cloud_Porter();
		$patterns = isset( $collection['patterns'] ) && is_array( $collection['patterns'] ) ? $collection['patterns'] : array();
		foreach ( $patterns as &$pattern ) {
			$pattern['installed'] = isset( $pattern['id'] ) ? $porter->find_installed( (int) $pattern['id'] ) : null;
		}
		unset( $pattern, $collection['patterns'] );

		return array(
			'collection' => $collection,
			'patterns'   => $patterns,
		);
	}

	/**
	 * Search the community's patterns.
	 */
	private function register_search_cloud_patterns() {
		wp_register_ability(
			'pattern-builder/search-cloud-patterns',
			array(
				'label'               => __( 'Search cloud patterns', 'pattern-builder' ),
				'description'         => __( 'Searches the public patterns on patternbuilderwp.com by title and description. Each summary names the collection it is in; narrow to one with collection as "{owner}/{slug}".', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'     => array( 'type' => 'string' ),
						'collection' => array(
							'type'        => 'string',
							'description' => '"{owner}/{slug}" of one collection.',
						),
						'page'       => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'patterns' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'total'    => array( 'type' => 'integer' ),
						'pages'    => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_search_cloud_patterns' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_meta(),
			)
		);
	}

	/**
	 * Search patterns.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_search_cloud_patterns( $input = array() ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		$query = array( 'page' => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1 );
		if ( ! empty( $input['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $input['search'] );
		}
		if ( ! empty( $input['collection'] ) ) {
			$query['collection'] = sanitize_text_field( (string) $input['collection'] );
		}

		$listed = Pattern_Builder_Cloud::request( 'GET', '/directory/patterns', array( 'query' => $query ) );
		if ( is_wp_error( $listed ) ) {
			return $listed;
		}

		return array(
			'patterns' => isset( $listed['items'] ) ? array_values( (array) $listed['items'] ) : array(),
			'total'    => isset( $listed['total'] ) ? (int) $listed['total'] : 0,
			'pages'    => isset( $listed['pages'] ) ? (int) $listed['pages'] : 1,
		);
	}

	/**
	 * Install a whole collection.
	 */
	private function register_install_collection() {
		wp_register_ability(
			'pattern-builder/install-collection',
			array(
				'label'               => __( 'Install a cloud collection', 'pattern-builder' ),
				'description'         => __( 'Installs every pattern of a community collection onto this site in one action, as theme patterns or user patterns, images included and under a local pattern category named for the collection. Patterns already installed from it are skipped; a failure is reported and the rest carry on. A premium collection needs a Pattern Builder Pro account. Returns per-pattern results.', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'owner' => array( 'type' => 'integer' ),
							'slug'  => array( 'type' => 'string' ),
						),
						$this->install_properties()
					),
					'required'             => array( 'owner', 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'collection' => $this->collection_schema(),
						'results'    => array(
							'type'        => 'array',
							'description' => 'One per pattern: cloudId, title, status (installed|skipped|failed), and for an installed one its local type and id, for a failed one the message.',
							'items'       => array( 'type' => 'object' ),
						),
						'installed'  => array( 'type' => 'integer' ),
						'skipped'    => array( 'type' => 'integer' ),
						'failed'     => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_install_collection' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => $this->write_meta( true ),
			)
		);
	}

	/**
	 * Install a collection.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_install_collection( $input ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		$porter = new Pattern_Builder_Cloud_Porter();
		return $porter->install_collection(
			(int) $input['owner'],
			(string) $input['slug'],
			isset( $input['destination'] ) ? (string) $input['destination'] : 'user',
			isset( $input['tokens'] ) && 'skip' === $input['tokens'] ? 'skip' : 'add'
		);
	}

	/**
	 * Install one cloud pattern.
	 */
	private function register_install_cloud_pattern() {
		wp_register_ability(
			'pattern-builder/install-cloud-pattern',
			array(
				'label'               => __( 'Install a cloud pattern', 'pattern-builder' ),
				'description'         => __( 'Installs one pattern from patternbuilderwp.com onto this site, as a theme pattern or a user pattern, images included. A community pattern lands under a local category named for its collection; one from the connected account’s own library (source "library") does not. Returns the local pattern.', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'id'     => array(
								'type'        => 'integer',
								'description' => 'The cloud pattern id, as search-cloud-patterns or get-collection reports it.',
							),
							'source' => array(
								'type'        => 'string',
								'enum'        => array( 'directory', 'library' ),
								'description' => 'Where the pattern lives: the community directory (default), or the connected account’s own library.',
							),
						),
						$this->install_properties()
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pattern' => array(
							'type'        => 'object',
							'description' => 'The local pattern: type (theme|user), id, title, tokensWritten.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_install_cloud_pattern' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => $this->write_meta( false ),
			)
		);
	}

	/**
	 * Install one pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_install_cloud_pattern( $input ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern(
			(int) $input['id'],
			isset( $input['destination'] ) ? (string) $input['destination'] : 'user',
			! ( isset( $input['tokens'] ) && 'skip' === $input['tokens'] ),
			array(),
			false,
			isset( $input['source'] ) && 'library' === $input['source'] ? 'library' : 'directory'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'pattern' => $result );
	}

	/**
	 * Upload a pattern into a collection.
	 */
	private function register_upload_pattern() {
		wp_register_ability(
			'pattern-builder/upload-pattern',
			array(
				'label'               => __( 'Upload a pattern to the cloud', 'pattern-builder' ),
				'description'         => __( 'Uploads a pattern from this site into one of the connected account’s collections on patternbuilderwp.com, images included — an existing local pattern by id, or finished markup as title and content, which is stored here as a user pattern first. The collection defaults to Personal (private). A pattern that references other patterns brings them with it: they are uploaded into the same collection, every reference is rewritten to name it, and the result lists everything that went up under “members” — a reference to a pattern this site does not have refuses the whole upload by name. A pattern already uploaded updates its cloud copy, and naming a collection then does nothing, since a pattern’s collection is part of its permanent name and is decided the first time it is uploaded. Validate the markup first: the same rule as create-pattern, and the service refuses what its checks catch.', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => array(
							'type'        => 'string',
							'description' => 'A local pattern: a namespaced theme pattern name, or a user pattern’s post id.',
						),
						'title'      => array( 'type' => 'string' ),
						'content'    => array(
							'type'        => 'string',
							'description' => 'Finished, validated block markup, when no id is given.',
						),
						'collection' => array(
							'type'        => 'string',
							'description' => 'A collection id from list-collections (scope mine), or "personal". Defaults to personal. Read on a pattern’s first upload only; a pattern cannot change collection afterwards.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pattern' => array(
							'type'        => 'object',
							'description' => 'The cloud pattern as the service summarizes it, its collection included.',
						),
						'updated' => array( 'type' => 'boolean' ),
						'members' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Every pattern that went up, the one asked for last: a pattern brings the patterns it references with it.',
						),
						'local'   => array(
							'type'        => 'object',
							'description' => 'The local pattern that was uploaded: type and id.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_upload_pattern' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => $this->write_meta( true ),
			)
		);
	}

	/**
	 * Upload a pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_upload_pattern( $input ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		if ( ! empty( $input['id'] ) ) {
			$id = (string) $input['id'];
			if ( ctype_digit( $id ) ) {
				$post = get_post( (int) $id );
				if ( ! $post || 'wp_block' !== $post->post_type ) {
					return new WP_Error( 'pb_pattern_not_found', __( 'No user pattern with that id.', 'pattern-builder' ), array( 'status' => 404 ) );
				}
				$type = 'user';
				$id   = (int) $id;
			} else {
				$type = 'theme';
			}
		} elseif ( ! empty( $input['title'] ) && ! empty( $input['content'] ) ) {
			// Finished markup with nowhere to live yet: it becomes a user
			// pattern here, so the upload has something to link to.
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_title'   => sanitize_text_field( (string) $input['title'] ),
					'post_content' => (string) $input['content'],
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
			$type = 'user';
			$id   = (int) $post_id;
		} else {
			return new WP_Error( 'pattern_builder_bad_request', __( 'Give a local pattern id, or a title and content.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$collection = isset( $input['collection'] ) && '' !== $input['collection'] ? $input['collection'] : 'personal';

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( $type, $id, $collection );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'pattern' => $result['pattern'],
			'updated' => (bool) $result['updated'],
			// Everything that went up, the pattern itself last. One name
			// for a pattern that references nothing; several for a page.
			'members' => isset( $result['members'] ) ? $result['members'] : array(),
			'local'   => array(
				'type' => $type,
				'id'   => $id,
			),
		);
	}

	/**
	 * Create a private collection.
	 */
	private function register_create_collection() {
		wp_register_ability(
			'pattern-builder/create-collection',
			array(
				'label'               => __( 'Create a cloud collection', 'pattern-builder' ),
				'description'         => __( 'Creates a private collection on the connected account. The slug is permanent and becomes part of the name of every pattern in the collection ({handle}/{collection}/{pattern}), so choose it deliberately: it cannot be changed, only replaced by making another collection. Always private: an agent never publishes, and nothing here makes a collection public, changes a visibility or deletes one — the account holder does that in Pattern Builder. On a free account the service refuses with an upgrade message, since free accounts only create public collections; upload into Personal instead.', 'pattern-builder' ),
				'category'            => Pattern_Builder_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						// Permanent, and the middle segment of every pattern
						// name in the collection. Lower-case letters, numbers
						// and single hyphens, starting with a letter.
						'slug'        => array(
							'type'      => 'string',
							'pattern'   => '^[a-z][a-z0-9]*(-[a-z0-9]+)*$',
							'minLength' => 3,
							'maxLength' => 32,
						),
						'description' => array( 'type' => 'string' ),
					),
					'required'             => array( 'name', 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'collection' => $this->collection_schema(),
					),
				),
				'execute_callback'    => array( $this, 'execute_create_collection' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => $this->write_meta( false ),
			)
		);
	}

	/**
	 * Create a collection, private.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_create_collection( $input ) {
		$connected = self::require_connection();
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		$created = Pattern_Builder_Cloud::request(
			'POST',
			'/library/collections',
			array(
				'body' => array(
					'name'        => sanitize_text_field( (string) $input['name'] ),
					'slug'        => sanitize_key( (string) $input['slug'] ),
					'description' => isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
					'visibility'  => 'private',
				),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array( 'collection' => $created );
	}
}
