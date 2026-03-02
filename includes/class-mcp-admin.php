<?php
/**
 * MCP Admin Settings Page
 *
 * Provides a settings screen to generate Application Passwords and
 * copy-ready MCP client configuration snippets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_Admin {

	const PAGE_SLUG  = 'wp-mcp-settings';
	const NONCE_NAME = 'wp_mcp_generate';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_generate' ) );
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

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&generated=1' ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
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
		<div class="wrap">
			<h1><?php esc_html_e( 'WP MCP Server', 'wp-mcp-server' ); ?></h1>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; align-items: start;">

				<!-- Column 1: Endpoint -->
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
				</div>

				<!-- Column 2: Generate Connection -->
				<div>
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

					<div class="card" style="max-width: none;">
						<h2><?php esc_html_e( 'How It Works', 'wp-mcp-server' ); ?></h2>
						<ol>
							<li><?php
								printf(
									/* translators: %s: "Generate Connection" (bold) */
									esc_html__( 'Click %s to create an Application Password and auth token.', 'wp-mcp-server' ),
									'<strong>' . esc_html__( 'Generate Connection', 'wp-mcp-server' ) . '</strong>'
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
				</div>

				<!-- Column 3: OAuth -->
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
