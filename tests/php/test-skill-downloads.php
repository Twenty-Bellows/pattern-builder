<?php
/**
 * Downloading a skill's files by signed link: what opens, for whom, and that what arrives is
 * exactly what the install checks it against.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;

class Test_Skill_Downloads extends WP_UnitTestCase {
	/**
	 * The abilities under test.
	 *
	 * @var Pattern_Builder_Abilities
	 */
	private $abilities;

	public function set_up() {
		parent::set_up();

		$this->abilities = new Pattern_Builder_Abilities();
	}

	/**
	 * A skill's manifest, as an administrator asks for it.
	 *
	 * @param string $name Skill name.
	 * @return array
	 */
	private function manifest( $name ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$manifest = $this->abilities->execute_authoring_guide( array( 'skill' => $name ) );
		wp_set_current_user( 0 );

		return $manifest;
	}

	/**
	 * Fetches a download, as someone signed in to nothing.
	 *
	 * @param string $skill Skill name in the route.
	 * @param string $path  File path in the route.
	 * @param array  $query The link's query arguments.
	 * @return WP_REST_Response
	 */
	private function download( $skill, $path, $query ) {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/skills/' . $skill . '/' . $path );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * The query arguments of a signed link.
	 *
	 * @param string $url The link.
	 * @return array
	 */
	private function query_of( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		unset( $query['rest_route'] );

		return $query;
	}

	/**
	 * The checksum the install checks one file against.
	 *
	 * @param string $install The install recipe.
	 * @param string $path    The file's path.
	 * @return string
	 */
	private function checked_against( $install, $path ) {
		preg_match( '/^([0-9a-f]{64})  ' . preg_quote( $path, '/' ) . '$/m', $install, $match );

		return $match[1] ?? '';
	}

	public function test_every_file_downloads_signed_and_matches_the_install() {
		foreach ( array( 'pattern-author', 'design-reproduction' ) as $name ) {
			$manifest = $this->manifest( $name );

			foreach ( $manifest['files'] as $file ) {
				$response = $this->download( $name, $file['path'], $this->query_of( $file['url'] ) );

				$this->assertSame( 200, $response->get_status(), $name . '/' . $file['path'] );
				$this->assertSame(
					$this->checked_against( $manifest['install'], $file['path'] ),
					hash( 'sha256', $response->get_data() ),
					$name . '/' . $file['path'] . ' is not what the install checks it against.'
				);
			}
		}
	}

	/**
	 * The entry is the one file that needs its front matter on disk, or nothing triggers it.
	 */
	public function test_the_entry_downloads_with_its_front_matter() {
		$manifest = $this->manifest( 'pattern-author' );
		$entry    = $manifest['files'][0];

		$response = $this->download( 'pattern-author', $entry['path'], $this->query_of( $entry['url'] ) );

		$this->assertStringStartsWith( "---\nname: pattern-author\n", $response->get_data() );
		$this->assertStringContainsString( '  checksum: "' . $manifest['checksum'] . '"', $response->get_data() );
	}

	public function test_a_link_without_a_valid_token_does_not_open() {
		$manifest = $this->manifest( 'pattern-author' );
		$query    = $this->query_of( $manifest['files'][0]['url'] );

		$this->assertSame( 401, $this->download( 'pattern-author', 'SKILL.md', array_merge( $query, array( 'token' => str_repeat( 'a', 64 ) ) ) )->get_status() );
		$this->assertSame( 401, $this->download( 'pattern-author', 'SKILL.md', array_merge( $query, array( 'expires' => $query['expires'] + 60 ) ) )->get_status() );
	}

	/**
	 * A link is signed for one skill; the same token opens nothing in another.
	 */
	public function test_a_link_opens_only_its_own_skill() {
		$query = $this->query_of( $this->manifest( 'pattern-author' )['files'][0]['url'] );

		$this->assertSame( 401, $this->download( 'design-reproduction', 'SKILL.md', $query )->get_status() );
	}

	public function test_an_expired_link_does_not_open() {
		$sign    = new ReflectionMethod( Pattern_Builder_Abilities::class, 'sign_skill_link' );
		$expires = time() - 1;
		$sign->setAccessible( true );

		$response = $this->download(
			'pattern-author',
			'SKILL.md',
			array(
				'expires' => $expires,
				'token'   => $sign->invoke( null, 'pattern-author', $expires ),
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * A path is looked up in the skill's own inventory, never on disk, so a valid token still
	 * reaches nothing the site was not already offering.
	 */
	public function test_a_valid_link_reaches_only_the_skills_own_files() {
		$query = $this->query_of( $this->manifest( 'pattern-author' )['files'][0]['url'] );

		foreach ( array( 'references/../../../pattern-builder.php', 'references/nope.md', '../uninstall.php' ) as $path ) {
			$this->assertSame( 404, $this->download( 'pattern-author', $path, $query )->get_status(), $path );
		}
	}

	/**
	 * The file goes out as itself, not wrapped in JSON, so a plain download writes what the
	 * checksum covers. Anything else on the REST API is left to be served as usual.
	 */
	public function test_a_download_is_sent_as_the_file_itself() {
		$manifest = $this->manifest( 'pattern-author' );
		$file     = $manifest['files'][1];
		$request  = new WP_REST_Request( 'GET', '/pattern-builder/v1/skills/pattern-author/' . $file['path'] );
		$request->set_query_params( $this->query_of( $file['url'] ) );
		$response = rest_do_request( $request );

		ob_start();
		$served = $this->abilities->serve_skill_download( false, $response, $request );
		$sent   = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( $response->get_data(), $sent );

		$elsewhere = new WP_REST_Request( 'GET', '/wp/v2/types' );
		$this->assertFalse( $this->abilities->serve_skill_download( false, new WP_REST_Response( 'text', 200 ), $elsewhere ) );
	}
}
