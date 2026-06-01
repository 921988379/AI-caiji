<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI rewrite client for WP Caiji.
 *
 * Uses OpenAI-compatible chat completions endpoints. API key is now stored as
 * plain text by request, while previously encrypted values remain readable for
 * compatibility.
 */
class WP_Caiji_AI
{
    const ENC_PREFIX = 'caiji_enc:v1:';

    public static function default_prompt()
    {
        return "请将下面采集到的文章改写为自然、流畅、适合中文网站发布的原创表达。\n要求：\n1. 保留原意和事实，不要编造不存在的信息。\n2. 优化标题和正文表达，避免机械翻译腔。\n3. 正文允许保留必要 HTML 标签，例如 p、h2、h3、ul、ol、li、strong、em、a、img。\n4. 正文中一些需要数据的段落，请你在网上找到对应的数据，并在数据下面说明数据来源和RUL。\n5. 正文尾部增加FAQ。\n6. 不要输出解释，不要输出 Markdown。\n7. 必须只返回 JSON：{\"title\":\"改写后的标题\",\"content\":\"改写后的 HTML 正文\"}";
    }

    public static function legacy_default_prompts()
    {
        return array(
            "请将下面采集到的文章改写为自然、流畅、适合中文网站发布的原创表达。\n要求：\n1. 保留原意和事实，不要编造不存在的信息。\n2. 优化标题和正文表达，避免机械翻译腔。\n3. 正文允许保留必要 HTML 标签，例如 p、h2、h3、ul、ol、li、strong、em、a、img。\n4. 不要输出解释，不要输出 Markdown。\n5. 必须只返回 JSON：{\"title\":\"改写后的标题\",\"content\":\"改写后的 HTML 正文\"}",
        );
    }

    public static function mask_secret($secret)
    {
        $secret = (string)$secret;
        if ($secret === '') return '未设置';
        if (strlen($secret) <= 8) return str_repeat('*', strlen($secret));
        return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
    }

    public static function preserve_or_update_secret($incoming, $existing_value = '')
    {
        $incoming = trim((string)$incoming);
        if ($incoming === '') return (string)$existing_value;
        if (preg_match('/^\*+$/', $incoming) || preg_match('/^[^*]{1,8}\*{4,}[^*]{1,8}$/', $incoming)) {
            return (string)$existing_value;
        }
        return $incoming;
    }

    public static function prepare_api_key_for_storage($api_key, $existing_value = '')
    {
        return trim((string)$api_key);
    }

    public static function get_plain_api_key_from_value($value)
    {
        return self::decrypt((string)$value);
    }

    public static function maybe_encrypt_api_key($api_key, $existing_value = '')
    {
        return self::prepare_api_key_for_storage($api_key, $existing_value);
    }

    public static function language_options()
    {
        return array(
            'zh-CN' => '中文',
            'en' => '英文',
            'ja' => '日文',
            'ko' => '韩文',
            'es' => '西班牙文',
            'fr' => '法文',
            'de' => '德文',
            'auto' => '不限制/不检测',
        );
    }

    public static function sanitize_language($language, $allow_empty = false)
    {
        $language = sanitize_key((string)$language);
        if ($allow_empty && $language === '') return '';
        return array_key_exists($language, self::language_options()) ? $language : 'zh-CN';
    }

    public static function language_label($language)
    {
        $language = self::sanitize_language($language);
        $options = self::language_options();
        return $options[$language] ?? $options['zh-CN'];
    }

    public static function detect_language_matches($title, $content, $language)
    {
        $language = self::sanitize_language($language);
        if ($language === 'auto') return true;

        $text = wp_strip_all_tags((string)$title . "\n" . (string)$content);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') return false;

        preg_match_all('/[\p{Han}]/u', $text, $han);
        preg_match_all('/[\p{Hiragana}\p{Katakana}]/u', $text, $kana);
        preg_match_all('/[\x{AC00}-\x{D7AF}]/u', $text, $hangul);
        preg_match_all('/[A-Za-zÀ-ÿ]+/u', $text, $latin);

        $han_count = count($han[0]);
        $kana_count = count($kana[0]);
        $hangul_count = count($hangul[0]);
        $latin_count = count($latin[0]);
        $letter_count = max(1, $han_count + $kana_count + $hangul_count + $latin_count);
        $latin_ratio = $latin_count / $letter_count;

        if ($language === 'zh-CN') return $han_count >= 12 && ($han_count / $letter_count) >= 0.25;
        if ($language === 'ja') return $kana_count >= 8 || ($kana_count >= 3 && $han_count >= 8);
        if ($language === 'ko') return $hangul_count >= 12 && ($hangul_count / $letter_count) >= 0.30;

        $lower = mb_strtolower(' ' . $text . ' ', 'UTF-8');
        $scores = array(
            'en' => preg_match_all('/\b(the|and|or|of|to|in|for|with|that|is|are|this|from|by|as)\b/u', $lower),
            'es' => preg_match_all('/\b(el|la|los|las|de|del|que|para|con|una|por|como|este|esta|son|más)\b/u', $lower),
            'fr' => preg_match_all('/\b(le|la|les|des|de|du|que|pour|avec|une|dans|est|sont|plus|sur)\b/u', $lower),
            'de' => preg_match_all('/\b(der|die|das|und|oder|von|mit|für|ist|sind|ein|eine|nicht|auf|zu)\b/u', $lower),
        );
        if (isset($scores[$language])) {
            $max_other = 0;
            foreach ($scores as $key => $score) {
                if ($key !== $language) $max_other = max($max_other, (int)$score);
            }
            return $latin_ratio >= 0.55 && ((int)$scores[$language] >= 2 || ((int)$scores[$language] >= 1 && (int)$scores[$language] >= $max_other));
        }

        return true;
    }

    public static function get_api_key($settings = array())
    {
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        return self::decrypt((string)($settings['ai_api_key'] ?? ''));
    }

    private static function crypto_key()
    {
        return hash('sha256', wp_salt('auth') . '|wp-caiji-ai', true);
    }

    private static function encrypt($plain)
    {
        if (!function_exists('openssl_encrypt')) return (string)$plain;
        $iv = random_bytes(16);
        $cipher = openssl_encrypt((string)$plain, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) return (string)$plain;
        return self::ENC_PREFIX . base64_encode($iv . $cipher);
    }

    private static function decrypt($value)
    {
        $value = (string)$value;
        if ($value === '') return '';
        if (strpos($value, self::ENC_PREFIX) !== 0) return $value;
        if (!function_exists('openssl_decrypt')) return '';
        $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) <= 16) return '';
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    public static function normalize_endpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') $endpoint = 'https://api.openai.com/v1/chat/completions';
        $endpoint = rtrim($endpoint);
        $parts = wp_parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) return '';

        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if ($path === '') {
            $path = '/v1/chat/completions';
        } elseif (preg_match('#/chat/completions$#i', $path)) {
            // Already a full OpenAI-compatible chat completions endpoint.
        } elseif (preg_match('#/v1$#i', $path)) {
            $path .= '/chat/completions';
        } else {
            $path .= '/v1/chat/completions';
        }

        $url = strtolower($parts['scheme']) . '://' . $parts['host'];
        if (!empty($parts['port'])) $url .= ':' . (int)$parts['port'];
        $url .= $path;
        if (!empty($parts['query'])) $url .= '?' . $parts['query'];
        return $url;
    }

    public static function validate_endpoint($endpoint)
    {
        $endpoint = self::normalize_endpoint($endpoint);
        if ($endpoint === '' || !self::is_safe_public_endpoint($endpoint)) {
            return new WP_Error('wp_caiji_ai_endpoint_unsafe', 'AI API Endpoint 无效或不安全，必须是公网 HTTP/HTTPS 地址');
        }
        return $endpoint;
    }

    public static function is_safe_public_endpoint($url)
    {
        $url = trim((string)$url);
        if ($url === '') return false;

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
        if (!empty($parts['user']) || !empty($parts['pass'])) return false;

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) return false;

        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
            if ($port < 1 || $port > 65535) return false;
        }

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
            if (!WP_Caiji_Utils::is_public_ip($ip)) return false;
        }

        return true;
    }

    public static function rewrite($title, $content, $rule, $settings)
    {
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        $api_key = self::get_api_key($settings);
        if ($api_key === '') return new WP_Error('wp_caiji_ai_no_key', 'AI API Key 未设置');

        $endpoint = self::validate_endpoint($settings['ai_endpoint'] ?? '');
        if (is_wp_error($endpoint)) return $endpoint;

        $model = trim((string)($settings['ai_model'] ?? '')) ?: 'gpt-5.5';
        $prompt = trim((string)($rule['ai_rewrite_prompt'] ?? ''));
        if ($prompt === '') $prompt = trim((string)($settings['ai_rewrite_prompt'] ?? ''));
        if ($prompt === '') $prompt = self::default_prompt();
        $target_language = self::sanitize_language($rule['ai_rewrite_language'] ?? '', true);
        if ($target_language === '') $target_language = self::sanitize_language($settings['ai_rewrite_language'] ?? 'zh-CN');
        if ($target_language !== 'auto') {
            $language_label = self::language_label($target_language);
            $prompt .= "

语言要求：请将改写后的标题和正文全部输出为{$language_label}。如果原文不是{$language_label}，请先翻译再改写。";
        }

        $max_chars = max(1000, min(60000, (int)($settings['ai_max_input_chars'] ?? 12000)));
        $clean_content = mb_substr((string)$content, 0, $max_chars);
        $temperature = max(0, min(2, (float)($settings['ai_temperature'] ?? 0.7)));
        $timeout = max(10, min(120, (int)($settings['ai_timeout_seconds'] ?? 45)));

        $payload = array(
            'model' => $model,
            'temperature' => $temperature,
            'messages' => array(
                array('role' => 'system', 'content' => $prompt),
                array('role' => 'user', 'content' => "标题：\n" . wp_strip_all_tags((string)$title) . "\n\n正文 HTML：\n" . $clean_content),
            ),
        );

        $response = wp_remote_post($endpoint, array(
            'timeout' => $timeout,
            'redirection' => 0,
            'reject_unsafe_urls' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ));

        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('wp_caiji_ai_http_error', 'AI API 请求失败：HTTP ' . $code . ' ' . wp_html_excerpt($body, 300));
        }

        $data = json_decode($body, true);
        $message = '';
        if (is_array($data)) {
            $message = (string)($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');
        }
        if (trim($message) === '') return new WP_Error('wp_caiji_ai_empty', 'AI API 返回内容为空');

        $parsed = self::parse_model_output($message);
        $new_title = trim(wp_strip_all_tags((string)($parsed['title'] ?? '')));
        $new_content = trim((string)($parsed['content'] ?? ''));
        if ($new_content === '') return new WP_Error('wp_caiji_ai_parse_failed', 'AI 改写结果解析失败或正文为空；响应片段：' . self::safe_excerpt($message, 260));
        if (mb_strlen(wp_strip_all_tags($new_content)) < 80) {
            return new WP_Error('wp_caiji_ai_too_short', 'AI 改写结果正文过短，已判定为失败；响应片段：' . self::safe_excerpt($message, 260));
        }
        $new_content = wp_kses_post($new_content);
        $new_title = $new_title !== '' ? $new_title : $title;
        if (!empty($settings['ai_language_check']) && !self::detect_language_matches($new_title, $new_content, $target_language)) {
            return new WP_Error('wp_caiji_ai_language_mismatch', 'AI 改写结果未通过目标语言检测，目标语言：' . self::language_label($target_language) . '；已判定为失败');
        }

        return array(
            'title' => $new_title,
            'content' => $new_content,
        );
    }

    public static function test_connection($settings)
    {
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        $api_key = self::get_api_key($settings);
        $endpoint = self::validate_endpoint($settings['ai_endpoint'] ?? '');
        $model = trim((string)($settings['ai_model'] ?? '')) ?: 'gpt-5.5';
        $timeout = max(10, min(120, (int)($settings['ai_timeout_seconds'] ?? 45)));
        $result = array(
            'ok' => false,
            'endpoint' => is_wp_error($endpoint) ? self::normalize_endpoint($settings['ai_endpoint'] ?? '') : $endpoint,
            'model' => $model,
            'http_code' => '',
            'latency_ms' => '',
            'message' => '',
        );
        if ($api_key === '') {
            $result['message'] = 'AI API Key 未设置';
            return $result;
        }
        if (is_wp_error($endpoint)) {
            $result['message'] = $endpoint->get_error_message();
            return $result;
        }

        $payload = array(
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 16,
            'messages' => array(
                array('role' => 'system', 'content' => 'You are an API connectivity test. Reply with OK only.'),
                array('role' => 'user', 'content' => 'OK'),
            ),
        );
        $started = microtime(true);
        $response = wp_remote_post($endpoint, array(
            'timeout' => $timeout,
            'redirection' => 0,
            'reject_unsafe_urls' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ));
        $result['latency_ms'] = (string)round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            $result['message'] = $response->get_error_message();
            return $result;
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $result['http_code'] = (string)$code;
        if ($code < 200 || $code >= 300) {
            $result['message'] = 'HTTP ' . $code . ' ' . self::safe_excerpt($body, 260);
            return $result;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $result['message'] = 'HTTP 成功，但返回不是 JSON：' . self::safe_excerpt($body, 220);
            return $result;
        }
        $message = (string)($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');
        $result['ok'] = true;
        $result['message'] = trim($message) !== '' ? self::safe_excerpt($message, 160) : 'HTTP 成功，JSON 解析成功';
        return $result;
    }

    private static function parse_model_output($message)
    {
        $message = trim((string)$message);
        $message = preg_replace('/^```(?:json)?\s*/i', '', $message);
        $message = preg_replace('/\s*```$/', '', $message);
        $json = json_decode($message, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $message, $m)) {
            $json = json_decode($m[0], true);
        }
        if (is_array($json)) {
            return array(
                'title' => (string)($json['title'] ?? ''),
                'content' => (string)($json['content'] ?? ''),
            );
        }
        return array('title' => '', 'content' => $message);
    }

    private static function safe_excerpt($text, $length = 300)
    {
        $text = wp_strip_all_tags((string)$text);
        $text = preg_replace('/sk-[A-Za-z0-9_\-]{12,}/', 'sk-***', $text);
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return wp_html_excerpt(trim($text), max(80, (int)$length));
    }
}
