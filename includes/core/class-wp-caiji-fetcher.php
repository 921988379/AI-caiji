<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTTP fetching and request-header handling for WP Caiji.
 */
class WP_Caiji_Fetcher
{
    public static function fetch($plugin, $url, $rule_id = 0, $queue_id = 0)
    {
        $url = WP_Caiji_Utils::normalize_url($url);
        if (!WP_Caiji_Utils::is_safe_public_url($url)) {
            $plugin->log_public('error', 'URL 不安全或不可访问公网，已拒绝抓取', $rule_id, $queue_id, $url);
            return '';
        }

        $args = array(
            'timeout' => 25,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 5242880,
            'user-agent' => 'Mozilla/5.0 WordPress WP-Caiji/' . WP_CAIJI_VERSION,
        );
        $headers = array();
        $rule = self::get_rule_for_headers($plugin, $rule_id);
        if ($rule) {
            $ua = self::pick_user_agent($rule['ua_list'] ?? '');
            if ($ua) $args['user-agent'] = $ua;
            if (!empty($rule['referer']) && WP_Caiji_Utils::is_safe_public_url($rule['referer'])) $headers['Referer'] = $rule['referer'];
            if (!empty($rule['cookie'])) $headers['Cookie'] = $rule['cookie'];
        }
        if ($headers) $args['headers'] = $headers;
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            $plugin->log_public('error', $response->get_error_message(), $rule_id, $queue_id, $url);
            return '';
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $plugin->log_public('error', 'HTTP 状态异常：' . $code, $rule_id, $queue_id, $url);
            return '';
        }
        return (string)wp_remote_retrieve_body($response);
    }


    public static function get_rule_for_headers($plugin, $rule_id)
    {
        static $cache = array();
        $rule_id = (int)$rule_id;
        if (!$rule_id) return null;
        if (isset($cache[$rule_id])) return $cache[$rule_id];
        global $wpdb;
        $rules_table = $plugin->rules_table();
        $cache[$rule_id] = $wpdb->get_row($wpdb->prepare("SELECT ua_list, referer, cookie FROM {$rules_table} WHERE id=%d", $rule_id), ARRAY_A);
        return $cache[$rule_id];
    }


    public static function pick_user_agent($ua_list)
    {
        $items = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$ua_list)));
        if (!$items) return '';
        return $items[array_rand($items)];
    }


}
