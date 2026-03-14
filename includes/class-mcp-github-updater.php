<?php
/**
 * GitHub-based auto-updater for WP MCP Server.
 *
 * Checks the GitHub Releases API for new versions and integrates
 * with the WordPress plugin update system.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_GitHub_Updater {

	/**
	 * GitHub repository owner/name.
	 */
	private $repo = 'LAPSrj/WP-MCP-Plugin';

	/**
	 * Plugin basename (e.g. wp-mcp-plugin/wp-mcp-plugin.php).
	 */
	private $basename;

	/**
	 * Plugin slug (directory name).
	 */
	private $slug;

	/**
	 * Current plugin version.
	 */
	private $version;

	/**
	 * Cached GitHub release data.
	 */
	private $github_release = null;

	public function __construct() {
		$this->basename = WP_MCP_BASENAME;
		$this->slug     = dirname( WP_MCP_BASENAME );
		$this->version  = WP_MCP_VERSION;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * Fetch the latest release from GitHub. Cached per request.
	 *
	 * @return object|false Release data or false on failure.
	 */
	private function get_github_release() {
		if ( null !== $this->github_release ) {
			return $this->github_release;
		}

		$url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Accept' => 'application/vnd.github.v3+json',
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->github_release = false;
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $body->tag_name ) ) {
			$this->github_release = false;
			return false;
		}

		$this->github_release = $body;
		return $body;
	}

	/**
	 * Normalize a version tag (strip leading "v").
	 */
	private function normalize_version( $tag ) {
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Get plugin icon URLs.
	 */
	private function get_icons() {
		$base = 'https://raw.githubusercontent.com/' . $this->repo . '/main/assets/';
		return array(
			'1x' => $base . 'icon-128x128.png',
			'2x' => $base . 'icon-256x256.png',
		);
	}

	/**
	 * Get the zip download URL for a release.
	 */
	private function get_download_url( $release ) {
		// Prefer a .zip asset attached to the release.
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( 'application/zip' === $asset->content_type || str_ends_with( $asset->name, '.zip' ) ) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fall back to the auto-generated source zipball.
		return $release->zipball_url;
	}

	/**
	 * Inject update information into the WordPress update transient.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $this->normalize_version( $release->tag_name );

		if ( version_compare( $remote_version, $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $remote_version,
				'url'         => 'https://github.com/' . $this->repo,
				'package'     => $this->get_download_url( $release ),
				'icons'       => $this->get_icons(),
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin details for the "View details" popup in the admin.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_github_release();

		if ( ! $release ) {
			return $result;
		}

		$info = (object) array(
			'name'              => 'WP MCP Server',
			'slug'              => $this->slug,
			'version'           => $this->normalize_version( $release->tag_name ),
			'author'            => '<a href="https://github.com/LAPSrj">Leandro</a>',
			'homepage'          => 'https://github.com/' . $this->repo,
			'requires'          => '6.0',
			'requires_php'      => '7.4',
			'downloaded'        => 0,
			'last_updated'      => $release->published_at,
			'sections'          => array(
				'description'  => 'Exposes a Model Context Protocol (MCP) server on your WordPress site, allowing AI agents to connect and interact with your site\'s REST API.',
				'changelog'    => nl2br( esc_html( $release->body ) ),
			),
			'download_link'     => $this->get_download_url( $release ),
			'icons'             => $this->get_icons(),
		);

		return $info;
	}

	/**
	 * After install, rename the extracted directory to match the plugin slug.
	 *
	 * GitHub's zipball extracts to "Owner-Repo-hash/", which WordPress
	 * won't map back to the plugin. This renames it to the expected directory.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $result;
		}

		global $wp_filesystem;

		$proper_destination = WP_PLUGIN_DIR . '/' . $this->slug;
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;

		// Re-activate the plugin if it was active before.
		if ( is_plugin_active( $this->basename ) ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}
}
