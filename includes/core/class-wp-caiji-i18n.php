<?php
if (!defined('ABSPATH')) {
    exit;
}

class WP_Caiji_I18n
{
    private static $buffering = false;

    public static function init()
    {
        add_action('plugins_loaded', array(__CLASS__, 'load_textdomain'), 1);
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('current_screen', array(__CLASS__, 'maybe_start_admin_buffer'));
    }

    public static function load_textdomain()
    {
        $domain = 'wp-caiji';
        unload_textdomain($domain);

        $locale = is_admin() && function_exists('get_user_locale') ? get_user_locale() : get_locale();
        if (!$locale && function_exists('get_locale')) {
            $locale = get_locale();
        }

        $mofile = WP_CAIJI_DIR . 'languages/' . $domain . '-' . $locale . '.mo';
        if ($locale && file_exists($mofile)) {
            load_textdomain($domain, $mofile);
            return;
        }

        load_plugin_textdomain($domain, false, dirname(plugin_basename(WP_CAIJI_FILE)) . '/languages');
    }

    public static function maybe_start_admin_buffer($screen)
    {
        if (self::$buffering || !is_admin() || empty($screen->id) || strpos((string)$screen->id, 'wp-caiji') === false) {
            return;
        }
        self::$buffering = true;
        ob_start(array(__CLASS__, 'translate_admin_html'));
    }

    public static function translate($text)
    {
        $text = (string)$text;
        if ($text === '' || !self::contains_cjk($text)) return $text;
        $translated = __($text, 'wp-caiji');
        if ($translated !== $text || !self::is_non_chinese_locale()) {
            return $translated;
        }
        return self::fallback_translate($text);
    }

    public static function translate_admin_html($html)
    {
        if (!is_string($html) || $html === '' || !self::contains_cjk($html)) return $html;

        $html = preg_replace_callback('/>([^<>]*[\x{4e00}-\x{9fff}][^<>]*)</u', function ($m) {
            return '>' . self::translate_text_segment($m[1]) . '<';
        }, $html);

        $attrs = array('placeholder', 'title', 'aria-label', 'data-confirm', 'data-wp-caiji-copy');
        foreach ($attrs as $attr) {
            $html = preg_replace_callback('/\s(' . preg_quote($attr, '/') . ')=("|\')([^"\']*[\x{4e00}-\x{9fff}][^"\']*)(\2)/u', function ($m) {
                return ' ' . $m[1] . '=' . $m[2] . esc_attr(self::translate($m[3])) . $m[4];
            }, $html);
        }

        return $html;
    }

    private static function translate_text_segment($text)
    {
        if (!self::contains_cjk($text)) return $text;
        return preg_replace_callback('/([^\x{4e00}-\x{9fff}]*)(.*?[\x{4e00}-\x{9fff}].*?)([^\x{4e00}-\x{9fff}]*)$/u', function ($m) {
            $leading = $m[1];
            $body = trim($m[2]);
            $trailing = $m[3];
            if ($body === '') return $m[0];
            return $leading . esc_html(self::translate($body)) . $trailing;
        }, $text);
    }

    private static function is_non_chinese_locale()
    {
        $locale = is_admin() && function_exists('get_user_locale') ? get_user_locale() : get_locale();
        return $locale && stripos((string)$locale, 'zh') !== 0;
    }

    private static function fallback_translate($text)
    {
        $map = array(
            'WP 采集助手' => 'WP Collection Assistant',
            'WP 采集' => 'WP Collection',
            '采集规则' => 'Collection Rules',
            'URL 队列' => 'URL Queue',
            '采集日志' => 'Collection Logs',
            '健康检查' => 'Health Check',
            '设置' => 'Settings',
            '概览' => 'Overview',
            '新建/编辑规则' => 'New/Edit Rule',
            '查看待采集' => 'View Pending Items',
            '中转 / 自定义' => 'Relay / Custom',
            '免费 / 免费额度' => 'Free / Free quota',
            '免费额度/免费模型' => 'Free quota / free models',
            '付费' => 'Paid',
            '中国大陆' => 'Mainland China',
            '其他国家/海外' => 'Other countries / overseas',
            '中转站/自定义' => 'Relay / Custom',
            'AI 服务商' => 'AI Provider',
            'AI 改写设置' => 'AI Rewrite Settings',
            '说明' => 'Guide',
            '官方入口' => 'Official entry',
            '平台说明' => 'Platform notes',
            '操作步骤' => 'Steps',
            '注意事项' => 'Notes',
            '我知道了' => 'Got it',
            '关闭' => 'Close',
            '启用' => 'Enable',
            '停用' => 'Disable',
            '待采' => 'Pending',
            '运行中' => 'Running',
            '成功' => 'Success',
            '失败' => 'Failed',
            '跳过' => 'Skipped',
            '信息' => 'Info',
            '警告' => 'Warning',
            '错误' => 'Error',
            '规则' => 'Rule',
            '状态' => 'Status',
            '操作' => 'Actions',
            '标题' => 'Title',
            '正文' => 'Content',
            '图片' => 'Images',
            '日志' => 'Logs',
            '队列' => 'Queue',
            '文章' => 'Post',
            '链接' => 'Link',
            '选择器' => 'Selector',
            '测试' => 'Test',
            '保存' => 'Save',
            '删除' => 'Delete',
            '编辑' => 'Edit',
            '复制' => 'Copy',
            '重试' => 'Retry',
            '筛选' => 'Filter',
            '重置' => 'Reset',
            '导入' => 'Import',
            '导出' => 'Export',
            '发布' => 'Publish',
            '草稿' => 'Draft',
            '分钟' => 'minutes',
            '秒' => 'seconds',
            '条' => 'items',
            '篇' => 'posts',
            '张' => 'images',
            '字符' => 'characters',
        );
        uksort($map, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        $out = (string)$text;
        foreach ($map as $from => $to) {
            $out = str_replace($from, $to, $out);
        }
        $punct = array('，' => ', ', '。' => '. ', '；' => '; ', '：' => ': ', '、' => ', ', '“' => '"', '”' => '"', '（' => ' (', '）' => ') ', '？' => '?', '！' => '!', '→' => ' -> ');
        $out = strtr($out, $punct);
        if (self::contains_cjk($out)) {
            $out = preg_replace('/[\x{4e00}-\x{9fff}]+/u', 'text', $out);
        }
        return trim(preg_replace('/\s+/', ' ', $out));
    }

    private static function contains_cjk($text)
    {
        return preg_match('/[\x{4e00}-\x{9fff}]/u', (string)$text) === 1;
    }
}
