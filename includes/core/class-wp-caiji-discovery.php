<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Discovery and URL enqueue helpers for WP Caiji.
 */
class WP_Caiji_Discovery
{
    public static function build_list_pages($rule)
    {
        $urls = self::parse_urls($rule['list_urls'] ?? '');
        $pattern = trim((string)($rule['pagination_pattern'] ?? ''));
        if ($pattern && strpos($pattern, '{page}') !== false) {
            $start = max(1, (int)$rule['page_start']);
            $end = max($start, min($start + 500, (int)$rule['page_end']));
            for ($i = $start; $i <= $end; $i++) {
                $u = WP_Caiji_Utils::normalize_url(str_replace('{page}', (string)$i, $pattern));
                if (WP_Caiji_Utils::is_safe_public_url($u)) $urls[] = $u;
            }
        }
        return array_values(array_unique($urls));
    }

    public static function enqueue_manual_urls($plugin, $rule_id, $text)
    {
        $added = 0;
        $offset = 0;
        $rule = $plugin->get_rule_public($rule_id);
        foreach (self::parse_urls($text) as $url) {
            if (self::enqueue_url($plugin, $rule_id, $url, $offset, $rule)) {
                $added++;
                $offset++;
            }
        }
        return $added;
    }

    public static function enqueue_url($plugin, $rule_id, $url, $offset = 0, $rule = null)
    {
        global $wpdb;
        $url = WP_Caiji_Utils::normalize_url($url);
        if (!WP_Caiji_Utils::is_safe_public_url($url)) return false;
        if (WP_Caiji_Queue::post_exists_by_source($url)) return false;

        if (!$rule) $rule = $plugin->get_rule_public($rule_id);
        $delay = max(0, min(30, (int)($rule['request_delay'] ?? 0)));
        $scheduled_at = date('Y-m-d H:i:s', current_time('timestamp') + ($delay * max(0, (int)$offset)));

        $queue_table = $plugin->queue_table();
        $ok = $wpdb->insert($queue_table, array(
            'rule_id' => (int)$rule_id,
            'url' => $url,
            'url_hash' => md5($url),
            'status' => 'pending',
            'attempts' => 0,
            'discovered_at' => current_time('mysql'),
            'scheduled_at' => $scheduled_at,
        ));
        return (bool)$ok;
    }

    public static function parse_urls($text)
    {
        $urls = array();
        foreach (WP_Caiji_Utils::lines($text) as $line) {
            $url = WP_Caiji_Utils::normalize_url($line);
            if (WP_Caiji_Utils::is_safe_public_url($url)) $urls[] = $url;
        }
        return array_values(array_unique($urls));
    }
}
