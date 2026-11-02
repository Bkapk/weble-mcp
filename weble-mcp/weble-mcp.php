<?php
/**
 * Plugin Name: Weble MCP for WordPress
 * Plugin URI: https://github.com/Bkapk/weble-mcp
 * Description: Let your AI agent design straight into Elementor in your site's own style: pages, templates, theme builder and the global kit. Also content, custom fields, media and menus, with linting, signed previews and a read-only site audit. Works with Claude Code, Codex, Cursor, VS Code and any MCP client. Never changes plugins, themes, users or files.
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Weble Studio
 * Author URI: https://weble.studio
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: weble-mcp
 * Update URI: https://github.com/Bkapk/weble-mcp
 *
 * Copyright (C) 2026 Bleart Kepuska
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the GNU General
 * Public License as published by the Free Software Foundation, either version 2 of the License, or (at your
 * option) any later version. It is distributed WITHOUT ANY WARRANTY. See the LICENSE file for details.
 */

defined('ABSPATH') || exit;

const WBMCP_VERSION = '1.0.1';
/** Marks the application passwords this plugin creates. They only open the MCP endpoint. */
const WBMCP_APP_ID = '8f3c2a4e-5b1d-4c7e-9a6f-2d8b7e1c4a90';

$GLOBALS['wbmcp_tools'] = [];

/** Register an MCP tool. $props are JSON-schema properties. */
function wbmcp_tool($name, $description, array $props, callable $cb, array $required = []) {
    $GLOBALS['wbmcp_tools'][$name] = compact('description', 'props', 'cb', 'required');
}

/** Abort the current tool call; the message is returned to the model as an error. */
function wbmcp_fail($msg) {
    throw new RuntimeException($msg); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned to the agent as JSON, never printed as HTML
}

function wbmcp_check($v) {
    if (is_wp_error($v)) wbmcp_fail($v->get_error_message());
    return $v;
}

/* ---------- Custom code: off by default, switched on per site by an admin ---------- */

function wbmcp_code_allowed() {
    return (bool) get_option('wbmcp_custom_code') && current_user_can('unfiltered_html');
}

/** True if a browser would execute $v: script-like tags, event handlers, script URLs. HTML markup and CSS pass. */
function wbmcp_is_code($v, $key = '') {
    $v = (string) $v;
    // Browsers decode numeric references without the trailing ";" (&#106avascript:); PHP does not, so do it first.
    $d = preg_replace_callback('/&#(?:x([0-9a-f]+)|(\d+));?/i', function ($m) {
        $n = $m[1] !== '' ? hexdec($m[1]) : (int) $m[2];
        // html_entity_decode, not mb_chr: mbstring is optional and WordPress has no mb_chr polyfill.
        return $n > 0 && $n < 0x110000 ? html_entity_decode('&#' . $n . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }, $v);
    $d = html_entity_decode($d, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Dynamic tags and some settings arrive URL-encoded.
    if (strpos($d, '%') !== false && ($u = rawurldecode($d)) !== $d && wbmcp_is_code($u, $key)) return true;
    if (wbmcp_html_has_code($v)) return true;
    $flat = preg_replace('/[\x00-\x20]+/', '', $d); // browsers ignore whitespace inside URL schemes
    $scheme = '(javascript|vbscript|livescript):|data:text/html';
    return preg_match('~<\s*/?\s*(script|iframe|frame|frameset|object|embed|applet|form|meta|base|link)\b|<[a-z](?:[^>"\']|"[^"]*"|\'[^\']*\')*?(?<=[\s/"\'])on[a-z]+\s*=~i', $d)
        || preg_match("~[\"'=(]($scheme)~i", $flat)
        || (preg_match('/(^|_)(url|href|link)$/', $key) && preg_match("~^($scheme)~i", $flat))
        || (strpos($key, 'attributes') !== false && preg_match('/(^|\n)\s*on[a-z]+\s*\|/i', $d)); // Elementor "key|value" attribute lines
}

/** Parse HTML the way a browser does (WP 6.2+): blocked tags, any on* attribute, script URLs in any attribute. Attribute values come back decoded. */
function wbmcp_html_has_code($html) {
    if (strpos($html, '<') === false || !class_exists('WP_HTML_Tag_Processor')) return false;
    $p = new WP_HTML_Tag_Processor($html);
    while ($p->next_tag(['tag_closers' => 'visit'])) {
        if (in_array(strtolower($p->get_tag()), ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'meta', 'base', 'link'], true)) return true;
        if ($p->get_attribute_names_with_prefix('on')) return true;
        foreach ((array) $p->get_attribute_names_with_prefix('') as $name) {
            $val = $p->get_attribute($name);
            if (is_string($val) && preg_match('~^((java|vb|live)script:|data:text/html)~i', preg_replace('/[\x00-\x20]+/', '', $val))) return true;
        }
    }
    return false;
}

/** With custom code off, reject executable code anywhere in a tool call's arguments. */
function wbmcp_code_guard($v, $key = '') {
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            wbmcp_code_guard((string) $k, 'key');
            wbmcp_code_guard($x, (string) $k);
        }
    } elseif (is_string($v) && wbmcp_is_code($v, $key)) {
        wbmcp_fail("'$key' contains executable code (script, event handler, iframe/embed, form or javascript: URL). Custom code is off on this site: an admin can enable it under Weble MCP > Settings. HTML markup and CSS are allowed.");
    }
}

function wbmcp_clean_rules($s) {
    return mb_substr(sanitize_textarea_field((string) $s), 0, 5000);
}

require __DIR__ . '/includes/content.php';
require __DIR__ . '/includes/elementor.php';
require __DIR__ . '/includes/lint.php';
require __DIR__ . '/includes/brief.php';
require __DIR__ . '/includes/preview.php';
require __DIR__ . '/includes/audit.php';
require __DIR__ . '/includes/admin.php';
if (file_exists(__DIR__ . '/includes/updater.php')) require __DIR__ . '/includes/updater.php'; // not in the wordpress.org build

/* ---------- MCP over Streamable HTTP (JSON responses only) ---------- */

add_action('rest_api_init', function () {
    register_rest_route('weble-mcp/v1', '/mcp', [
        [
            'methods' => 'POST',
            'callback' => 'wbmcp_rpc',
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ],
        // No server-initiated SSE stream: MCP clients expect 405 here.
        [
            'methods' => 'GET, DELETE',
            'callback' => function () { return new WP_REST_Response(null, 405); },
            'permission_callback' => '__return_true',
        ],
    ]);
});

function wbmcp_rpc(WP_REST_Request $req) {
    $m = $req->get_json_params();
    if (!is_array($m)) return wbmcp_rpc_reply(null, null, [-32700, 'Parse error']);
    // Notifications and client responses carry no id and need no reply.
    if (!isset($m['method']) || !array_key_exists('id', $m)) return new WP_REST_Response(null, 202);

    $p = $m['params'] ?? [];
    switch ($m['method']) {
        case 'initialize':
            return wbmcp_rpc_reply($m['id'], [
                // ponytail: echo the client's version; only the stable tools API is used.
                'protocolVersion' => $p['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'weble-mcp', 'version' => WBMCP_VERSION],
                'instructions' => wbmcp_instructions(),
            ]);
        case 'ping':
            return wbmcp_rpc_reply($m['id'], new stdClass());
        case 'tools/list':
            return wbmcp_rpc_reply($m['id'], ['tools' => wbmcp_tool_list()]);
        case 'tools/call':
            return wbmcp_rpc_reply($m['id'], wbmcp_tool_call($p['name'] ?? '', $p['arguments'] ?? []));
    }
    return wbmcp_rpc_reply($m['id'], null, [-32601, 'Method not found: ' . $m['method']]);
}

function wbmcp_rpc_reply($id, $result, $error = null) {
    $body = ['jsonrpc' => '2.0', 'id' => $id];
    if ($error) $body['error'] = ['code' => $error[0], 'message' => $error[1]];
    else $body['result'] = $result;
    return new WP_REST_Response($body, 200);
}

/** Tools that can change the site. elementor_lint counts: fix=true rewrites the page. */
function wbmcp_tool_writes($name) {
    return !preg_match('/^(get_|list_|elementor_(get|list|widget|dynamic))/', $name);
}

function wbmcp_tool_list() {
    $out = [];
    foreach ($GLOBALS['wbmcp_tools'] as $name => $t) {
        if (strpos($name, 'elementor_') === 0 && !did_action('elementor/loaded')) continue;
        $schema = ['type' => 'object', 'properties' => (object) $t['props']];
        if ($t['required']) $schema['required'] = $t['required'];
        // Lets clients approve reads silently and ask the user before writes.
        $hints = wbmcp_tool_writes($name) ? ['readOnlyHint' => false, 'destructiveHint' => (bool) preg_match('/^(delete_|elementor_set_content|update_design_system|set_custom_css)/', $name)] : ['readOnlyHint' => true];
        $out[] = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $schema, 'annotations' => $hints];
    }
    return $out;
}

function wbmcp_tool_call($name, $args) {
    try {
        $t = $GLOBALS['wbmcp_tools'][$name] ?? null;
        if (!$t) wbmcp_fail("Unknown tool: $name");
        $args = is_array($args) ? $args : [];
        if (!wbmcp_code_allowed()) wbmcp_code_guard($args);
        $out = ($t['cb'])($args);
        $text = is_string($out) ? $out : wp_json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return ['content' => [['type' => 'text', 'text' => $text]]];
    } catch (Throwable $e) {
        return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
    }
}

// Kept under ~2KB: Claude Code truncates longer server instructions. The full playbook is in get_site_brief.
function wbmcp_instructions() {
    return <<<'TXT'
You are connected to a WordPress site through Weble MCP.

1. Call get_site_brief before anything else in a session. It holds the setup, content model, design system, the house style measured from existing pages, the owner's house rules and the build playbook. Treat it as the spec.
2. Ask before you change anything; reading is free. Tell the user in plain words what will change and where, and wait for a yes. One yes covers the plan you showed, nothing beyond it. Work without asking only when the user explicitly said so, and only for that task. Publishing, published pages, live templates, the kit and site-wide CSS go live at once: name that when you ask.
3. Style through the kit: colors and fonts via settings.__globals__, never hardcoded values the kit defines. Match the house style.
4. Design for phones: rows, large text and wide padding need _tablet/_mobile values.
5. CSS only through set_custom_css, targeting the _css_classes you set. Never per-element custom_css.
6. After every build: elementor_lint, then get_preview_url and look at it at 1440, 768 and 390 px. Fix and repeat until it is clean.
7. Edit with elementor_edit ops. Every Elementor write is snapshotted; elementor_undo reverts. New posts are drafts.
Out of scope by design: changing plugins, themes, users or PHP files. Admins can read them (and settings, WooCommerce, code, cron) with get_site_audit; nothing is ever changed there.
TXT;
}

/* ---------- MCP passwords only open the MCP endpoint ---------- */

/** Passwords this plugin created (v2 marks them with WBMCP_APP_ID; v1 named them "Claude MCP ..."). */
function wbmcp_is_mcp_password($item) {
    return ($item['app_id'] ?? '') === WBMCP_APP_ID || strpos((string) ($item['name'] ?? ''), 'Claude MCP') === 0;
}

/** Revoke every user's MCP connection. Returns how many were revoked. */
function wbmcp_revoke_all() {
    $n = 0;
    foreach (get_users(['fields' => 'ID', 'meta_key' => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS]) as $uid) {
        foreach ((array) WP_Application_Passwords::get_user_application_passwords($uid) as $item) {
            if (wbmcp_is_mcp_password($item) && WP_Application_Passwords::delete_application_password($uid, $item['uuid']) === true) $n++;
        }
    }
    return $n;
}

// Deactivated, the plugin can no longer keep its passwords on the MCP endpoint, so they must not outlive it.
register_deactivation_hook(__FILE__, 'wbmcp_revoke_all');

// A leaked connection password must not reach the rest of the REST API or XML-RPC.
// Only REST counts: XML-RPC, and anything a plugin marks as an API request (GraphQL...), never reaches the route check below.
add_action('wp_authenticate_application_password_errors', function ($error, $user, $item) {
    if (wbmcp_is_mcp_password($item) && !(defined('REST_REQUEST') && REST_REQUEST)) {
        $error->add('wbmcp_scope', 'This application password only works for Weble MCP.');
    }
}, 10, 3);

// Checks the route WordPress actually dispatches, which may differ from the URL path (?rest_route= wins).
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    $uuid = rest_get_authenticated_app_password();
    if (!$uuid || untrailingslashit($request->get_route()) === '/weble-mcp/v1/mcp') return $result;
    $item = WP_Application_Passwords::get_user_application_password(get_current_user_id(), $uuid);
    if ($item && wbmcp_is_mcp_password($item)) {
        return new WP_Error('wbmcp_scope', 'This application password only works for Weble MCP.', ['status' => 403]);
    }
    return $result;
}, 999, 3);
