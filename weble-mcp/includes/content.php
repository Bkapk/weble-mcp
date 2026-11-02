<?php
defined('ABSPATH') || exit;

/** Site options the MCP may change. Everything else (URLs, roles, plugins, theme) stays off limits. */
const WBMCP_SETTINGS = ['blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'site_icon', 'date_format', 'time_format', 'timezone_string', 'start_of_week'];

/** Post types never exposed: customer data and form submissions, executable code, field schemas, form definitions (their mail settings route leads). Extend with the wbmcp_post_types filter. */
const WBMCP_BLOCKED_TYPES = ['shop_order', 'shop_order_refund', 'shop_subscription', 'shop_coupon', 'wc_user_membership', 'edd_payment', 'edd_discount', 'flamingo_inbound', 'flamingo_contact', 'feedback', 'nf_sub', 'wpcf7_contact_form', 'wpforms', 'wpcode', 'wbcr-snippets', 'custom-css-js', 'elementor_snippet', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page'];

/** Meta the generic `meta` field may not write: owned by the elementor_* tools, file paths, or page-builder data that can run code. */
const WBMCP_META_DENY = '/^(_elementor_|_wbmcp_|_wp_attached_file$|_wp_attachment_metadata$|_wp_attachment_backup_sizes$|_bricks_|ct_builder_|_breakdance_|breakdance_|_fl_builder_|_oxygen_)/';

/** Exposed post types: shown in the admin, not blocked, and editable by the connected user (a type you can't edit can hold data you shouldn't read, like coupon codes). */
function wbmcp_post_types() {
    $types = array_filter(array_diff(get_post_types(['show_ui' => true]), WBMCP_BLOCKED_TYPES), function ($t) {
        $o = get_post_type_object($t);
        return $o && current_user_can($o->cap->edit_posts);
    });
    return apply_filters('wbmcp_post_types', array_values($types));
}

/** True if a meta key may be written through the generic `meta` field. ASCII only and compared case-insensitively, because MySQL matches meta_key with a case- and accent-insensitive collation. */
function wbmcp_meta_key_ok($k) {
    $k = (string) $k;
    return (bool) preg_match('/^[A-Za-z0-9_\-:.]{1,191}$/', $k) && !preg_match(WBMCP_META_DENY, strtolower($k));
}

/** Load a post the current user may access, or fail. */
function wbmcp_post($id, $cap = 'edit_post') {
    $p = get_post((int) $id);
    if (!$p || !in_array($p->post_type, wbmcp_post_types(), true)) wbmcp_fail("Post $id not found or its post type is not exposed.");
    if (!current_user_can($cap, $p->ID)) wbmcp_fail("You are not allowed to $cap $p->ID.");
    // read_post passes for a published post with a password: its content stays with people who can edit it.
    if ($cap === 'read_post' && $p->post_password !== '' && !current_user_can('edit_post', $p->ID)) wbmcp_fail("Post $p->ID is password-protected.");
    // Elementor kits hold the site-wide design: any change to one, through any tool, needs an administrator.
    if ($cap !== 'read_post' && $p->post_type === 'elementor_library' && get_post_meta($p->ID, '_elementor_template_type', true) === 'kit' && !current_user_can('manage_options')) {
        wbmcp_fail('Changing the global kit requires an administrator.');
    }
    return $p;
}

function wbmcp_summary(WP_Post $p) {
    return [
        'id' => $p->ID,
        'type' => $p->post_type,
        'title' => $p->post_title,
        'status' => $p->post_status,
        'slug' => $p->post_name,
        'parent' => $p->post_parent,
        'modified' => $p->post_modified,
        'link' => get_permalink($p),
        'template' => get_page_template_slug($p) ?: null,
        'elementor' => get_post_meta($p->ID, '_elementor_edit_mode', true) === 'builder',
    ];
}

function wbmcp_meta($id) {
    $skip = ['_elementor_data', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_controls_usage', '_edit_lock', '_edit_last', '_wbmcp_snapshots'];
    $editor = current_user_can('edit_post', $id); // protected meta can hold secrets (download URLs, form keys): editors only
    $out = [];
    foreach (get_post_meta($id) as $k => $vals) {
        if (in_array($k, $skip, true) || (!$editor && is_protected_meta($k, 'post'))) continue;
        // ACF field-key references (_field => field_abc123) are noise; values come through "acf".
        if ($k[0] === '_' && is_string($vals[0]) && strpos($vals[0], 'field_') === 0) continue;
        // Never revive objects from meta another plugin or role may have planted.
        $vals = array_map(function ($v) { return is_serialized($v) ? @unserialize(trim($v), ['allowed_classes' => false]) : $v; }, $vals);
        $out[$k] = count($vals) === 1 ? $vals[0] : $vals;
    }
    return $out;
}

function wbmcp_terms_of(WP_Post $p) {
    $out = [];
    foreach (get_object_taxonomies($p->post_type) as $tax) {
        $terms = wp_get_post_terms($p->ID, $tax);
        if (!is_wp_error($terms) && $terms) {
            $out[$tax] = array_map(function ($t) { return ['id' => $t->term_id, 'name' => $t->name]; }, $terms);
        }
    }
    return $out;
}

function wbmcp_settings() {
    $out = [];
    foreach (WBMCP_SETTINGS as $k) $out[$k] = get_option($k);
    return $out;
}

/** Map tool args to wp_insert_post fields, enforcing the publish capability. */
function wbmcp_postarr(array $a, WP_Post_Type $type) {
    $map = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name', 'parent' => 'post_parent', 'menu_order' => 'menu_order', 'date' => 'post_date', 'template' => 'page_template'];
    $arr = [];
    foreach ($map as $k => $field) {
        if (array_key_exists($k, $a)) $arr[$field] = $a[$k];
    }
    if (in_array($arr['post_status'] ?? '', ['publish', 'future', 'private'], true) && !current_user_can($type->cap->publish_posts)) {
        wbmcp_fail("You are not allowed to publish {$type->name}.");
    }
    return $arr;
}

/** Featured image, meta, ACF, terms and Elementor layout for create/update. */
function wbmcp_apply_extras($id, array $a) {
    if (array_key_exists('featured_media', $a)) {
        if ($a['featured_media']) set_post_thumbnail($id, (int) $a['featured_media']);
        else delete_post_thumbnail($id);
    }
    foreach ((array) ($a['meta'] ?? []) as $k => $v) {
        if (!wbmcp_meta_key_ok($k)) wbmcp_fail("Meta '$k' can't be written here. Elementor data and page settings go through the elementor_* tools and elementor_page_settings; file paths and other builders' data are off limits.");
        if ($v === null) delete_post_meta($id, $k);
        else update_post_meta($id, $k, wp_slash($v));
    }
    if (!empty($a['acf'])) {
        if (!function_exists('update_field')) wbmcp_fail('ACF is not active on this site.');
        foreach ($a['acf'] as $k => $v) {
            // An unknown name makes update_field write raw meta under that name, past the meta checks.
            $f = acf_get_field($k);
            if (!$f || !wbmcp_meta_key_ok($f['name'] ?? '')) wbmcp_fail("'$k' is not an ACF field on this site.");
            update_field($k, $v, $id);
        }
    }
    foreach ((array) ($a['terms'] ?? []) as $tax => $terms) {
        if (!is_object_in_taxonomy(get_post_type($id), $tax)) wbmcp_fail("Taxonomy '$tax' is not registered for this post type.");
        $caps = get_taxonomy($tax)->cap;
        if (!current_user_can($caps->assign_terms)) wbmcp_fail("You are not allowed to assign $tax terms.");
        foreach ((array) $terms as $t) { // names that don't exist yet would be created
            if (!is_int($t) && !term_exists((string) $t, $tax) && !current_user_can($caps->edit_terms)) wbmcp_fail("'$t' is not a $tax term and you are not allowed to create terms.");
        }
        wbmcp_check(wp_set_object_terms($id, (array) $terms, $tax));
    }
    if (isset($a['elementor_data'])) wbmcp_el_save($id, (array) $a['elementor_data'], $a['elementor_page_settings'] ?? null);
}

/* ---------- Site (the overview lives in get_site_brief) ---------- */

wbmcp_tool('update_site_settings', 'Update safe site settings. Allowed keys: ' . implode(', ', WBMCP_SETTINGS) . '.', [
    'settings' => ['type' => 'object', 'description' => 'e.g. {"blogdescription":"...","show_on_front":"page","page_on_front":12}'],
], function ($a) {
    if (!current_user_can('manage_options')) wbmcp_fail('Requires an administrator.');
    $s = (array) ($a['settings'] ?? []);
    if ($bad = array_diff(array_keys($s), WBMCP_SETTINGS)) wbmcp_fail('Not editable through MCP: ' . implode(', ', $bad));
    foreach ($s as $k => $v) update_option($k, $v);
    return wbmcp_settings();
}, ['settings']);

/* ---------- Posts, pages, CPTs ---------- */

$wbmcp_fields = [
    'title' => ['type' => 'string'],
    'content' => ['type' => 'string', 'description' => 'HTML or block markup. Has no visible effect on Elementor pages: use the elementor_* tools there.'],
    'excerpt' => ['type' => 'string'],
    'status' => ['type' => 'string', 'description' => 'draft | publish | pending | private | future'],
    'slug' => ['type' => 'string'],
    'parent' => ['type' => 'integer'],
    'menu_order' => ['type' => 'integer'],
    'date' => ['type' => 'string', 'description' => 'Y-m-d H:i:s, for scheduling with status=future'],
    'template' => ['type' => 'string', 'description' => 'Page template: elementor_canvas, elementor_header_footer, default or a theme template file'],
    'featured_media' => ['type' => 'integer', 'description' => 'Attachment id; 0 removes'],
    'meta' => ['type' => 'object', 'description' => '{key: value}; null deletes the key'],
    'acf' => ['type' => 'object', 'description' => '{field_name: value} via ACF update_field'],
    'terms' => ['type' => 'object', 'description' => '{taxonomy: [term ids or names]}; replaces the terms of that taxonomy'],
    'elementor_data' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Full Elementor layout (array of top-level elements). Makes the post an Elementor page.'],
    'elementor_page_settings' => ['type' => 'object', 'description' => 'Elementor page settings to merge, e.g. {"hide_title":"yes"}; null deletes a key'],
];

wbmcp_tool('list_posts', 'List or search posts of any exposed post type (pages, posts, CPTs, elementor_library, attachment...).', [
    'post_type' => ['type' => 'string', 'description' => 'Post type or "any". Default "page".'],
    'status' => ['type' => 'string', 'description' => 'publish | draft | pending | private | future | any (default)'],
    'search' => ['type' => 'string'],
    'parent' => ['type' => 'integer'],
    'meta_key' => ['type' => 'string'],
    'meta_value' => ['type' => 'string'],
    'elementor_only' => ['type' => 'boolean'],
    'orderby' => ['type' => 'string', 'description' => 'modified (default) | date | title | menu_order'],
    'per_page' => ['type' => 'integer', 'description' => '1-100, default 20'],
    'page' => ['type' => 'integer'],
], function ($a) {
    $type = $a['post_type'] ?? 'page';
    $types = wbmcp_post_types();
    if ($type !== 'any' && !in_array($type, $types, true)) wbmcp_fail("Post type '$type' is not exposed. Available: " . implode(', ', $types));
    $orderby = $a['orderby'] ?? 'modified';
    $q = [
        'post_type' => $type === 'any' ? $types : $type,
        'post_status' => $a['status'] ?? 'any',
        's' => $a['search'] ?? '',
        'posts_per_page' => min(100, max(1, (int) ($a['per_page'] ?? 20))),
        'paged' => max(1, (int) ($a['page'] ?? 1)),
        'orderby' => $orderby,
        'order' => in_array($orderby, ['title', 'menu_order'], true) ? 'ASC' : 'DESC',
    ];
    if (isset($a['parent'])) $q['post_parent'] = (int) $a['parent'];
    if (!empty($a['meta_key'])) {
        $q['meta_key'] = $a['meta_key'];
        if (isset($a['meta_value'])) $q['meta_value'] = $a['meta_value'];
    }
    if (!empty($a['elementor_only'])) $q['meta_query'] = [['key' => '_elementor_edit_mode', 'value' => 'builder']];
    $wq = new WP_Query($q);
    $items = array_filter($wq->posts, function ($p) { return current_user_can('read_post', $p->ID); });
    return ['total' => $wq->found_posts, 'pages' => $wq->max_num_pages, 'items' => array_values(array_map('wbmcp_summary', $items))];
});

wbmcp_tool('get_post', 'Full post: fields, content, all custom meta, ACF fields (raw values), terms and featured image. Elementor layouts are read with elementor_get_content.', [
    'id' => ['type' => 'integer'],
], function ($a) {
    $p = wbmcp_post($a['id'] ?? 0, 'read_post');
    $s = wbmcp_summary($p);
    $out = $s + [
        'date' => $p->post_date,
        'author' => (int) $p->post_author,
        'menu_order' => $p->menu_order,
        'excerpt' => $p->post_excerpt,
        'content' => $s['elementor'] ? '(Elementor page: read it with elementor_get_content)' : $p->post_content,
        'featured_media' => (int) get_post_thumbnail_id($p),
        'terms' => wbmcp_terms_of($p),
        'meta' => wbmcp_meta($p->ID),
    ];
    if ($p->post_type === 'attachment') $out['url'] = wp_get_attachment_url($p->ID);
    if (function_exists('get_fields')) $out['acf'] = get_fields($p->ID, false) ?: null;
    return $out;
}, ['id']);

wbmcp_tool('create_post', 'Create a page, post or CPT item (draft unless status is given). Meta, ACF, terms and a full Elementor layout can be set in the same call.', [
    'post_type' => ['type' => 'string', 'description' => 'Default "page"'],
] + $wbmcp_fields, function ($a) {
    $type = $a['post_type'] ?? 'page';
    if ($type === 'attachment') wbmcp_fail('Use upload_media for attachments.');
    if (!in_array($type, wbmcp_post_types(), true)) wbmcp_fail("Post type '$type' is not exposed.");
    $obj = get_post_type_object($type);
    if (!current_user_can($obj->cap->create_posts)) wbmcp_fail("You are not allowed to create $type.");
    if (isset($a['elementor_data'])) wbmcp_el(); // fail before inserting if Elementor is missing
    $id = wbmcp_check(wp_insert_post(wp_slash(wbmcp_postarr($a, $obj) + ['post_type' => $type, 'post_status' => 'draft']), true));
    wbmcp_apply_extras($id, $a);
    return wbmcp_summary(get_post($id));
});

wbmcp_tool('update_post', 'Update any fields of an existing post. Only the given fields change.', [
    'id' => ['type' => 'integer'],
] + $wbmcp_fields, function ($a) {
    $p = wbmcp_post($a['id'] ?? 0);
    $arr = wbmcp_postarr($a, get_post_type_object($p->post_type));
    if ($arr) wbmcp_check(wp_update_post(wp_slash(['ID' => $p->ID] + $arr), true));
    wbmcp_apply_extras($p->ID, $a);
    return wbmcp_summary(get_post($p->ID));
}, ['id']);

wbmcp_tool('delete_post', 'Move a post to the trash. Never deletes permanently.', [
    'id' => ['type' => 'integer'],
], function ($a) {
    $p = wbmcp_post($a['id'] ?? 0, 'delete_post');
    if (!wp_trash_post($p->ID)) wbmcp_fail('Could not trash the post.');
    return ['trashed' => $p->ID];
}, ['id']);

unset($wbmcp_fields);

/* ---------- Taxonomies ---------- */

wbmcp_tool('list_terms', 'List terms of a taxonomy (category, post_tag, product_cat, custom...).', [
    'taxonomy' => ['type' => 'string'],
    'search' => ['type' => 'string'],
    'parent' => ['type' => 'integer'],
], function ($a) {
    $tax = $a['taxonomy'] ?? '';
    if (!taxonomy_exists($tax)) wbmcp_fail("Unknown taxonomy '$tax'.");
    if (!current_user_can(get_taxonomy($tax)->cap->assign_terms)) wbmcp_fail("You are not allowed to use $tax terms.");
    $q = ['taxonomy' => $tax, 'hide_empty' => false, 'search' => $a['search'] ?? '', 'number' => 200];
    if (isset($a['parent'])) $q['parent'] = (int) $a['parent'];
    return array_map(function ($t) {
        return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count, 'description' => $t->description];
    }, wbmcp_check(get_terms($q)));
}, ['taxonomy']);

wbmcp_tool('create_term', 'Create a term in a taxonomy.', [
    'taxonomy' => ['type' => 'string'],
    'name' => ['type' => 'string'],
    'slug' => ['type' => 'string'],
    'parent' => ['type' => 'integer'],
    'description' => ['type' => 'string'],
], function ($a) {
    $tax = get_taxonomy($a['taxonomy'] ?? '');
    if (!$tax) wbmcp_fail('Unknown taxonomy.');
    if (!current_user_can($tax->cap->edit_terms)) wbmcp_fail('You are not allowed to create terms here.');
    $r = wbmcp_check(wp_insert_term(wp_slash($a['name'] ?? ''), $tax->name, wp_slash(array_intersect_key($a, array_flip(['slug', 'parent', 'description'])))));
    return ['id' => $r['term_id']];
}, ['taxonomy', 'name']);

/* ---------- Media ---------- */

wbmcp_tool('list_media', 'Search the Media Library.', [
    'search' => ['type' => 'string'],
    'mime' => ['type' => 'string', 'description' => 'image | video | application/pdf ...'],
    'per_page' => ['type' => 'integer', 'description' => '1-100, default 30'],
    'page' => ['type' => 'integer'],
], function ($a) {
    $q = new WP_Query([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        's' => $a['search'] ?? '',
        'post_mime_type' => $a['mime'] ?? '',
        'posts_per_page' => min(100, max(1, (int) ($a['per_page'] ?? 30))),
        'paged' => max(1, (int) ($a['page'] ?? 1)),
    ]);
    return ['total' => $q->found_posts, 'items' => array_map(function ($p) {
        $m = wp_get_attachment_metadata($p->ID) ?: [];
        return [
            'id' => $p->ID,
            'title' => $p->post_title,
            'url' => wp_get_attachment_url($p->ID),
            'alt' => get_post_meta($p->ID, '_wp_attachment_image_alt', true),
            'mime' => $p->post_mime_type,
            'width' => $m['width'] ?? null,
            'height' => $m['height'] ?? null,
        ];
    }, $q->posts)];
});

wbmcp_tool('upload_media', 'Download a file from a public URL into the Media Library. Edit alt/title later with update_post (alt is meta _wp_attachment_image_alt).', [
    'url' => ['type' => 'string'],
    'filename' => ['type' => 'string', 'description' => 'Optional SEO-friendly file name including extension'],
    'title' => ['type' => 'string'],
    'alt' => ['type' => 'string'],
    'caption' => ['type' => 'string'],
    'attach_to' => ['type' => 'integer', 'description' => 'Parent post id'],
], function ($a) {
    if (!current_user_can('upload_files')) wbmcp_fail('You are not allowed to upload files.');
    if (!empty($a['attach_to'])) wbmcp_post($a['attach_to']);
    // Admins may upload HTML/JS/SVG, which run as script on this domain: only with custom code on.
    $mimes = function ($m) { return wbmcp_code_allowed() ? $m : array_diff_key($m, array_flip(['htm|html', 'js', 'svg', 'svgz', 'xml', 'xhtml', 'swf'])); };
    add_filter('upload_mimes', $mimes, 999);
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = wbmcp_check(download_url($a['url'] ?? '')); // wp_safe_remote_get: blocks internal hosts
    $name = $a['filename'] ?? wp_basename((string) wp_parse_url($a['url'], PHP_URL_PATH));
    if (!pathinfo($name, PATHINFO_EXTENSION)) { // image CDNs often omit the extension
        $mime = wp_get_image_mime($tmp);
        $ext = $mime ? explode('|', (string) array_search($mime, wp_get_mime_types(), true))[0] : '';
        $name = ($name ?: 'upload') . ($ext ? ".$ext" : '');
    }
    $id = media_handle_sideload(['name' => sanitize_file_name($name), 'tmp_name' => $tmp], (int) ($a['attach_to'] ?? 0), null, array_filter([
        'post_title' => $a['title'] ?? null,
        'post_excerpt' => $a['caption'] ?? null,
    ]));
    remove_filter('upload_mimes', $mimes, 999);
    if (is_wp_error($id)) {
        wp_delete_file($tmp);
        wbmcp_fail($id->get_error_message());
    }
    if (!empty($a['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($a['alt']));
    return ['id' => $id, 'url' => wp_get_attachment_url($id)];
}, ['url']);

/* ---------- Menus ---------- */

wbmcp_tool('list_menus', 'Nav menus with their items, plus the theme menu locations.', [], function () {
    $locs = get_nav_menu_locations();
    $out = ['locations' => get_registered_nav_menus(), 'menus' => []];
    foreach (wp_get_nav_menus() as $m) {
        $out['menus'][] = [
            'id' => $m->term_id,
            'name' => $m->name,
            'slug' => $m->slug,
            'locations' => array_keys($locs, $m->term_id),
            'items' => array_map(function ($i) {
                return ['id' => $i->ID, 'title' => $i->title, 'url' => $i->url, 'parent' => (int) $i->menu_item_parent, 'object' => $i->object, 'object_id' => (int) $i->object_id, 'order' => $i->menu_order];
            }, wp_get_nav_menu_items($m) ?: []),
        ];
    }
    return $out;
});

wbmcp_tool('add_menu_item', 'Add a link to a nav menu. The menu is created if no menu has that name. Link a post with post_id, or anything else with url.', [
    'menu' => ['type' => 'string', 'description' => 'Menu id or name'],
    'title' => ['type' => 'string', 'description' => 'Defaults to the post title for post links'],
    'post_id' => ['type' => 'integer'],
    'url' => ['type' => 'string'],
    'parent_item' => ['type' => 'integer', 'description' => 'Menu item id to nest under'],
    'position' => ['type' => 'integer'],
], function ($a) {
    if (!current_user_can('edit_theme_options')) wbmcp_fail('You are not allowed to edit menus.');
    $menu = wp_get_nav_menu_object($a['menu'] ?? '');
    $menu_id = $menu ? $menu->term_id : wbmcp_check(wp_create_nav_menu((string) ($a['menu'] ?? '')));
    $item = [
        'menu-item-title' => $a['title'] ?? '',
        'menu-item-status' => 'publish',
        'menu-item-parent-id' => (int) ($a['parent_item'] ?? 0),
        'menu-item-position' => (int) ($a['position'] ?? 0),
    ];
    if (!empty($a['post_id'])) {
        $p = wbmcp_post($a['post_id'], 'read_post');
        $item += ['menu-item-type' => 'post_type', 'menu-item-object' => $p->post_type, 'menu-item-object-id' => $p->ID];
    } else {
        $item += ['menu-item-type' => 'custom', 'menu-item-url' => esc_url_raw($a['url'] ?? '')];
    }
    return ['menu_id' => $menu_id, 'item_id' => wbmcp_check(wp_update_nav_menu_item($menu_id, 0, $item))];
}, ['menu']);

wbmcp_tool('delete_menu_item', 'Remove one item from a nav menu.', [
    'item_id' => ['type' => 'integer'],
], function ($a) {
    if (!current_user_can('edit_theme_options')) wbmcp_fail('You are not allowed to edit menus.');
    $p = get_post((int) ($a['item_id'] ?? 0));
    if (!$p || $p->post_type !== 'nav_menu_item') wbmcp_fail('Not a menu item.');
    wp_delete_post($p->ID, true);
    return ['deleted' => $p->ID];
}, ['item_id']);
