<?php
/**
 * MCP Admin Settings Page
 *
 * Provides a settings screen to generate Application Passwords and
 * copy-ready MCP client configuration snippets, plus endpoint filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_Admin {

	const PAGE_SLUG    = 'wp-mcp-settings';
	const NONCE_NAME   = 'wp_mcp_generate';
	const OPTION_NAME  = 'wp_mcp_endpoint_settings';
	const AJAX_ACTION  = 'wp_mcp_save_endpoint_settings';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_generate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_save_endpoint_settings' ) );
	}

	public function add_menu_page() {
		add_options_page(
			__( 'WP MCP Server', 'wp-mcp-server' ),
			__( 'WP MCP Server', 'wp-mcp-server' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the "Generate Connection" form submission.
	 */
	public function handle_generate() {
		if ( ! isset( $_POST['wp_mcp_generate_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['wp_mcp_generate_nonce'], self::NONCE_NAME ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = isset( $_POST['wp_mcp_user'] ) ? absint( $_POST['wp_mcp_user'] ) : 0;
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			add_settings_error( self::PAGE_SLUG, 'invalid_user', __( 'Invalid user selected.', 'wp-mcp-server' ), 'error' );
			return;
		}

		$app_password = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'WP MCP Server (' . gmdate( 'Y-m-d H:i' ) . ')' )
		);

		if ( is_wp_error( $app_password ) ) {
			add_settings_error( self::PAGE_SLUG, 'app_password_error', $app_password->get_error_message(), 'error' );
			return;
		}

		$password = $app_password[0];
		$token    = base64_encode( $user->user_login . ':' . $password );

		set_transient( 'wp_mcp_generated_token_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
		set_transient( 'wp_mcp_generated_user_' . get_current_user_id(), $user_id, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=tools&generated=1' ) );
		exit;
	}

	/**
	 * Enqueue JS/CSS on the settings page only.
	 */
	public function enqueue_settings_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		if ( 'settings' !== $tab ) {
			return;
		}

		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

		wp_enqueue_style(
			'wp-mcp-endpoint-settings',
			$plugin_url . 'assets/css/endpoint-settings.css',
			array(),
			WP_MCP_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-endpoint-settings',
			$plugin_url . 'assets/js/endpoint-settings.js',
			array(),
			WP_MCP_VERSION,
			true
		);

		$discovery = new WP_MCP_Discovery();
		$routes    = $discovery->get_all_route_patterns();
		$current   = get_option( self::OPTION_NAME, array() );

		$defaults = array(
			'mode'             => 'all',
			'endpoints'        => array(),
			'auto_disable_new' => false,
			'known_routes'     => array(),
		);
		$current = wp_parse_args( $current, $defaults );

		wp_localize_script( 'wp-mcp-endpoint-settings', 'wpMcpEndpointSettings', array(
			'routes'  => $routes,
			'current' => $current,
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
		) );
	}

	/**
	 * AJAX handler: save endpoint settings.
	 */
	public function ajax_save_endpoint_settings() {
		check_ajax_referer( self::AJAX_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( 'Invalid JSON payload.' );
		}

		$valid_modes = array( 'all', 'allowlist', 'blocklist', 'compact' );
		$mode        = isset( $data['mode'] ) ? sanitize_key( $data['mode'] ) : 'all';
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			$mode = 'all';
		}

		$endpoints = array();
		if ( isset( $data['endpoints'] ) && is_array( $data['endpoints'] ) ) {
			foreach ( $data['endpoints'] as $ep ) {
				$endpoints[] = sanitize_text_field( $ep );
			}
		}

		$auto_disable_new = ! empty( $data['auto_disable_new'] );

		// Snapshot all current routes for detecting new ones later.
		$discovery    = new WP_MCP_Discovery();
		$all_routes   = $discovery->get_all_route_patterns();
		$known_routes = array();
		foreach ( $all_routes as $route ) {
			$known_routes[] = $route['pattern'];
		}

		$settings = array(
			'mode'             => $mode,
			'endpoints'        => $endpoints,
			'auto_disable_new' => $auto_disable_new,
			'known_routes'     => $known_routes,
		);

		update_option( self::OPTION_NAME, $settings );

		wp_send_json_success( 'Saved.' );
	}

	/**
	 * Render the settings page with tabs.
	 */
	public function render_page() {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$tabs        = array(
			'settings' => __( 'Settings', 'wp-mcp-server' ),
			'tools'    => __( 'Tools', 'wp-mcp-server' ),
			'info'     => __( 'Info', 'wp-mcp-server' ),
		);

		if ( ! isset( $tabs[ $current_tab ] ) ) {
			$current_tab = 'settings';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP MCP Server', 'wp-mcp-server' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=' . $tab_key ) ); ?>"
					   class="nav-tab <?php echo $tab_key === $current_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $current_tab ) {
				case 'tools':
					$this->render_tab_tools();
					break;
				case 'info':
					$this->render_tab_info();
					break;
				default:
					$this->render_tab_settings();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Settings tab: endpoint filtering mode and endpoint selection.
	 */
	private function render_tab_settings() {
		$current  = get_option( self::OPTION_NAME, array() );
		$defaults = array(
			'mode'             => 'all',
			'endpoints'        => array(),
			'auto_disable_new' => false,
		);
		$current = wp_parse_args( $current, $defaults );
		?>
		<div class="card" style="max-width: none;">
			<h2><?php esc_html_e( 'Endpoint Filtering', 'wp-mcp-server' ); ?></h2>
			<p><?php esc_html_e( 'Control which WordPress REST API endpoints are exposed as MCP tools.', 'wp-mcp-server' ); ?></p>

			<div class="wp-mcp-mode-selector">
				<label class="<?php echo 'all' === $current['mode'] ? 'active' : ''; ?>">
					<input type="radio" name="wp_mcp_mode" value="all" <?php checked( $current['mode'], 'all' ); ?>>
					<strong><?php esc_html_e( 'All Endpoints', 'wp-mcp-server' ); ?></strong>
					<span class="mode-description"><?php esc_html_e( 'Expose every discovered REST API route as an MCP tool. (Default)', 'wp-mcp-server' ); ?></span>
				</label>
				<label class="<?php echo 'allowlist' === $current['mode'] ? 'active' : ''; ?>">
					<input type="radio" name="wp_mcp_mode" value="allowlist" <?php checked( $current['mode'], 'allowlist' ); ?>>
					<strong><?php esc_html_e( 'Allowlist', 'wp-mcp-server' ); ?></strong>
					<span class="mode-description"><?php esc_html_e( 'Only the selected endpoints below are exposed as MCP tools.', 'wp-mcp-server' ); ?></span>
				</label>
				<label class="<?php echo 'blocklist' === $current['mode'] ? 'active' : ''; ?>">
					<input type="radio" name="wp_mcp_mode" value="blocklist" <?php checked( $current['mode'], 'blocklist' ); ?>>
					<strong><?php esc_html_e( 'Blocklist', 'wp-mcp-server' ); ?></strong>
					<span class="mode-description"><?php esc_html_e( 'All endpoints are exposed except the selected ones below.', 'wp-mcp-server' ); ?></span>
				</label>
				<label class="<?php echo 'compact' === $current['mode'] ? 'active' : ''; ?>">
					<input type="radio" name="wp_mcp_mode" value="compact" <?php checked( $current['mode'], 'compact' ); ?>>
					<strong><?php esc_html_e( 'Compact Mode', 'wp-mcp-server' ); ?></strong>
					<span class="mode-description"><?php esc_html_e( 'Replace all tools with a single universal wp_api tool. Minimal token usage.', 'wp-mcp-server' ); ?></span>
				</label>
			</div>

			<div id="wp-mcp-endpoint-panel" class="wp-mcp-endpoint-panel" style="<?php echo in_array( $current['mode'], array( 'allowlist', 'blocklist' ), true ) ? '' : 'display:none;'; ?>">
				<div class="wp-mcp-endpoint-toolbar">
					<input type="search" id="wp-mcp-search" class="search-input" placeholder="<?php esc_attr_e( 'Search endpoints…', 'wp-mcp-server' ); ?>">
					<span id="wp-mcp-counter" class="counter"></span>
					<span class="bulk-actions">
						<button type="button" id="wp-mcp-select-all" class="button button-small"><?php esc_html_e( 'Select All', 'wp-mcp-server' ); ?></button>
						<button type="button" id="wp-mcp-deselect-all" class="button button-small"><?php esc_html_e( 'Deselect All', 'wp-mcp-server' ); ?></button>
					</span>
				</div>
				<div id="wp-mcp-endpoint-list" class="wp-mcp-endpoint-list">
					<!-- Rendered by JS -->
				</div>
			</div>

			<div id="wp-mcp-auto-disable" class="wp-mcp-auto-disable" style="<?php echo 'blocklist' === $current['mode'] ? '' : 'display:none;'; ?>">
				<label>
					<input type="checkbox" id="wp-mcp-auto-disable-new" <?php checked( $current['auto_disable_new'] ); ?>>
					<?php esc_html_e( 'Automatically block newly discovered endpoints', 'wp-mcp-server' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'When new REST API routes appear (e.g. after installing a plugin), they will be blocked by default.', 'wp-mcp-server' ); ?></p>
			</div>

			<div class="wp-mcp-save-row">
				<button type="button" id="wp-mcp-save-settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'wp-mcp-server' ); ?></button>
				<span id="wp-mcp-save-feedback" class="wp-mcp-save-feedback"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Tools tab: MCP Endpoint, Connection, OAuth config.
	 */
	private function render_tab_tools() {
		$endpoint    = rest_url( 'mcp/v1' );
		$current_uid = get_current_user_id();
		$token       = false;
		$gen_user_id = 0;

		if ( isset( $_GET['generated'] ) ) {
			$token       = get_transient( 'wp_mcp_generated_token_' . $current_uid );
			$gen_user_id = get_transient( 'wp_mcp_generated_user_' . $current_uid );
			delete_transient( 'wp_mcp_generated_token_' . $current_uid );
			delete_transient( 'wp_mcp_generated_user_' . $current_uid );
		}

		$users = get_users( array( 'capability__in' => array( 'edit_posts' ) ) );
		?>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; align-items: start;">

			<!-- Column 1 -->
			<div>
				<div class="card" style="max-width: none;">
					<h2><?php esc_html_e( 'MCP Endpoint', 'wp-mcp-server' ); ?></h2>
					<p><?php esc_html_e( 'AI agents connect to your site at:', 'wp-mcp-server' ); ?></p>
					<p>
						<input type="text" value="<?php echo esc_attr( $endpoint ); ?>" readonly
							style="width: 100%; font-family: monospace; font-size: 14px; padding: 8px;"
							onclick="this.select();" />
					</p>
				</div>

				<?php if ( $token ) : ?>
					<div class="card" style="max-width: none; border-left: 4px solid #00a32a;">
						<h2><?php esc_html_e( 'Connection Ready', 'wp-mcp-server' ); ?></h2>
						<p><?php esc_html_e( 'An Application Password was created. Copy one of the configuration snippets below into your MCP client.', 'wp-mcp-server' ); ?></p>
						<p><strong><?php esc_html_e( 'This token is shown only once.', 'wp-mcp-server' ); ?></strong> <?php esc_html_e( 'If you lose it, generate a new one.', 'wp-mcp-server' ); ?></p>

						<h3>Claude Desktop <small><code>claude_desktop_config.json</code></small></h3>
						<textarea readonly rows="11" style="width: 100%; font-family: monospace; font-size: 13px; padding: 8px; background: #f0f0f1;" onclick="this.select();"><?php
							echo esc_textarea( $this->build_config_json( $endpoint, $token, false ) );
						?></textarea>

						<h3>Claude Code / Cursor <small><code>.mcp.json</code></small></h3>
						<textarea readonly rows="11" style="width: 100%; font-family: monospace; font-size: 13px; padding: 8px; background: #f0f0f1;" onclick="this.select();"><?php
							echo esc_textarea( $this->build_config_json( $endpoint, $token, true ) );
						?></textarea>
					</div>
				<?php endif; ?>

				<div class="card" style="max-width: none;">
					<h2><?php esc_html_e( 'Generate Connection', 'wp-mcp-server' ); ?></h2>
					<p><?php esc_html_e( 'Select a WordPress user and click Generate. This creates an Application Password and gives you a ready-to-paste config snippet.', 'wp-mcp-server' ); ?></p>
					<p><?php esc_html_e( "The user's permissions will apply to all MCP operations — choose a user with the appropriate role.", 'wp-mcp-server' ); ?></p>

					<form method="post">
						<?php wp_nonce_field( self::NONCE_NAME, 'wp_mcp_generate_nonce' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="wp_mcp_user"><?php esc_html_e( 'WordPress User', 'wp-mcp-server' ); ?></label></th>
								<td>
									<select name="wp_mcp_user" id="wp_mcp_user" style="min-width: 250px;">
										<?php foreach ( $users as $user ) : ?>
											<option value="<?php echo esc_attr( $user->ID ); ?>"
												<?php selected( $user->ID, $gen_user_id ? $gen_user_id : $current_uid ); ?>>
												<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ') — ' . implode( ', ', $user->roles ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>

						<?php settings_errors( self::PAGE_SLUG ); ?>

						<?php submit_button( __( 'Generate Connection', 'wp-mcp-server' ), 'primary', 'submit', true ); ?>
					</form>
				</div>
			</div>

			<!-- Column 2: OAuth -->
			<div>
				<div class="card" style="max-width: none; border-left: 4px solid #2271b1;">
					<h2><?php esc_html_e( 'OAuth 2.1 Configuration', 'wp-mcp-server' ); ?></h2>
					<p><?php esc_html_e( 'For MCP clients that support OAuth 2.1 (automatic authentication via browser). No token needed — the client handles login automatically.', 'wp-mcp-server' ); ?></p>

					<h3>Claude Desktop <small><code>claude_desktop_config.json</code></small></h3>
					<textarea readonly rows="7" style="width: 100%; font-family: monospace; font-size: 13px; padding: 8px; background: #f0f0f1;" onclick="this.select();"><?php
						echo esc_textarea( $this->build_oauth_config_json( $endpoint, false ) );
					?></textarea>

					<h3>Claude Code / Cursor <small><code>.mcp.json</code></small></h3>
					<textarea readonly rows="8" style="width: 100%; font-family: monospace; font-size: 13px; padding: 8px; background: #f0f0f1;" onclick="this.select();"><?php
						echo esc_textarea( $this->build_oauth_config_json( $endpoint, true ) );
					?></textarea>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Info tab: How It Works guide.
	 */
	private function render_tab_info() {
		?>
		<div class="card" style="max-width: 800px;">
			<h2><?php esc_html_e( 'How It Works', 'wp-mcp-server' ); ?></h2>
			<ol>
				<li><?php
					echo wp_kses(
						__( 'Click <strong>Generate Connection</strong> to create an Application Password and auth token.', 'wp-mcp-server' ),
						array( 'strong' => array() )
					);
				?></li>
				<li><?php esc_html_e( "Copy the config snippet into your MCP client's configuration file.", 'wp-mcp-server' ); ?></li>
				<li><?php esc_html_e( 'Restart your MCP client. It will connect to your site and discover all available REST API routes as tools.', 'wp-mcp-server' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Each REST API route becomes an MCP tool:', 'wp-mcp-server' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Route', 'wp-mcp-server' ); ?></th><th><?php esc_html_e( 'Tool Name', 'wp-mcp-server' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>/wp/v2/posts</code></td><td><code>posts</code></td></tr>
					<tr><td><code>/wp/v2/posts/&lt;id&gt;</code></td><td><code>posts_id</code></td></tr>
					<tr><td><code>/wp/v2/media</code></td><td><code>media</code></td></tr>
					<tr><td><code>/wc/v3/products</code></td><td><code>wc_v3_products</code></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Build an OAuth JSON config snippet for MCP clients (URL-only, no auth header).
	 */
	private function build_oauth_config_json( $endpoint, $include_type ) {
		$server_config = array( 'url' => $endpoint );

		if ( $include_type ) {
			$server_config = array_merge( array( 'type' => 'streamable-http' ), $server_config );
		}

		$config = array(
			'mcpServers' => array(
				'wordpress' => $server_config,
			),
		);

		return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Build a JSON config snippet for MCP clients.
	 */
	private function build_config_json( $endpoint, $token, $include_type ) {
		$server_config = array(
			'url'     => $endpoint,
			'headers' => array(
				'Authorization' => 'Basic ' . $token,
			),
		);

		if ( $include_type ) {
			$server_config = array_merge( array( 'type' => 'streamable-http' ), $server_config );
		}

		$config = array(
			'mcpServers' => array(
				'wordpress' => $server_config,
			),
		);

		return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
