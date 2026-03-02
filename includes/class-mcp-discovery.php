<?php
/**
 * MCP Tool Discovery
 *
 * Discovers WordPress REST API routes and converts them into MCP tool definitions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_Discovery {

	private $tools       = null;
	private $tool_routes = array();

	/**
	 * Get all MCP tool definitions discovered from WordPress REST routes.
	 */
	public function get_tools() {
		if ( null !== $this->tools ) {
			return $this->tools;
		}

		$this->tools = array();
		$rest_server  = rest_get_server();
		$routes       = $rest_server->get_routes();
		$acf_active   = $this->is_acf_active( $routes );

		foreach ( $routes as $pattern => $endpoints_data ) {
			if ( '/' === $pattern ) {
				continue;
			}

			if ( 0 === strpos( $pattern, '/mcp/' ) ) {
				continue;
			}

			$path_params = $this->extract_path_params( $pattern );
			$methods     = $this->get_all_methods( $endpoints_data );

			if ( empty( $methods ) ) {
				continue;
			}

			$tool_name    = $this->build_tool_name( $pattern );
			$description  = $this->build_tool_description( $pattern, $methods );
			$input_schema = $this->build_input_schema( $endpoints_data, $path_params, $pattern, $methods, $acf_active );

			$this->tools[] = array(
				'name'        => $tool_name,
				'description' => $description,
				'inputSchema' => $input_schema,
			);

			$this->tool_routes[ $tool_name ] = array(
				'pattern'     => $pattern,
				'path_params' => $path_params,
			);
		}

		return $this->tools;
	}

	/**
	 * Get route info for a tool by name.
	 */
	public function get_tool_route( $tool_name ) {
		if ( null === $this->tools ) {
			$this->get_tools();
		}
		return isset( $this->tool_routes[ $tool_name ] ) ? $this->tool_routes[ $tool_name ] : null;
	}

	/**
	 * Check if ACF plugin routes are registered.
	 */
	private function is_acf_active( $routes ) {
		foreach ( $routes as $pattern => $data ) {
			if ( 0 === strpos( $pattern, '/acf/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract named path parameters from a route pattern.
	 * e.g. /wp/v2/posts/(?P<id>[\d]+) => ['id']
	 */
	private function extract_path_params( $pattern ) {
		preg_match_all( '/\(\?P<([^>]+)>[^)]+\)/', $pattern, $matches );
		return $matches[1];
	}

	/**
	 * Get all unique HTTP methods across all endpoints of a route.
	 */
	private function get_all_methods( $endpoints_data ) {
		$methods = array();
		foreach ( $endpoints_data as $endpoint ) {
			if ( ! isset( $endpoint['methods'] ) ) {
				continue;
			}
			if ( is_array( $endpoint['methods'] ) ) {
				foreach ( $endpoint['methods'] as $method => $enabled ) {
					if ( $enabled ) {
						$methods[ $method ] = true;
					}
				}
			}
		}
		return array_keys( $methods );
	}

	/**
	 * Build a tool name from a route pattern.
	 * /wp/v2/posts => posts
	 * /wp/v2/posts/(?P<id>[\d]+) => posts.id
	 * /wc/v3/products => wc.v3.products
	 */
	private function build_tool_name( $pattern ) {
		$name = preg_replace( '#^/wp/v2/#', '/', $pattern );
		$name = preg_replace( '/\(\?P<([^>]+)>[^)]+\)/', '$1', $name );
		$name = str_replace( '/', '.', $name );
		$name = ltrim( $name, '.' );
		return $name;
	}

	/**
	 * Build a human-readable description for a tool.
	 */
	private function build_tool_description( $pattern, $methods ) {
		return $pattern . ' [' . implode( ', ', $methods ) . ']';
	}

	/**
	 * Build a JSON Schema for the tool's input parameters.
	 */
	private function build_input_schema( $endpoints_data, $path_params, $pattern, $methods, $acf_active ) {
		$properties = array();
		$required   = array( 'method' );

		$properties['method'] = array(
			'type'        => 'string',
			'enum'        => array_values( $methods ),
			'description' => 'HTTP method to use',
		);

		foreach ( $path_params as $param ) {
			$properties[ $param ] = array(
				'type'        => 'string',
				'description' => 'Path parameter: ' . $param,
			);
			$required[] = $param;
		}

		$merged_args = $this->merge_endpoint_args( $endpoints_data );

		foreach ( $merged_args as $arg_name => $arg_def ) {
			if ( isset( $properties[ $arg_name ] ) ) {
				continue;
			}

			$prop = $this->convert_wp_arg_to_schema( $arg_def );
			if ( $prop ) {
				$properties[ $arg_name ] = $prop;
			}
		}

		if ( $this->is_media_route( $pattern ) ) {
			$properties['file_content'] = array(
				'type'        => 'string',
				'description' => 'Base64-encoded file content for media upload',
			);
			$properties['file_name'] = array(
				'type'        => 'string',
				'description' => 'Filename with extension for media upload (e.g. image.jpg)',
			);
		}

		if ( $acf_active && $this->is_writable_core_route( $pattern, $methods ) ) {
			$properties['acf'] = array(
				'type'        => 'object',
				'description' => 'Advanced Custom Fields values',
			);
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		);
	}

	/**
	 * Merge args from all endpoints of a route into a superset.
	 */
	private function merge_endpoint_args( $endpoints_data ) {
		$merged = array();
		foreach ( $endpoints_data as $endpoint ) {
			if ( ! isset( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
				continue;
			}
			foreach ( $endpoint['args'] as $name => $def ) {
				if ( ! isset( $merged[ $name ] ) ) {
					$merged[ $name ] = $def;
				}
			}
		}
		return $merged;
	}

	/**
	 * Convert a WordPress REST API argument definition to a JSON Schema property.
	 */
	private function convert_wp_arg_to_schema( $arg_def ) {
		$prop = array();

		$type = isset( $arg_def['type'] ) ? $arg_def['type'] : 'string';
		$prop['type'] = $this->sanitize_type( $type );

		if ( isset( $arg_def['description'] ) ) {
			$prop['description'] = $arg_def['description'];
		}

		if ( isset( $arg_def['enum'] ) && is_array( $arg_def['enum'] ) ) {
			$prop['enum'] = $arg_def['enum'];
		}

		if ( 'array' === $prop['type'] && isset( $arg_def['items'] ) ) {
			$items_type = isset( $arg_def['items']['type'] ) ? $arg_def['items']['type'] : 'string';
			$prop['items'] = array( 'type' => $this->sanitize_type( $items_type ) );
		}

		return $prop;
	}

	/**
	 * Sanitize a WordPress type to a valid JSON Schema type.
	 */
	private function sanitize_type( $type ) {
		if ( is_array( $type ) ) {
			$valid = array( 'string', 'number', 'integer', 'boolean', 'array', 'object' );
			foreach ( $type as $t ) {
				if ( in_array( $t, $valid, true ) ) {
					return $t;
				}
			}
			return 'string';
		}

		$valid_types = array( 'string', 'number', 'integer', 'boolean', 'array', 'object' );
		if ( in_array( $type, $valid_types, true ) ) {
			return $type;
		}
		return 'string';
	}

	/**
	 * Check if a route is a media upload route.
	 */
	private function is_media_route( $pattern ) {
		return (bool) preg_match( '#^/wp/v2/media#', $pattern );
	}

	/**
	 * Check if a route is a writable core WordPress route.
	 */
	private function is_writable_core_route( $pattern, $methods ) {
		if ( 0 !== strpos( $pattern, '/wp/v2/' ) ) {
			return false;
		}
		$writable = array( 'POST', 'PUT', 'PATCH' );
		return count( array_intersect( $methods, $writable ) ) > 0;
	}
}
