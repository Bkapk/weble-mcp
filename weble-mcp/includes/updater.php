<?php
// Updates from GitHub Releases through WordPress's own Update URI hook (WP 5.8+). A release tagged vX.Y.Z with a
// weble-mcp.zip asset shows up as a normal plugin update, and the per-plugin auto-update toggle works with it.
// Left out of the wordpress.org build (php build-zip.php --wporg), where wordpress.org delivers updates itself.

defined('ABSPATH') || exit;

const WBMCP_REPO = 'Bkapk/weble-mcp';

/** The latest release as {version, package, url, notes, published, tested, checked}, cached for 12 hours (1 hour after a failure). */
function wbmcp_latest_release() {
    $cached = get_site_transient('wbmcp_release');
    if (is_array($cached)) return $cached['version'] ? $cached : null;

    $res = wp_remote_get('https://api.github.com/repos/' . WBMCP_REPO . '/releases/latest', [
        'timeout' => 8,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'weble-mcp/' . WBMCP_VERSION],
    ]);
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    $release = ['version' => '', 'checked' => time()];
    if (wp_remote_retrieve_response_code($res) === 200 && is_array($body) && empty($body['draft']) && empty($body['prerelease'])) {
        foreach ((array) ($body['assets'] ?? []) as $a) {
            // Only install packages GitHub serves from this repo's own releases.
            if (($a['name'] ?? '') === 'weble-mcp.zip' && strpos((string) ($a['browser_download_url'] ?? ''), 'https://github.com/' . WBMCP_REPO . '/releases/download/') === 0) {
                // "Tested up to" comes from the release's own readme, so the Updates screen doesn't say "Not tested".
                $readme = wp_remote_get('https://raw.githubusercontent.com/' . WBMCP_REPO . '/' . rawurlencode((string) $body['tag_name']) . '/weble-mcp/readme.txt', ['timeout' => 8]);
                preg_match('/^Tested up to:\s*([\d.]+)/mi', (string) wp_remote_retrieve_body($readme), $m);
                $release = [
                    'checked' => time(),
                    'tested' => $m[1] ?? '',
                    'version' => ltrim((string) $body['tag_name'], 'vV'),
                    'package' => (string) $a['browser_download_url'],
                    'url' => (string) $body['html_url'],
                    'notes' => (string) ($body['body'] ?? ''),
                    'published' => (string) ($body['published_at'] ?? ''),
                ];
            }
        }
    }
    set_site_transient('wbmcp_release', $release, $release['version'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $release['version'] ? $release : null;
}

/** Icon and banners for the Updates screen and the details box. Shipped in assets/brand, which the wordpress.org build leaves out. */
function wbmcp_brand() {
    $u = plugins_url('assets/brand/', dirname(__DIR__) . '/weble-mcp.php');
    return [
        'icons' => ['svg' => $u . 'icon.svg', '1x' => $u . 'icon-128x128.png', '2x' => $u . 'icon-256x256.png'],
        'banners' => ['low' => $u . 'banner-772x250.png', 'high' => $u . 'banner-1544x500.png'],
    ];
}

/** For the Settings screen: installed and latest version, when GitHub was last asked, and a one-click update link. */
function wbmcp_update_status() {
    $r = get_site_transient('wbmcp_release');
    $r = is_array($r) ? $r : [];
    $file = plugin_basename(dirname(__DIR__) . '/weble-mcp.php');
    $available = !empty($r['version']) && version_compare($r['version'], WBMCP_VERSION, '>');
    return [
        'current' => WBMCP_VERSION,
        'latest' => ($r['version'] ?? '') ?: null,
        'checked' => (int) ($r['checked'] ?? 0),
        'notes' => $r['url'] ?? null,
        'available' => $available,
        'update_url' => $available && current_user_can('update_plugins') ? wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($file)), 'upgrade-plugin_' . $file) : null,
    ];
}

// "Check for updates" on the Settings screen: ask GitHub now, then refresh WordPress's own update list to match.
add_action('rest_api_init', function () {
    register_rest_route('weble-mcp/v1', '/admin/update-check', ['methods' => 'POST', 'permission_callback' => 'wbmcp_admin_can', 'callback' => function () {
        delete_site_transient('wbmcp_release');
        wbmcp_latest_release();
        delete_site_transient('update_plugins');
        wp_update_plugins();
        return wbmcp_update_status();
    }]);
});

// WordPress asks this filter about plugins whose Update URI host is github.com.
add_filter('update_plugins_github.com', function ($update, $data, $file) {
    if ($file !== plugin_basename(dirname(__DIR__) . '/weble-mcp.php')) return $update;
    $r = wbmcp_latest_release();
    if (!$r) return $update;
    return [
        'slug' => 'weble-mcp',
        'version' => $r['version'],
        'url' => $r['url'],
        'package' => $r['package'],
        'requires' => $data['RequiresWP'] ?? '',
        'requires_php' => $data['RequiresPHP'] ?? '',
        'tested' => $r['tested'] ?? '',
    ] + wbmcp_brand();
}, 10, 3);

// The "View details" box on the Plugins screen.
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'weble-mcp') return $result;
    $r = wbmcp_latest_release();
    if (!$r) return $result;
    return (object) [
        'name' => 'Weble MCP for WordPress',
        'slug' => 'weble-mcp',
        'version' => $r['version'],
        'author' => '<a href="https://weble.studio/">Weble Studio</a>',
        'homepage' => 'https://github.com/' . WBMCP_REPO,
        'download_link' => $r['package'],
        'last_updated' => $r['published'],
        'requires' => '6.2',
        'requires_php' => '7.4',
        'tested' => $r['tested'] ?? '',
        'sections' => [
            'description' => '<p>' . esc_html__('Let your AI agent design straight into Elementor, in your site’s own style. Works with Claude Code, Codex, Cursor and any MCP client.', 'weble-mcp') . '</p>',
            'changelog' => '<h4>' . esc_html($r['version']) . '</h4>' . wpautop(esc_html($r['notes'])) . '<p><a href="' . esc_url($r['url']) . '">Full release notes on GitHub</a></p>',
        ],
    ] + wbmcp_brand();
}, 10, 3);

// A copy installed from a differently named folder (e.g. weble-mcp-main) keeps its folder, so it stays active after updating.
add_filter('upgrader_source_selection', function ($source, $remote, $upgrader, $extra = []) {
    $mine = plugin_basename(dirname(__DIR__) . '/weble-mcp.php');
    if (($extra['plugin'] ?? '') !== $mine || !is_dir($source)) return $source;
    $want = trailingslashit(dirname($source)) . dirname($mine) . '/';
    if (untrailingslashit($source) === untrailingslashit($want)) return $source;
    global $wp_filesystem;
    return $wp_filesystem && $wp_filesystem->move($source, $want, true) ? $want : $source;
}, 10, 4);

// A fresh check right after updating, so the notice clears.
add_action('upgrader_process_complete', function () { delete_site_transient('wbmcp_release'); });
