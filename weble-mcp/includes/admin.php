<?php
// Admin screen: one menu page that mounts the React app (assets/app/app.js, built by Vite from /app-src),
// and the REST routes it talks to. Those routes take a logged-in admin's cookie only, never an application password.

defined('ABSPATH') || exit;

const WBMCP_MENU = 'weble-mcp';

function wbmcp_admin_url($route = '') {
    return admin_url('admin.php?page=' . WBMCP_MENU) . '#/' . ltrim($route, '/');
}

/* ---------- menu and app shell ---------- */

add_action('admin_menu', function () {
    global $submenu;
    $icon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="5 6 22 22"><path fill="black" fill-rule="evenodd" d="M10.5 16.5a4.2 4.2 0 1 1 0 8.4 4.2 4.2 0 0 1 0-8.4Zm0 1.8a2.4 2.4 0 1 0 0 4.8 2.4 2.4 0 0 0 0-4.8Z"/><path fill="black" d="M12.61 18.22l5.6-4.8 1.17 1.37-5.6 4.8z"/><circle fill="black" cx="21.5" cy="11.5" r="3.6"/></svg>');
    $GLOBALS['wbmcp_hook'] = add_menu_page('Weble MCP', 'Weble MCP', 'manage_options', WBMCP_MENU, 'wbmcp_admin_page', $icon, 81);
    $routes = ['' => __('Overview', 'weble-mcp'), 'connections' => __('Connections', 'weble-mcp'), 'rules' => __('House rules', 'weble-mcp'), 'tools' => __('Tools', 'weble-mcp'), 'settings' => __('Settings', 'weble-mcp')];
    foreach ($routes as $route => $label) {
        $submenu[WBMCP_MENU][] = [$label, 'manage_options', wbmcp_admin_url($route)]; // hash routes into one page
    }
});

add_filter('plugin_action_links', function ($links, $file) {
    if ($file === plugin_basename(dirname(__DIR__) . '/weble-mcp.php')) {
        array_unshift($links, '<a href="' . esc_url(wbmcp_admin_url()) . '">' . esc_html__('Connect', 'weble-mcp') . '</a>');
    }
    return $links;
}, 10, 2);

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== ($GLOBALS['wbmcp_hook'] ?? '')) return;
    $file = dirname(__DIR__) . '/assets/app/app.js';
    $ver = file_exists($file) ? WBMCP_VERSION . '.' . filemtime($file) : WBMCP_VERSION;
    wp_enqueue_script('wbmcp-app', plugins_url('assets/app/app.js', dirname(__DIR__) . '/weble-mcp.php'), [], $ver, true);
    wp_add_inline_script('wbmcp-app', 'window.wbmcpApp=' . wp_json_encode([
        'restUrl' => esc_url_raw(rest_url('weble-mcp/v1/admin/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'version' => WBMCP_VERSION,
        'repo' => 'https://github.com/Bkapk/weble-mcp',
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';', 'before');
    add_filter('admin_body_class', function ($c) { return $c . ' wbmcp-app-screen'; });
});

function wbmcp_admin_page() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to access this page.', 'weble-mcp'), '', ['response' => 403]);
    if (!file_exists(dirname(__DIR__) . '/assets/app/app.js')) {
        echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Weble MCP: the admin app is not built. Run npm run build in app-src.', 'weble-mcp') . '</p></div></div>';
        return;
    }
    echo '<div id="wbmcp-app" class="wbmcp-root"></div>';
}

/* ---------- REST for the app ---------- */

/** Admins in a browser session only: a connection password must never manage connections or switch custom code on. */
function wbmcp_admin_can() {
    return current_user_can('manage_options') && !rest_get_authenticated_app_password();
}

add_action('rest_api_init', function () {
    $r = function ($path, $methods, $cb) {
        register_rest_route('weble-mcp/v1', '/admin/' . $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => 'wbmcp_admin_can']);
    };
    $r('bootstrap', 'GET', function () { return wbmcp_admin_state(); });
    $r('connections', 'POST', 'wbmcp_admin_connect');
    $r('connections/(?P<user>\d+)/(?P<uuid>[a-f0-9-]{36})', 'DELETE', 'wbmcp_admin_revoke');
    $r('settings', 'POST', 'wbmcp_admin_save');
});

function wbmcp_admin_state() {
    $user = wp_get_current_user();
    $kit = (int) get_option('elementor_active_kit');
    return [
        'site' => ['name' => get_bloginfo('name'), 'host' => (string) wp_parse_url(home_url(), PHP_URL_HOST), 'https' => is_ssl() || strpos(home_url(), 'https://') === 0],
        'endpoint' => rest_url('weble-mcp/v1/mcp'),
        'user' => $user->user_login,
        'passwords' => wp_is_application_passwords_available_for_user($user),
        'local' => wp_get_environment_type() === 'local',
        'plainPermalinks' => !get_option('permalink_structure'),
        'canCode' => current_user_can('unfiltered_html'),
        'elementor' => [
            'active' => did_action('elementor/loaded') > 0,
            'version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            'pro' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : '',
            'kit' => $kit && get_post($kit) ? $kit : 0,
        ],
        'settings' => ['custom_code' => (bool) get_option('wbmcp_custom_code'), 'house_rules' => (string) get_option('wbmcp_house_rules', ''), 'delete_data' => (bool) get_option('wbmcp_delete_data')],
        'connections' => wbmcp_admin_connections(),
        'tools' => wbmcp_admin_tools(),
    ];
}

/** Every user's MCP connections, newest first. Revocable when the admin may edit that user. */
function wbmcp_admin_connections() {
    $out = [];
    $users = get_users(['meta_key' => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, 'fields' => ['ID', 'user_login']]);
    foreach ($users as $u) {
        foreach ((array) WP_Application_Passwords::get_user_application_passwords($u->ID) as $c) {
            if (!wbmcp_is_mcp_password($c)) continue;
            $out[] = [
                'uuid' => $c['uuid'], 'user_id' => (int) $u->ID, 'user' => $u->user_login, 'name' => $c['name'],
                'created' => (int) ($c['created'] ?? 0), 'last_used' => (int) ($c['last_used'] ?? 0), 'last_ip' => (string) ($c['last_ip'] ?? ''),
                'can_revoke' => current_user_can('edit_user', $u->ID),
            ];
        }
    }
    usort($out, function ($a, $b) { return $b['created'] <=> $a['created']; });
    return $out;
}

const WBMCP_TOOL_GROUPS = [
    'Start' => ['get_site_brief', 'update_house_rules'],
    'Content' => ['list_posts', 'get_post', 'create_post', 'update_post', 'delete_post', 'list_terms', 'create_term'],
    'Media and menus' => ['list_media', 'upload_media', 'list_menus', 'add_menu_item', 'delete_menu_item'],
    'Site and design system' => ['update_site_settings', 'get_design_system', 'update_design_system', 'set_custom_css'],
    'Elementor' => ['elementor_get_content', 'elementor_set_content', 'elementor_edit', 'elementor_undo', 'elementor_widget_schema', 'elementor_list_templates', 'elementor_create_template', 'elementor_set_conditions', 'elementor_dynamic_tags'],
    'Verify' => ['elementor_lint', 'get_preview_url'],
    'Audit' => ['get_site_audit'],
];

function wbmcp_admin_tools() {
    $group = [];
    foreach (WBMCP_TOOL_GROUPS as $g => $names) foreach ($names as $n) $group[$n] = $g;
    $elementor = did_action('elementor/loaded') > 0;
    $out = [];
    foreach ($GLOBALS['wbmcp_tools'] as $name => $t) {
        $out[] = [
            'name' => $name,
            'summary' => wbmcp_admin_summary($t['description']),
            'group' => $group[$name] ?? 'Other',
            'writes' => wbmcp_tool_writes($name),
            'available' => strpos($name, 'elementor_') !== 0 || $elementor,
        ];
    }
    return $out;
}

/** The first sentence is enough for a catalog (the model gets the full text); a very short one takes the next too. */
function wbmcp_admin_summary($d) {
    $s = preg_split('/(?<=[.!?])\s+/', trim($d), 3);
    return strlen($s[0]) < 40 && isset($s[1]) ? $s[0] . ' ' . $s[1] : $s[0];
}

function wbmcp_admin_connect() {
    $user = wp_get_current_user();
    if (!wp_is_application_passwords_available_for_user($user)) {
        return new WP_Error('wbmcp_passwords', 'Application Passwords are unavailable on this site.', ['status' => 400]);
    }
    $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => 'Weble MCP ' . wp_date('Y-m-d H:i'), 'app_id' => WBMCP_APP_ID]);
    if (is_wp_error($created)) return $created;
    // The app turns these into the config for each MCP client.
    $connection = [
        'name' => 'weble-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))), '-'),
        'url'  => rest_url('weble-mcp/v1/mcp'),
        'auth' => 'Basic ' . base64_encode($user->user_login . ':' . $created[0]),
    ];
    return ['connection' => $connection, 'connections' => wbmcp_admin_connections()];
}

function wbmcp_admin_revoke(WP_REST_Request $req) {
    $uid = (int) $req['user'];
    if (!current_user_can('edit_user', $uid)) return new WP_Error('wbmcp_forbidden', 'You cannot revoke this connection.', ['status' => 403]);
    $item = WP_Application_Passwords::get_user_application_password($uid, $req['uuid']);
    if (!$item || !wbmcp_is_mcp_password($item)) return new WP_Error('wbmcp_missing', 'That connection no longer exists.', ['status' => 404]);
    $done = WP_Application_Passwords::delete_application_password($uid, $req['uuid']);
    if (is_wp_error($done)) return $done;
    return ['connections' => wbmcp_admin_connections()];
}

function wbmcp_admin_save(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (array_key_exists('custom_code', $p)) {
        if ($p['custom_code'] && !current_user_can('unfiltered_html')) return new WP_Error('wbmcp_forbidden', 'Your account cannot post unfiltered HTML, so it cannot switch custom code on.', ['status' => 403]);
        update_option('wbmcp_custom_code', $p['custom_code'] ? 1 : 0, false);
    }
    if (array_key_exists('delete_data', $p)) update_option('wbmcp_delete_data', $p['delete_data'] ? 1 : 0, false);
    if (array_key_exists('house_rules', $p)) update_option('wbmcp_house_rules', wbmcp_clean_rules($p['house_rules']), false);
    return ['settings' => wbmcp_admin_state()['settings']];
}
