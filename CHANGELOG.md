# Changelog

## 1.0.0

First public release.

- MCP server over Streamable HTTP, served from the WordPress REST API at `/wp-json/weble-mcp/v1/mcp`
- Site brief with the house style measured from existing pages, plus house rules
- Content, custom fields (ACF), taxonomies, media, menus and allowlisted site settings
- Full Elementor access: pages, kit, templates, theme-builder conditions, dynamic tags, edit ops and undo
- `elementor_lint` and signed preview links for visual checks
- Read-only `get_site_audit` for migrations, with secret redaction
- Connection passwords that only work on the MCP endpoint
- Custom code off by default
- Deactivating revokes every connection password; deleting removes settings, house rules and snapshots when "Delete plugin data" is on (off by default, per site on multisite)
- Serialized meta is read without reviving objects
- New admin app in the Weble Studio style: overview, connections with per-connection revoke, house rules editor, tool catalog and settings
- Connection passwords now open exactly `/weble-mcp/v1/mcp`, nothing else under the namespace
- Updates from GitHub Releases through WordPress's own Update URI hook, with the auto-update toggle
- THIRD-PARTY-LICENSES.txt for the bundled React and fonts
- Works with any MCP client: the connect box has a plain-language prompt for any agent, plus ready configs for Claude Code, Codex, Cursor, VS Code, Gemini CLI and Claude Desktop
- `set_custom_css`: page or site CSS goes in Elementor Pro's Custom CSS fields, or without Pro in a page-scoped block of Additional CSS
- The agent is told to describe each change and wait for a yes unless told to work on its own; tools carry read-only and destructive hints so clients can ask before writes
- Elementor is front and centre on the Overview screen
- Security hardening before release:
  - post types the connected user can't edit are no longer exposed (WooCommerce coupons were readable by Contributors)
  - meta keys are checked case-insensitively and ASCII-only, ACF writes only accept real fields, and undo only restores Elementor data
  - the custom-code guard parses HTML like a browser and catches entity, quoting and URL-encoding tricks
  - connection passwords are refused outside REST requests (GraphQL and similar)
  - display conditions need an administrator; password-protected posts stay hidden from people who can't edit them
  - the site audit also scrubs secrets found inside values; updates only install zips from this repo's releases; workflow actions are pinned to commits
