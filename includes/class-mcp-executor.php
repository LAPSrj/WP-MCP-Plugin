<?php
/**
 * MCP Tool Executor
 *
 * Executes MCP tool calls by making internal WordPress REST API requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_Executor {

	private $discovery;

	public function __construct( WP_MCP_Discovery $discovery ) {
		$this->discovery = $discovery;
	}

	/**
	 * Execute a tool call and return the MCP result.
	 *
	 * @param string $tool_name  The tool name.
	 * @param array  $arguments  The tool arguments.
	 * @return array MCP tool result with 'content' array.
	 */
	public function execute( $tool_name, $arguments ) {
		if ( 'refresh_tools' === $tool_name ) {
			return $this->handle_refresh_tools();
		}

		$route_info = $this->discovery->get_tool_route( $tool_name );

		if ( ! $route_info ) {
			return $this->error_result( 'Unknown tool: ' . $tool_name );
		}

		$http_method = isset( $arguments['method'] ) ? strtoupper( $arguments['method'] ) : 'GET';
		unset( $arguments['method'] );

		$route = $this->substitute_path_params(
			$route_info['pattern'],
			$route_info['path_params'],
			$arguments
		);

		foreach ( $route_info['path_params'] as $param ) {
			unset( $arguments[ $param ] );
		}

		if ( isset( $arguments['file_content'] ) && isset( $arguments['file_name'] ) ) {
			return $this->handle_media_upload( $route, $arguments );
		}

		$request = new WP_REST_Request( $http_method, $route );

		if ( 'GET' === $http_method || 'DELETE' === $http_method ) {
			$request->set_query_params( $arguments );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $arguments ) );
		}

		$response = rest_do_request( $request );

		return $this->format_response( $response );
	}

	/**
	 * Handle the refresh_tools built-in tool.
	 *
	 * Forces re-discovery of REST API routes and returns the updated count.
	 * The server will include a tools/list_changed notification in the response
	 * so the client knows to re-fetch the tool list.
	 */
	private function handle_refresh_tools() {
		$this->discovery->reset();
		$tools = $this->discovery->get_tools();
		$count = count( $tools );

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'Refreshed tool list. Discovered ' . $count . ' tools.',
				),
			),
			'isError'          => false,
			'_tools_refreshed' => true,
		);
	}

	/**
	 * Handle media file upload from base64 content.
	 */
	private function handle_media_upload( $route, $arguments ) {
		$file_content = base64_decode( $arguments['file_content'] );
		$file_name    = sanitize_file_name( $arguments['file_name'] );
		unset( $arguments['file_content'], $arguments['file_name'] );

		if ( false === $file_content ) {
			return $this->error_result( 'Invalid base64 file content' );
		}

		$upload = wp_upload_bits( $file_name, null, $file_content );

		if ( ! empty( $upload['error'] ) ) {
			return $this->error_result( 'Upload failed: ' . $upload['error'] );
		}

		$file_type  = wp_check_filetype( $file_name );
		$attachment = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attach_id ) ) {
			return $this->error_result( 'Failed to create attachment: ' . $attach_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		if ( ! empty( $arguments ) ) {
			$update_request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $attach_id );
			$update_request->set_header( 'Content-Type', 'application/json' );
			$update_request->set_body( wp_json_encode( $arguments ) );
			rest_do_request( $update_request );
		}

		$get_request = new WP_REST_Request( 'GET', '/wp/v2/media/' . $attach_id );
		$response    = rest_do_request( $get_request );

		return $this->format_response( $response );
	}

	/**
	 * Substitute path parameters into a route pattern.
	 * e.g. /wp/v2/posts/(?P<id>[\d]+) with id=123 => /wp/v2/posts/123
	 */
	private function substitute_path_params( $pattern, $path_params, $arguments ) {
		$route = $pattern;
		foreach ( $path_params as $param ) {
			if ( isset( $arguments[ $param ] ) ) {
				$route = preg_replace(
					'/\(\?P<' . preg_quote( $param, '/' ) . '>[^)]+\)/',
					rawurlencode( $arguments[ $param ] ),
					$route
				);
			}
		}
		return $route;
	}

	/**
	 * Format a REST API response as an MCP tool result.
	 */
	private function format_response( $response ) {
		$rest_server = rest_get_server();
		$data        = $rest_server->response_to_data( $response, false );
		$status      = $response->get_status();
		$is_error    = $status >= 400;

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				),
			),
			'isError' => $is_error,
		);
	}

	/**
	 * Build an error result.
	 */
	private function error_result( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}
}
