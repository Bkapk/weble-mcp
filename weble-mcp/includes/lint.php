<?php
defined('ABSPATH') || exit;

/* ---------- Color helpers (pure) ---------- */

/** [r, g, b, a] from #rgb, #rgba, #rrggbb, #rrggbbaa, rgb() or rgba(); null for anything else. */
function wbmcp_rgba($v) {
    $v = strtolower(trim((string) $v));
    if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v, $m)) {
        $h = strlen($m[1]) <= 4 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), strlen($h) === 8 ? round(hexdec(substr($h, 6, 2)) / 255, 3) : 1];
    }
    if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,\/]+([\d.]+)(%?))?\s*\)$/', $v, $m)) {
        $a = isset($m[4]) ? (float) $m[4] / (($m[5] ?? '') === '%' ? 100 : 1) : 1;
        return [(int) $m[1], (int) $m[2], (int) $m[3], round($a, 3)];
    }
    return null;
}

/** Canonical #rrggbb, with an alpha suffix only when translucent. */
function wbmcp_hex(array $c) {
    return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]) . ($c[3] < 1 ? sprintf('%02x', round($c[3] * 255)) : '');
}

/** WCAG contrast ratio of two opaque colors (1 to 21). */
function wbmcp_contrast(array $a, array $b) {
    $lum = function (array $c) {
        $ch = array_map(function ($x) { $x /= 255; return $x <= 0.03928 ? $x / 12.92 : pow(($x + 0.055) / 1.055, 2.4); }, array_slice($c, 0, 3));
        return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
    };
    $l = [$lum($a), $lum($b)];
    return (max($l) + 0.05) / (min($l) + 0.05);
}

/** Pixels of an Elementor size value; em/rem count as 16px, fluid units (vw, %, clamp) as 0. */
function wbmcp_px($v) {
    if (!is_array($v) || !is_numeric($v['size'] ?? '')) return 0;
    $u = $v['unit'] ?? 'px';
    return $u === 'px' ? (float) $v['size'] : (in_array($u, ['em', 'rem'], true) ? 16 * (float) $v['size'] : 0);
}

/** Id of the kit color closest to $rgba. */
function wbmcp_nearest(array $rgba, array $colors) {
    $best = null;
    $dist = INF;
    foreach ($colors as $id => $c) {
        $d = ($c['rgba'][0] - $rgba[0]) ** 2 + ($c['rgba'][1] - $rgba[1]) ** 2 + ($c['rgba'][2] - $rgba[2]) ** 2;
        if ($d < $dist) [$dist, $best] = [$d, $id];
    }
    return $best;
}

/* ---------- Kit ---------- */

function wbmcp_kit() {
    return did_action('elementor/loaded') ? (get_post_meta((int) get_option('elementor_active_kit'), '_elementor_page_settings', true) ?: []) : [];
}

/** Kit colors by global id: [id => [title, rgba]]. */
function wbmcp_kit_colors(array $kit) {
    $out = [];
    foreach (array_merge($kit['system_colors'] ?? [], $kit['custom_colors'] ?? []) as $c) {
        if (!empty($c['_id']) && ($rgba = wbmcp_rgba($c['color'] ?? ''))) $out[$c['_id']] = ['title' => $c['title'] ?? $c['_id'], 'rgba' => $rgba];
    }
    return $out;
}

/** Lowercased font families the kit defines, in global typography and theme styles. */
function wbmcp_kit_fonts(array $kit) {
    $out = [];
    array_walk_recursive($kit, function ($v, $k) use (&$out) {
        if (is_string($k) && substr($k, -11) === 'font_family' && is_string($v) && $v !== '') $out[strtolower($v)] = true;
    });
    return $out;
}

/* ---------- Lint ---------- */

/**
 * Collect issues in an element tree into $ctx['issues'] and return the tree, with kit-color fixes applied when $ctx['fix'].
 * $ctx: colors (wbmcp_kit_colors), palette (hex => id), fonts (wbmcp_kit_fonts), fix, global_ok(element, key), alt(attachment id).
 */
function wbmcp_lint_walk(array $els, array &$ctx, $bg = null) {
    foreach ($els as &$e) {
        $s = (array) ($e['settings'] ?? []);
        $id = '#' . ($e['id'] ?? '?');
        $wt = ($e['elType'] ?? '') === 'widget' ? (string) ($e['widgetType'] ?? '') : '';
        $box = in_array($e['elType'] ?? '', ['container', 'section', 'column'], true);
        $g = (array) ($s['__globals__'] ?? []);
        $dyn = (array) ($s['__dynamic__'] ?? []);
        $warn = function ($m) use (&$ctx, $id) { $ctx['issues'][] = "WARN $id $m"; };
        $note = function ($m) use (&$ctx, $id) { $ctx['issues'][] = "NOTE $id $m"; };

        foreach ($s as $k => $v) {
            $k = (string) $k;
            // Hardcoded colors: an exact kit color becomes its global (fixable), anything else is off-palette.
            if (strpos($k, 'color') !== false && is_string($v) && empty($g[$k]) && empty($dyn[$k]) && ($rgba = wbmcp_rgba($v))) {
                $gid = $ctx['palette'][wbmcp_hex($rgba)] ?? null;
                if ($gid === null) {
                    $ctx['off'][wbmcp_hex($rgba)][] = $id;
                } elseif (($ctx['global_ok'])($e, $k)) {
                    $ctx['kit_hard'][] = "$id $k";
                    if ($ctx['fix']) {
                        unset($s[$k]);
                        $s['__globals__'][$k] = "globals/colors?id=$gid";
                    }
                }
            } elseif (substr($k, -11) === 'font_family' && is_string($v) && $v !== '' && empty($g[substr($k, 0, -11) . 'typography']) && !isset($ctx['fonts'][strtolower($v)])) {
                $note("font \"$v\" is not in the kit");
            } elseif (substr($k, -9) === 'font_size' && ($px = wbmcp_px($v)) >= 40 && empty($g[substr($k, 0, -9) . 'typography']) && empty($s[$k . '_mobile']['size'])) {
                $warn("{$px}px text has no phone size → set {$k}_mobile");
            } elseif (in_array($k, ['padding', '_padding'], true) && is_array($v) && empty($s[$k . '_mobile'])) {
                $side = max(wbmcp_px(['size' => $v['left'] ?? '', 'unit' => $v['unit'] ?? 'px']), wbmcp_px(['size' => $v['right'] ?? '', 'unit' => $v['unit'] ?? 'px']));
                if ($side >= 60) $warn("{$side}px side padding is not reduced on phones → set {$k}_mobile");
            } elseif (in_array($k, ['width', '_element_custom_width'], true) && ($px = wbmcp_px($v)) > 500 && empty($s[$k . '_mobile']['size'])) {
                $note("fixed {$px}px width may overflow phones → set {$k}_mobile");
            }
        }

        if (($e['elType'] ?? '') === 'container' && in_array($s['flex_direction'] ?? '', ['row', 'row-reverse'], true)) {
            $cols = count(array_filter($e['elements'] ?? [], function ($x) { return ($x['elType'] ?? '') === 'container'; }));
            if ($cols >= 2 && empty($s['flex_direction_mobile']) && empty($s['flex_direction_tablet']) && ($s['flex_wrap'] ?? '') !== 'wrap' && ($s['flex_wrap_mobile'] ?? '') !== 'wrap') {
                $warn("row of $cols columns stays side by side on phones → flex_direction_mobile \"column\"" . ($cols >= 3 ? ' (and check tablet)' : ''));
            }
        }
        if ($wt === 'heading') $ctx['headings'][] = [(int) substr((string) ($s['header_size'] ?? 'h2'), 1), $id];
        if ($wt === 'image' && empty($dyn['image']) && !empty($s['image']['id']) && ($ctx['alt'])((int) $s['image']['id']) === '') {
            $warn('image has no alt text → update_post id=' . (int) $s['image']['id'] . ' meta {"_wp_attachment_image_alt":"…"}');
        }
        if ($wt === 'button' && empty($s['link']['url']) && empty($dyn['link'])) $warn('button has no link');
        if ($box && empty($e['elements']) && empty($s['background_background'])) $note('empty ' . $e['elType']);

        // Contrast: resolved text color against the nearest solid background. Images and gradients make it unknown.
        $col = function ($k) use ($s, $ctx) {
            if (!empty($s['__globals__'][$k])) return $ctx['colors'][wbmcp_gid($s['__globals__'][$k])]['rgba'] ?? null;
            return is_string($s[$k] ?? null) ? wbmcp_rgba($s[$k]) : null;
        };
        $pfx = $box ? 'background_' : '_background_';
        $bb = $s[$pfx . 'background'] ?? '';
        $here = $bg;
        if ($bb === 'classic' && empty($s[$pfx . 'image']['url']) && ($c = $col($pfx . 'color')) && $c[3] >= 0.99) $here = $c;
        elseif ($bb !== '') $here = null;
        $pairs = $wt === 'button' ? ['button_text_color' => [4.5, $col('background_color')]] : ($wt ? ['title_color' => [3, $here], 'text_color' => [4.5, $here], 'description_color' => [4.5, $here]] : []);
        foreach ($pairs as $k => [$min, $back]) {
            $fg = $col($k);
            if ($fg && $back && $fg[3] >= 0.99 && $back[3] >= 0.99 && ($r = wbmcp_contrast($fg, $back)) < $min) {
                $warn(sprintf('%s %s on %s has %.1f:1 contrast, needs %s:1', $k, wbmcp_hex($fg), wbmcp_hex($back), $r, $min));
            }
        }

        if (!empty($e['elements'])) $e['elements'] = wbmcp_lint_walk($e['elements'], $ctx, $here);
        $e['settings'] = $s;
    }
    unset($e);
    return $els;
}

/** Page-level checks after the walk, then the report. */
function wbmcp_lint_report(array $ctx, $is_template) {
    $out = [];
    $h = array_values(array_filter($ctx['headings'], function ($x) { return $x[0] >= 1 && $x[0] <= 6; }));
    $h1 = array_column(array_filter($h, function ($x) { return $x[0] === 1; }), 1);
    if (count($h1) > 1) $out[] = 'WARN ' . implode(' ', $h1) . ': ' . count($h1) . ' h1 headings, a page should have one';
    if (!$h1 && !$is_template) $out[] = 'NOTE no h1 heading: fine only if the theme prints the page title as h1';
    $prev = 0;
    foreach ($h as [$lvl, $hid]) {
        if ($prev && $lvl > $prev + 1) $out[] = "NOTE $hid h$prev → h$lvl skips a heading level";
        $prev = $lvl;
    }
    foreach ($ctx['off'] as $hex => $ids) {
        $near = wbmcp_nearest(wbmcp_rgba($hex), $ctx['colors']);
        $out[] = "NOTE off-palette $hex ×" . count($ids) . ' (' . implode(' ', array_slice(array_unique($ids), 0, 5)) . ')' . ($near ? ", nearest kit color: $near " . wbmcp_hex($ctx['colors'][$near]['rgba']) : '') . '. Use a global, or add the color to the kit if it is intended.';
    }
    $all = array_merge($ctx['issues'], $out);
    $sorted = array_merge(preg_grep('/^WARN/', $all), preg_grep('/^NOTE/', $all));
    $head = count(preg_grep('/^WARN/', $all)) . ' warnings, ' . count(preg_grep('/^NOTE/', $all)) . ' notes.';
    if ($ctx['kit_hard']) {
        $head .= $ctx['fix']
            ? ' FIXED: ' . count($ctx['kit_hard']) . ' hardcoded colors that equal a kit color now use the global (elementor_undo reverts).'
            : ' FIXABLE: ' . count($ctx['kit_hard']) . ' hardcoded colors equal a kit color (' . implode(', ', array_slice($ctx['kit_hard'], 0, 6)) . '). Run elementor_lint with fix=true to switch them to globals.';
    }
    return $head . "\n" . implode("\n", array_slice($sorted, 0, 60)) . (count($sorted) > 60 ? "\n… " . (count($sorted) - 60) . ' more' : '');
}

wbmcp_tool('elementor_lint', 'Check one Elementor page or template for problems that break on phones (rows that never stack, big text or padding without mobile values, fixed widths), styling outside the kit (hardcoded or off-palette colors, fonts not in the kit), low text contrast, heading order, missing alt text and dead buttons. fix=true applies the one safe auto-fix: hardcoded colors that exactly equal a kit color become that global. It is snapshotted, so elementor_undo reverts it.', [
    'post_id' => ['type' => 'integer'],
    'fix' => ['type' => 'boolean'],
], function ($a) {
    $el = wbmcp_el();
    $p = wbmcp_post($a['post_id'] ?? 0, 'read_post');
    $els = wbmcp_el_read($p->ID);
    if (!$els) return 'No Elementor content on this post.';
    $colors = wbmcp_kit_colors(wbmcp_kit());
    $palette = [];
    foreach ($colors as $gid => $c) $palette[wbmcp_hex($c['rgba'])] = $palette[wbmcp_hex($c['rgba'])] ?? $gid;
    $controls = [];
    $ctx = [
        'colors' => $colors, 'palette' => $palette, 'fonts' => wbmcp_kit_fonts(wbmcp_kit()), 'fix' => !empty($a['fix']),
        'issues' => [], 'off' => [], 'kit_hard' => [], 'headings' => [],
        // Only color controls that accept globals can be switched; Elementor would ignore a global elsewhere.
        'global_ok' => function ($e, $k) use ($el, &$controls) {
            $w = ($e['elType'] ?? '') === 'widget';
            $type = $w ? ($e['widgetType'] ?? '') : ($e['elType'] ?? '');
            if (!isset($controls[$type])) {
                $obj = $w ? $el->widgets_manager->get_widget_types($type) : $el->elements_manager->get_element_types($type);
                $controls[$type] = $obj ? $obj->get_controls() : [];
            }
            $c = $controls[$type][$k] ?? [];
            $gl = $c['global'] ?? [];
            return ($c['type'] ?? '') === 'color' && $gl !== false && ($gl['active'] ?? true) !== false;
        },
        'alt' => function ($att) { return get_post($att) ? (string) get_post_meta($att, '_wp_attachment_image_alt', true) : null; },
    ];
    $els = wbmcp_lint_walk($els, $ctx);
    if ($ctx['fix'] && $ctx['kit_hard']) {
        if (!current_user_can('edit_post', $p->ID)) wbmcp_fail("You are not allowed to edit post $p->ID.");
        wbmcp_el_save($p->ID, $els);
    }
    return "Lint of #$p->ID \"$p->post_title\": " . wbmcp_lint_report($ctx, $p->post_type === 'elementor_library');
}, ['post_id']);
