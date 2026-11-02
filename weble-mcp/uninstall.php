<?php
// Runs when the plugin is deleted. Connection passwords are always revoked (deactivation already did, this is the
// safety net). Settings, house rules and Elementor snapshots go only when "Delete plugin data" is on in Settings,
// off by default. On multisite that choice is read per site.

defined('WP_UNINSTALL_PLUGIN') || exit;

const WBMCP_APP_ID = '8f3c2a4e-5b1d-4c7e-9a6f-2d8b7e1c4a90';

// Application passwords live in user meta, which multisite shares across sites: one pass covers the network.
foreach (get_users(['blog_id' => 0, 'fields' => 'ID', 'meta_key' => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS]) as $uid) {
    foreach ((array) WP_Application_Passwords::get_user_application_passwords($uid) as $item) {
        if (($item['app_id'] ?? '') === WBMCP_APP_ID || strpos((string) ($item['name'] ?? ''), 'Claude MCP') === 0) {
            WP_Application_Passwords::delete_application_password($uid, $item['uuid']);
        }
    }
}
delete_site_transient('wbmcp_release');

function wbmcp_uninstall_site() {
    if (!get_option('wbmcp_delete_data')) return;
    foreach (['wbmcp_custom_code', 'wbmcp_house_rules', 'wbmcp_delete_data'] as $o) delete_option($o);
    delete_post_meta_by_key('_wbmcp_snapshots');
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog) {
        switch_to_blog($blog);
        wbmcp_uninstall_site();
        restore_current_blog();
    }
} else {
    wbmcp_uninstall_site();
}
