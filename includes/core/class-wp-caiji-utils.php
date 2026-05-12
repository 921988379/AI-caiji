<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared helper methods for future WP Caiji modules.
 */
class WP_Caiji_Utils
{
    public static function lines($text)
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$text))));
    }

    public static function clamp_int($value, $min, $max, $default = 0)
    {
        $value = is_numeric($value) ? (int)$value : (int)$default;
        return max((int)$min, min((int)$max, $value));
    }

    public static function now_mysql()
    {
        return current_time('mysql');
    }

    public static function normalize_url($url)
    {
        $url = esc_url_raw(trim((string)$url));
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $url;

        $drop = array('utm_source','utm_medium','utm_campaign','utm_term','utm_content','from','spm');
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach ($drop as $key) unset($query[$key]);
            $parts['query'] = http_build_query($query);
        }

        $rebuilt = $parts['scheme'] . '://' . strtolower($parts['host']);
        if (!empty($parts['path'])) $rebuilt .= $parts['path'];
        if (!empty($parts['query'])) $rebuilt .= '?' . $parts['query'];
        return rtrim($rebuilt, '/');
    }

    public static function is_safe_public_url($url)
    {
        $url = trim((string)$url);
        if ($url === '' || !wp_http_validate_url($url)) return false;

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) return false;

        $host = trim((string)$parts['host'], "[] \t\n\r\0\x0B");
        if ($host === '') return false;

        $host_lc = strtolower($host);
        if (in_array($host_lc, array('localhost', 'localhost.localdomain'), true) || substr($host_lc, -6) === '.local') {
            return false;
        }

        $ips = array();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = gethostbynamel($host);
            if (!$resolved || !is_array($resolved)) return false;
            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) return false;
        }

        return true;
    }

    public static function is_public_ip($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function render_template($template, $title, $excerpt, $source)
    {
        $template = $template ?: '{title}';
        return strtr($template, array(
            '{title}' => wp_strip_all_tags($title),
            '{excerpt}' => wp_strip_all_tags($excerpt),
            '{site}' => get_bloginfo('name'),
            '{source}' => esc_url_raw($source),
        ));
    }
}
