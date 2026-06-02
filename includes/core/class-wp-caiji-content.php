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


    /**
     * Match automatic post tags from the post title only.
     *
     * Supported formats per line:
     * - 关键词
     * - 关键词=>标签名
     * - 关键词1|关键词2=>标签名
     *
     * Matching rules:
     * - Chinese / mixed non-ASCII keywords: substring match.
     * - ASCII-only keywords: whole-word-ish match with Unicode-safe boundaries,
     *   so `AI` will not match `Paid`.
     */
    public static function match_auto_tags_by_title($title, $keywords, $advanced = true)
    {
        $title = (string)$title;
        $items = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$keywords)));

        if (!$advanced) {
            $tags = array();
            foreach ($items as $kw) {
                if ($kw !== '' && stripos($title, $kw) !== false) $tags[] = $kw;
            }
            return self::normalize_tag_list($tags);
        }

        $tags = array();
        foreach ($items as $line) {
            $parsed = self::parse_auto_tag_rule($line);
            if (!$parsed) continue;

            $matched = false;
            foreach ($parsed['patterns'] as $pattern) {
                if (self::title_matches_auto_tag_pattern($title, $pattern)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $tags[] = $parsed['tag'];
            }
        }
        return self::normalize_tag_list($tags);
    }

    public static function match_auto_tags($text, $keywords, $advanced = true)
    {
        return self::match_auto_tags_by_title($text, $keywords, $advanced);
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
        if (is_array($tags)) return self::normalize_tag_list($tags);
        $items = array_filter(array_map('trim', preg_split('/[\r\n,，、;；|\/]+/u', (string)$tags)));
        return self::normalize_tag_list($items);
    }

    public static function normalize_tags($tags)
    {
        return self::normalize_tag_list($tags);
    }


    private static function parse_auto_tag_rule($line)
    {
        $line = trim((string)$line);
        if ($line === '') return null;

        $raw_patterns = $line;
        $tag = $line;
        if (strpos($line, '=>') !== false) {
            list($raw_patterns, $tag) = array_map('trim', explode('=>', $line, 2));
        }

        $patterns = array_values(array_filter(array_map('trim', explode('|', (string)$raw_patterns))));
        $tag = trim((string)$tag);
        if (!$patterns || $tag === '') return null;

        return array(
            'patterns' => $patterns,
            'tag' => $tag,
        );
    }

    private static function title_matches_auto_tag_pattern($title, $pattern)
    {
        $title = (string)$title;
        $pattern = trim((string)$pattern);
        if ($title === '' || $pattern === '') return false;

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _+\-.#&\/]*$/', $pattern)) {
            $quoted = preg_quote($pattern, '/');
            return (bool)preg_match('/(^|[^\p{L}\p{N}_])' . $quoted . '([^\p{L}\p{N}_]|$)/iu', $title);
        }

        return stripos($title, $pattern) !== false;
    }

    private static function normalize_tag_list($tags)
    {
        $normalized = array();
        $seen = array();
        foreach ((array)$tags as $tag) {
            $tag = trim(wp_strip_all_tags((string)$tag));
            if ($tag === '') continue;
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $normalized[] = $tag;
        }
        return array_values($normalized);
    }


}
