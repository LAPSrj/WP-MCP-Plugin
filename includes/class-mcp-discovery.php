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
		$used_names   = array();

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

			$tool_name = $this->build_tool_name( $pattern );

			if ( empty( $tool_name ) ) {
				continue;
			}

			// Deduplicate names that collide after sanitization/truncation.
			if ( isset( $used_names[ $tool_name ] ) ) {
				$suffix = 2;
				while ( isset( $used_names[ $tool_name . '_' . $suffix ] ) ) {
					$suffix++;
				}
				$tool_name = $tool_name . '_' . $suffix;
			}
			$used_names[ $tool_name ] = true;

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

		// Built-in tool: refresh_tools — lets agents trigger a tools/list re-fetch.
		array_unshift( $this->tools, array(
			'name'        => 'refresh_tools',
			'description' => 'Re-discover all WordPress REST API routes and update the tool list. Use this after registering new post types, installing plugins, or any change that adds/removes REST API endpoints.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		) );

		// Apply endpoint filtering settings before the wp_mcp_tools filter.
		$this->apply_endpoint_settings();

		// Apply minimal descriptions if enabled.
		$this->maybe_apply_minimal_descriptions();

		/**
		 * Filter the list of MCP tools exposed to clients.
		 *
		 * Allows site administrators to restrict which REST API routes
		 * are available as MCP tools (e.g. remove sensitive endpoints).
		 *
		 * @param array $tools       Array of MCP tool definitions.
		 * @param array $tool_routes Map of tool name to route info.
		 */
		$this->tools = apply_filters( 'wp_mcp_tools', $this->tools, $this->tool_routes );

		return $this->tools;
	}

	/**
	 * Reset the cached tool list, forcing re-discovery on next get_tools() call.
	 */
	public function reset() {
		$this->tools       = null;
		$this->tool_routes = array();
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
	 * Get all route patterns grouped by namespace, for the admin UI.
	 *
	 * @return array Array of arrays with 'pattern', 'methods', 'namespace' keys.
	 */
	public function get_all_route_patterns() {
		$rest_server = rest_get_server();
		$routes      = $rest_server->get_routes();
		$result      = array();

		foreach ( $routes as $pattern => $endpoints_data ) {
			if ( '/' === $pattern ) {
				continue;
			}
			if ( 0 === strpos( $pattern, '/mcp/' ) ) {
				continue;
			}

			$methods = $this->get_all_methods( $endpoints_data );
			if ( empty( $methods ) ) {
				continue;
			}

			// Extract namespace (first two segments, e.g. "wp/v2").
			$parts     = explode( '/', ltrim( $pattern, '/' ) );
			$namespace = count( $parts ) >= 2 ? $parts[0] . '/' . $parts[1] : $parts[0];

			$result[] = array(
				'pattern'   => $pattern,
				'methods'   => $methods,
				'namespace' => $namespace,
			);
		}

		return $result;
	}

	/**
	 * Determine the category for a route pattern.
	 *
	 * @param string $pattern Route pattern.
	 * @return string One of 'post_types', 'taxonomies', 'core', 'plugin'.
	 */
	public function get_route_category( $pattern ) {
		// Non wp/v2 routes are always "plugin".
		if ( 0 !== strpos( $pattern, '/wp/v2/' ) ) {
			return 'plugin';
		}

		// Extract the rest_base segment (third segment after /wp/v2/).
		$trimmed  = substr( $pattern, 7 ); // Remove "/wp/v2/"
		$slash    = strpos( $trimmed, '/' );
		$rest_base = ( false !== $slash ) ? substr( $trimmed, 0, $slash ) : $trimmed;

		// Check post types.
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );
		foreach ( $post_types as $pt ) {
			if ( ! empty( $pt->rest_base ) && $pt->rest_base === $rest_base ) {
				return 'post_types';
			}
			if ( empty( $pt->rest_base ) && $pt->name === $rest_base ) {
				return 'post_types';
			}
		}

		// Check taxonomies.
		$taxonomies = get_taxonomies( array( 'show_in_rest' => true ), 'objects' );
		foreach ( $taxonomies as $tax ) {
			if ( ! empty( $tax->rest_base ) && $tax->rest_base === $rest_base ) {
				return 'taxonomies';
			}
			if ( empty( $tax->rest_base ) && $tax->name === $rest_base ) {
				return 'taxonomies';
			}
		}

		// Remaining wp/v2 routes are "core".
		return 'core';
	}

	/**
	 * Get all route patterns grouped by category, for the description mode admin UI.
	 *
	 * @return array Associative array with category keys, each containing an array of route info.
	 */
	public function get_all_route_patterns_grouped() {
		$rest_server = rest_get_server();
		$routes      = $rest_server->get_routes();
		$grouped     = array(
			'post_types' => array(),
			'taxonomies' => array(),
			'core'       => array(),
			'plugin'     => array(),
		);

		foreach ( $routes as $pattern => $endpoints_data ) {
			if ( '/' === $pattern ) {
				continue;
			}
			if ( 0 === strpos( $pattern, '/mcp/' ) ) {
				continue;
			}

			$methods = $this->get_all_methods( $endpoints_data );
			if ( empty( $methods ) ) {
				continue;
			}

			$category = $this->get_route_category( $pattern );

			$grouped[ $category ][] = array(
				'pattern' => $pattern,
				'methods' => $methods,
			);
		}

		return $grouped;
	}

	/**
	 * Apply admin endpoint filtering settings to the tool list.
	 */
	private function apply_endpoint_settings() {
		$settings = get_option( 'wp_mcp_endpoint_settings', array() );
		$mode     = isset( $settings['mode'] ) ? $settings['mode'] : 'all';

		if ( 'all' === $mode ) {
			return;
		}

		if ( 'compact' === $mode ) {
			$this->apply_compact_mode();
			return;
		}

		$selected     = isset( $settings['endpoints'] ) ? $settings['endpoints'] : array();
		$selected_map = array_flip( $selected );

		// Per-category "include new items" with backward compat for old auto_disable_new.
		$endpoint_include_new = isset( $settings['endpoint_include_new'] ) ? $settings['endpoint_include_new'] : array();
		$known_routes         = isset( $settings['known_routes'] ) ? $settings['known_routes'] : array();
		$known_map            = array_flip( $known_routes );

		// Backward compat: old auto_disable_new (blocklist only) → all categories.
		if ( empty( $endpoint_include_new ) && ! empty( $settings['auto_disable_new'] ) && 'blocklist' === $mode ) {
			$endpoint_include_new = array( 'post_types', 'taxonomies', 'core', 'plugin' );
		}

		if ( 'allowlist' === $mode ) {
			$filtered        = array();
			$filtered_routes = array();

			foreach ( $this->tools as $tool ) {
				if ( 'refresh_tools' === $tool['name'] ) {
					$filtered[] = $tool;
					continue;
				}

				$route_info = isset( $this->tool_routes[ $tool['name'] ] ) ? $this->tool_routes[ $tool['name'] ] : null;
				if ( ! $route_info ) {
					continue;
				}

				$pattern = $route_info['pattern'];

				// Include if explicitly selected.
				if ( isset( $selected_map[ $pattern ] ) ) {
					$filtered[]                        = $tool;
					$filtered_routes[ $tool['name'] ]  = $route_info;
					continue;
				}

				// Auto-include new routes in categories with "include new items" checked.
				if ( ! empty( $endpoint_include_new ) && ! isset( $known_map[ $pattern ] ) ) {
					$category = $this->get_route_category( $pattern );
					if ( in_array( $category, $endpoint_include_new, true ) ) {
						$filtered[]                        = $tool;
						$filtered_routes[ $tool['name'] ]  = $route_info;
					}
				}
			}

			$this->tools       = $filtered;
			$this->tool_routes = $filtered_routes;
			return;
		}

		if ( 'blocklist' === $mode ) {
			$filtered        = array();
			$filtered_routes = array();

			foreach ( $this->tools as $tool ) {
				if ( 'refresh_tools' === $tool['name'] ) {
					$filtered[] = $tool;
					continue;
				}

				$route_info = isset( $this->tool_routes[ $tool['name'] ] ) ? $this->tool_routes[ $tool['name'] ] : null;
				if ( ! $route_info ) {
					$filtered[] = $tool;
					continue;
				}

				$pattern = $route_info['pattern'];

				// Block if explicitly selected.
				if ( isset( $selected_map[ $pattern ] ) ) {
					continue;
				}

				// Auto-block new routes in categories with "include new items" checked.
				if ( ! empty( $endpoint_include_new ) && ! isset( $known_map[ $pattern ] ) ) {
					$category = $this->get_route_category( $pattern );
					if ( in_array( $category, $endpoint_include_new, true ) ) {
						continue;
					}
				}

				$filtered[]                        = $tool;
				$filtered_routes[ $tool['name'] ]  = $route_info;
			}

			$this->tools       = $filtered;
			$this->tool_routes = $filtered_routes;
		}
	}

	/**
	 * Apply description mode settings. Supports four modes:
	 * - 'all'       — every tool keeps verbose descriptions (no-op)
	 * - 'none'      — every tool gets minimal one-liner descriptions
	 * - 'allowlist'  — only selected items keep verbose; rest get minimal
	 * - 'blocklist'  — all verbose except selected items, which get minimal
	 *
	 * Backward compat: old boolean `minimal_descriptions: true` → treated as 'none'.
	 */
	private function maybe_apply_minimal_descriptions() {
		$settings = get_option( 'wp_mcp_endpoint_settings', array() );

		// Determine effective description mode.
		$desc_mode = 'all';

		if ( isset( $settings['description_mode'] ) ) {
			$desc_mode = $settings['description_mode'];
		} elseif ( ! empty( $settings['minimal_descriptions'] ) ) {
			// Backward compat: old boolean toggle.
			$desc_mode = 'none';
		}

		if ( 'all' === $desc_mode ) {
			return;
		}

		$desc_items       = isset( $settings['description_items'] ) ? array_flip( $settings['description_items'] ) : array();
		$desc_include_new = isset( $settings['description_include_new'] ) ? $settings['description_include_new'] : array();
		$desc_known       = isset( $settings['description_known_routes'] ) ? array_flip( $settings['description_known_routes'] ) : array();

		foreach ( $this->tools as &$tool ) {
			// Skip built-in tools that don't map to a REST route.
			if ( 'refresh_tools' === $tool['name'] || 'wp_api' === $tool['name'] ) {
				continue;
			}

			$route_info = isset( $this->tool_routes[ $tool['name'] ] ) ? $this->tool_routes[ $tool['name'] ] : null;
			$pattern    = $route_info ? $route_info['pattern'] : '';

			$should_minimize = false;

			if ( 'none' === $desc_mode ) {
				$should_minimize = true;
			} elseif ( 'allowlist' === $desc_mode ) {
				// Verbose only if in the selected items list, or auto-included for its category.
				$is_selected = isset( $desc_items[ $pattern ] );
				$is_auto     = false;
				if ( ! $is_selected && $pattern && ! empty( $desc_include_new ) && ! isset( $desc_known[ $pattern ] ) ) {
					$category = $this->get_route_category( $pattern );
					$is_auto  = in_array( $category, $desc_include_new, true );
				}
				$should_minimize = ! $is_selected && ! $is_auto;
			} elseif ( 'blocklist' === $desc_mode ) {
				// Minimize only if explicitly selected, or if it's a new route in an auto-included category.
				$is_selected = isset( $desc_items[ $pattern ] );
				$is_auto     = false;
				if ( ! $is_selected && $pattern && ! empty( $desc_include_new ) && ! isset( $desc_known[ $pattern ] ) ) {
					$category = $this->get_route_category( $pattern );
					$is_auto  = in_array( $category, $desc_include_new, true );
				}
				$should_minimize = $is_selected || $is_auto;
			}

			if ( ! $should_minimize ) {
				continue;
			}

			$schema = isset( $tool['inputSchema'] ) ? $tool['inputSchema'] : array();
			$props  = isset( $schema['properties'] ) ? $schema['properties'] : array();

			// Collect param names (excluding 'method' which is always present).
			$param_names = array();
			foreach ( $props as $name => $def ) {
				if ( 'method' === $name ) {
					continue;
				}
				$param_names[] = $name;
			}

			// Build one-liner: route [METHODS] — params list.
			$route   = $pattern ? $pattern : $tool['name'];
			$methods = isset( $props['method']['enum'] ) ? $props['method']['enum'] : array();
			$desc    = $route . ' [' . implode( ', ', $methods ) . ']';

			if ( ! empty( $param_names ) ) {
				$desc .= ' — Params: ' . implode( ', ', $param_names );
			}

			$tool['description'] = $desc;

			// Strip verbose descriptions from schema properties.
			foreach ( $props as $name => &$prop ) {
				unset( $prop['description'] );
			}
			unset( $prop );

			$tool['inputSchema']['properties'] = $props;
		}
		unset( $tool );
	}

	/**
	 * Replace all tools with the compact wp_api universal tool.
	 */
	private function apply_compact_mode() {
		$this->tools = array(
			array(
				'name'        => 'refresh_tools',
				'description' => 'Re-discover all WordPress REST API routes and update the tool list. Use this after registering new post types, installing plugins, or any change that adds/removes REST API endpoints.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
			),
			array(
				'name'        => 'wp_api',
				'description' => 'Universal WordPress REST API tool. Make any REST API request to your WordPress site. Examples: {"method":"GET","path":"/wp/v2/posts"} to list posts, {"method":"POST","path":"/wp/v2/posts","params":{"title":"Hello","status":"draft"}} to create a post, {"method":"GET","path":"/wp/v2/users/me"} to get current user info. For media uploads, include file_content (base64) and file_name. Use GET /wp/v2 to discover all available routes.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'method'       => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
							'description' => 'HTTP method to use.',
						),
						'path'         => array(
							'type'        => 'string',
							'description' => 'REST API path, e.g. /wp/v2/posts or /wp/v2/posts/123.',
						),
						'params'       => array(
							'type'        => 'object',
							'description' => 'Query parameters (for GET/DELETE) or body parameters (for POST/PUT/PATCH).',
						),
						'file_content' => array(
							'type'        => 'string',
							'description' => 'Base64-encoded file content for media upload.',
						),
						'file_name'    => array(
							'type'        => 'string',
							'description' => 'Filename with extension for media upload (e.g. image.jpg).',
						),
					),
					'required'   => array( 'method', 'path' ),
				),
			),
		);

		$this->tool_routes = array();
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
	 * /wp/v2/posts/(?P<id>[\d]+) => posts_id
	 * /wc/v3/products => wc_v3_products
	 */
	private function build_tool_name( $pattern ) {
		// Strip /wp/v2/ prefix for core routes (handled first, before general cleanup).
		$name = preg_replace( '#^/wp/v2/#', '/', $pattern );

		// Extract named capture groups: (?P<id>...) → id
		$name = preg_replace( '/\(\?P<([^>]+)>[^)]*\)/', '$1', $name );

		// Remove any remaining regex fragments (parentheses, brackets, etc.)
		$name = preg_replace( '/\([^)]*\)/', '', $name );

		$name = str_replace( '/', '_', $name );

		// Strip any characters not allowed by MCP spec.
		$name = preg_replace( '/[^a-zA-Z0-9_-]/', '', $name );

		// Collapse multiple underscores/hyphens.
		$name = preg_replace( '/_+/', '_', $name );

		$name = trim( $name, '_' );

		if ( strlen( $name ) <= 64 ) {
			return $name;
		}

		// Progressively shorten:
		// 1. Strip "wp-" or "wp_" prefix from non-core namespaces.
		$shortened = preg_replace( '/^wp[-_]/', '', $name );
		if ( strlen( $shortened ) <= 64 ) {
			return $shortened;
		}

		// 2. Keep first two and last two segments for readability.
		$parts = explode( '_', $shortened );
		if ( count( $parts ) > 4 ) {
			$candidate = $parts[0] . '_' . $parts[1] . '_' . $parts[ count( $parts ) - 2 ] . '_' . $parts[ count( $parts ) - 1 ];
			if ( strlen( $candidate ) <= 64 ) {
				return $candidate;
			}
		}

		// 3. Last resort: keep start and end with a hash for uniqueness.
		$hash = substr( md5( $name ), 0, 6 );
		$max  = 64 - strlen( $hash ) - 2; // 2 underscores
		$head = substr( $shortened, 0, intval( $max / 2 ) );
		$tail = substr( $shortened, -intval( $max / 2 ) );

		return rtrim( $head, '_' ) . '_' . $hash . '_' . ltrim( $tail, '_' );
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
