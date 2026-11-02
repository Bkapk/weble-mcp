<?php
defined('ABSPATH') || exit;

function wbmcp_el() {
    if (!did_action('elementor/loaded')) wbmcp_fail('Elementor is not active on this site.');
    return \Elementor\Plugin::$instance;
}

function wbmcp_short($v, $n = 70) {
    $v = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $v)));
    return mb_strlen($v) > $n ? mb_substr($v, 0, $n) . '…' : $v;
}

/** The id inside a global reference such as "globals/colors?id=primary". */
function wbmcp_gid($ref) {
    return preg_match('/[?&]id=([\w-]+)/', (string) $ref, $m) ? $m[1] : '';
}

/** Readable Elementor value: size {unit,size}, dimensions {top,right,bottom,left}, gaps {row,column} or a scalar. */
function wbmcp_val($v) {
    if (!is_array($v)) return is_scalar($v) ? (string) $v : '';
    $u = $v['unit'] ?? 'px';
    if (($v['size'] ?? '') !== '') return $v['size'] . $u;
    if (isset($v['top'])) {
        $d = [(string) $v['top'], (string) ($v['right'] ?? ''), (string) ($v['bottom'] ?? ''), (string) ($v['left'] ?? '')];
        if (implode('', $d) === '') return '';
        return (count(array_unique($d)) === 1 ? $d[0] : implode(' ', $d)) . $u;
    }
    if (($v['column'] ?? '') !== '') return (($v['row'] ?? $v['column']) == $v['column'] ? '' : $v['row'] . '/') . $v['column'] . $u;
    return '';
}

/** Keep the last 5 values of a meta key so elementor_undo can restore them. */
function wbmcp_snapshot($id, $key) {
    $snaps = get_post_meta($id, '_wbmcp_snapshots', true) ?: [];
    $snaps[] = ['time' => current_time('mysql'), 'key' => $key, 'value' => get_post_meta($id, $key, true)];
    update_post_meta($id, '_wbmcp_snapshots', wp_slash(array_slice($snaps, -5)));
}

/* ---------- Element tree helpers (pure) ---------- */

/** Replace the element with $id by the elements $fn returns (zero or more). */
function wbmcp_el_walk(array $els, $id, callable $fn, &$found = false) {
    $out = [];
    foreach ($els as $el) {
        if (!$found && (string) ($el['id'] ?? '') === (string) $id) {
            $found = true;
            array_push($out, ...$fn($el));
            continue;
        }
        if (!empty($el['elements'])) $el['elements'] = wbmcp_el_walk($el['elements'], $id, $fn, $found);
        $out[] = $el;
    }
    return $out;
}

function wbmcp_el_find(array $els, $id) {
    $hit = null;
    $found = false;
    wbmcp_el_walk($els, $id, function ($e) use (&$hit) { $hit = $e; return [$e]; }, $found);
    if (!$found) wbmcp_fail("Element '$id' not found.");
    return $hit;
}

/** Insert $new before/after an anchor id, or into parent (null = root) at position (default end). */
function wbmcp_el_place(array $els, array $op, array $new) {
    foreach (['before', 'after'] as $side) {
        if (empty($op[$side])) continue;
        $found = false;
        $els = wbmcp_el_walk($els, $op[$side], function ($e) use ($side, $new) {
            return $side === 'before' ? array_merge($new, [$e]) : array_merge([$e], $new);
        }, $found);
        if (!$found) wbmcp_fail("Anchor element '{$op[$side]}' not found.");
        return $els;
    }
    $splice = function (array $list) use ($op, $new) {
        $pos = isset($op['position']) ? max(0, min((int) $op['position'], count($list))) : count($list);
        array_splice($list, $pos, 0, $new);
        return $list;
    };
    if (empty($op['parent'])) return $splice($els);
    $found = false;
    $els = wbmcp_el_walk($els, $op['parent'], function ($e) use ($splice) {
        $e['elements'] = $splice($e['elements'] ?? []);
        return [$e];
    }, $found);
    if (!$found) wbmcp_fail("Parent element '{$op['parent']}' not found.");
    return $els;
}

function wbmcp_el_strip_ids(array $e) {
    unset($e['id']);
    $e['elements'] = array_map('wbmcp_el_strip_ids', $e['elements'] ?? []);
    return $e;
}

/** Merge settings; null deletes. __globals__ and __dynamic__ merge per key, and a plain value drops a global or dynamic tag on the same key, since those would win over it. */
function wbmcp_el_merge(array $s, array $new) {
    foreach ($new as $k => $v) {
        if ($k === '__globals__' || $k === '__dynamic__') {
            foreach ((array) $v as $gk => $gv) {
                if ($gv === null) unset($s[$k][$gk]);
                else $s[$k][$gk] = $gv;
            }
        } elseif ($v === null) {
            unset($s[$k]);
        } else {
            $s[$k] = $v;
            foreach (['__globals__', '__dynamic__'] as $m) {
                if (!isset($new[$m][$k])) unset($s[$m][$k]);
            }
        }
    }
    return $s;
}

/** Elementor's stored form of a dynamic tag: a shortcode whose settings are URL-encoded JSON. */
function wbmcp_el_tag_text($name, array $settings, $id) {
    return sprintf('[elementor-tag id="%s" name="%s" settings="%s"]', $id, $name, urlencode(wp_json_encode((object) $settings)));
}

/** Compact, token-cheap tree: type, id and the human-readable bits of each element. */
function wbmcp_el_outline(array $els, $depth = 0) {
    $out = '';
    foreach ($els as $e) {
        $s = (array) ($e['settings'] ?? []);
        $bits = [];
        foreach ($s as $k => $v) {
            if (strpos((string) $k, '_') === 0 || count($bits) >= 3) continue;
            if (is_string($v) && $v !== '' && preg_match('/(^|_)(title|text|editor|description|content|heading|html|label|caption|name|price)$/', $k)) {
                $bits[] = $k . '="' . wbmcp_short($v) . '"';
            } elseif (is_array($v) && isset($v[0]) && is_array($v[0])) {
                $bits[] = $k . '[' . count($v) . ']';
            }
        }
        foreach (['link', 'image', 'background_image'] as $k) {
            if (!empty($s[$k]['url'])) $bits[] = "$k=" . $s[$k]['url'];
        }
        if (!empty($s['flex_direction'])) $bits[] = 'dir=' . $s['flex_direction'] . (empty($s['flex_direction_mobile']) ? '' : '/mobile:' . $s['flex_direction_mobile']);
        $cls = $s['_css_classes'] ?? $s['css_classes'] ?? '';
        if ($cls) $bits[] = '.' . str_replace(' ', '.', trim($cls));
        if (!empty($s['__globals__'])) $bits[] = 'globals=' . count($s['__globals__']);
        foreach ((array) ($s['__dynamic__'] ?? []) as $k => $d) {
            if (is_string($d) && preg_match('/name="([\w-]+)"/', $d, $m)) $bits[] = "$k=@" . $m[1];
        }
        if ($n = count(preg_grep('/^(?!hide_).+_(tablet|mobile)$/', array_keys($s)))) $bits[] = "responsive=$n";
        $hidden = array_filter(['desktop', 'tablet', 'mobile'], function ($d) use ($s) { return !empty($s["hide_$d"]); });
        if ($hidden) $bits[] = 'hidden:' . implode(',', $hidden);
        $anim = $s['_animation'] ?? $s['animation'] ?? '';
        if (is_string($anim) && $anim !== '' && $anim !== 'none') $bits[] = "anim=$anim";
        if (!empty($s['custom_css'])) $bits[] = 'custom_css';
        $label = ($e['elType'] ?? '') === 'widget' ? ($e['widgetType'] ?? '?') : ($e['elType'] ?? '?');
        $out .= str_repeat('  ', $depth) . "- $label #" . ($e['id'] ?? '?') . ($bits ? ' ' . implode(' ', $bits) : '') . "\n";
        $out .= wbmcp_el_outline($e['elements'] ?? [], $depth + 1);
    }
    return $out;
}

/** Widget types used inside a subtree, in order of first appearance. */
function wbmcp_el_types(array $els, array &$acc = []) {
    foreach ($els as $e) {
        if (($e['elType'] ?? '') === 'widget') $acc[$e['widgetType'] ?? '?'] = true;
        wbmcp_el_types($e['elements'] ?? [], $acc);
    }
    return array_keys($acc);
}

/* ---------- Read / validate / save ---------- */

function wbmcp_el_read($id) {
    wbmcp_el();
    $p = wbmcp_post($id, 'read_post');
    $raw = get_post_meta($p->ID, '_elementor_data', true);
    $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/** Encode friendly dynamic tags {"tag":"acf-text","settings":{...}}; strings are already encoded and pass through. */
function wbmcp_el_dynamic(array $dyn) {
    $tags = null;
    foreach ($dyn as $k => $d) {
        if (!is_array($d)) continue;
        $tags = $tags ?? wbmcp_el()->dynamic_tags->get_tags();
        $name = (string) ($d['tag'] ?? '');
        if (!isset($tags[$name])) wbmcp_fail("Unknown dynamic tag '$name' on '$k'. List them with elementor_dynamic_tags.");
        $dyn[$k] = wbmcp_el_tag_text($name, (array) ($d['settings'] ?? []), substr(bin2hex(random_bytes(4)), 0, 7));
    }
    return $dyn;
}

/** Validate types, give every element a unique id, set isInner by depth, encode dynamic tags. */
function wbmcp_el_normalize(array $els, array &$seen, $depth = 0) {
    $el = wbmcp_el();
    $out = [];
    foreach ($els as $e) {
        if (!is_array($e) || empty($e['elType'])) wbmcp_fail('Every element needs elType (container, section, column or widget).');
        if ($e['elType'] === 'widget') {
            if (empty($e['widgetType']) || !$el->widgets_manager->get_widget_types($e['widgetType'])) {
                wbmcp_fail("Unknown widgetType '" . ($e['widgetType'] ?? '') . "'. List widgets with elementor_widget_schema.");
            }
        } elseif (!$el->elements_manager->get_element_types($e['elType'])) {
            wbmcp_fail("Unknown elType '{$e['elType']}'." . ($e['elType'] === 'container' ? ' Flexbox Container is disabled on this site: use section > column.' : ''));
        }
        $id = (string) ($e['id'] ?? '');
        while ($id === '' || isset($seen[$id])) $id = substr(bin2hex(random_bytes(4)), 0, 7);
        $seen[$id] = true;
        $e['id'] = $id;
        $e['settings'] = $e['settings'] ?? [];
        if (!empty($e['settings']['__dynamic__'])) $e['settings']['__dynamic__'] = wbmcp_el_dynamic((array) $e['settings']['__dynamic__']);
        if (in_array($e['elType'], ['container', 'section'], true)) $e['isInner'] = $depth > 0;
        $e['elements'] = wbmcp_el_normalize($e['elements'] ?? [], $seen, $depth + 1);
        $out[] = $e;
    }
    return $out;
}

/** Save a full layout through Elementor's document API (sanitizing, CSS regeneration, version meta). */
function wbmcp_el_save($id, array $elements, $page_settings = null) {
    $el = wbmcp_el();
    $p = wbmcp_post($id);
    $doc = $el->documents->get($p->ID, false);
    if (!$doc || !$doc->is_editable_by_current_user()) {
        wbmcp_fail("Elementor can't edit post $p->ID. Is the '$p->post_type' post type enabled in Elementor > Settings?");
    }
    $seen = [];
    $data = ['elements' => wbmcp_el_normalize($elements, $seen)];
    if ($page_settings) {
        $cur = get_post_meta($p->ID, '_elementor_page_settings', true) ?: [];
        $data['settings'] = wbmcp_el_merge($cur, (array) $page_settings);
    }
    wbmcp_snapshot($p->ID, '_elementor_data');
    $doc->set_is_built_with_elementor(true);
    if (!$doc->save($data)) wbmcp_fail('Elementor refused to save the document.');
    wp_update_post(['ID' => $p->ID]); // bumps modified and fires save_post, so page caches purge as after an editor save
    return $data['elements'];
}

/* ---------- Design system ---------- */

wbmcp_tool('get_design_system', 'Full raw design system: Elementor kit (system/custom colors and typography, layout, buttons, forms, body/heading styles, custom CSS), theme.json palette and fonts for block themes, and Customizer additional CSS. get_site_brief has a readable summary; use this before update_design_system.', [], function () {
    $out = [];
    if (did_action('elementor/loaded')) {
        $kit_id = (int) get_option('elementor_active_kit');
        $out['elementor_kit'] = ['kit_id' => $kit_id] + (get_post_meta($kit_id, '_elementor_page_settings', true) ?: []);
        $out['elementor_notes'] = 'Only saved values appear; anything missing uses Elementor defaults. CSS vars: --e-global-color-<_id>, --e-global-typography-<_id>-font-family|font-size|font-weight|line-height.';
    }
    if (function_exists('wp_theme_has_theme_json') && wp_theme_has_theme_json()) {
        $g = wp_get_global_settings();
        $out['theme_json'] = [
            'palette' => $g['color']['palette']['theme'] ?? [],
            'font_families' => $g['typography']['fontFamilies']['theme'] ?? [],
            'font_sizes' => $g['typography']['fontSizes']['theme'] ?? [],
            'layout' => $g['layout'] ?? [],
        ];
    }
    $out['customizer_css'] = wp_get_custom_css() ?: null;
    return $out;
});

wbmcp_tool('update_design_system', 'Change global design. elementor_kit: kit settings merged at top level (arrays such as system_colors, custom_colors, system_typography, custom_typography are replaced whole, so send the full list; null deletes a key). customizer_css replaces the whole Customizer "Additional CSS", including blocks set_custom_css manages: for page or site CSS use set_custom_css instead. The kit is snapshotted: elementor_undo with the kit_id reverts.', [
    'elementor_kit' => ['type' => 'object'],
    'customizer_css' => ['type' => 'string'],
], function ($a) {
    $out = [];
    if (isset($a['elementor_kit'])) {
        if (!current_user_can('manage_options')) wbmcp_fail('Changing the global kit requires an administrator.');
        $kit = wbmcp_el()->kits_manager->get_active_kit();
        $id = $kit->get_main_id();
        $settings = wbmcp_el_merge(get_post_meta($id, '_elementor_page_settings', true) ?: [], (array) $a['elementor_kit']);
        wbmcp_snapshot($id, '_elementor_page_settings');
        // Kit::save runs Elementor's own tab hooks and clears all generated CSS.
        if (!$kit->save(['settings' => $settings + ['post_status' => 'publish']])) wbmcp_fail('Elementor refused to save the kit.');
        $out['kit_id'] = $id;
    }
    if (isset($a['customizer_css'])) {
        if (!current_user_can('edit_css')) wbmcp_fail('You are not allowed to edit CSS.');
        wbmcp_check(wp_update_custom_css_post($a['customizer_css']));
        $out['customizer_css'] = 'updated';
    }
    if (!$out) wbmcp_fail('Nothing to update.');
    return $out;
});

/* ---------- Custom CSS: Elementor Pro's Custom CSS fields, or a marked block in Additional CSS ---------- */

/** Elementor Pro adds Custom CSS to page settings, site settings (the kit) and every element. */
function wbmcp_el_has_custom_css() {
    return did_action('elementor/loaded') && class_exists('\ElementorPro\Modules\CustomCss\Module');
}

/** Replace one marked block in Additional CSS ('' removes it) and return the block's previous CSS. Blocks for other pages are left alone. */
function wbmcp_css_block($tag, $label, $css) {
    if (!current_user_can('edit_css')) wbmcp_fail('Your account cannot edit Additional CSS on this site. Ask an administrator to make this change.');
    $all = wp_get_custom_css();
    $re = '#\s*/\* weble-mcp:' . preg_quote($tag, '#') . ' start[^*]*\*/\n?(.*?)\n?/\* weble-mcp:' . preg_quote($tag, '#') . ' end \*/#s';
    $prev = preg_match($re, $all, $m) ? $m[1] : '';
    $all = trim(preg_replace($re, '', $all));
    if ($css !== '') {
        $label = str_replace('*', '', $label);
        $all = ltrim($all . "\n\n/* weble-mcp:$tag start: $label. Managed by Weble MCP. */\n$css\n/* weble-mcp:$tag end */");
    }
    wbmcp_check(wp_update_custom_css_post($all));
    return $prev;
}

wbmcp_tool('set_custom_css', 'Set the custom CSS of one page or template (post_id), or of the whole site (scope=site). Replaces what was stored there before; "" removes it. Write "selector" for the page wrapper and target elements by the _css_classes you set on them (e.g. "selector .wb-hero__title { ... }"); use _element_id only for anchors. With Elementor Pro it goes in the page\'s Advanced > Custom CSS (site: Site Settings > Custom CSS). Without Pro, or on a page not built with Elementor, it goes in its own marked block of Appearance > Customize > Additional CSS, scoped to that page. Returns where it went and the previous CSS, so you can restore it.', [
    'post_id' => ['type' => 'integer'],
    'scope' => ['type' => 'string', 'description' => 'page (default, needs post_id) | site'],
    'css' => ['type' => 'string'],
], function ($a) {
    $css = trim((string) ($a['css'] ?? ''));
    if (preg_match('#<\s*/?\s*style#i', $css)) wbmcp_fail('Send plain CSS, without <style> tags.');
    if (strlen($css) > 100000) wbmcp_fail('That CSS is over 100 KB. Keep page CSS small and move shared styles to scope=site.');
    $pro = wbmcp_el_has_custom_css();

    if (($a['scope'] ?? 'page') === 'site') {
        if (!current_user_can('manage_options')) wbmcp_fail('Site-wide CSS requires an administrator.');
        if ($pro) {
            $kit = wbmcp_el()->kits_manager->get_active_kit();
            $id = $kit->get_main_id();
            $settings = get_post_meta($id, '_elementor_page_settings', true) ?: [];
            $prev = (string) ($settings['custom_css'] ?? '');
            $settings = wbmcp_el_merge($settings, ['custom_css' => $css === '' ? null : $css]);
            wbmcp_snapshot($id, '_elementor_page_settings');
            if (!$kit->save(['settings' => $settings + ['post_status' => 'publish']])) wbmcp_fail('Elementor refused to save the site settings.');
            return ['location' => 'Elementor > Site Settings > Custom CSS', 'live' => true, 'previous' => $prev, 'undo' => "elementor_undo post_id=$id"];
        }
        $prev = wbmcp_css_block('site', 'site-wide', preg_replace('/\bselector\b/', 'body', $css));
        return ['location' => 'Appearance > Customize > Additional CSS, block "weble-mcp:site"', 'live' => true, 'previous' => $prev];
    }

    $p = wbmcp_post($a['post_id'] ?? 0);
    $doc = did_action('elementor/loaded') ? wbmcp_el()->documents->get($p->ID, false) : null;
    $built = $doc && ($doc->is_built_with_elementor() || $p->post_type === 'elementor_library');
    $live = $p->post_status === 'publish';

    if ($pro && $built) {
        if (!$doc->is_editable_by_current_user()) wbmcp_fail("Elementor can't edit post $p->ID.");
        $settings = get_post_meta($p->ID, '_elementor_page_settings', true) ?: [];
        $prev = (string) ($settings['custom_css'] ?? '');
        $settings = wbmcp_el_merge($settings, ['custom_css' => $css === '' ? null : $css]);
        wbmcp_snapshot($p->ID, '_elementor_page_settings');
        if (!$doc->save(['settings' => $settings])) wbmcp_fail('Elementor refused to save the page settings.');
        wp_update_post(['ID' => $p->ID]);
        return ['location' => "Page settings > Advanced > Custom CSS of #$p->ID", 'live' => $live, 'previous' => $prev, 'undo' => "elementor_undo post_id=$p->ID"];
    }

    // Additional CSS loads on every page, so scope it: Elementor wraps a document in .elementor-<id>; other posts get WordPress body classes.
    $scope = $built ? ".elementor-$p->ID" : ($p->post_type === 'page' ? "body.page-id-$p->ID" : "body.postid-$p->ID");
    if ($css !== '' && strpos($css, 'selector') === false) wbmcp_fail("Start every rule with \"selector\" (it becomes $scope), so this page's CSS cannot leak onto other pages.");
    $prev = wbmcp_css_block("post-$p->ID", "#$p->ID " . wbmcp_short($p->post_title, 40), preg_replace('/\bselector\b/', $scope, $css));
    return [
        'location' => "Appearance > Customize > Additional CSS, block \"weble-mcp:post-$p->ID\"" . ($pro ? '' : ' (Custom CSS fields need Elementor Pro)'),
        'scoped_to' => $scope,
        'live' => $live,
        'note' => 'Additional CSS belongs to the active theme: it does not follow a theme switch.',
        'previous' => $prev,
    ];
}, ['css']);

/* ---------- Elementor: pages ---------- */

wbmcp_tool('elementor_get_content', 'Read the Elementor layout of any post, page or template. format=outline (default) gives a compact tree with element ids, responsive-override counts, hidden devices, dynamic tags and animations; format=json gives exact settings. Pass element_id to read one subtree.', [
    'post_id' => ['type' => 'integer'],
    'format' => ['type' => 'string', 'description' => 'outline | json'],
    'element_id' => ['type' => 'string'],
], function ($a) {
    $id = (int) ($a['post_id'] ?? 0);
    $els = wbmcp_el_read($id);
    if (!empty($a['element_id'])) $els = [wbmcp_el_find($els, $a['element_id'])];
    if (($a['format'] ?? 'outline') === 'json') {
        wbmcp_post($id); // raw settings include form recipients, webhooks and API keys: people who can edit only
        $out = ['elements' => $els];
        if (empty($a['element_id'])) $out['page_settings'] = get_post_meta($id, '_elementor_page_settings', true) ?: new stdClass();
        return $out;
    }
    return $els ? wbmcp_el_outline($els) : 'No Elementor content on this post.';
}, ['post_id']);

wbmcp_tool('elementor_set_content', 'Replace the whole Elementor layout of a post/page/template (turns a normal post into an Elementor page). Prefer elementor_edit for partial changes. Returns the outline with generated ids.', [
    'post_id' => ['type' => 'integer'],
    'elements' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Top-level elements'],
    'page_settings' => ['type' => 'object', 'description' => 'Elementor page settings to merge, e.g. {"hide_title":"yes"}'],
], function ($a) {
    return wbmcp_el_outline(wbmcp_el_save((int) ($a['post_id'] ?? 0), (array) ($a['elements'] ?? []), $a['page_settings'] ?? null));
}, ['post_id', 'elements']);

wbmcp_tool('elementor_edit', 'Apply a batch of element operations to one Elementor document and save once. All-or-nothing. Ops:
{"op":"update","id":"abc1234","settings":{...}}  merge settings (null deletes a key; __globals__ and __dynamic__ merge per key)
{"op":"insert","element":{...} or [..],"parent":"id"|null,"position":0}  or with "before"/"after":"id" instead of parent. "template_id":123 inserts a saved template instead of element.
{"op":"delete","id":"..."}
{"op":"move","id":"...","parent"|"before"|"after":"...","position":n}
{"op":"duplicate","id":"..."}  copy placed right after the original
Returns the new outline.', [
    'post_id' => ['type' => 'integer'],
    'ops' => ['type' => 'array', 'items' => ['type' => 'object']],
], function ($a) {
    $id = (int) ($a['post_id'] ?? 0);
    $els = wbmcp_el_read($id);
    foreach ((array) ($a['ops'] ?? []) as $n => $op) {
        $target = (string) ($op['id'] ?? '');
        $found = false;
        switch ($op['op'] ?? '') {
            case 'update':
                $els = wbmcp_el_walk($els, $target, function ($e) use ($op) {
                    $e['settings'] = wbmcp_el_merge((array) ($e['settings'] ?? []), (array) ($op['settings'] ?? []));
                    return [$e];
                }, $found);
                break;
            case 'delete':
                $els = wbmcp_el_walk($els, $target, function () { return []; }, $found);
                break;
            case 'duplicate':
                $els = wbmcp_el_walk($els, $target, function ($e) { return [$e, wbmcp_el_strip_ids($e)]; }, $found);
                break;
            case 'move':
                $moved = null;
                $els = wbmcp_el_walk($els, $target, function ($e) use (&$moved) { $moved = $e; return []; }, $found);
                if ($found) $els = wbmcp_el_place($els, $op, [$moved]);
                break;
            case 'insert':
                if (!empty($op['template_id'])) $new = array_map('wbmcp_el_strip_ids', wbmcp_el_read($op['template_id']));
                else $new = isset($op['element']['elType']) ? [$op['element']] : (array) ($op['element'] ?? []);
                if (!$new) wbmcp_fail("ops[$n]: insert needs a non-empty element or template_id.");
                $els = wbmcp_el_place($els, $op, $new);
                $found = true;
                break;
            default:
                wbmcp_fail("ops[$n]: unknown op '" . ($op['op'] ?? '') . "'. Use update, insert, delete, move or duplicate.");
        }
        if (!$found) wbmcp_fail("ops[$n]: element '$target' not found. Nothing was saved.");
    }
    return wbmcp_el_outline(wbmcp_el_save($id, $els));
}, ['post_id', 'ops']);

wbmcp_tool('elementor_undo', 'Revert the last Elementor write on a post/template (layout) or on the kit id (design system). Up to 5 steps are kept per document.', [
    'post_id' => ['type' => 'integer'],
], function ($a) {
    $el = wbmcp_el();
    $p = wbmcp_post($a['post_id'] ?? 0);
    $snaps = get_post_meta($p->ID, '_wbmcp_snapshots', true) ?: [];
    $s = array_pop($snaps);
    if (!$s) wbmcp_fail('No snapshots for this post.');
    if (!in_array($s['key'] ?? '', ['_elementor_data', '_elementor_page_settings'], true)) wbmcp_fail('This snapshot is not one Weble MCP wrote, so it was not restored.');
    if ($s['value'] === '' || $s['value'] === []) {
        delete_post_meta($p->ID, $s['key']);
        if ($s['key'] === '_elementor_data') delete_post_meta($p->ID, '_elementor_edit_mode');
    } else {
        update_post_meta($p->ID, $s['key'], wp_slash($s['value']));
    }
    update_post_meta($p->ID, '_wbmcp_snapshots', wp_slash($snaps));
    $el->files_manager->clear_cache(); // ponytail: sitewide CSS regen (lazy); per-post clear if undo gets frequent on big sites
    wp_update_post(['ID' => $p->ID]);
    return ['restored' => $s['key'], 'state_from' => $s['time'], 'steps_left' => count($snaps)];
}, ['post_id']);

/* ---------- Elementor: templates and theme builder ---------- */

wbmcp_tool('elementor_list_templates', 'Saved Elementor templates (sections, containers, pages, headers, footers, singles, archives, popups, loop items) with their display conditions, plus the template types this site can create.', [
    'type' => ['type' => 'string', 'description' => 'Filter by template type'],
], function ($a) {
    $el = wbmcp_el();
    $q = ['post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => 200];
    if (!empty($a['type'])) $q['meta_query'] = [['key' => '_elementor_template_type', 'value' => $a['type']]];
    $out = [];
    foreach (get_posts($q) as $p) {
        $type = get_post_meta($p->ID, '_elementor_template_type', true);
        if ($type === 'kit') continue;
        $out[] = ['id' => $p->ID, 'title' => $p->post_title, 'type' => $type, 'status' => $p->post_status, 'conditions' => get_post_meta($p->ID, '_elementor_conditions', true) ?: [], 'modified' => $p->post_modified];
    }
    return ['templates' => $out, 'creatable_types' => array_keys($el->documents->get_document_types(['show_in_library' => true]))];
});

/** Save theme-builder display conditions ("include/singular/page/42") through Elementor Pro, which also rebuilds its conditions cache. */
function wbmcp_el_conditions($id, array $conditions) {
    if (!class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) wbmcp_fail('Display conditions need Elementor Pro.');
    // Conditions put a template on other people's pages (site-wide for include/general): theme-level power.
    if (!current_user_can('edit_theme_options')) wbmcp_fail('Changing where a template shows needs an administrator (edit_theme_options).');
    $tb = \ElementorPro\Modules\ThemeBuilder\Module::instance();
    if (!$tb->get_document($id)) wbmcp_fail("Template $id is not a theme-builder template (header, footer, single, archive, popup...), so it takes no conditions.");
    $rows = [];
    foreach ($conditions as $c) {
        if (!preg_match('#^(include|exclude)(/[\w-]+){0,3}$#', (string) $c)) wbmcp_fail("Bad condition '$c'. Use include|exclude/<name>/<sub_name>/<id>, e.g. include/general or include/singular/page/42.");
        $p = array_pad(explode('/', $c, 4), 4, '');
        $rows[] = ['type' => $p[0], 'name' => $p[1], 'sub_name' => $p[2], 'sub_id' => $p[3]];
    }
    $tb->get_conditions_manager()->save_conditions($id, $rows);
}

wbmcp_tool('elementor_create_template', 'Create a saved Elementor template, optionally with content and (Pro) display conditions such as "include/general", "include/singular/page/42", "include/singular/service", "exclude/archive". With conditions the template defaults to draft; it goes live only when status=publish.', [
    'title' => ['type' => 'string'],
    'type' => ['type' => 'string', 'description' => 'section, container, page, header, footer, single-post, archive, popup, loop-item ... (see elementor_list_templates)'],
    'elements' => ['type' => 'array', 'items' => ['type' => 'object']],
    'conditions' => ['type' => 'array', 'items' => ['type' => 'string']],
    'status' => ['type' => 'string', 'description' => 'publish | draft'],
], function ($a) {
    $el = wbmcp_el();
    $type = $a['type'] ?? '';
    $types = array_keys($el->documents->get_document_types(['show_in_library' => true]));
    if (!in_array($type, $types, true)) wbmcp_fail("Unknown template type '$type'. Available: " . implode(', ', $types));
    $caps = get_post_type_object('elementor_library')->cap;
    if (!current_user_can($caps->create_posts)) wbmcp_fail('You are not allowed to create templates.');
    if (!empty($a['conditions']) && !class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) wbmcp_fail('Display conditions need Elementor Pro.');
    if (!empty($a['conditions']) && !current_user_can('edit_theme_options')) wbmcp_fail('Templates with display conditions need an administrator (edit_theme_options).');
    $status = $a['status'] ?? (empty($a['conditions']) ? 'publish' : 'draft');
    if (!in_array($status, ['publish', 'draft'], true)) wbmcp_fail('status must be publish or draft.');
    if ($status === 'publish' && !current_user_can($caps->publish_posts)) wbmcp_fail('You are not allowed to publish templates.');
    $doc = wbmcp_check($el->documents->create($type, ['post_title' => $a['title'] ?? 'Untitled', 'post_status' => $status]));
    if (!$doc) wbmcp_fail('Elementor could not create the template.');
    $id = $doc->get_main_id();
    if (!empty($a['elements'])) wbmcp_el_save($id, (array) $a['elements']);
    if (!empty($a['conditions'])) wbmcp_el_conditions($id, (array) $a['conditions']);
    return ['id' => $id, 'type' => $type, 'status' => $status, 'edit_url' => admin_url("post.php?post=$id&action=elementor")];
}, ['title', 'type']);

wbmcp_tool('elementor_set_conditions', 'Replace where a theme-builder template (header, footer, single, archive, popup...) is shown. Elementor Pro. Conditions like "include/general", "include/singular/page/42", "include/singular/service", "exclude/archive"; [] removes them all. On a published template the change is live at once, so confirm with the user first. Returns the previous conditions so you can restore them.', [
    'template_id' => ['type' => 'integer'],
    'conditions' => ['type' => 'array', 'items' => ['type' => 'string']],
], function ($a) {
    wbmcp_el();
    $p = wbmcp_post($a['template_id'] ?? 0);
    if ($p->post_type !== 'elementor_library') wbmcp_fail("Post $p->ID is not an Elementor template.");
    $before = get_post_meta($p->ID, '_elementor_conditions', true) ?: [];
    wbmcp_el_conditions($p->ID, (array) ($a['conditions'] ?? []));
    return ['template_id' => $p->ID, 'status' => $p->post_status, 'previous' => $before, 'now' => get_post_meta($p->ID, '_elementor_conditions', true) ?: []];
}, ['template_id', 'conditions']);

/* ---------- Elementor: widget and dynamic tag reference ---------- */

/** One line per setting: key (control type), responsive flag, options, default. */
function wbmcp_controls_lines(array $controls, $search = '', $max_options = 120) {
    $ui_only = ['section', 'tab', 'tabs', 'heading', 'divider', 'raw_html', 'button', 'deprecated_notice', 'alert', 'notice'];
    $bp = '/_(mobile|mobile_extra|tablet|tablet_extra|laptop|widescreen)$/';
    $q = strtolower($search);
    $lines = [];
    foreach ($controls as $name => $c) {
        if (in_array($c['type'] ?? '', $ui_only, true) || preg_match($bp, $name) || ($q && strpos($name, $q) === false)) continue;
        $line = "$name ({$c['type']})";
        if (isset($controls[$name . '_tablet']) || isset($controls[$name . '_mobile'])) $line .= ' responsive';
        $opts = is_array($c['options'] ?? null) ? $c['options'] : [];
        foreach ((array) ($c['groups'] ?? []) as $g) $opts += (array) ($g['options'] ?? []); // grouped selects, e.g. ACF field keys
        if ($opts) $line .= ' options=' . wbmcp_short(implode('|', array_keys($opts)), $max_options);
        if (isset($c['default']) && $c['default'] !== '' && $c['default'] !== []) $line .= ' default=' . wbmcp_short(wp_json_encode($c['default']), 80);
        $lines[] = $line;
    }
    return $lines;
}

wbmcp_tool('elementor_widget_schema', 'Without name: every widget and layout element available on this site (core, Pro and third-party). With name: its setting keys, control types, options and defaults. Use search to narrow (e.g. "color", "typography", "link").', [
    'name' => ['type' => 'string', 'description' => 'widgetType (heading, button, ...) or elType (container, section, column)'],
    'search' => ['type' => 'string'],
], function ($a) {
    $el = wbmcp_el();
    if (empty($a['name'])) {
        $lines = [];
        foreach ($el->widgets_manager->get_widget_types() as $n => $w) $lines[] = "$n: " . $w->get_title();
        return 'Layout elTypes: ' . implode(', ', array_keys($el->elements_manager->get_element_types())) . "\nWidgets (widgetType: title):\n" . implode("\n", $lines);
    }
    $obj = $el->widgets_manager->get_widget_types($a['name']) ?: $el->elements_manager->get_element_types($a['name']);
    if (!$obj) wbmcp_fail("Unknown widget or element '{$a['name']}'.");
    return "Settings for '{$a['name']}': key (control type). Responsive keys also accept _tablet / _mobile suffixes. Global styles go in __globals__, dynamic values in __dynamic__.\n" . implode("\n", wbmcp_controls_lines($obj->get_controls(), $a['search'] ?? ''));
}, []);

wbmcp_tool('elementor_dynamic_tags', 'Dynamic tags on this site (Elementor Pro, ACF, add-ons). Without name: every tag with its group and the value categories it fills (text, url, image, color...). With name: its settings and allowed values; acf-* tags list every ACF field key. Use a tag in element settings as "__dynamic__": {"<setting>": {"tag":"<name>","settings":{...}}}; the server encodes it.', [
    'name' => ['type' => 'string'],
], function ($a) {
    $tags = wbmcp_el()->dynamic_tags->get_tags();
    if (!$tags) wbmcp_fail('No dynamic tags on this site. They come with Elementor Pro or an add-on.');
    if (empty($a['name'])) {
        $lines = [];
        foreach ($tags as $name => $t) {
            $i = $t['instance'];
            $lines[] = "$name: " . $i->get_title() . ' [' . implode(',', (array) $i->get_group()) . '] fills ' . implode(',', $i->get_categories());
        }
        return "Dynamic tags (name: title [group] fills categories). A tag fits a setting when the setting's control accepts one of its categories.\n" . implode("\n", $lines);
    }
    if (!isset($tags[$a['name']])) wbmcp_fail("Unknown dynamic tag '{$a['name']}'.");
    $lines = wbmcp_controls_lines($tags[$a['name']]['instance']->get_controls(), '', 3000);
    return "Settings for tag '{$a['name']}' (pass them as settings):\n" . ($lines ? implode("\n", $lines) : '(none)');
}, []);
