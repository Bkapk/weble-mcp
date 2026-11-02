# Weble MCP for WordPress

[![CI](https://github.com/Bkapk/weble-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/Bkapk/weble-mcp/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Let your AI agent design straight into Elementor, in your site's own style.

Describe a page, or hand over a design, and the agent builds it as real Elementor containers and widgets you can keep editing by hand. It measures your existing pages first (spacing, type scale, colors, classes) and builds with your kit's global colors and fonts. It also handles theme-builder templates, dynamic tags, content, custom fields, media and menus.

The plugin is itself an MCP server, so it works with Claude Code, Codex, Cursor, VS Code, Gemini CLI and any other MCP client. There is no Node bridge and nothing to host. It's plain PHP with no dependencies, and runs on WordPress 6.2 to 7.1 and PHP 7.4 or newer, including PHP 8.

## Install on a site

1. Download `weble-mcp.zip` from the [latest release](https://github.com/Bkapk/weble-mcp/releases/latest). Upload it under Plugins → Add New → Upload, then activate it.
2. Open **Weble MCP** in the admin menu and click **Generate connection**.
3. Pick your agent. Each tab shows the command or config file for it:
   - **Ask your agent**: a plain-language prompt to paste into any agent's chat. The agent adds the server itself.
   - **Claude Code**: `claude mcp add --transport http weble-example-com https://example.com/wp-json/weble-mcp/v1/mcp --header "Authorization: Basic …"`
   - **Codex**, **Cursor** (and Windsurf), **VS Code**, **Gemini CLI**: the config block for that tool
   - **Claude Desktop** and other clients that only start local servers: a config that goes through `mcp-remote`
   - **Other**: the URL and header, for any client with Streamable HTTP

**Updates.** The plugin checks this repo's GitHub Releases twice a day and offers new versions on the Plugins screen, like any other update. Switch on "Enable auto-updates" there to get them without clicking.

Authentication uses WordPress Application Passwords, a core feature. Each connection has its own password. The admin page lists them with their last use, and you can revoke them under Users → Profile.

## How a session works

1. **Site brief.** The agent calls `get_site_brief` first. It returns one compact document:
   - the setup: theme, plugins, Elementor/Pro version, breakpoints, SEO meta keys, forms, languages
   - the content model: post types, taxonomies, ACF field groups
   - the theme builder map
   - the design system, summarized
   - the **house style** measured from existing pages: section padding scale, headings by tag, colors on and off the kit, fonts, radii, animations, and how well the pages adapt to phones
   - the pages and menus
   - your **house rules**
   - the full build playbook
2. **Plan and ask.** The agent says what it will change and where, and waits for your yes. It works on its own only when you tell it to.
3. **Build** through the kit's global colors and fonts, matching the house style. CSS goes in the page's Custom CSS with Elementor Pro, or in a block of Additional CSS limited to that page without it, always aimed at classes the agent sets.
4. **Verify.** `elementor_lint` catches the following:
   - rows that never stack on phones
   - large text or padding with no mobile value
   - hardcoded colors and fonts, and off-palette colors
   - low contrast
   - heading order
   - missing alt text
   - dead buttons

   `fix=true` switches hardcoded colors that match the kit to their globals. Then `get_preview_url` gives a signed link that the agent screenshots at 1440, 768 and 390 px wide.

**House rules** are standing rules for one site, such as "buttons are pill-shaped". Edit them under Weble MCP → House rules, or ask Claude to remember a rule (`update_house_rules`).

**Screenshots** need a browser tool in your agent, for example Claude in Chrome or the Playwright MCP (`claude mcp add playwright -- npx @playwright/mcp@latest`). Without one, the agent tries `npx -y playwright screenshot`, and otherwise tells you how to add one and sends you the preview link.

## Tools

| Area | Tools |
|---|---|
| Start | `get_site_brief`, `update_house_rules` |
| Site | `update_site_settings` (only an allowlist: title, tagline, front page, posts page, formats, timezone, site icon) |
| Content | `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post` (sends to trash). These work on any post type and handle meta, ACF, terms, featured image and an Elementor layout. |
| Taxonomies | `list_terms`, `create_term` |
| Media | `list_media`, `upload_media` (imports from a URL) |
| Menus | `list_menus`, `add_menu_item`, `delete_menu_item` |
| Design system | `get_design_system`, `update_design_system` (Elementor kit colors, typography and layout), `set_custom_css` (page or site CSS: Elementor Pro Custom CSS, or a scoped Additional CSS block) |
| Elementor | `elementor_get_content`, `elementor_set_content`, `elementor_edit`, `elementor_undo`, `elementor_widget_schema` |
| Theme builder and dynamic | `elementor_list_templates`, `elementor_create_template`, `elementor_set_conditions`, `elementor_dynamic_tags` |
| Verify | `elementor_lint`, `get_preview_url` |
| Audit | `get_site_audit` (administrators only, read-only): `section` = core, plugins, woocommerce, options_index, options (with `prefix`), code, marketing or ops. For planning a migration. |

**WooCommerce.** Products are an ordinary post type, so the agent can create and edit them, sort them into categories and build shop and product templates in Elementor. Prices and stock should be changed in the Woo product editor, because raw meta writes skip Woo's lookup tables; the brief tells the agent so. Orders, coupons and subscriptions are blocked.

**Dynamic tags.** Bind a setting with `"__dynamic__": {"title": {"tag": "acf-text", "settings": {"key": "…"}}}` and the server encodes it into Elementor's format. `elementor_dynamic_tags name=acf-text` lists the exact ACF keys.

## Safety model

- **Connection passwords are scoped.** A password made on the admin page works only on the MCP endpoint. It is rejected everywhere else in the REST API and on XML-RPC, so a leaked one can't reach users, plugins or settings.
- **No tools that change plugins, themes, users, files or PHP.** Site options are limited to an allowlist. Administrators can *read* plugins, themes, user counts, settings, WooCommerce config, custom code and cron with `get_site_audit`. It never writes (option saves and outgoing HTTP are blocked while it runs) and redacts any value under a key that looks like a secret (`pass`, `secret`, `token`, `key`, `auth`, `api`, `license`…), including inside serialized and JSON values. Public IDs (GA measurement, pixel, GTM, container, account IDs) stay visible. Values are cut at 4,000 characters and responses at ~200 KB.
- **Custom code is off by default.** While it is off:
  - every tool rejects scripts, event handlers, `javascript:` URLs, iframes, embeds and forms
  - `upload_media` refuses HTML, JS and SVG files

  HTML markup and CSS are always fine. An admin can switch custom code on under Weble MCP → Settings, for example for custom HTML sections or GSAP. Switch it off again when the work is done.
- **Blocked post types:** WooCommerce orders and subscriptions, form submissions and form definitions, code-snippet post types (WPCode, Woody, Custom CSS & JS, Elementor Custom Code) and ACF schema post types. You can change this list with the `wbmcp_post_types` filter.
- **Blocked meta keys:** the generic `meta` field can't write Elementor data, attachment file paths, or other page builders' data (Bricks, Oxygen, Breakdance, Beaver Builder).
- **Permissions:** every call is checked against the WordPress capabilities of the connected user:
  - Only administrators can change the kit, through any tool.
  - The brief needs an Editor or above.
  - Protected meta and raw Elementor JSON need edit rights on that item.
- **Deletes and undo:**
  - Deleting a post only moves it to the trash.
  - Every Elementor or kit write keeps a snapshot, and `elementor_undo` reverts it. The last 5 snapshots are kept per document.
- **Drafts first:** new posts are created as drafts, and templates that have display conditions start as drafts too.
- **Preview links** are signed with the site's salts and bound to one post. They expire within 60 minutes, and the pages they open are served uncached with `noindex` and `no-referrer` headers.

## Troubleshooting

- **401 errors:** Apache/CGI hosts sometimes strip the `Authorization` header. Add this line to `.htaccess`:
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
- **403 "only works for Weble MCP":** you used a connection password somewhere other than the MCP endpoint. Create a separate Application Password for other integrations.
- **The Weble MCP page says Application Passwords are unavailable:** the site needs HTTPS, or `define('WP_ENVIRONMENT_TYPE', 'local');` for local development. Also check whether a security plugin has turned the feature off.
- **Plain permalinks:** the endpoint is `https://example.com/?rest_route=/weble-mcp/v1/mcp`. The admin page always shows the correct URL.
- **"Elementor can't edit post":** enable that post type under Elementor → Settings → Post Types.

## Help and feedback

- **Bugs and sites that don't work:** [open an issue](https://github.com/Bkapk/weble-mcp/issues/new/choose).
- **Questions, ideas, and what you built:** [Discussions](https://github.com/Bkapk/weble-mcp/discussions).
- **Security issues:** by email, see [SECURITY.md](SECURITY.md).

## Development

- `php -d zend.assertions=1 tests/tree-test.php` checks the logic without WordPress:
  - the Elementor tree operations
  - the settings merge and dynamic-tag encoding
  - the custom-code guard
  - the color and contrast math
  - the lint rules
  - the preview tokens
  - the audit redaction and size limits
- `php build-zip.php` rebuilds the installable zip. `php build-zip.php --wporg` builds the wordpress.org version, which leaves out the GitHub updater.

## Releasing

1. Set the same version in three places: `Version:` and `WBMCP_VERSION` in `weble-mcp/weble-mcp.php`, and `Stable tag:` in `weble-mcp/readme.txt`. Add a CHANGELOG entry.
2. Commit, then tag and push: `git tag v1.0.1 && git push origin main v1.0.1`.
3. The Release workflow checks the versions match, runs the tests, builds both zips and publishes the GitHub Release. Installed sites see the update within 12 hours.

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately: [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later, the license WordPress itself uses. You can use it on any site, including client and commercial sites, change it and share it. If you distribute a changed version, it has to stay GPL and include its source.

The admin screen bundles React (MIT) and the Onest and Funnel Display fonts (OFL 1.1). Their notices are in [weble-mcp/THIRD-PARTY-LICENSES.txt](weble-mcp/THIRD-PARTY-LICENSES.txt).

Copyright (C) 2026 Bleart Kepuska. Made by [Weble Studio](https://weble.studio). "Weble" and the Weble MCP icon are not covered by the code license, so forks should use their own name.

## Admin app

The admin screen is a React app in `app-src/`, built with Vite into `weble-mcp/assets/app/` (the build is committed, so the plugin runs without Node):

```
cd app-src && npm install && npm run build
```
