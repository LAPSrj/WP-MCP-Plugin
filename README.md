# WP MCP Server

A WordPress plugin that exposes a [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server on your site, allowing AI agents to connect and interact with your content through the REST API.

## Features

- **Streamable HTTP transport** — agents connect directly via your site URL
- **Automatic route discovery** — all REST API routes become MCP tools (posts, pages, media, users, WooCommerce, ACF, custom post types, etc.)
- **OAuth 2.1 authentication** — MCP clients authenticate automatically via browser login and consent (PKCE, no tokens to manage)
- **Basic Auth support** — also supports Application Passwords for clients that don't support OAuth
- **Settings page** — generate auth tokens and copy-ready config snippets in one click
- **Endpoint filtering** — allowlist/blocklist endpoints by category (Post Types, Taxonomies, Core, Plugin) or use Compact Mode
- **Description control** — per-category verbose/minimal tool descriptions to optimize token usage
- **ACF support** — detects Advanced Custom Fields and adds ACF parameters to writable routes
- **Media uploads** — supports base64 file uploads via MCP
- **Zero configuration** — activate and connect

## Installation

1. [Download the latest release](https://github.com/leandro/wp-mcp-plugin/releases/latest/download/wp-mcp-plugin.zip)
2. Upload the zip via **Plugins > Add New > Upload Plugin** (or extract to `/wp-content/plugins/`)
3. Activate the plugin through the **Plugins** menu
4. Go to **Settings > WP MCP Server**
5. Copy the config snippet for your MCP client

## Client Configuration

The MCP endpoint is at:

```
https://your-site.com/wp-json/mcp/v1
```

### OAuth 2.1 (Recommended)

MCP clients that support OAuth will authenticate automatically — they open your browser, you log in to WordPress and approve the connection. No tokens to copy or manage.

#### Claude Desktop

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://your-site.com/wp-json/mcp/v1"
    }
  }
}
```

#### Claude Code / Cursor

Add to `.mcp.json`:

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "streamable-http",
      "url": "https://your-site.com/wp-json/mcp/v1"
    }
  }
}
```

### Basic Auth (Legacy)

For clients that don't support OAuth, you can use Application Passwords directly.

1. Go to **Settings > WP MCP Server**
2. Select a user and click **Generate Connection**
3. Copy the config snippet with the embedded auth token

#### Claude Desktop

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://your-site.com/wp-json/mcp/v1",
      "headers": {
        "Authorization": "Basic YOUR_TOKEN"
      }
    }
  }
}
```

#### Claude Code / Cursor

```json
{
  "mcpServers": {
    "wordpress": {
      "type": "streamable-http",
      "url": "https://your-site.com/wp-json/mcp/v1",
      "headers": {
        "Authorization": "Basic YOUR_TOKEN"
      }
    }
  }
}
```

> Use the **Settings > WP MCP Server** page to generate `YOUR_TOKEN` automatically. The token is shown only once — generate a new one if you lose it.

## Settings

All settings are under **Settings > WP MCP Server > Settings** tab.

### Endpoint Filtering

Control which REST API endpoints are exposed as MCP tools. Four modes are available:

| Mode | Behavior |
|---|---|
| **All Endpoints** | Every discovered REST API route is exposed. (Default) |
| **Allowlist** | Only the selected endpoints are exposed. |
| **Blocklist** | All endpoints are exposed except the selected ones. |
| **Compact Mode** | Replaces all tools with a single universal `wp_api` tool for minimal token usage. |

In Allowlist and Blocklist modes, endpoints are grouped by category:

- **Post Types** — routes for registered post types (posts, pages, custom post types)
- **Taxonomies** — routes for registered taxonomies (categories, tags, custom taxonomies)
- **Core** — other `/wp/v2/*` routes (users, comments, settings, search, etc.)
- **Plugin** — non-core namespaces (`/wc/v3/*`, `/acf/v3/*`, etc.)

Each category has:
- A **group checkbox** to select/deselect all endpoints in the category
- An **"Include new items"** toggle — automatically includes newly discovered endpoints in that category (e.g. after installing a plugin or registering a custom post type)

### Tool Descriptions

Control which tools get verbose descriptions vs minimal one-liners. Minimizing descriptions reduces token usage on every `tools/list` call.

| Mode | Behavior |
|---|---|
| **All Verbose** | Every tool gets full verbose descriptions. |
| **Allowlist** | Only selected tools/categories keep verbose descriptions; the rest get minimal. |
| **Blocklist** | All tools are verbose except selected ones, which get minimized. |
| **All Minimal** | Every tool gets minimal one-liner descriptions. (Default) |

The Allowlist and Blocklist modes use the same category-based grouping as endpoint filtering, with per-category "Include new items" toggles.

When switching to Allowlist mode for the first time, Post Types and Taxonomies are pre-selected as verbose, while Core and Plugin default to minimal.

## How It Works

1. The plugin registers a REST API endpoint at `/wp-json/mcp/v1`
2. MCP clients discover OAuth metadata via `/.well-known/oauth-protected-resource` and authenticate through your WordPress login
3. When connected, the client discovers all registered WordPress REST routes
4. Each route becomes an MCP tool with its parameters derived from the endpoint schema
5. Tool calls are executed as internal REST API requests (no HTTP overhead)
6. The authenticated user's permissions apply to all operations

## Tool Naming

Routes are converted to underscore-separated tool names:

| Route | Tool Name |
|---|---|
| `/wp/v2/posts` | `posts` |
| `/wp/v2/posts/(?P<id>[\d]+)` | `posts_id` |
| `/wp/v2/categories` | `categories` |
| `/wp/v2/media` | `media` |
| `/wc/v3/products` | `wc_v3_products` |
| `/acf/v1/posts/(?P<id>[\d]+)` | `acf_v1_posts_id` |

Every tool accepts a `method` parameter to specify the HTTP verb (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- HTTPS recommended (required for Application Passwords on most setups)

## License

[PolyForm Strict License 1.0.0](LICENSE.md)
