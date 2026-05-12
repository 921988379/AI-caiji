<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser/extractor/cleaner layer for WP Caiji.
 */
class WP_Caiji_Parser
{
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
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') return self::selector_match_count($scoped_html, $selector);
        return count(self::extract_all_anchor_links($scoped_html, 'https://example.com/'));
    }

    public static function slice_between_markers($html, $before_marker = '', $after_marker = '')
    {
        $html = (string)$html;
        $before_marker = (string)$before_marker;
        $after_marker = (string)$after_marker;
        if ($html === '' || ($before_marker === '' && $after_marker === '')) return $html;

        $start = 0;
        if ($before_marker !== '') {
            $pos = strpos($html, $before_marker);
            if ($pos === false) return '';
            $start = $pos + strlen($before_marker);
        }

        $end = strlen($html);
        if ($after_marker !== '') {
            $pos = strpos($html, $after_marker, $start);
            if ($pos === false) return '';
            $end = $pos;
        }

        if ($end <= $start) return '';
        return substr($html, $start, $end - $start);
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
        $scoped_html = self::slice_between_markers($html, $before, $after);
        if ($selector !== '') return self::extract_outer_html_sample($scoped_html, $selector, $max_chars);
        return mb_substr(trim($scoped_html), 0, max(200, (int)$max_chars));
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
        $path = isset($parts['path']) ? dirname($parts['path']) : '';
        return $parts['scheme'] . '://' . $parts['host'] . rtrim($path, '/') . '/' . ltrim($src, '/');
    }


}
