<?php
/**
 * MCP OAuth 2.1 Authorization
 *
 * Implements OAuth 2.1 discovery and authorization flow for MCP clients.
 * Uses WordPress Application Passwords as access tokens — no custom DB tables.
 *
 * Endpoints:
 *   /.well-known/oauth-protected-resource     — Resource metadata
 *   /.well-known/oauth-authorization-server    — Authorization server metadata
 *   /wp-json/mcp/v1/oauth/register             — Dynamic client registration (stateless)
 *   /wp-json/mcp/v1/oauth/authorize            — Authorization + consent
 *   /wp-json/mcp/v1/oauth/token                — Token exchange
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_OAuth {

	const NAMESPACE = 'mcp/v1';

	/**
	 * Hook into WordPress to serve well-known endpoints.
	 */
	public function init() {
		add_action( 'parse_request', array( $this, 'handle_well_known' ) );
	}

	/**
	 * Register OAuth REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/authorize',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_authorize_get' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_authorize_post' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Intercept /.well-known/ requests before WordPress routing.
	 */
	public function handle_well_known( $wp ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( '/.well-known/oauth-protected-resource' === $path ) {
			$this->send_json( $this->get_protected_resource_metadata() );
		}

		if ( '/.well-known/oauth-authorization-server' === $path ) {
			$this->send_json( $this->get_authorization_server_metadata() );
		}
	}

	/**
	 * Build protected resource metadata.
	 */
	private function get_protected_resource_metadata() {
		return array(
			'resource'                 => rest_url( self::NAMESPACE ),
			'authorization_servers'    => array( home_url() ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * Build authorization server metadata.
	 */
	private function get_authorization_server_metadata() {
		$base = rest_url( self::NAMESPACE );

		return array(
			'issuer'                             => home_url(),
			'authorization_endpoint'             => $base . '/oauth/authorize',
			'token_endpoint'                     => $base . '/oauth/token',
			'registration_endpoint'              => $base . '/oauth/register',
			'response_types_supported'           => array( 'code' ),
			'grant_types_supported'              => array( 'authorization_code' ),
			'code_challenge_methods_supported'   => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
		);
	}

	/**
	 * Dynamic client registration — stateless.
	 *
	 * Generates a deterministic client_id from redirect_uris and echoes
	 * everything back. No storage needed.
	 */
	public function handle_register( $request ) {
		$params        = $request->get_json_params();
		$redirect_uris = isset( $params['redirect_uris'] ) ? $params['redirect_uris'] : array();
		$client_name   = isset( $params['client_name'] ) ? $params['client_name'] : 'MCP Client';

		if ( empty( $redirect_uris ) || ! is_array( $redirect_uris ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris is required.' ),
				400
			);
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! $this->is_valid_redirect_uri( $uri ) ) {
				return new WP_REST_Response(
					array( 'error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URIs must use HTTPS or localhost.' ),
					400
				);
			}
		}

		$client_id = hash( 'sha256', wp_json_encode( $redirect_uris ) );

		return new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_name'                => $client_name,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => array( 'authorization_code' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
			201
		);
	}

	/**
	 * Authorization endpoint — GET shows consent page or redirects to login.
	 */
	public function handle_authorize_get( $request ) {
		$response_type  = $request->get_param( 'response_type' );
		$client_id      = $request->get_param( 'client_id' );
		$redirect_uri   = $request->get_param( 'redirect_uri' );
		$state          = $request->get_param( 'state' );
		$code_challenge = $request->get_param( 'code_challenge' );
		$code_challenge_method = $request->get_param( 'code_challenge_method' );
		$resource       = $request->get_param( 'resource' );

		// Validate required params.
		if ( 'code' !== $response_type ) {
			return $this->authorize_error( $redirect_uri, $state, 'unsupported_response_type', 'Only response_type=code is supported.' );
		}

		if ( empty( $code_challenge ) || 'S256' !== $code_challenge_method ) {
			return $this->authorize_error( $redirect_uri, $state, 'invalid_request', 'PKCE with S256 code_challenge is required.' );
		}

		if ( empty( $client_id ) || empty( $redirect_uri ) ) {
			return $this->authorize_error( $redirect_uri, $state, 'invalid_request', 'client_id and redirect_uri are required.' );
		}

		if ( ! $this->is_valid_redirect_uri( $redirect_uri ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_request', 'error_description' => 'Invalid redirect_uri.' ),
				400
			);
		}

		// If not logged in, redirect to WordPress login and come back.
		if ( ! is_user_logged_in() ) {
			$current_url = add_query_arg(
				array(
					'response_type'         => $response_type,
					'client_id'             => $client_id,
					'redirect_uri'          => $redirect_uri,
					'state'                 => $state,
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => $code_challenge_method,
					'resource'              => $resource,
				),
				rest_url( self::NAMESPACE . '/oauth/authorize' )
			);
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		// Render consent page.
		$this->render_consent_page( $request );
		exit;
	}

	/**
	 * Authorization endpoint — POST handles consent form submission.
	 */
	public function handle_authorize_post( $request ) {
		// Verify nonce.
		$nonce = $request->get_param( 'wp_mcp_oauth_nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_mcp_oauth_authorize' ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_request', 'error_description' => 'Invalid or expired nonce.' ),
				403
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array( 'error' => 'login_required', 'error_description' => 'User must be logged in.' ),
				401
			);
		}

		$redirect_uri   = $request->get_param( 'redirect_uri' );
		$state          = $request->get_param( 'state' );
		$client_id      = $request->get_param( 'client_id' );
		$code_challenge = $request->get_param( 'code_challenge' );
		$resource       = $request->get_param( 'resource' );
		$consent        = $request->get_param( 'consent' );

		if ( ! $this->is_valid_redirect_uri( $redirect_uri ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_request', 'error_description' => 'Invalid redirect_uri.' ),
				400
			);
		}

		// User denied.
		if ( 'approve' !== $consent ) {
			$deny_url = add_query_arg(
				array(
					'error' => 'access_denied',
					'state' => $state,
				),
				$redirect_uri
			);
			wp_redirect( $deny_url );
			exit;
		}

		// Generate auth code.
		$code = bin2hex( random_bytes( 32 ) );

		// Store in transient with 60s TTL.
		$transient_data = array(
			'user_id'        => get_current_user_id(),
			'client_id'      => $client_id,
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => $code_challenge,
			'resource'       => $resource,
		);
		set_transient( 'wp_mcp_oauth_code_' . hash( 'sha256', $code ), $transient_data, 60 );

		$approve_url = add_query_arg(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);
		wp_redirect( $approve_url );
		exit;
	}

	/**
	 * Token endpoint — exchange auth code for access token.
	 */
	public function handle_token( $request ) {
		$grant_type    = $request->get_param( 'grant_type' );
		$code          = $request->get_param( 'code' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$client_id     = $request->get_param( 'client_id' );
		$code_verifier = $request->get_param( 'code_verifier' );

		if ( 'authorization_code' !== $grant_type ) {
			return new WP_REST_Response(
				array( 'error' => 'unsupported_grant_type' ),
				400
			);
		}

		if ( empty( $code ) || empty( $code_verifier ) || empty( $client_id ) || empty( $redirect_uri ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_request', 'error_description' => 'Missing required parameters.' ),
				400
			);
		}

		// Retrieve and delete the stored auth code.
		$transient_key = 'wp_mcp_oauth_code_' . hash( 'sha256', $code );
		$stored        = get_transient( $transient_key );

		if ( false === $stored ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid or expired.' ),
				400
			);
		}

		// Delete immediately — single-use.
		delete_transient( $transient_key );

		// Verify client_id and redirect_uri match.
		if ( $stored['client_id'] !== $client_id || $stored['redirect_uri'] !== $redirect_uri ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_grant', 'error_description' => 'client_id or redirect_uri mismatch.' ),
				400
			);
		}

		// Verify PKCE: base64url(sha256(code_verifier)) must match stored code_challenge.
		$computed_challenge = $this->base64url_encode( hash( 'sha256', $code_verifier, true ) );
		if ( ! hash_equals( $stored['code_challenge'], $computed_challenge ) ) {
			return new WP_REST_Response(
				array( 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.' ),
				400
			);
		}

		// Create Application Password for the user.
		$user = get_user_by( 'id', $stored['user_id'] );
		if ( ! $user ) {
			return new WP_REST_Response(
				array( 'error' => 'server_error', 'error_description' => 'User not found.' ),
				500
			);
		}

		$app_password = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'MCP OAuth (' . gmdate( 'Y-m-d H:i' ) . ')' )
		);

		if ( is_wp_error( $app_password ) ) {
			return new WP_REST_Response(
				array( 'error' => 'server_error', 'error_description' => $app_password->get_error_message() ),
				500
			);
		}

		$password     = $app_password[0];
		$access_token = base64_encode( $user->user_login . ':' . $password );

		return new WP_REST_Response(
			array(
				'access_token' => $access_token,
				'token_type'   => 'Bearer',
			),
			200
		);
	}

	/**
	 * Render the consent page HTML.
	 */
	private function render_consent_page( $request ) {
		$user        = wp_get_current_user();
		$client_id   = esc_attr( $request->get_param( 'client_id' ) );
		$redirect_uri = esc_attr( $request->get_param( 'redirect_uri' ) );
		$state       = esc_attr( $request->get_param( 'state' ) );
		$code_challenge = esc_attr( $request->get_param( 'code_challenge' ) );
		$resource    = esc_attr( $request->get_param( 'resource' ) );
		$site_name   = get_bloginfo( 'name' );
		$nonce       = wp_create_nonce( 'wp_mcp_oauth_authorize' );
		$action_url  = rest_url( self::NAMESPACE . '/oauth/authorize' );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Authorize — <?php echo esc_html( $site_name ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; margin: 0; padding: 40px 20px; }
		.consent-box { max-width: 420px; margin: 0 auto; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 24px 32px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
		h1 { font-size: 20px; margin: 0 0 16px; }
		.info { background: #f6f7f7; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 16px 0; font-size: 13px; }
		.info code { background: #e0e0e0; padding: 2px 6px; border-radius: 3px; }
		.user { font-weight: 600; }
		.buttons { margin-top: 24px; display: flex; gap: 12px; }
		.btn { padding: 8px 20px; border: 1px solid #c3c4c7; border-radius: 3px; font-size: 14px; cursor: pointer; text-decoration: none; }
		.btn-primary { background: #2271b1; color: #fff; border-color: #2271b1; }
		.btn-primary:hover { background: #135e96; }
		.btn-secondary { background: #f6f7f7; color: #50575e; }
		.btn-secondary:hover { background: #e0e0e0; }
	</style>
</head>
<body>
	<div class="consent-box">
		<h1>Authorize MCP Client</h1>
		<p>An application wants to access <strong><?php echo esc_html( $site_name ); ?></strong> on your behalf.</p>

		<div class="info">
			<p>Logged in as <span class="user"><?php echo esc_html( $user->display_name ); ?></span> (<?php echo esc_html( $user->user_login ); ?>)</p>
			<p>Redirect: <code><?php echo esc_html( $request->get_param( 'redirect_uri' ) ); ?></code></p>
		</div>

		<p>This will grant the application permission to act with your WordPress capabilities.</p>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="wp_mcp_oauth_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
			<input type="hidden" name="redirect_uri" value="<?php echo $redirect_uri; ?>">
			<input type="hidden" name="state" value="<?php echo $state; ?>">
			<input type="hidden" name="code_challenge" value="<?php echo $code_challenge; ?>">
			<input type="hidden" name="resource" value="<?php echo $resource; ?>">

			<div class="buttons">
				<button type="submit" name="consent" value="approve" class="btn btn-primary">Approve</button>
				<button type="submit" name="consent" value="deny" class="btn btn-secondary">Deny</button>
			</div>
		</form>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Redirect with an OAuth error.
	 */
	private function authorize_error( $redirect_uri, $state, $error, $description ) {
		if ( empty( $redirect_uri ) || ! $this->is_valid_redirect_uri( $redirect_uri ) ) {
			return new WP_REST_Response(
				array( 'error' => $error, 'error_description' => $description ),
				400
			);
		}

		$params = array( 'error' => $error, 'error_description' => $description );
		if ( ! empty( $state ) ) {
			$params['state'] = $state;
		}
		$url = add_query_arg( $params, $redirect_uri );
		wp_redirect( $url );
		exit;
	}

	/**
	 * Check that a redirect URI is HTTPS or localhost.
	 */
	private function is_valid_redirect_uri( $uri ) {
		if ( empty( $uri ) ) {
			return false;
		}

		$parsed = wp_parse_url( $uri );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		// Allow HTTPS.
		if ( 'https' === $parsed['scheme'] ) {
			return true;
		}

		// Allow HTTP only for localhost / 127.0.0.1 / [::1].
		if ( 'http' === $parsed['scheme'] ) {
			$host = strtolower( $parsed['host'] );
			if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Base64url encode (RFC 7636).
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Send a JSON response and exit.
	 */
	private function send_json( $data ) {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}
}
