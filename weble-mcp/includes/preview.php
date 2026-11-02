<?php
defined('ABSPATH') || exit;

/* ---------- Signed preview links: a browser tool can see drafts without logging in ---------- */

function wbmcp_preview_sig($post_id, $exp) {
    return hash_hmac('sha256', "wbmcp-preview|$post_id|$exp", wp_salt('auth'));
}

/** Token is "<expiry>.<hmac>", bound to one post; null-safe for any input. */
function wbmcp_preview_valid($token, $post_id) {
    $parts = explode('.', (string) $token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) return false;
    return hash_equals(wbmcp_preview_sig((int) $post_id, (int) $parts[0]), $parts[1]);
}

wbmcp_tool('get_preview_url', 'A link that shows a page, post or CPT item (drafts included) to any browser without logging in, for screenshots. It expires after `minutes` (default 20, max 60), bypasses page caches, and shows entrance-animated elements in their final state (the body gets class wbmcp-preview). Screenshot it at 1440, 768 and 390 px wide. Theme templates (header, footer, single, archive) show on the pages they apply to; to check a saved section, insert it into a draft page.', [
    'post_id' => ['type' => 'integer'],
    'minutes' => ['type' => 'integer'],
], function ($a) {
    $p = wbmcp_post($a['post_id'] ?? 0); // edit_post: only people who can edit an item may share it
    if (in_array($p->post_type, ['attachment', 'elementor_library'], true) || !is_post_type_viewable($p->post_type) || $p->post_status === 'trash') {
        wbmcp_fail("Post $p->ID has no front-end page to preview. Preview a page the template applies to, or insert the section into a draft page.");
    }
    $exp = time() + 60 * min(60, max(1, (int) ($a['minutes'] ?? 20)));
    return [
        'url' => add_query_arg('wbmcp_preview', $exp . '.' . wbmcp_preview_sig($p->ID, $exp), get_preview_post_link($p)),
        'expires' => wp_date('Y-m-d H:i', $exp),
        'viewports' => ['desktop' => '1440x900', 'tablet' => '768x1024', 'phone' => '390x844'],
    ];
}, ['post_id']);

if (isset($_GET['wbmcp_preview']) && !is_admin()) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed, expiring token is checked in wbmcp_preview_posts
    add_filter('posts_results', 'wbmcp_preview_posts', 10, 2);
}

/** Main single-post query with a valid token: show the post even if unpublished, and serve it uncached. */
function wbmcp_preview_posts($posts, $q) {
    if (!$q->is_main_query()) return $posts;
    remove_filter('posts_results', 'wbmcp_preview_posts', 10);
    if (count($posts) !== 1 || !$q->is_singular() || $posts[0]->post_status === 'trash') return $posts;
    if (!wbmcp_preview_valid(sanitize_text_field(wp_unslash($_GET['wbmcp_preview'] ?? '')), $posts[0]->ID)) return $posts; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- HMAC token stands in for a nonce (agents have no session)

    if ($posts[0]->post_status !== 'publish') {
        $posts[0]->post_status = 'publish'; // in memory only, so WP_Query's status check lets it through
        add_filter('comments_open', '__return_false');
        add_filter('pings_open', '__return_false');
    }
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-plugin constant; honored by WP Rocket, LiteSpeed, W3TC, Super Cache...
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer'); // keep the token out of third-party logs
    add_filter('show_admin_bar', '__return_false');
    add_filter('wp_lazy_loading_enabled', '__return_false'); // full-page screenshots don't scroll
    add_filter('body_class', function ($c) { $c[] = 'wbmcp-preview'; return $c; });
    add_action('wp_head', function () {
        echo "<meta name=\"robots\" content=\"noindex, nofollow\">\n<style id=\"wbmcp-preview\">.elementor-invisible{visibility:visible!important}.elementor-element.animated{animation:none!important}</style>\n";
    }, 99);
    return $posts;
}
