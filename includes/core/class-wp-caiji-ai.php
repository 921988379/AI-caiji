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
        return "请将下面采集到的文章改写为自然、流畅、适合中文网站发布的原创表达。\n要求：\n1. 保留原意和事实，不要编造不存在的信息。\n2. 优化标题和正文表达，避免机械翻译腔。\n3. 正文允许保留必要 HTML 标签，例如 p、h2、h3、ul、ol、li、strong、em、a、img。\n4. 不要输出解释，不要输出 Markdown。\n5. 必须只返回 JSON：{\"title\":\"改写后的标题\",\"content\":\"改写后的 HTML 正文\"}";
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
        $api_key = self::preserve_or_update_secret($api_key, $existing_value);
        if ($api_key === '') return '';
        if ($existing_value !== '' && hash_equals(self::get_plain_api_key_from_value((string)$existing_value), $api_key)) {
            return (string)$existing_value;
        }
        return $api_key;
    }

    public static function get_plain_api_key_from_value($value)
    {
        return self::decrypt((string)$value);
    }

    public static function maybe_encrypt_api_key($api_key, $existing_value = '')
    {
        return self::prepare_api_key_for_storage($api_key, $existing_value);
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
        if ($endpoint === '' || !WP_Caiji_Utils::is_safe_public_url($endpoint) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            return new WP_Error('wp_caiji_ai_endpoint_unsafe', 'AI API Endpoint 无效或不安全，必须是公网 HTTPS 地址');
        }
        return $endpoint;
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
            'reject_unsafe_urls' => true,
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

        return array(
            'title' => $new_title !== '' ? $new_title : $title,
            'content' => wp_kses_post($new_content),
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
            'reject_unsafe_urls' => true,
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
