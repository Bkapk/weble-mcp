<?php
defined('ABSPATH') || exit;

/* ---------- Site audit: read-only inventory before a migration ---------- */

const WBMCP_AUDIT_SECTIONS = ['core', 'plugins', 'woocommerce', 'options_index', 'options', 'code', 'marketing', 'ops'];
const WBMCP_SECRET_KEYS = '/pass|secret|token|key|auth|salt|licen[cs]e|api|bearer|private|credential|webhook_secret|consumer/i';
/** IDs that are public by nature (they ship in every page's HTML) stay visible. */
const WBMCP_PUBLIC_KEYS = '/measurement_id|property_id|pixel_?id|gtm|container_id|account_id/i';
const WBMCP_AUDIT_VALUE_MAX = 4000;
const WBMCP_AUDIT_MAX = 200000;

wbmcp_tool('get_site_audit', 'Read-only audit of the live site for planning a migration (administrators only). Never writes anything. Secrets are redacted. Sections: core (versions, server, settings, themes, user counts), plugins (installed, active, updates, mu-plugins, drop-ins), woocommerce (options, HPOS, gateways, shipping, tax, webhooks, emails, coupons, order counts), options_index (every option name with autoload and size, grouped by prefix: use it to find plugin settings), options (values of options starting with `prefix`), code (snippets, custom code, custom CSS, header/footer scripts), marketing (Site Kit, pixels, OptinMonster, Jetpack, SMTP, popups), ops (cron, .htaccess, redirects, widgets, backups).', [
    'section' => ['type' => 'string', 'enum' => WBMCP_AUDIT_SECTIONS],
    'prefix' => ['type' => 'string', 'description' => 'Option name prefix, required for section=options, e.g. "elementor_" or "wpseo"'],
    'limit' => ['type' => 'integer', 'description' => 'Max rows per list (options, coupons, snippets, table rows). Default 200.'],
], function ($a) {
    if (!current_user_can('manage_options')) wbmcp_fail('get_site_audit requires an administrator.');
    $section = $a['section'] ?? '';
    if (!in_array($section, WBMCP_AUDIT_SECTIONS, true)) wbmcp_fail('section must be one of: ' . implode(', ', WBMCP_AUDIT_SECTIONS));
    $limit = min(5000, max(1, (int) ($a['limit'] ?? 200)));
    // Belt and braces: nothing a section triggers (plugin constructors, filters) may save an option or call out.
    $keep = function ($v, $o, $old) { return $old; };
    $offline = function () { return new WP_Error('wbmcp_audit', 'Remote calls are blocked during the audit.'); };
    add_filter('pre_update_option', $keep, PHP_INT_MAX, 3);
    add_filter('pre_http_request', $offline, PHP_INT_MAX);
    try {
        $out = ('wbmcp_audit_' . $section)($a, $limit);
    } finally {
        remove_filter('pre_update_option', $keep, PHP_INT_MAX);
        remove_filter('pre_http_request', $offline, PHP_INT_MAX);
    }
    // The index holds names and sizes only; redacting would hide names like "api_key" that the agent needs to find.
    return wbmcp_audit_fit($section === 'options_index' ? $out : wbmcp_audit_redact($out));
}, ['section']);

/* ---------- Redaction and size limits ---------- */

/** Unserialize stored values without instantiating classes. */
function wbmcp_audit_value($v) {
    if (is_string($v) && is_serialized($v)) {
        $u = @unserialize(trim($v), ['allowed_classes' => false]);
        if ($u !== false || trim($v) === 'b:0;') return $u;
    }
    return $v;
}

/** Recursively replace values under secret-looking keys, decode serialized/JSON strings, and cap long strings. */
function wbmcp_audit_redact($v, $secret = false, $depth = 0) {
    $v = wbmcp_audit_value($v);
    if (is_string($v) && preg_match('/^\s*[\[{]/', $v) && is_array($j = json_decode($v, true))) $v = $j;
    if (is_object($v)) { // incomplete classes from unserialize: drop the \0*\0 visibility markers from property names
        $o = [];
        foreach ((array) $v as $k => $x) $o[preg_replace('/^\0[^\0]*\0/', '', (string) $k)] = $x;
        $v = $o;
    }
    if (is_array($v)) {
        if ($depth > 20) return '[nested too deep]';
        $out = [];
        foreach ($v as $k => $x) {
            $s = preg_match(WBMCP_PUBLIC_KEYS, (string) $k) ? false : ($secret || preg_match(WBMCP_SECRET_KEYS, (string) $k));
            $out[$k] = wbmcp_audit_redact($x, $s, $depth + 1);
        }
        return $out;
    }
    if ($secret && $v !== null && $v !== '') return '[redacted, ' . strlen((string) $v) . ' chars]';
    if (is_string($v)) $v = wbmcp_audit_scrub($v);
    if (is_string($v) && mb_strlen($v) > WBMCP_AUDIT_VALUE_MAX) {
        return mb_substr($v, 0, WBMCP_AUDIT_VALUE_MAX) . '… [truncated, ' . mb_strlen($v) . ' chars]';
    }
    return $v;
}

/** Secrets inside values whose key gave nothing away: snippet code, .htaccess, URLs with credentials. */
function wbmcp_audit_scrub($s) {
    $s = preg_replace('~-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)~s', '[redacted private key]', $s);
    $s = preg_replace('~\b([a-z][a-z0-9+.-]*://)[^/\s:@"\']+:[^/\s@"\']+@~i', '$1[redacted]@', $s);
    return preg_replace('~\b(sk|rk)_(live|test)_[0-9A-Za-z]{10,}|\bAKIA[0-9A-Z]{16}\b|\bgh[pousr]_[0-9A-Za-z]{30,}|\bxox[abpr]-[0-9A-Za-z-]{10,}|\bAIza[0-9A-Za-z_-]{35}\b|\bSG\.[\w-]{16,}\.[\w-]{20,}|\bre_[0-9A-Za-z_]{20,}|\bsk-(?:ant-|proj-)?[0-9A-Za-z_-]{20,}~', '[redacted secret]', $s);
}

function wbmcp_audit_size($v) {
    return strlen((string) wp_json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

/** Keep the response under ~200 KB, dropping trailing entries with a marker where they were cut. */
function wbmcp_audit_fit($out) {
    if (wbmcp_audit_size($out) <= WBMCP_AUDIT_MAX) return $out;
    $budget = WBMCP_AUDIT_MAX - 1000;
    return ['note' => 'Response cut to ~200 KB: lists marked _truncated are incomplete. Narrow it with section=options and a prefix, or a lower limit.'] + wbmcp_audit_cut($out, $budget);
}

function wbmcp_audit_cut($v, &$budget) {
    if (!is_array($v)) {
        $budget -= wbmcp_audit_size($v) + 4;
        return $v;
    }
    $out = [];
    foreach ($v as $k => $x) {
        if ($budget <= 0) {
            $out['_truncated'] = (count($v) - count($out)) . ' more entries not shown';
            break;
        }
        $budget -= strlen((string) $k) + 4;
        $out[$k] = wbmcp_audit_cut($x, $budget);
    }
    return $out;
}

/* ---------- Readers ---------- */

/** Options whose name starts with $prefix, as name => value. */
function wbmcp_audit_opts($prefix, $limit) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE %s ORDER BY option_name LIMIT %d", $wpdb->esc_like($prefix) . '%', $limit));
    $out = [];
    foreach ((array) $rows as $r) $out[$r->option_name] = wbmcp_audit_value($r->option_value);
    return $out;
}

/** Rows of a plugin's table, or null if the table doesn't exist. SELECT * so other plugin versions' schemas can't break the query. */
function wbmcp_audit_table($table, $limit, array $cols = []) {
    global $wpdb;
    $t = $wpdb->prefix . $table;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) !== $t) return null;
    $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$t` LIMIT %d", $limit), ARRAY_A);
    return $cols ? array_map(function ($r) use ($cols) { return array_intersect_key($r, array_flip($cols)); }, $rows) : $rows;
}

/** Posts of any type (blocked ones included: this is read-only and admin-only) with selected meta. */
function wbmcp_audit_posts(array $q, $limit, array $meta = [], $content = null) {
    $out = [];
    foreach (get_posts($q + ['post_status' => 'any', 'numberposts' => $limit, 'suppress_filters' => true]) as $p) {
        $row = ['id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status];
        foreach ($meta as $k => $key) $row[$k] = get_post_meta($p->ID, $key, true);
        if ($content) $row[$content] = $p->post_content;
        $out[] = $row;
    }
    return $out;
}

/* ---------- Sections ---------- */

function wbmcp_audit_core() {
    global $wpdb;
    $theme = wp_get_theme();
    $parent = $theme->parent();
    $themes = [];
    // Not wp_get_themes(): on a cold cache it saves the theme_roots transient, and the audit writes nothing.
    foreach (glob(get_theme_root() . '/*/style.css') ?: [] as $f) {
        $t = get_file_data($f, ['name' => 'Theme Name', 'version' => 'Version', 'parent' => 'Template']);
        if ($t['name']) $themes[basename(dirname($f))] = ['name' => $t['name'], 'version' => $t['version'], 'parent' => $t['parent'] ?: null];
    }
    $uploads = wp_get_upload_dir();
    $users = count_users();
    return [
        'wordpress' => get_bloginfo('version'),
        'php' => PHP_VERSION,
        'mysql' => $wpdb->db_server_info(),
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
        'memory_limit' => ini_get('memory_limit'),
        'wp_memory_limit' => WP_MEMORY_LIMIT,
        'max_upload' => size_format(wp_max_upload_size()),
        'wp_debug' => WP_DEBUG,
        'disable_wp_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'multisite' => is_multisite(),
        'siteurl' => get_option('siteurl'),
        'home' => get_option('home'),
        'permalink_structure' => get_option('permalink_structure') ?: 'plain',
        'blog_public' => (bool) get_option('blog_public'),
        'users_can_register' => (bool) get_option('users_can_register'),
        'default_role' => get_option('default_role'),
        'timezone' => wp_timezone_string(),
        'uploads' => ['baseurl' => $uploads['baseurl'], 'year_month_folders' => (bool) get_option('uploads_use_yearmonth_folders'), 'upload_path' => get_option('upload_path') ?: null],
        'theme' => ['name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'stylesheet' => $theme->get_stylesheet(), 'parent' => $parent ? ['name' => $parent->get('Name'), 'version' => $parent->get('Version')] : null],
        'themes' => $themes,
        'users' => ['total' => $users['total_users'], 'by_role' => $users['avail_roles']],
    ];
}

function wbmcp_audit_plugins() {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $updates = (array) (get_site_transient('update_plugins')->response ?? []);
    $plugins = [];
    foreach (get_plugins() as $file => $p) {
        $plugins[] = [
            'file' => $file,
            'name' => $p['Name'],
            'version' => $p['Version'],
            'by' => wp_strip_all_tags($p['Author']), // not "author": that key would match the secret filter
            'uri' => $p['PluginURI'],
            'active' => is_plugin_active($file),
            'update' => $updates[$file]->new_version ?? null,
        ];
    }
    $short = function ($list) {
        return array_map(function ($p) { return ['name' => $p['Name'], 'version' => $p['Version']]; }, $list);
    };
    return ['plugins' => $plugins, 'mu_plugins' => $short(get_mu_plugins()), 'dropins' => $short(get_dropins())];
}

function wbmcp_audit_woocommerce($a, $limit) {
    if (!class_exists('WooCommerce')) return ['note' => 'WooCommerce is not active.'];
    global $wpdb;

    $gateways = [];
    foreach (WC()->payment_gateways()->payment_gateways() as $g) {
        $gateways[] = ['id' => $g->id, 'title' => $g->get_title(), 'method' => $g->get_method_title(), 'enabled' => $g->enabled === 'yes', 'settings' => $g->settings];
    }

    $zones = [];
    foreach (array_merge(array_keys(WC_Shipping_Zones::get_zones()), [0]) as $id) {
        $z = WC_Shipping_Zones::get_zone($id);
        $methods = [];
        foreach ($z->get_shipping_methods() as $m) {
            $methods[] = [
                'instance_id' => $m->instance_id,
                'id' => $m->id,
                'title' => $m->get_title(),
                'enabled' => $m->is_enabled(),
                'settings' => $m->instance_settings ?: get_option($m->get_instance_option_key(), []),
                // Flexible Shipping 3.x and older kept its rules outside the instance settings.
                'flexible_shipping_legacy' => get_option('flexible_shipping_methods_' . $m->instance_id, null),
            ];
        }
        $zones[] = [
            'id' => $id,
            'name' => $id ? $z->get_zone_name() : 'Locations not covered by your other zones',
            'regions' => array_map(function ($l) { return $l->type . ':' . $l->code; }, $z->get_zone_locations()),
            'methods' => $methods,
        ];
    }

    $classes = array_map(function ($c) { return ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'products' => $c->count]; }, WC()->shipping()->get_shipping_classes());

    $rates = [];
    foreach ((array) wbmcp_audit_table('woocommerce_tax_rates', 5000) as $r) $rates[$r['tax_rate_id']] = $r + ['locations' => []];
    foreach ((array) wbmcp_audit_table('woocommerce_tax_rate_locations', 20000) as $l) {
        if (isset($rates[$l['tax_rate_id']])) $rates[$l['tax_rate_id']]['locations'][] = $l['location_type'] . ':' . $l['location_code'];
    }

    $hooks = array_map(function ($w) {
        return ['id' => $w['webhook_id'], 'name' => $w['name'], 'topic' => $w['topic'], 'delivery_host' => wp_parse_url($w['delivery_url'], PHP_URL_HOST), 'status' => $w['status']];
    }, (array) wbmcp_audit_table('wc_webhooks', $limit, ['webhook_id', 'name', 'topic', 'delivery_url', 'status']));

    $emails = [];
    foreach (WC()->mailer()->get_emails() as $e) {
        $emails[] = [
            'id' => $e->id,
            'enabled' => $e->is_enabled(),
            'recipient' => $e->is_customer_email() ? 'customer' : $e->get_recipient(),
            'subject' => $e->get_option('subject') ?: (method_exists($e, 'get_default_subject') ? $e->get_default_subject() : ''),
        ];
    }

    $coupons = [];
    foreach (get_posts(['post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => $limit, 'fields' => 'ids']) as $id) {
        $c = new WC_Coupon($id);
        $exp = $c->get_date_expires();
        $coupons[] = [
            'code' => $c->get_code(),
            'type' => $c->get_discount_type(),
            'amount' => $c->get_amount(),
            'free_shipping' => $c->get_free_shipping(),
            'usage' => $c->get_usage_count() . ' / ' . ($c->get_usage_limit() ?: 'unlimited'),
            'expires' => $exp ? $exp->date('Y-m-d') : null,
            'status' => get_post_status($id),
        ];
    }

    $orders = [];
    foreach (array_keys(wc_get_order_statuses()) as $s) $orders[$s] = wc_orders_count(preg_replace('/^wc-/', '', $s));

    return [
        'version' => WC()->version,
        'hpos' => class_exists('Automattic\WooCommerce\Utilities\OrderUtil') ? Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() : false,
        'options' => wbmcp_audit_opts('woocommerce_', max($limit, 1000)),
        'payment_gateways' => $gateways,
        'shipping_zones' => $zones,
        'shipping_classes' => $classes,
        'tax_classes' => array_merge(['standard'], WC_Tax::get_tax_class_slugs()),
        'tax_rates' => array_values($rates),
        'webhooks' => $hooks,
        'emails' => $emails,
        'coupons' => $coupons,
        'orders_by_status' => $orders,
        'customers' => count_users()['avail_roles']['customer'] ?? 0, // registered accounts; guest buyers have none
    ];
}

function wbmcp_audit_options_index() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM $wpdb->options WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY option_name");
    $groups = [];
    foreach ((array) $rows as $r) {
        $groups[preg_split('/[_\-]+/', ltrim($r->option_name, '_'))[0]][] = [$r->option_name, $r->autoload, (int) $r->bytes];
    }
    return ['format' => '{prefix: [[option_name, autoload, value_bytes], ...]}. Read values with section=options and a prefix.', 'count' => count((array) $rows), 'groups' => $groups];
}


function wbmcp_audit_options($a, $limit) {
    $prefix = (string) ($a['prefix'] ?? '');
    if (strlen($prefix) < 2) wbmcp_fail('section=options needs a prefix of at least 2 characters. Find one with section=options_index.');
    return wbmcp_audit_opts($prefix, $limit);
}

function wbmcp_audit_code($a, $limit) {
    $kit = (int) get_option('elementor_active_kit');
    $yp = array_filter(wbmcp_audit_opts('wt_', $limit) + wbmcp_audit_opts('yp_', $limit), function ($v) { return is_string($v) && strpos($v, '{') !== false; });
    $hf = [];
    foreach (['ihaf_insert_header', 'ihaf_insert_body', 'ihaf_insert_footer'] as $k) { // Insert Headers and Footers, and WPCode which kept its options
        if (($v = get_option($k, null)) !== null) $hf[$k] = $v;
    }
    return [
        'code_snippets' => wbmcp_audit_table('snippets', $limit, ['id', 'name', 'description', 'scope', 'active', 'priority', 'tags', 'code']),
        'elementor_custom_code' => wbmcp_audit_posts(['post_type' => 'elementor_snippet'], $limit, ['location' => '_elementor_location', 'priority' => '_elementor_priority', 'conditions' => '_elementor_conditions', 'code' => '_elementor_code']),
        'wpcode_snippets' => wbmcp_audit_posts(['post_type' => 'wpcode'], $limit, [], 'code'),
        'customizer_css' => wbmcp_audit_posts(['post_type' => 'custom_css'], $limit, [], 'css'), // one post per theme; title is the theme
        'yellowpencil_css' => $yp,
        'elementor_kit_css' => $kit ? (((array) get_post_meta($kit, '_elementor_page_settings', true))['custom_css'] ?? null) : null,
        'header_footer_options' => $hf,
        'header_footer_code_manager' => wbmcp_audit_table('hfcm_scripts', $limit, ['script_id', 'name', 'snippet', 'location', 'display_on', 'device_type', 'status']),
    ];
}

function wbmcp_audit_marketing($a, $limit) {
    $smtp = (array) get_option('wp_mail_smtp', []);
    return [
        'site_kit' => wbmcp_audit_opts('googlesitekit_', $limit),
        'pixelyoursite' => wbmcp_audit_opts('pys_', $limit),
        'facebook_for_woocommerce' => wbmcp_audit_opts('wc_facebook_', $limit) + ['facebook_config' => get_option('facebook_config', null)],
        'optinmonster' => wbmcp_audit_opts('omapi', $limit),
        'jetpack_modules' => get_option('jetpack_active_modules', null),
        'wp_mail_smtp' => $smtp ? ['mailer' => $smtp['mail']['mailer'] ?? null, 'from_email' => $smtp['mail']['from_email'] ?? null, 'from_name' => $smtp['mail']['from_name'] ?? null] : null,
        'popups' => [
            'ays_pb' => wbmcp_audit_table('ays_pb', $limit),
            'elementor' => wbmcp_audit_posts(['post_type' => 'elementor_library', 'meta_key' => '_elementor_template_type', 'meta_value' => 'popup'], $limit, ['conditions' => '_elementor_conditions', 'triggers' => '_elementor_popup_display_settings']),
        ],
    ];
}

function wbmcp_audit_ops($a, $limit) {
    $cron = [];
    foreach ((array) _get_cron_array() as $ts => $hooks) {
        foreach ((array) $hooks as $hook => $events) {
            foreach ((array) $events as $e) $cron[] = ['hook' => $hook, 'schedule' => $e['schedule'] ?: 'once', 'next_run' => gmdate('Y-m-d H:i', $ts) . ' UTC'];
        }
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $ht = get_home_path() . '.htaccess';
    $widgets = [];
    foreach (wp_get_sidebars_widgets() as $sidebar => $ids) {
        if (is_array($ids)) $widgets[$sidebar] = $ids;
    }
    $updraft = [];
    foreach (['updraft_interval', 'updraft_interval_database', 'updraft_retain', 'updraft_retain_db', 'updraft_service'] as $k) { // schedule and storage type only, never the per-service credential options
        if (($v = get_option($k, null)) !== null) $updraft[$k] = $v;
    }
    return [
        'cron' => $cron,
        'htaccess' => is_readable($ht) ? file_get_contents($ht) : null,
        'redirection' => wbmcp_audit_table('redirection_items', $limit, ['id', 'url', 'match_type', 'regex', 'action_type', 'action_code', 'action_data', 'status']),
        'rank_math_redirections' => wbmcp_audit_table('rank_math_redirections', $limit, ['id', 'sources', 'url_to', 'header_code', 'status']),
        'sidebars_widgets' => $widgets,
        'updraftplus' => $updraft ?: null,
    ];
}
