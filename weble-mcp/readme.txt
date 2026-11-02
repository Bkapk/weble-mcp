=== Weble MCP ===
Contributors: bkapk
Tags: mcp, ai, elementor, claude, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let your AI agent design straight into Elementor, in your site's own style. Works with Claude Code, Codex, Cursor and any MCP client.

== Description ==

Describe a page, or hand over a design, and your AI agent builds it as real Elementor containers and widgets that you can keep editing by hand. Before it builds, it measures your existing pages (spacing, type scale, colors, classes) and follows them, with colors and fonts from your Elementor kit.

Weble MCP serves the Model Context Protocol straight from the WordPress REST API. There is no Node bridge and nothing to host.

= Elementor =

* Pages, sections and saved templates, built through your kit's global colors and fonts
* Theme builder: headers, footers, single and archive templates, loop grids and display conditions (Elementor Pro)
* Dynamic content from ACF and other sources through dynamic tags (Elementor Pro)
* Custom CSS where it belongs: the page's Advanced > Custom CSS with Elementor Pro, or a block of Additional CSS limited to that page without it
* Phone and tablet values on every layout, checked by a built-in linter, then screenshots at three widths
* Every Elementor write can be undone

= Also =

* A site brief first: setup, content model, theme builder map, design system and the house style measured from your existing pages
* Posts of any type, with custom fields (ACF), terms, featured images and media
* Menus and site settings
* lint its own work (rows that never stack on phones, off-palette colors, low contrast, heading order, missing alt text) and screenshot it through signed preview links
* run a read-only site audit when you plan a migration

= Safety =

* Connection passwords only work on the MCP endpoint, not the rest of the REST API or XML-RPC.
* There are no tools that change plugins, themes, users or files.
* Scripts, iframes and forms are rejected unless an admin switches custom code on.
* New posts are drafts, deletes go to the trash, and every Elementor write can be undone.
* Every call is checked against the connected user's WordPress capabilities.
* The agent is told to describe each change and wait for your yes, unless you tell it to work on its own.

== Installation ==

1. Install and activate the plugin.
2. Open Weble MCP in the admin menu and click "Generate connection".
3. Pick your agent (Claude Code, Codex, Cursor, VS Code, Gemini CLI, Claude Desktop) and copy the command or config, or copy the plain-language prompt and paste it into any agent's chat.

Application Passwords need HTTPS, or `WP_ENVIRONMENT_TYPE` set to `local`.

== Frequently Asked Questions ==

= Does it need Elementor? =

No, but it is where the plugin shines. Content, media, menus and settings tools work on any site; the Elementor tools appear when Elementor is active. Theme builder, display conditions, dynamic tags and the per-page Custom CSS field need Elementor Pro.

= Which WordPress and PHP versions does it support? =

WordPress 6.2 or newer, up to the current 7.1, and PHP 7.4 or newer, including PHP 8. It needs nothing beyond WordPress itself: no Node, no Composer packages and no extra PHP extensions. Single sites and multisite subsites both work.

= Does it work with WooCommerce? =

Yes, for products and the shop's look. The agent can create and edit products, sort them into categories and build shop and product templates in Elementor. Change prices and stock in the WooCommerce product editor. Orders, coupons and subscriptions are never exposed.

= Where do I get help? =

Report bugs in GitHub Issues and ask questions in GitHub Discussions, both at https://github.com/Bkapk/weble-mcp.

= I get 401 errors =

Some Apache/CGI hosts strip the Authorization header. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess`.

= Can the agent install plugins or edit theme files? =

No. That is out of scope by design.

= How do I get updates? =

Installed from wordpress.org, updates come through wordpress.org as usual. Installed from the GitHub release zip, the plugin checks GitHub Releases twice a day and shows new versions on the Plugins screen like any other update. You can switch on auto-updates for it there.

= Where is the source of the admin screen? =

The admin screen is a small React app. assets/app/app.js is built from app-src/ in https://github.com/Bkapk/weble-mcp, where the full, readable source is.

= What happens when I deactivate or delete the plugin? =

Deactivating it revokes every connection password it created, so none of them can be used while the plugin is off. Deleting it keeps your house rules and settings unless you switch on "Delete plugin data when the plugin is deleted" under Weble MCP > Settings. Your pages, posts and Elementor kit are never touched.

== Third-party software ==

The admin screen bundles React (MIT) and the Onest and Funnel Display fonts (SIL Open Font License 1.1). Full license texts are in THIRD-PARTY-LICENSES.txt.

== Changelog ==

= 1.0.3 =
* Renamed to Weble MCP.

= 1.0.2 =
* Check for updates from the Settings screen.
* The plugin icon and banner show on the Updates screen and in the update details.

= 1.0.1 =
* Meets the wordpress.org Plugin Check: safer database reads, sanitized server input, core functions for file cleanup and widget lists.
* The agent instructions no longer link to a CDN.

= 1.0.0 =
* First public release.
