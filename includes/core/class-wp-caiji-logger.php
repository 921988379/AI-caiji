<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logging helper for WP Caiji.
 */
class WP_Caiji_Logger
{
    public static function log($plugin, $level, $message, $rule_id = 0, $queue_id = 0, $url = '')
    {
        global $wpdb;
        $settings = get_option(WP_Caiji::OPTION_SETTINGS, array());
        $settings = wp_parse_args($settings, WP_Caiji_DB::default_settings());
        if (empty($settings['enable_logs'])) return;

        $logs_table = $plugin->logs_table();
        $wpdb->insert($logs_table, array(
            'rule_id' => (int)$rule_id,
            'queue_id' => (int)$queue_id,
            'level' => sanitize_key($level),
            'message' => wp_strip_all_tags($message),
            'url' => esc_url_raw($url),
            'created_at' => current_time('mysql'),
        ));

        self::trim($plugin, (int)$settings['log_retention']);
    }

    public static function trim($plugin, $retention)
    {
        global $wpdb;
        $keep = max(100, min(20000, (int)$retention));
        $logs_table = $plugin->logs_table();
        $wpdb->query("DELETE FROM {$logs_table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$logs_table} ORDER BY id DESC LIMIT {$keep}) keep_logs)");
    }
}
