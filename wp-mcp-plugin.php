<?php
/**
 * Plugin Name: WP MCP Server
 * Plugin URI: https://github.com/leandro/wp-mcp-plugin
 * Description: Exposes a Model Context Protocol (MCP) server on your WordPress site, allowing AI agents to connect and interact with your site's REST API.
 * Version: 1.0.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Leandro
 * License: PolyForm Strict License 1.0.0
 * License URI: https://polyformproject.org/licenses/strict/1.0.0
 * Text Domain: wp-mcp-server
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MCP_VERSION', '1.0.6' );
define( 'WP_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_BASENAME', plugin_basename( __FILE__ ) );

add_action( 'init', function () {
	load_plugin_textdomain( 'wp-mcp-server', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

require_once WP_MCP_PATH . 'includes/class-mcp-discovery.php';
require_once WP_MCP_PATH . 'includes/class-mcp-executor.php';
require_once WP_MCP_PATH . 'includes/class-mcp-server.php';
require_once WP_MCP_PATH . 'includes/class-mcp-admin.php';
require_once WP_MCP_PATH . 'includes/class-mcp-oauth.php';

$wp_mcp_oauth = new WP_MCP_OAuth();
$wp_mcp_oauth->init();

add_action( 'rest_api_init', function () use ( $wp_mcp_oauth ) {
	$discovery = new WP_MCP_Discovery();
	$executor  = new WP_MCP_Executor( $discovery );
	$server    = new WP_MCP_Server( $discovery, $executor );
	$server->register_routes();

	$wp_mcp_oauth->register_routes();
} );

$wp_mcp_admin = new WP_MCP_Admin();
$wp_mcp_admin->init();

add_filter( 'plugin_action_links_' . WP_MCP_BASENAME, function ( $links ) {
	$url     = admin_url( 'options-general.php?page=' . WP_MCP_Admin::PAGE_SLUG );
	$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-mcp-server' ) . '</a>';
	return $links;
} );

register_deactivation_hook( __FILE__, function () {
	$files = array(
		ABSPATH . '.well-known/oauth-protected-resource',
		ABSPATH . '.well-known/oauth-authorization-server',
	);
	foreach ( $files as $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
} );
