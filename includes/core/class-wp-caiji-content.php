<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content, SEO, category, tag and replacement handling for WP Caiji.
 */
class WP_Caiji_Content
{
    public static function make_excerpt($content, $length = 160)
    {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)));
        return mb_substr($text, 0, max(50, min(500, (int)$length)));
    }


    public static function render_template($template, $title, $excerpt, $source)
    {
        return WP_Caiji_Utils::render_template($template, $title, $excerpt, $source);
    }


    public static function write_seo_meta($post_id, $rule, $title, $excerpt, $source)
    {
        $plugin = $rule['seo_plugin'] ?? 'none';
        if ($plugin === 'none') return;
        $seo_title = self::render_template($rule['seo_title_template'] ?: '{title}', $title, $excerpt, $source);
        $seo_desc = self::render_template($rule['seo_desc_template'] ?: '{excerpt}', $title, $excerpt, $source);
        if ($plugin === 'rank_math') {
            update_post_meta($post_id, 'rank_math_title', $seo_title);
            update_post_meta($post_id, 'rank_math_description', $seo_desc);
        } elseif ($plugin === 'yoast') {
            update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_desc);
        } elseif ($plugin === 'aioseo') {
            update_post_meta($post_id, '_aioseo_title', $seo_title);
            update_post_meta($post_id, '_aioseo_description', $seo_desc);
        }
    }


    public static function match_category_id($text, $rules)
    {
        if (!$rules) return 0;
        $lines = preg_split('/\r\n|\r|\n/', (string)$rules);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=>') === false) continue;
            list($keyword, $cat_id) = array_map('trim', explode('=>', $line, 2));
            if ($keyword !== '' && stripos($text, $keyword) !== false && absint($cat_id)) return absint($cat_id);
        }
        return 0;
    }


    public static function match_auto_tags($text, $keywords)
    {
        $tags = array();
        $items = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$keywords)));
        foreach ($items as $kw) {
            if ($kw !== '' && stripos($text, $kw) !== false) $tags[] = $kw;
        }
        return array_values(array_unique($tags));
    }


    public static function apply_replacements($text, $rules)
    {
        if (!$rules || !is_string($rules)) return $text;
        $lines = preg_split('/\r\n|\r|\n/', $rules);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=>') === false) continue;
            list($from, $to) = array_map('trim', explode('=>', $line, 2));
            if ($from !== '') $text = str_replace($from, $to, $text);
        }
        return $text;
    }


    public static function parse_tags($tags)
    {
        $items = array_filter(array_map('trim', explode(',', (string)$tags)));
        return array_values(array_unique($items));
    }


}
