<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Media/image handling for WP Caiji.
 */
class WP_Caiji_Media
{
    public static function download_images($content, $base_url, $set_featured, &$featured_id, $alt_template = '', $title = '', $settings = array(), $logger = null, $rule_id = 0, $queue_id = 0, &$stats = null)
    {
        $sources = WP_Caiji_Parser::extract_image_sources($content);
        $stats = array('found'=>count($sources), 'downloaded'=>0, 'failed'=>0, 'skipped'=>0, 'featured_id'=>0);
        if (!$sources) return $content;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $downloaded = 0;
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        $max_images = max(0, min(50, (int)$settings['max_images_per_post']));
        $max_size = max(1, min(50, (int)$settings['max_image_size_mb'])) * MB_IN_BYTES;
        if ($max_images < 1) {
            $stats['skipped'] = count($sources);
            return $content;
        }
        $allowed_exts = array('jpg','jpeg','png','gif','webp');

        foreach ($sources as $src) {
            if ($downloaded >= $max_images) break;

            $absolute = WP_Caiji_Parser::absolute_url($src, $base_url);
            $absolute = WP_Caiji_Utils::normalize_url($absolute);
            if (!WP_Caiji_Utils::is_safe_public_url($absolute)) {
                self::media_log($logger, 'warning', '图片 URL 无效或被安全策略拒绝', $rule_id, $queue_id, $absolute);
                $stats['failed']++;
                continue;
            }

            $path = (string)parse_url($absolute, PHP_URL_PATH);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext && !in_array($ext, $allowed_exts, true)) {
                self::media_log($logger, 'warning', '图片扩展名不支持:' . $ext, $rule_id, $queue_id, $absolute);
                $stats['skipped']++;
                continue;
            }

            $tmp = self::download_with_retry($absolute, 20);
            if (is_wp_error($tmp)) {
                self::media_log($logger, 'warning', '图片下载失败:' . $tmp->get_error_message(), $rule_id, $queue_id, $absolute);
                $stats['failed']++;
                continue;
            }

            if (!file_exists($tmp) || filesize($tmp) <= 0 || filesize($tmp) > $max_size) {
                self::media_log($logger, 'warning', '图片文件为空或超过大小限制', $rule_id, $queue_id, $absolute);
                $stats['failed']++;
                @unlink($tmp);
                continue;
            }

            $type = wp_check_filetype_and_ext($tmp, basename($path));
            if (empty($type['type']) || strpos((string)$type['type'], 'image/') !== 0) {
                self::media_log($logger, 'warning', '图片 MIME 检测失败或不是图片', $rule_id, $queue_id, $absolute);
                $stats['failed']++;
                @unlink($tmp);
                continue;
            }

            $name = basename($path);
            if (!$name || strpos($name, '.') === false) $name = 'caiji-image-' . time() . '.' . ($type['ext'] ?: 'jpg');

            $file = array('name'=>sanitize_file_name($name), 'tmp_name'=>$tmp);
            $id = media_handle_sideload($file, 0);
            if (is_wp_error($id)) {
                self::media_log($logger, 'warning', '图片入库失败:' . $id->get_error_message(), $rule_id, $queue_id, $absolute);
                $stats['failed']++;
                @unlink($tmp);
                continue;
            }

            if (strpos((string)get_post_mime_type($id), 'image/') !== 0) {
                self::media_log($logger, 'warning', '附件 MIME 不是图片，已删除附件', $rule_id, $queue_id, $absolute);
                $stats['failed']++;
                wp_delete_attachment($id, true);
                continue;
            }

            if ($alt_template) {
                $alt = WP_Caiji_Utils::render_template($alt_template, $title, '', $base_url);
                update_post_meta($id, '_wp_attachment_image_alt', wp_strip_all_tags($alt));
            }
            if (!$featured_id && $set_featured) {
                $featured_id = (int)$id;
                $stats['featured_id'] = $featured_id;
            }

            $new = wp_get_attachment_url($id);
            if ($new) {
                $content = str_replace($src, $new, $content);
                $downloaded++;
                $stats['downloaded']++;
            } else {
                $stats['failed']++;
                self::media_log($logger, 'warning', '图片入库后无法获取附件 URL', $rule_id, $queue_id, $absolute);
            }
        }
        return $content;
    }

    private static function download_with_retry($url, $timeout)
    {
        $first = download_url($url, $timeout, false);
        if (!is_wp_error($first)) return $first;

        $code = (string)$first->get_error_code();
        $message = strtolower((string)$first->get_error_message());
        $temporary = strpos($code, 'http_') !== false || strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false || strpos($message, '429') !== false || strpos($message, '500') !== false || strpos($message, '502') !== false || strpos($message, '503') !== false || strpos($message, '504') !== false;
        if (!$temporary) return $first;

        $second = download_url($url, $timeout, false);
        return is_wp_error($second) ? $first : $second;
    }

    private static function media_log($logger, $level, $message, $rule_id, $queue_id, $url)
    {
        if (is_callable($logger)) {
            call_user_func($logger, $level, $message, (int)$rule_id, (int)$queue_id, (string)$url);
        }
    }
}
