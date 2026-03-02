# WP MCP Server

A WordPress plugin that exposes a [Model Context Protocol (MCP)](https://modelcontextprotocol.io/) server on your site, allowing AI agents to connect and interact with your content through the REST API.

## Features

- **Streamable HTTP transport** — agents connect directly via your site URL
- **Automatic route discovery** — all REST API routes become MCP tools (posts, pages, media, users, WooCommerce, ACF, custom post types, etc.)
- **OAuth 2.1 authentication** — MCP clients authenticate automatically via browser login and consent (PKCE, no tokens to manage)
- **Basic Auth support** — also supports Application Passwords for clients that don't support OAuth
- **Settings page** — generate auth tokens and copy-ready config snippets in one click
- **ACF support** — detects Advanced Custom Fields and adds ACF parameters to writable routes
- **Media uploads** — supports base64 file uploads via MCP
- **Zero configuration** — activate and connect

## Installation

1. Upload the `wp-mcp-plugin` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu
3. Go to **Settings > WP MCP Server**
4. Copy the config snippet for your MCP client

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

## How It Works

1. The plugin registers a REST API endpoint at `/wp-json/mcp/v1`
2. MCP clients discover OAuth metadata via `/.well-known/oauth-protected-resource` and authenticate through your WordPress login
3. When connected, the client discovers all registered WordPress REST routes
4. Each route becomes an MCP tool with its parameters derived from the endpoint schema
5. Tool calls are executed as internal REST API requests (no HTTP overhead)
6. The authenticated user's permissions apply to all operations

## Tool Naming

Routes are converted to dot-separated tool names:

| Route | Tool Name |
|---|---|
| `/wp/v2/posts` | `posts` |
| `/wp/v2/posts/(?P<id>[\d]+)` | `posts.id` |
| `/wp/v2/categories` | `categories` |
| `/wp/v2/media` | `media` |
| `/wc/v3/products` | `wc.v3.products` |
| `/acf/v1/posts/(?P<id>[\d]+)` | `acf.v1.posts.id` |

Every tool accepts a `method` parameter to specify the HTTP verb (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- HTTPS recommended (required for Application Passwords on most setups)

## License

[PolyForm Strict License 1.0.0](LICENSE.md)
