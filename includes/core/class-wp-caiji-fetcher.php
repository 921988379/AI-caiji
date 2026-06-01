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
            'user-agent' => self::generate_user_agent(),
        );
        $headers = array();
        $rule = self::get_rule_for_headers($plugin, $rule_id);
        if ($rule) {
            $ua = self::pick_user_agent($rule['ua_list'] ?? '');
            if ($ua) $args['user-agent'] = $ua;
            if (!empty($rule['referer']) && WP_Caiji_Utils::is_safe_public_url($rule['referer'])) {
                $headers['Referer'] = $rule['referer'];
            } else {
                $referer = self::generate_referer($url);
                if ($referer) $headers['Referer'] = $referer;
            }
            $cookie = self::pick_cookie($rule['cookie'] ?? '');
            if ($cookie) $headers['Cookie'] = $cookie;
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

    public static function pick_cookie($cookie_list)
    {
        $items = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$cookie_list)));
        if (!$items) return '';
        return $items[array_rand($items)];
    }

    public static function generate_referer($url)
    {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) return '';

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        if (!in_array($scheme, array('http', 'https'), true)) return '';

        $base = $scheme . '://' . $host . '/';
        $path = isset($parts['path']) ? trim((string)$parts['path'], '/') : '';
        $candidates = array($base);

        if ($path !== '') {
            $segments = explode('/', $path);
            array_pop($segments);
            if ($segments) {
                $candidates[] = $base . implode('/', array_map('rawurlencode', $segments)) . '/';
            }
        }

        $external = array(
            'https://www.google.com/',
            'https://www.bing.com/',
            'https://www.baidu.com/',
            'https://www.so.com/',
            'https://www.sogou.com/',
        );
        $candidates = array_merge($candidates, $external);

        $referer = $candidates[array_rand($candidates)];
        return WP_Caiji_Utils::is_safe_public_url($referer) ? $referer : '';
    }

    public static function generate_user_agent()
    {
        $chrome_major = wp_rand(120, 126);
        $chrome_build = wp_rand(6000, 6478);
        $chrome_patch = wp_rand(80, 180);
        $edge_major = $chrome_major;
        $edge_build = $chrome_build;
        $edge_patch = $chrome_patch;
        $mac_minor = wp_rand(2, 7);
        $ios_minor = wp_rand(0, 5);
        $android_version = wp_rand(12, 14);

        $agents = array(
            sprintf('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Safari/537.36', $chrome_major, $chrome_build, $chrome_patch),
            sprintf('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Safari/537.36 Edg/%d.0.%d.%d', $chrome_major, $chrome_build, $chrome_patch, $edge_major, $edge_build, $edge_patch),
            sprintf('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_%d) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Safari/537.36', $mac_minor, $chrome_major, $chrome_build, $chrome_patch),
            sprintf('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_%d) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.%d Safari/605.1.15', $mac_minor, wp_rand(0, 5)),
            sprintf('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Safari/537.36', $chrome_major, $chrome_build, $chrome_patch),
            sprintf('Mozilla/5.0 (Linux; Android %d; Pixel %d) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%d.0.%d.%d Mobile Safari/537.36', $android_version, wp_rand(6, 8), $chrome_major, $chrome_build, $chrome_patch),
            sprintf('Mozilla/5.0 (iPhone; CPU iPhone OS 17_%d like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.%d Mobile/15E148 Safari/604.1', $ios_minor, $ios_minor),
        );

        return $agents[array_rand($agents)];
    }


}
