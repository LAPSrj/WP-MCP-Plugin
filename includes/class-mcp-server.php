<?php
/**
 * MCP Server
 *
 * Implements the MCP Streamable HTTP transport over WordPress REST API.
 * Endpoint: /wp-json/mcp/v1
 *
 * Clients connect with HTTP Basic Auth using WordPress Application Passwords.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_Server {

	const NAMESPACE        = 'mcp/v1';
	const PROTOCOL_VERSION = '2024-11-05';
	const SERVER_NAME      = 'wp-mcp';

	private $discovery;
	private $executor;

	public function __construct( WP_MCP_Discovery $discovery, WP_MCP_Executor $executor ) {
		$this->discovery = $discovery;
		$this->executor  = $executor;
	}

	/**
	 * Register the MCP REST API routes and intercept namespace root requests.
	 *
	 * WordPress auto-generates a GET-only namespace index at /mcp/v1 that
	 * conflicts with our route at '/'. We use rest_pre_dispatch to intercept
	 * POST and DELETE requests on the namespace root before WP's routing.
	 */
	public function register_routes() {
		add_filter( 'rest_pre_dispatch', array( $this, 'intercept_namespace_root' ), 10, 3 );
	}

	/**
	 * Intercept POST and DELETE requests to the MCP namespace root.
	 *
	 * WordPress auto-generates a GET-only namespace index that shadows our
	 * route. This filter fires before WP dispatches, letting us handle
	 * POST and DELETE ourselves.
	 */
	public function intercept_namespace_root( $result, $server, $request ) {
		$route  = $request->get_route();
		$method = $request->get_method();

		// Only intercept requests to our namespace root.
		$is_root = ( '/mcp/v1' === $route || '/mcp/v1/' === $route );
		if ( ! $is_root ) {
			return $result;
		}

		// Let GET fall through to the namespace index (or our handler below).
		if ( 'POST' !== $method && 'DELETE' !== $method ) {
			return $result;
		}

		// Check permissions.
		$permission = $this->check_permissions( $request );
		if ( true !== $permission ) {
			return rest_ensure_response( $permission );
		}

		if ( 'POST' === $method ) {
			return rest_ensure_response( $this->handle_post( $request ) );
		}

		return rest_ensure_response( $this->handle_delete( $request ) );
	}

	/**
	 * Check that the request is authenticated with sufficient permissions.
	 *
	 * Supports both Basic Auth (backward compatible) and Bearer tokens (OAuth 2.1).
	 */
	public function check_permissions( $request ) {
		// WordPress native auth (Basic Auth with Application Passwords) already ran.
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		// Try Bearer token: decode base64 to user:pass and authenticate.
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && 0 === strpos( $auth_header, 'Bearer ' ) ) {
			$token   = substr( $auth_header, 7 );
			$decoded = base64_decode( $token, true );

			if ( $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $username, $password ) = explode( ':', $decoded, 2 );

				$user = wp_authenticate_application_password( null, $username, $password );

				if ( $user instanceof WP_User ) {
					wp_set_current_user( $user->ID );

					if ( current_user_can( 'edit_posts' ) ) {
						return true;
					}
				}
			}
		}

		// No valid auth — send 401 with resource metadata hint.
		$resource_url = home_url( '/.well-known/oauth-protected-resource' );
		header( 'WWW-Authenticate: Bearer resource_metadata="' . $resource_url . '"' );

		return new WP_Error(
			'rest_forbidden',
			__( 'Authentication required.', 'wp-mcp-server' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Handle POST requests — JSON-RPC messages from MCP clients.
	 */
	public function handle_post( $request ) {
		$body = json_decode( $request->get_body(), true );

		if ( null === $body ) {
			return $this->jsonrpc_error( null, -32700, 'Parse error' );
		}

		if ( ! isset( $body['id'] ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$id     = $body['id'];
		$method = isset( $body['method'] ) ? $body['method'] : '';
		$params = isset( $body['params'] ) ? $body['params'] : array();

		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize( $id, $params );

			case 'ping':
				return $this->jsonrpc_response( $id, array() );

			case 'tools/list':
				return $this->handle_tools_list( $id );

			case 'tools/call':
				return $this->handle_tools_call( $id, $params );

			default:
				return $this->jsonrpc_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * Handle GET requests — SSE stream endpoint.
	 * Returns 405 since this server only supports POST-based communication.
	 */
	public function handle_get( $request ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32000,
					'message' => 'SSE stream not supported. Use POST to send JSON-RPC messages.',
				),
			),
			405
		);
	}

	/**
	 * Handle DELETE requests — session termination.
	 */
	public function handle_delete( $request ) {
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Handle the 'initialize' method.
	 */
	private function handle_initialize( $id, $params ) {
		$result = array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools' => new stdClass(),
			),
			'serverInfo'      => array(
				'name'    => self::SERVER_NAME,
				'version' => WP_MCP_VERSION,
			),
		);

		$response = $this->jsonrpc_response( $id, $result );
		$response->header( 'Mcp-Session-Id', wp_generate_uuid4() );
		return $response;
	}

	/**
	 * Handle the 'tools/list' method.
	 */
	private function handle_tools_list( $id ) {
		$tools  = $this->discovery->get_tools();
		$result = array( 'tools' => $tools );
		return $this->jsonrpc_response( $id, $result );
	}

	/**
	 * Handle the 'tools/call' method.
	 */
	private function handle_tools_call( $id, $params ) {
		$tool_name = isset( $params['name'] ) ? $params['name'] : '';
		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

		if ( empty( $tool_name ) ) {
			return $this->jsonrpc_error( $id, -32602, 'Missing tool name' );
		}

		$result = $this->executor->execute( $tool_name, $arguments );

		return $this->jsonrpc_response( $id, $result );
	}

	/**
	 * Build a JSON-RPC success response.
	 */
	private function jsonrpc_response( $id, $result ) {
		$data = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Build a JSON-RPC error response.
	 */
	private function jsonrpc_error( $id, $code, $message ) {
		$data = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
		return new WP_REST_Response( $data, 200 );
	}
}
