<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser/extractor/cleaner layer for WP Caiji.
 */
class WP_Caiji_Parser
{
    public static function extract_next_data($html)
    {
        return self::extract_json_data($html, '__NEXT_DATA__');
    }

    public static function extract_json_data($html, $source = '__NEXT_DATA__')
    {
        $html = (string)$html;
        $source = trim((string)$source);
        if ($html === '') return null;

        if ($source === '' || $source === '__NEXT_DATA__' || $source === 'next') {
            if (!preg_match('/<script\b[^>]*\bid=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $m)) return null;
            return self::decode_json_script($m[1]);
        }

        if ($source === 'ld+json' || $source === 'json-ld') {
            if (!preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) return null;
            $items = array();
            foreach ($matches[1] as $json) {
                $decoded = self::decode_json_script($json);
                if ($decoded !== null) $items[] = $decoded;
            }
            if (!$items) return null;
            return count($items) === 1 ? $items[0] : $items;
        }

        if (strpos($source, 'id:') === 0) {
            $id = preg_quote(substr($source, 3), '/');
            if ($id === '') return null;
            if (!preg_match('/<script\b[^>]*\bid=["\']' . $id . '["\'][^>]*>(.*?)<\/script>/is', $html, $m)) return null;
            return self::decode_json_script($m[1]);
        }

        if (strpos($source, 'var:') === 0) {
            $name = preg_quote(substr($source, 4), '/');
            if ($name === '') return null;
            if (!preg_match('/(?:window\.)?' . $name . '\s*=\s*(\{.*?\}|\[.*?\])\s*(?:;|<\/script>)/is', $html, $m)) return null;
            return self::decode_json_script($m[1]);
        }

        return null;
    }

    private static function decode_json_script($json)
    {
        $json = trim(html_entity_decode((string)$json, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($json === '') return null;
        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    public static function json_path_get($data, $path)
    {
        $path = trim((string)$path);
        if ($path === '') return null;
        if (strpos($path, '$.') === 0) $path = substr($path, 2);
        if ($path === '$') return $data;
        $parts = preg_split('/\./', $path);
        $current = $data;
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (!preg_match_all('/([^\[\]]+)|\[(\d+|\*)\]/', $part, $matches, PREG_SET_ORDER)) return null;
            foreach ($matches as $match) {
                if (isset($match[1]) && $match[1] !== '') {
                    $key = $match[1];
                    if (is_array($current) && array_key_exists($key, $current)) {
                        $current = $current[$key];
                    } else {
                        return null;
                    }
                } elseif (isset($match[2]) && $match[2] !== '') {
                    if ($match[2] === '*') return is_array($current) ? $current : null;
                    $idx = (int)$match[2];
                    if (is_array($current) && array_key_exists($idx, $current)) {
                        $current = $current[$idx];
                    } else {
                        return null;
                    }
                }
            }
        }
        return $current;
    }

    public static function extract_json_links_by_rule($html, $rule, $base_url)
    {
        $path = trim((string)($rule['link_json_path'] ?? ''));
        $field = trim((string)($rule['link_json_url_field'] ?? ''));
        if ($field === 'caijin_url') {
            $links = array();
            if (preg_match_all('/\\\\"slug\\\\"\s*:\s*\\\\"([^\\\\"]+)\\\\".*?\\\\"content_type\\\\"\s*:\s*\\\\"(podcast|article)\\\\"/is', (string)$html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $slug = trim((string)$match[1]);
                    $type = trim((string)$match[2]);
                    if ($slug === '') continue;
                    $abs = self::absolute_url('/' . $type . 's/' . ltrim($slug, '/'), $base_url);
                    if (WP_Caiji_Utils::is_safe_public_url($abs)) $links[] = WP_Caiji_Utils::normalize_url($abs);
                }
            }
            if ($links) return array_values(array_unique($links));
        }
        if ($path === '') return array();
        $data = self::extract_json_data($html, $rule['json_source'] ?? '__NEXT_DATA__');
        if (!$data) return array();
        $items = self::json_path_get($data, $path);
        if ($items === null) return array();
        if (!is_array($items)) $items = array($items);
        $links = array();
        foreach ($items as $item) {
            $value = null;
            if ($field !== '') {
                if ($field === 'caijin_url' && is_array($item)) {
                    $slug = isset($item['slug']) && is_scalar($item['slug']) ? trim((string)$item['slug']) : '';
                    $type = isset($item['content_type']) && is_scalar($item['content_type']) ? trim((string)$item['content_type']) : '';
                    if ($slug !== '' && in_array($type, array('podcast', 'article'), true)) {
                        $value = '/' . $type . 's/' . ltrim($slug, '/');
                    }
                }
                if ($value === null) {
                    $value = is_array($item) ? self::json_path_get($item, $field) : null;
                }
            } elseif (is_string($item)) {
                $value = $item;
            } elseif (is_array($item)) {
                foreach (array('url', 'href', 'link', 'permalink', 'alias', 'path', 'slug') as $candidate) {
                    if (!empty($item[$candidate]) && is_scalar($item[$candidate])) { $value = $item[$candidate]; break; }
                }
            }
            if (is_scalar($value) && trim((string)$value) !== '') {
                $abs = self::absolute_url((string)$value, $base_url);
                if (WP_Caiji_Utils::is_safe_public_url($abs)) $links[] = WP_Caiji_Utils::normalize_url($abs);
            }
        }
        return array_values(array_unique($links));
    }

    public static function extract_json_field_by_rule($html, $rule, $field, $text_only = false)
    {
        $path = trim((string)($rule[$field . '_json_path'] ?? ''));
        if ($path === '') return '';
        $data = self::extract_json_data($html, $rule['json_source'] ?? '__NEXT_DATA__');
        if (!$data) return '';
        $value = self::json_path_get($data, $path);
        if ($value === null) return '';
        if (is_array($value) || is_object($value)) $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $value = (string)$value;
        return $text_only ? trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($value))) : trim($value);
    }

    public static function json_field_match_count_by_rule($html, $rule, $field)
    {
        $path = trim((string)($rule[$field . '_json_path'] ?? ''));
        if ($path === '') return 0;
        $data = self::extract_json_data($html, $rule['json_source'] ?? '__NEXT_DATA__');
        if (!$data) return 0;
        $value = self::json_path_get($data, $path);
        if ($value === null || $value === '') return 0;
        return is_array($value) ? count($value) : 1;
    }

    public static function extract_links($html, $selector, $base_url)
    {
        if (!$selector) return array();
        $nodes = self::query_nodes($html, $selector);
        $links = array();
        if (!$nodes) return $links;
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) continue;
            $href = strtolower($node->tagName) === 'a' ? $node->getAttribute('href') : '';
            if (!$href) {
                $a = $node->getElementsByTagName('a')->item(0);
                if ($a) $href = $a->getAttribute('href');
            }
            if ($href) {
                $abs = self::absolute_url($href, $base_url);
                if (WP_Caiji_Utils::is_safe_public_url($abs)) $links[] = WP_Caiji_Utils::normalize_url($abs);
            }
        }
        return array_values(array_unique($links));
    }

    public static function extract_links_by_rule($html, $rule, $base_url)
    {
        $rule = is_array($rule) ? $rule : array('link_selector' => (string)$rule);
        $selector = trim((string)($rule['link_selector'] ?? ''));
        $before = (string)($rule['link_before_marker'] ?? '');
        $after = (string)($rule['link_after_marker'] ?? '');
        $json_links = self::extract_json_links_by_rule($html, $rule, $base_url);
        if ($json_links) return $json_links;
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') {
            return self::extract_links($scoped_html, $selector, $base_url);
        }
        return self::extract_all_anchor_links($scoped_html, $base_url);
    }

    public static function link_match_count_by_rule($html, $rule)
    {
        $rule = is_array($rule) ? $rule : array('link_selector' => (string)$rule);
        $selector = trim((string)($rule['link_selector'] ?? ''));
        $before = (string)($rule['link_before_marker'] ?? '');
        $after = (string)($rule['link_after_marker'] ?? '');
        $json_links = self::extract_json_links_by_rule($html, $rule, 'https://example.com/');
        if ($json_links) return count($json_links);
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') return self::selector_match_count($scoped_html, $selector);
        return count(self::extract_all_anchor_links($scoped_html, 'https://example.com/'));
    }

    public static function slice_between_markers($html, $before_marker = '', $after_marker = '')
    {
        $html = (string)$html;
        $before_marker = self::normalize_marker((string)$before_marker);
        $after_marker = self::normalize_marker((string)$after_marker);
        if ($html === '' || ($before_marker === '' && $after_marker === '')) return $html;

        $start = 0;
        if ($before_marker !== '') {
            $match = self::find_marker_match($html, $before_marker, 0);
            if (!$match) return '';
            $start = $match['pos'] + $match['length'];
        }

        $end = strlen($html);
        if ($after_marker !== '') {
            $match = self::find_marker_match($html, $after_marker, $start);
            if (!$match) return '';
            $end = $match['pos'];
        }

        if ($end <= $start) return '';
        return substr($html, $start, $end - $start);
    }

    private static function normalize_marker($marker)
    {
        $marker = str_replace(array("\r\n", "\r"), "\n", (string)$marker);
        return trim($marker);
    }

    private static function find_marker_match($html, $marker, $offset = 0)
    {
        $variants = array($marker);
        $decoded = html_entity_decode($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $marker) $variants[] = $decoded;
        $encoded = htmlspecialchars($marker, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if ($encoded !== $marker) $variants[] = $encoded;

        foreach (array_values(array_unique($variants)) as $variant) {
            $pos = strpos($html, $variant, $offset);
            if ($pos !== false) return array('pos' => $pos, 'length' => strlen($variant));
        }

        $compact_marker = preg_replace('/\s+/u', ' ', trim($marker));
        if ($compact_marker !== '' && $compact_marker !== $marker) {
            $quoted = preg_quote($compact_marker, '/');
            $pattern = '/' . str_replace('\\ ', '\\s+', $quoted) . '/u';
            if (preg_match($pattern, substr($html, $offset), $m, PREG_OFFSET_CAPTURE)) {
                return array('pos' => $offset + $m[0][1], 'length' => strlen($m[0][0]));
            }
        }

        return false;
    }

    public static function extract_all_anchor_links($html, $base_url)
    {
        if (!$html) return array();
        $links = array();
        if (preg_match_all('/<a\b[^>]*\shref\s*=\s*(["\'])(.*?)\1/isu', (string)$html, $matches)) {
            foreach ($matches[2] as $href) {
                $abs = self::absolute_url($href, $base_url);
                if (WP_Caiji_Utils::is_safe_public_url($abs)) $links[] = WP_Caiji_Utils::normalize_url($abs);
            }
        }
        return array_values(array_unique($links));
    }

    public static function extract_field_by_rule($html, $rule, $field, $selector = '', $text_only = false)
    {
        $rule = is_array($rule) ? $rule : array();
        $field = sanitize_key($field);
        $selector = trim((string)$selector);
        $before = (string)($rule[$field . '_before_marker'] ?? '');
        $after = (string)($rule[$field . '_after_marker'] ?? '');
        $json_value = self::extract_json_field_by_rule($html, $rule, $field, $text_only);
        if ($json_value !== '') return $json_value;
        if ($field === 'title' && trim((string)($rule['title_json_path'] ?? '')) === 'caijin_title') {
            $caijin_title = self::extract_caijin_title($html);
            if ($caijin_title !== '') return $caijin_title;
        }
        if ($field === 'date' && trim((string)($rule['date_json_path'] ?? '')) === 'caijin_date') {
            $caijin_date = self::extract_caijin_date($html);
            if ($caijin_date !== '') return $caijin_date;
        }
        if ($field === 'content' && (!empty($rule['caijin_content_extract']) || trim((string)($rule['content_json_path'] ?? '')) === 'caijin_content')) {
            $caijin_content = self::extract_caijin_content($html, $text_only);
            if ($caijin_content !== '') return $caijin_content;
        }
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') {
            return self::extract($scoped_html, $selector, $text_only);
        }
        return $text_only ? trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($scoped_html))) : trim($scoped_html);
    }

    public static function field_match_count_by_rule($html, $rule, $field, $selector = '')
    {
        $rule = is_array($rule) ? $rule : array();
        $field = sanitize_key($field);
        $selector = trim((string)$selector);
        $before = (string)($rule[$field . '_before_marker'] ?? '');
        $after = (string)($rule[$field . '_after_marker'] ?? '');
        $json_count = self::json_field_match_count_by_rule($html, $rule, $field);
        if ($json_count > 0) return $json_count;
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') return self::selector_match_count($scoped_html, $selector);
        return trim(wp_strip_all_tags($scoped_html)) === '' ? 0 : 1;
    }

    public static function extract_field_outer_html_sample_by_rule($html, $rule, $field, $selector = '', $max_chars = 1500)
    {
        $rule = is_array($rule) ? $rule : array();
        $field = sanitize_key($field);
        $selector = trim((string)$selector);
        $before = (string)($rule[$field . '_before_marker'] ?? '');
        $after = (string)($rule[$field . '_after_marker'] ?? '');
        $json_value = self::extract_json_field_by_rule($html, $rule, $field, false);
        if ($json_value !== '') return mb_substr($json_value, 0, max(200, (int)$max_chars));
        if ($field === 'title' && trim((string)($rule['title_json_path'] ?? '')) === 'caijin_title') {
            $caijin_title = self::extract_caijin_title($html);
            if ($caijin_title !== '') return mb_substr($caijin_title, 0, max(200, (int)$max_chars));
        }
        if ($field === 'date' && trim((string)($rule['date_json_path'] ?? '')) === 'caijin_date') {
            $caijin_date = self::extract_caijin_date($html);
            if ($caijin_date !== '') return mb_substr($caijin_date, 0, max(200, (int)$max_chars));
        }
        if ($field === 'content' && (!empty($rule['caijin_content_extract']) || trim((string)($rule['content_json_path'] ?? '')) === 'caijin_content')) {
            $caijin_content = self::extract_caijin_content($html, false);
            if ($caijin_content !== '') return mb_substr($caijin_content, 0, max(200, (int)$max_chars));
        }
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') return self::extract_outer_html_sample($scoped_html, $selector, $max_chars);
        return mb_substr(trim($scoped_html), 0, max(200, (int)$max_chars));
    }

    public static function extract_caijin_title($html)
    {
        $html = (string)$html;
        foreach (array(
            '/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i',
            '/<meta\s+name=["\']twitter:title["\']\s+content=["\']([^"\']+)["\']/i',
            '/<title>(.*?)<\/title>/is',
        ) as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $title = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($title !== '' && $title !== '财经 Caijin') return $title;
            }
        }
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }
        return '';
    }

    public static function extract_caijin_date($html)
    {
        $html = (string)$html;
        if (preg_match('/<meta\s+property=["\']article:published_time["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(20\d{2}\s*年\s*\d{1,2}\s*月\s*\d{1,2}\s*日\s*\d{1,2}:\d{2})/u', $html, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    public static function extract_caijin_content($html, $text_only = false)
    {
        $content = '';
        $html = (string)$html;
        if (preg_match('/className\\":\\"prose[^\\"]*\\".*?children\\":\[\[\[\\"\$\\",\\"p\\",\\"0\\",\{\\"children\\":\\"((?:[^\\\\\\"]|\\\\.)*)\\"\}\],\[\\"\$\\",\\"br\\"/is', $html, $m)) {
            $content = '<p>' . esc_html(self::decode_js_string($m[1])) . '</p>';
        }
        if ($content === '' && preg_match('/className\\":\\"prose[^\\"]*\\".*?children\\":\[\[\[\\"\$\\",\\"p\\",\\"0\\",\{\\"children\\":\[(.*?)\]\}\],\[\\"\$\\",\\"br\\"/is', $html, $m)) {
            $content = self::caijin_react_children_to_html($m[1]);
        }
        if ($content === '' && preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $content = '<p>' . esc_html(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
        }
        if ($content === '' && preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $content = '<p>' . esc_html(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
        }
        if ($content === '' && preg_match('/<div class="prose[^" ]*[^" ]*"[^>]*>(?!.*animate-pulse)(.*?)<hr\b/is', $html, $m)) {
            $content = $m[1];
        }
        if ($content === '') return '';
        $content = html_entity_decode(str_replace('\\u0026nbsp;', '&nbsp;', $content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($text_only) return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)));
        return trim($content);
    }

    private static function caijin_react_children_to_html($raw)
    {
        $raw = (string)$raw;
        $out = '';
        if (preg_match_all('/\\"((?:[^\\\\\\"]|\\\\.)*)\\"|\[\\"\$\\",\\"(br|a)\\"(?:,.*?)?\]/is', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!empty($match[2])) {
                    if ($match[2] === 'br') $out .= '<br>';
                    elseif ($match[2] === 'a' && preg_match('/\\"href\\":\\"((?:[^\\\\\\"]|\\\\.)*)\\".*?\\"children\\":\\"((?:[^\\\\\\"]|\\\\.)*)\\"/is', $match[0], $am)) {
                        $href = self::decode_js_string($am[1]);
                        $text = self::decode_js_string($am[2]);
                        $out .= '<a href="' . esc_url($href) . '">' . esc_html($text) . '</a>';
                    }
                    continue;
                }
                $text = self::decode_js_string($match[1] ?? '');
                if ($text !== '$' && $text !== 'p' && $text !== '0' && $text !== 'children') $out .= esc_html($text);
            }
        }
        return $out !== '' ? '<p>' . $out . '</p>' : '';
    }

    private static function decode_js_string($value)
    {
        $json = '"' . str_replace('"', '\\"', (string)$value) . '"';
        $decoded = json_decode($json);
        return is_string($decoded) ? $decoded : stripcslashes((string)$value);
    }

    public static function extract_image_sources($content)
    {
        $sources = array();
        if (preg_match_all('/<img\b[^>]*>/i', (string)$content, $tags)) {
            foreach ($tags[0] as $tag) {
                foreach (array('src', 'data-src', 'data-original', 'data-lazy-src', 'data-original-src', 'data-echo') as $attr) {
                    if (preg_match('/\s' . preg_quote($attr, '/') . '=["\']([^"\']+)["\']/i', $tag, $m) && trim($m[1]) !== '') {
                        $sources[] = trim($m[1]);
                        break;
                    }
                }
                if (preg_match('/\ssrcset=["\']([^"\']+)["\']/i', $tag, $m)) {
                    foreach (explode(',', $m[1]) as $part) {
                        $url = trim(preg_split('/\s+/', trim($part))[0] ?? '');
                        if ($url !== '') $sources[] = $url;
                    }
                }
            }
        }
        if (preg_match_all('/background(?:-image)?\s*:\s*url\(([^)]+)\)/i', (string)$content, $bg_matches)) {
            foreach ($bg_matches[1] as $url) {
                $url = trim($url, " \t\n\r\0\x0B\"'");
                if ($url !== '') $sources[] = $url;
            }
        }
        return array_values(array_unique($sources));
    }




    public static function extract_tags_by_rule($html, $rule)
    {
        $rule = is_array($rule) ? $rule : array();
        $json_path = trim((string)($rule['tag_json_path'] ?? ''));
        $selector = trim((string)($rule['tag_selector'] ?? ''));
        $before = (string)($rule['tag_before_marker'] ?? '');
        $after = (string)($rule['tag_after_marker'] ?? '');

        if ($json_path !== '') {
            $json_tags = self::extract_json_tags_by_rule($html, $rule);
            if ($json_tags) return $json_tags;
        }

        if ($selector === '' && trim($before) === '' && trim($after) === '') {
            return array();
        }

        $scoped_html = self::slice_between_markers($html, $before, $after);

        if ($selector !== '') {
            $nodes = self::query_nodes($scoped_html, $selector);
            if ($nodes && $nodes->length > 0) {
                $tags = array();
                foreach ($nodes as $node) {
                    $tags = array_merge($tags, self::parse_tags_text($node->textContent ?? ''));
                }
                return array_values(array_unique($tags));
            }
            return array();
        }

        return self::parse_tags_text($scoped_html);
    }

    private static function extract_json_tags_by_rule($html, $rule)
    {
        $path = trim((string)($rule['tag_json_path'] ?? ''));
        if ($path === '') return array();
        $data = self::extract_json_data($html, $rule['json_source'] ?? '__NEXT_DATA__');
        if (!$data) return array();
        $value = self::json_path_get($data, $path);
        if ($value === null || $value === '') return array();
        return self::normalize_tag_values($value);
    }

    private static function normalize_tag_values($value)
    {
        $tags = array();
        if (is_array($value) || is_object($value)) {
            $array = (array)$value;
            $preferred_keys = array('name', 'title', 'label', 'tag', 'term', 'text');
            foreach ($preferred_keys as $key) {
                if (isset($array[$key]) && is_scalar($array[$key]) && trim((string)$array[$key]) !== '') {
                    return self::parse_tags_text((string)$array[$key]);
                }
            }
            foreach ($array as $key => $item) {
                $key_lc = strtolower((string)$key);
                if (in_array($key_lc, array('id', 'slug', 'url', 'href', 'link', 'count'), true)) continue;
                $tags = array_merge($tags, self::normalize_tag_values($item));
            }
        } else {
            $tags = array_merge($tags, self::parse_tags_text((string)$value));
        }
        return array_values(array_unique($tags));
    }

    public static function parse_tags_text($text)
    {
        $text = html_entity_decode(wp_strip_all_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($text === '') return array();
        $text = preg_replace('/(?:标签|Tags?|关键词|关键字)\s*[:：]/iu', '', $text);
        $parts = preg_split('/[\r\n,，、;；|\/]+/u', $text);
        $tags = array();
        foreach ((array)$parts as $part) {
            $tag = trim(preg_replace('/\s+/u', ' ', (string)$part));
            $tag = trim($tag, " #\t\n\r\0\x0B");
            if ($tag === '' || mb_strlen($tag) > 60) continue;
            $tags[] = $tag;
        }
        return array_values(array_unique($tags));
    }


    public static function extract($html, $selector, $text_only = false)
    {
        $nodes = self::query_nodes($html, $selector);
        if (!$nodes || $nodes->length === 0) return '';
        $node = $nodes->item(0);
        if ($text_only) return trim(preg_replace('/\s+/u', ' ', $node->textContent));
        $dom = $node->ownerDocument;
        $inner = '';
        foreach ($node->childNodes as $child) $inner .= $dom->saveHTML($child);
        return trim($inner ?: $dom->saveHTML($node));
    }

    public static function selector_match_count($html, $selector)
    {
        $nodes = self::query_nodes($html, $selector);
        return $nodes ? (int)$nodes->length : 0;
    }

    public static function extract_outer_html_sample($html, $selector, $max_chars = 1500)
    {
        $nodes = self::query_nodes($html, $selector);
        if (!$nodes || $nodes->length === 0) return '';
        $node = $nodes->item(0);
        $dom = $node->ownerDocument;
        return mb_substr(trim($dom->saveHTML($node)), 0, max(200, (int)$max_chars));
    }


    public static function query_nodes($html, $selector)
    {
        if (!$html || !$selector) return null;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        return $xpath->query(self::selector_to_xpath($selector));
    }


    public static function selector_to_xpath($selector)
    {
        $selector = trim($selector);
        if ($selector === '') return '//*';
        if (strpos($selector, '/') === 0 || strpos($selector, '(') === 0) return $selector;

        $parts = preg_split('/\s*(>)\s*|\s+/', $selector, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!$parts) return '//*';

        $xpath = '';
        $axis = '//';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if ($part === '>') {
                $axis = '/';
                continue;
            }
            $simple = self::css_simple_selector_to_xpath($part);
            if ($simple === '') return '//*';
            $xpath .= $axis . $simple;
            $axis = '//';
        }

        return $xpath ?: '//*';
    }


    private static function css_simple_selector_to_xpath($selector)
    {
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*|\*)?((?:[#.][a-zA-Z0-9_-]+)*)$/', $selector, $m)) {
            return '';
        }

        $tag = !empty($m[1]) && $m[1] !== '*' ? $m[1] : '*';
        $suffix = $m[2] ?? '';
        $predicates = array();
        if ($suffix !== '' && preg_match_all('/([#.])([a-zA-Z0-9_-]+)/', $suffix, $items, PREG_SET_ORDER)) {
            foreach ($items as $item) {
                if ($item[1] === '#') {
                    $predicates[] = '@id="' . self::xpath_literal($item[2]) . '"';
                } else {
                    $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . self::xpath_literal($item[2]) . ' ")';
                }
            }
        }

        return $tag . ($predicates ? '[' . implode(' and ', $predicates) . ']' : '');
    }


    public static function xpath_literal($value)
    {
        return str_replace(array('"', "'"), '', $value);
    }


    public static function clean_content($content, $rule_or_selectors)
    {
        $rule = is_array($rule_or_selectors) ? $rule_or_selectors : array('remove_selectors'=>$rule_or_selectors);
        $selectors = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($rule['remove_selectors'] ?? ''))));
        $selectors = array_merge(array('script','style','noscript'), $selectors);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="wp-caiji-root">' . $content . '</div>');
        $xpath = new DOMXPath($dom);
        foreach ($selectors as $selector) {
            $nodes = $xpath->query(self::selector_to_xpath($selector));
            if (!$nodes) continue;
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $n = $nodes->item($i);
                if ($n && $n->parentNode) $n->parentNode->removeChild($n);
            }
        }
        if (!empty($rule['remove_external_links'])) {
            $links = $dom->getElementsByTagName('a');
            for ($i = $links->length - 1; $i >= 0; $i--) {
                $a = $links->item($i);
                while ($a->firstChild) $a->parentNode->insertBefore($a->firstChild, $a);
                $a->parentNode->removeChild($a);
            }
        }
        $remove_keywords = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($rule['remove_paragraph_keywords'] ?? ''))));
        if ($remove_keywords) {
            foreach (array('p','div','section') as $tag) {
                $nodes = $dom->getElementsByTagName($tag);
                for ($i = $nodes->length - 1; $i >= 0; $i--) {
                    $node = $nodes->item($i);
                    $text = trim($node->textContent);
                    foreach ($remove_keywords as $kw) {
                        if ($kw !== '' && stripos($text, $kw) !== false && $node->parentNode) {
                            $node->parentNode->removeChild($node);
                            break;
                        }
                    }
                }
            }
        }
        if (!empty($rule['remove_empty_paragraphs'])) {
            foreach (array('p','div') as $tag) {
                $nodes = $dom->getElementsByTagName($tag);
                for ($i = $nodes->length - 1; $i >= 0; $i--) {
                    $node = $nodes->item($i);
                    if (trim($node->textContent) === '' && $node->getElementsByTagName('img')->length === 0 && $node->parentNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }
        $root = $dom->getElementById('wp-caiji-root');
        $html = '';
        if ($root) foreach ($root->childNodes as $child) $html .= $dom->saveHTML($child);
        return trim($html);
    }


    public static function absolute_url($src, $base)
    {
        $src = trim(html_entity_decode($src));
        if (WP_Caiji_Utils::is_safe_public_url($src)) return $src;
        if (strpos($src, '//') === 0) return 'https:' . $src;
        $parts = wp_parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $src;
        if (strpos($src, '/') === 0) return $parts['scheme'] . '://' . $parts['host'] . $src;
        if (preg_match('/^(?:node|article|news|story|post)/i', $src)) return $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($src, '/');
        $path = isset($parts['path']) ? dirname($parts['path']) : '';
        return $parts['scheme'] . '://' . $parts['host'] . rtrim($path, '/') . '/' . ltrim($src, '/');
    }


}
