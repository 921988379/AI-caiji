<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('wp_caiji_settings_v2', array());
if (empty($settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}caiji_rules");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}caiji_queue");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}caiji_logs");
delete_option('wp_caiji_settings_v2');
delete_option('wp_caiji_rules');
delete_option('wp_caiji_logs');
delete_option('wp_caiji_settings');
delete_transient('wp_caiji_lock_discover');
delete_transient('wp_caiji_lock_collect');
