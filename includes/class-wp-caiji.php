<?php
if (!defined('ABSPATH')) {
    exit;
}

class WP_Caiji
{
    const CRON_DISCOVER = 'wp_caiji_cron_discover';
    const CRON_COLLECT = 'wp_caiji_cron_collect';
    const CRON_DISCOVER_RULE_ONCE = 'wp_caiji_discover_rule_once';
    const CRON_COLLECT_RULE_ONCE = 'wp_caiji_collect_rule_once';
    const META_SOURCE_URL = '_wp_caiji_source_url';
    const OPTION_SETTINGS = 'wp_caiji_settings_v2';
    const OPTION_SCHEMA_VERSION = 'wp_caiji_schema_version';
    const SCHEMA_VERSION = '2.0.4';
    const LOCK_DISCOVER = 'wp_caiji_lock_discover';
    const LOCK_COLLECT = 'wp_caiji_lock_collect';

    private static $instance = null;
    private $rules_table;
    private $queue_table;
    private $logs_table;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        global $wpdb;
        $this->rules_table = $wpdb->prefix . 'caiji_rules';
        $this->queue_table = $wpdb->prefix . 'caiji_queue';
        $this->logs_table = $wpdb->prefix . 'caiji_logs';

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(self::CRON_DISCOVER, array($this, 'cron_discover'));
        add_action(self::CRON_COLLECT, array($this, 'cron_collect'));
        add_action(self::CRON_DISCOVER_RULE_ONCE, array($this, 'cron_discover_rule_once'));
        add_action(self::CRON_COLLECT_RULE_ONCE, array($this, 'cron_collect_rule_once'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade_schema'));
        add_action('admin_post_wp_caiji_save_rule', array($this, 'save_rule'));
        add_action('admin_post_wp_caiji_delete_rule', array($this, 'delete_rule'));
        add_action('admin_post_wp_caiji_toggle_rule', array($this, 'toggle_rule'));
        add_action('admin_post_wp_caiji_copy_rule', array($this, 'copy_rule'));
        add_action('admin_post_wp_caiji_export_rules', array($this, 'export_rules'));
        add_action('admin_post_wp_caiji_import_rules', array($this, 'import_rules'));
        add_action('admin_post_wp_caiji_save_settings', array($this, 'save_settings'));
        add_action('admin_post_wp_caiji_test_ai_api', array($this, 'test_ai_api'));
        add_action('admin_post_wp_caiji_health_action', array($this, 'health_action'));
        add_action('admin_post_wp_caiji_export_diagnostics', array($this, 'export_diagnostics'));
        add_action('admin_post_wp_caiji_discover_rule', array($this, 'discover_rule_now'));
        add_action('admin_post_wp_caiji_collect_rule', array($this, 'collect_rule_now'));
        add_action('admin_post_wp_caiji_test_rule', array($this, 'test_rule'));
        add_action('admin_post_wp_caiji_test_list', array($this, 'test_list'));
        add_action('admin_post_wp_caiji_bulk_queue', array($this, 'bulk_queue'));
        add_action('admin_post_wp_caiji_clean_queue', array($this, 'clean_queue'));
        add_action('admin_post_wp_caiji_retry_queue', array($this, 'retry_queue'));
        add_action('admin_post_wp_caiji_delete_queue', array($this, 'delete_queue'));
        add_action('admin_post_wp_caiji_clear_logs', array($this, 'clear_logs'));
        add_action('add_meta_boxes', array($this, 'add_post_ai_meta_box'));
        add_action('admin_post_wp_caiji_rewrite_post_ai', array($this, 'rewrite_post_ai'));
        WP_Caiji_Updater::init($this->get_settings());
    }

    public static function activate()
    {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules_static'));
        self::create_tables();
        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
        }
        self::schedule_events();
    }

    public static function deactivate()
    {
        self::clear_event_public(self::CRON_DISCOVER);
        self::clear_event_public(self::CRON_COLLECT);
    }

    public static function clear_event_public($hook)
    {
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }

    public static function create_tables()
    {
        WP_Caiji_DB::create_tables();
    }

    public static function maybe_upgrade_schema()
    {
        $installed = (string)get_option(self::OPTION_SCHEMA_VERSION, '');
        if (version_compare($installed ?: '0', self::SCHEMA_VERSION, '<')) {
            self::create_tables();
            update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
        }
    }

    public static function maybe_add_column($table, $column, $sql)
    {
        WP_Caiji_DB::maybe_add_column($table, $column, $sql);
    }

    public static function cron_schedules_static($schedules)
    {
        $schedules['wp_caiji_5min'] = array('interval' => 300, 'display' => '每 5 分钟');
        $schedules['wp_caiji_10min'] = array('interval' => 600, 'display' => '每 10 分钟');
        $schedules['wp_caiji_30min'] = array('interval' => 1800, 'display' => '每 30 分钟');
        return $schedules;
    }

    public function cron_schedules($schedules)
    {
        return self::cron_schedules_static($schedules);
    }

    public static function schedule_events()
    {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $discover = !empty($settings['discover_interval']) ? $settings['discover_interval'] : 'wp_caiji_30min';
        $collect = !empty($settings['collect_interval']) ? $settings['collect_interval'] : 'wp_caiji_10min';
        if (!wp_next_scheduled(self::CRON_DISCOVER)) {
            $scheduled = wp_schedule_event(time() + 120, $discover, self::CRON_DISCOVER);
            if ($scheduled === false) update_option('wp_caiji_cron_discover_error', current_time('mysql'), false);
            else delete_option('wp_caiji_cron_discover_error');
        }
        if (!wp_next_scheduled(self::CRON_COLLECT)) {
            $scheduled = wp_schedule_event(time() + 180, $collect, self::CRON_COLLECT);
            if ($scheduled === false) update_option('wp_caiji_cron_collect_error', current_time('mysql'), false);
            else delete_option('wp_caiji_cron_collect_error');
        }
    }


    public function admin_assets($hook)
    {
        if (strpos((string)$hook, 'wp-caiji') === false && !in_array((string)$hook, array('post.php', 'post-new.php'), true)) return;
        wp_enqueue_style('wp-caiji-admin', WP_CAIJI_URL . 'assets/admin.css', array(), WP_CAIJI_VERSION);
        wp_enqueue_script('wp-caiji-admin', WP_CAIJI_URL . 'assets/admin.js', array(), WP_CAIJI_VERSION, true);
    }

    public function admin_menu()
    {
        add_menu_page('WP 采集助手', 'WP 采集', 'manage_options', 'wp-caiji', array($this, 'render_dashboard'), 'dashicons-download', 58);
        add_submenu_page('wp-caiji', '采集规则', '采集规则', 'manage_options', 'wp-caiji-rules', array($this, 'render_rules'));
        add_submenu_page('wp-caiji', 'URL 队列', 'URL 队列', 'manage_options', 'wp-caiji-queue', array($this, 'render_queue'));
        add_submenu_page('wp-caiji', '采集日志', '采集日志', 'manage_options', 'wp-caiji-logs', array($this, 'render_logs'));
        add_submenu_page('wp-caiji', '设置', '设置', 'manage_options', 'wp-caiji-settings', array($this, 'render_settings'));
        add_submenu_page('wp-caiji', '健康检查', '健康检查', 'manage_options', 'wp-caiji-health', array($this, 'render_health'));
    }

    private function page_url($page, $args = array())
    {
        return add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php'));
    }

    public function render_page_header($title, $subtitle = '')
    {
        $current = isset($_GET['page']) ? sanitize_key($_GET['page']) : 'wp-caiji';
        $items = array(
            'wp-caiji' => '概览',
            'wp-caiji-rules' => '采集规则',
            'wp-caiji-queue' => 'URL 队列',
            'wp-caiji-logs' => '采集日志',
            'wp-caiji-settings' => '设置',
            'wp-caiji-health' => '健康检查',
        );
        ?>
        <div class="wp-caiji-hero">
            <div>
                <div class="wp-caiji-kicker"><span></span> WP CAIJI CONTROL</div>
                <h1><?php echo esc_html($title); ?></h1>
                <?php if ($subtitle !== ''): ?><p><?php echo esc_html($subtitle); ?></p><?php endif; ?>
            </div>
            <div class="wp-caiji-hero-actions">
                <a class="button button-primary" href="<?php echo esc_url($this->page_url('wp-caiji-rules')); ?>">新建/编辑规则</a>
                <a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'pending'))); ?>">查看待采集</a>
            </div>
        </div>
        <nav class="wp-caiji-nav" aria-label="WP 采集页面导航">
            <?php foreach ($items as $page => $label): ?>
                <a class="<?php echo $current === $page ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->page_url($page)); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    public function status_badge($status)
    {
        $status = (string)$status;
        $labels = array(
            'pending' => '待采',
            'running' => '运行中',
            'success' => '成功',
            'failed' => '失败',
            'skipped' => '跳过',
            'info' => '信息',
            'warning' => '警告',
            'error' => '错误',
            'enabled' => '启用',
            'disabled' => '停用',
        );
        $class = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($status));
        return '<span class="wp-caiji-badge wp-caiji-badge-' . esc_attr($class) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
    }

    public function admin_page_url($page, $args = array())
    {
        return $this->page_url($page, $args);
    }

    public function rules_table()
    {
        return $this->rules_table;
    }

    public function queue_table()
    {
        return $this->queue_table;
    }

    public function logs_table()
    {
        return $this->logs_table;
    }

    public function render_dashboard()
    {
        global $wpdb;
        if (!current_user_can('manage_options')) return;
        $settings = $this->get_settings();
        $cache_key = 'wp_caiji_dashboard_stats_v1';
        $stats = get_transient($cache_key);
        if (!is_array($stats)) {
            $counts = array();
            foreach (array('pending','running','success','failed','skipped') as $s) {
                $counts[$s] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->queue_table} WHERE status=%s", $s));
            }
            $rules_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->rules_table}");
            $today = current_time('Y-m-d') . ' 00:00:00';
            $today_success = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->queue_table} WHERE status='success' AND finished_at >= %s", $today));
            $today_failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->queue_table} WHERE status='failed' AND finished_at >= %s", $today));
            $today_logs_error = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->logs_table} WHERE level='error' AND created_at >= %s", $today));
            $today_discovered = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->queue_table} WHERE discovered_at >= %s", $today));
            $today_ai_issues = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->logs_table} WHERE created_at >= %s AND message LIKE %s", $today, '%' . $wpdb->esc_like('AI') . '%'));
            $today_image_issues = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->logs_table} WHERE created_at >= %s AND message LIKE %s", $today, '%' . $wpdb->esc_like('图片') . '%'));
            $running_timeout = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->queue_table} WHERE status='running' AND started_at < %s", date('Y-m-d H:i:s', current_time('timestamp') - ((int)$settings['running_timeout_minutes'] * 60))));
            $seven_days_ago = date('Y-m-d 00:00:00', current_time('timestamp') - 6 * DAY_IN_SECONDS);
            $daily_rows = $wpdb->get_results($wpdb->prepare("SELECT DATE(COALESCE(finished_at, discovered_at)) day,
                SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) success_count,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed_count,
                COUNT(*) total_count
                FROM {$this->queue_table}
                WHERE COALESCE(finished_at, discovered_at) >= %s
                GROUP BY DATE(COALESCE(finished_at, discovered_at))
                ORDER BY day DESC LIMIT 7", $seven_days_ago), ARRAY_A);
            $recent_errors = $wpdb->get_results("SELECT l.*, r.name rule_name FROM {$this->logs_table} l LEFT JOIN {$this->rules_table} r ON l.rule_id=r.id WHERE l.level IN ('error','warning') ORDER BY l.id DESC LIMIT 8", ARRAY_A);
            $top_rules = $wpdb->get_results("SELECT r.id, r.name,
                SUM(CASE WHEN q.status='pending' THEN 1 ELSE 0 END) pending_count,
                SUM(CASE WHEN q.status='success' THEN 1 ELSE 0 END) success_count,
                SUM(CASE WHEN q.status='failed' THEN 1 ELSE 0 END) failed_count,
                COUNT(q.id) total_count
                FROM {$this->rules_table} r LEFT JOIN {$this->queue_table} q ON r.id=q.rule_id GROUP BY r.id ORDER BY failed_count DESC, pending_count DESC LIMIT 8", ARRAY_A);
            $stats = compact('counts', 'rules_count', 'today_discovered', 'today_success', 'today_failed', 'today_logs_error', 'today_ai_issues', 'today_image_issues', 'running_timeout', 'daily_rows', 'recent_errors', 'top_rules');
            set_transient($cache_key, $stats, 60);
        } else {
            extract($stats, EXTR_SKIP);
        }
        $next_discover = wp_next_scheduled(self::CRON_DISCOVER);
        $next_collect = wp_next_scheduled(self::CRON_COLLECT);
        ?>
        <div class="wrap wp-caiji-page">
            <?php $this->render_page_header('WP 采集助手', '长期自动采集控制台:发现链接、队列采集、失败重试与健康检查集中管理。'); ?>
            <div class="wp-caiji-callout">长期自动采集建议:使用队列模式,先发现文章链接,再按批次采集文章,失败后可重试。</div>
            <div class="wp-caiji-grid">
                <?php foreach (array('规则'=>$rules_count,'待采集'=>$counts['pending'],'运行中'=>$counts['running'],'成功'=>$counts['success'],'失败'=>$counts['failed'],'跳过'=>$counts['skipped']) as $k=>$v): ?>
                    <div class="wp-caiji-stat"><strong><?php echo esc_html($v); ?></strong><br><?php echo esc_html($k); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="wp-caiji-section-title"><span>今日统计</span></div>
            <div class="wp-caiji-grid">
                <?php foreach (array('今日发现'=>$today_discovered,'今日成功'=>$today_success,'今日失败'=>$today_failed,'今日错误日志'=>$today_logs_error,'AI 相关'=>$today_ai_issues,'图片相关'=>$today_image_issues,'超时运行'=>$running_timeout) as $k=>$v): ?>
                    <div class="wp-caiji-stat"><strong><?php echo esc_html($v); ?></strong><br><?php echo esc_html($k); ?></div>
                <?php endforeach; ?>
            </div>
            <div class="wp-caiji-section-title"><span>最近 7 天趋势</span></div>
            <table class="widefat striped" style="max-width:1000px"><thead><tr><th>日期</th><th>总队列</th><th>成功</th><th>失败</th><th>成功率</th></tr></thead><tbody>
                <?php if (!$daily_rows): ?><tr><td colspan="5">暂无数据</td></tr><?php endif; ?>
                <?php foreach ($daily_rows as $d): $done = max(1, (int)$d['success_count'] + (int)$d['failed_count']); $rate = round(((int)$d['success_count'] / $done) * 100, 2); ?><tr><td><?php echo esc_html($d['day']); ?></td><td><?php echo intval($d['total_count']); ?></td><td><?php echo intval($d['success_count']); ?></td><td><?php echo intval($d['failed_count']); ?></td><td><?php echo esc_html($rate); ?>%</td></tr><?php endforeach; ?>
            </tbody></table>
            <div class="wp-caiji-section-title"><span>定时状态</span></div>
            <p>发现链接任务:<?php echo $next_discover ? esc_html(date_i18n('Y-m-d H:i:s', $next_discover)) : '未计划'; ?></p>
            <p>采集文章任务:<?php echo $next_collect ? esc_html(date_i18n('Y-m-d H:i:s', $next_collect)) : '未计划'; ?></p>
            <p><strong>建议服务器 Cron:</strong></p>
            <code>*/10 * * * * curl -s <?php echo esc_html(home_url('/wp-cron.php?doing_wp_cron')); ?> &gt;/dev/null 2&gt;&amp;1</code>
            <p style="margin-top:20px"><a class="button button-primary" href="<?php echo esc_url($this->page_url('wp-caiji-rules')); ?>">管理采集规则</a> <a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-queue')); ?>">查看队列</a> <a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-settings')); ?>">插件设置</a> <a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-health')); ?>">健康检查</a></p>
            <div class="wp-caiji-section-title"><span>规则概览</span></div>
            <table class="widefat striped" style="max-width:1000px"><thead><tr><th>ID</th><th>规则</th><th>待采</th><th>成功</th><th>失败</th><th>失败率</th><th>操作</th></tr></thead><tbody>
                <?php if (!$top_rules): ?><tr><td colspan="7">暂无规则</td></tr><?php endif; ?>
                <?php foreach ($top_rules as $r): $done = max(1, (int)$r['success_count'] + (int)$r['failed_count']); $fail_rate = round(((int)$r['failed_count'] / $done) * 100, 2); ?><tr><td><?php echo intval($r['id']); ?></td><td><?php echo esc_html($r['name']); ?></td><td><?php echo intval($r['pending_count']); ?></td><td><?php echo intval($r['success_count']); ?></td><td><?php echo intval($r['failed_count']); ?></td><td><?php echo esc_html($fail_rate); ?>%</td><td><a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('rule_id'=>$r['id']))); ?>">看队列</a></td></tr><?php endforeach; ?>
            </tbody></table>
            <div class="wp-caiji-section-title"><span>最近错误/警告</span></div>
            <table class="widefat striped" style="max-width:1000px"><thead><tr><th>时间</th><th>级别</th><th>规则</th><th>消息</th><th>URL</th></tr></thead><tbody>
                <?php if (!$recent_errors): ?><tr><td colspan="5">暂无错误</td></tr><?php endif; ?>
                <?php foreach ($recent_errors as $e): ?><tr><td><?php echo esc_html($e['created_at']); ?></td><td><?php echo esc_html($e['level']); ?></td><td><?php echo esc_html($e['rule_name']); ?></td><td><?php echo esc_html(wp_html_excerpt($e['message'], 80)); ?></td><td><?php echo esc_html(wp_html_excerpt($e['url'], 80)); ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <p>请确认采集行为符合目标网站 robots、版权声明和服务条款。长期采集建议先保存为草稿。</p>
        </div>
        <?php
    }

    public function render_rules()
    {
        global $wpdb;
        if (!current_user_can('manage_options')) return;
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $editing = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", $edit_id), ARRAY_A) : null;
        $rule = wp_parse_args($editing ?: array(), $this->default_rule());
        $has_test_result_modal = $edit_id && ($this->get_test_result('article', 'article_test') || $this->get_test_result('list', 'list_test'));
        $rules = $wpdb->get_results("SELECT r.*,
            SUM(CASE WHEN q.status='pending' THEN 1 ELSE 0 END) pending_count,
            SUM(CASE WHEN q.status='success' THEN 1 ELSE 0 END) success_count,
            SUM(CASE WHEN q.status='failed' THEN 1 ELSE 0 END) failed_count
            FROM {$this->rules_table} r LEFT JOIN {$this->queue_table} q ON r.id=q.rule_id GROUP BY r.id ORDER BY r.id DESC", ARRAY_A);
        ?>
        <div class="wrap wp-caiji-page">
            <?php $this->render_page_header('采集规则', '配置站点来源、内容选择器、正文清洗、AI 改写与发布策略。'); ?>
            <div class="wp-caiji-rule-actions">
                <?php if (!empty($_GET['scheduled'])): ?><div class="notice notice-success inline"><p><?php echo esc_html($_GET['scheduled'] === 'collect' ? '采集任务已提交,将在约 1 秒后后台执行。' : '发现链接任务已提交,将在约 1 秒后后台执行。'); ?></p></div><?php endif; ?>
                <button type="button" class="button button-primary wp-caiji-modal-open" data-target="wp-caiji-rule-modal">新增采集规则</button>
                <?php if ($editing): ?><a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-rules')); ?>">退出编辑</a><?php endif; ?>
            </div>
            <div id="wp-caiji-rule-modal" class="wp-caiji-modal <?php echo ($editing && !$has_test_result_modal) ? 'is-open' : ''; ?>" aria-hidden="<?php echo ($editing && !$has_test_result_modal) ? 'false' : 'true'; ?>">
                <div class="wp-caiji-modal-backdrop" data-wp-caiji-modal-close></div>
                <div class="wp-caiji-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wp-caiji-rule-modal-title">
                    <div class="wp-caiji-modal-header">
                        <div>
                            <div class="wp-caiji-kicker"><span></span> RULE BUILDER</div>
                            <h2 id="wp-caiji-rule-modal-title"><?php echo $editing ? '编辑采集规则' : '新增采集规则'; ?></h2>
                            <p>按分组填写规则,保存后可进行列表页测试和文章预览测试。</p>
                        </div>
                        <button type="button" class="wp-caiji-modal-close" data-wp-caiji-modal-close aria-label="关闭">×</button>
                    </div>
                    <div class="wp-caiji-rule-tabs" role="tablist" aria-label="采集规则配置分组"></div>
                    <div class="wp-caiji-modal-body">
            <form class="wp-caiji-rule-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wp_caiji_save_rule'); ?>
                <input type="hidden" name="action" value="wp_caiji_save_rule"><input type="hidden" name="id" value="<?php echo esc_attr($edit_id); ?>">
                <div class="wp-caiji-section"><h2>基础信息</h2>
                <table class="form-table" role="presentation">
                    <tr><th>规则名称</th><td><input name="name" class="regular-text" value="<?php echo esc_attr($rule['name']); ?>" required></td></tr>
                    <tr><th>规则状态</th><td><label><input name="enabled" type="checkbox" value="1" <?php checked($rule['enabled'],1); ?>> 启用定时发现/采集</label> &nbsp; <label><input name="dedupe_title" type="checkbox" value="1" <?php checked($rule['dedupe_title'],1); ?>> 标题/来源去重</label></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>来源与文章 URL</h2>
                <table class="form-table" role="presentation">
                    <tr><th>列表页 URL</th><td><textarea name="list_urls" rows="4" class="large-text code" placeholder="每行一个栏目/列表页 URL,用于自动发现文章链接"><?php echo esc_textarea($rule['list_urls']); ?></textarea></td></tr>
                    <tr><th>分页规则</th><td><input name="pagination_pattern" class="regular-text" value="<?php echo esc_attr($rule['pagination_pattern']); ?>" placeholder="例如:https://example.com/news/page/{page}"> 页码 <input name="page_start" type="number" min="1" value="<?php echo esc_attr($rule['page_start']); ?>" style="width:80px"> - <input name="page_end" type="number" min="1" value="<?php echo esc_attr($rule['page_end']); ?>" style="width:80px"></td></tr>
                    <tr><th>文章链接选择器</th><td><input name="link_selector" class="regular-text" value="<?php echo esc_attr($rule['link_selector']); ?>" placeholder="例如:.news-list a 或 //div[@class='list']//a"><p class="description">默认使用 CSS/XPath 提取链接。填写“前后代码”后，会先截取列表区域，再提取文章 URL。</p></td></tr>
                    <tr><th>链接前后代码</th><td>
                        <textarea name="link_before_marker" rows="3" class="large-text code" placeholder="前代码/开始标记，例如:&lt;div class=&quot;article-list&quot;&gt;"><?php echo esc_textarea($rule['link_before_marker']); ?></textarea>
                        <p style="margin:8px 0">到</p>
                        <textarea name="link_after_marker" rows="3" class="large-text code" placeholder="后代码/结束标记，例如:&lt;/div&gt;&lt;div class=&quot;page&quot;&gt;"><?php echo esc_textarea($rule['link_after_marker']); ?></textarea>
                        <p class="description">可只填前代码或只填后代码；选择器留空时自动提取片段里的所有 a 标签链接。</p>
                    </td></tr>
                    <tr><th>手动文章 URL</th><td><textarea name="manual_urls" rows="5" class="large-text code" placeholder="每行一个文章 URL,保存后可直接加入队列"><?php echo esc_textarea($rule['manual_urls']); ?></textarea></td></tr>
                    <tr><th>测试发现链接</th><td><input name="test_list_url" class="regular-text" placeholder="留空默认测试第一个列表页 URL"> <?php if ($editing): ?><button type="submit" name="wp_caiji_intent" value="list_test" class="button wp-caiji-form-action" data-wp-caiji-action="wp_caiji_test_list">测试发现链接</button><?php else: ?><span class="description">新规则请先保存后再测试。</span><?php endif; ?><p class="description">测试按钮不会保存当前修改；如刚改了规则，请先保存再测试。</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>文章字段提取</h2>
                <table class="form-table" role="presentation">
                    <tr><th>标题选择器</th><td><input name="title_selector" class="regular-text" value="<?php echo esc_attr($rule['title_selector']); ?>"><p class="description">先按标题前后代码截取，再用选择器提取；选择器留空时直接使用截取片段文本。</p></td></tr>
                    <tr><th>标题前后代码</th><td>
                        <textarea name="title_before_marker" rows="2" class="large-text code" placeholder="标题前代码/开始标记"><?php echo esc_textarea($rule['title_before_marker']); ?></textarea>
                        <p style="margin:8px 0">到</p>
                        <textarea name="title_after_marker" rows="2" class="large-text code" placeholder="标题后代码/结束标记"><?php echo esc_textarea($rule['title_after_marker']); ?></textarea>
                    </td></tr>
                    <tr><th>正文选择器</th><td><input name="content_selector" class="regular-text" value="<?php echo esc_attr($rule['content_selector']); ?>"><p class="description">先按正文前后代码截取，再用选择器提取；选择器留空时直接使用截取片段 HTML。</p></td></tr>
                    <tr><th>正文前后代码</th><td>
                        <textarea name="content_before_marker" rows="3" class="large-text code" placeholder="正文前代码/开始标记"><?php echo esc_textarea($rule['content_before_marker']); ?></textarea>
                        <p style="margin:8px 0">到</p>
                        <textarea name="content_after_marker" rows="3" class="large-text code" placeholder="正文后代码/结束标记"><?php echo esc_textarea($rule['content_after_marker']); ?></textarea>
                    </td></tr>
                    <tr><th>日期选择器</th><td><input name="date_selector" class="regular-text" value="<?php echo esc_attr($rule['date_selector']); ?>"><p class="description">可选。先按日期前后代码截取，再用选择器提取；选择器留空时直接使用截取片段文本。</p></td></tr>
                    <tr><th>日期前后代码</th><td>
                        <textarea name="date_before_marker" rows="2" class="large-text code" placeholder="日期/时间前代码/开始标记"><?php echo esc_textarea($rule['date_before_marker']); ?></textarea>
                        <p style="margin:8px 0">到</p>
                        <textarea name="date_after_marker" rows="2" class="large-text code" placeholder="日期/时间后代码/结束标记"><?php echo esc_textarea($rule['date_after_marker']); ?></textarea>
                    </td></tr>
                    <tr><th>测试文章预览</th><td><input name="test_url" class="regular-text" placeholder="留空默认测试手动 URL 或第一个发现到的文章 URL"> <?php if ($editing): ?><button type="submit" name="wp_caiji_intent" value="article_test" class="button wp-caiji-form-action" data-wp-caiji-action="wp_caiji_test_rule">测试预览</button><?php else: ?><span class="description">新规则请先保存后再测试。</span><?php endif; ?><p class="description">测试按钮不会保存当前修改；如刚改了字段提取或清洗规则，请先保存再测试。</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>正文清洗</h2>
                <table class="form-table" role="presentation">
                    <tr><th>删除选择器</th><td><textarea name="remove_selectors" rows="3" class="large-text code" placeholder="每行一个需要从正文中删除的选择器,如 .ad、.share、script"><?php echo esc_textarea($rule['remove_selectors']); ?></textarea></td></tr>
                    <tr><th>高级清洗</th><td><label><input name="remove_empty_paragraphs" type="checkbox" value="1" <?php checked($rule['remove_empty_paragraphs'],1); ?>> 删除空段落</label> &nbsp; <label><input name="remove_external_links" type="checkbox" value="1" <?php checked($rule['remove_external_links'],1); ?>> 删除正文外链但保留文字</label><p>删除包含关键词的段落,每行一个关键词:</p><textarea name="remove_paragraph_keywords" rows="3" class="large-text code"><?php echo esc_textarea($rule['remove_paragraph_keywords']); ?></textarea></td></tr>
                    <tr><th>关键词替换</th><td><textarea name="replace_rules" rows="4" class="large-text code" placeholder="每行一条:原词=>新词,例如:旧品牌=>新品牌"><?php echo esc_textarea($rule['replace_rules']); ?></textarea><p class="description">会同时作用于标题和正文。留空则不替换。</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>发布与节奏</h2>
                <table class="form-table" role="presentation">
                    <tr><th>发布设置</th><td>默认分类 ID <input name="category_id" type="number" value="<?php echo esc_attr($rule['category_id']); ?>" style="width:90px"> 作者 ID <input name="author_id" type="number" value="<?php echo esc_attr($rule['author_id']); ?>" style="width:90px"> 状态 <select name="post_status"><option value="draft" <?php selected($rule['post_status'],'draft'); ?>>草稿</option><option value="publish" <?php selected($rule['post_status'],'publish'); ?>>发布</option><option value="future" <?php selected($rule['post_status'],'future'); ?>>定时发布</option><option value="pending" <?php selected($rule['post_status'],'pending'); ?>>待审</option></select></td></tr>
                    <tr><th>发布节奏</th><td>模式 <select name="publish_mode"><option value="immediate" <?php selected($rule['publish_mode'],'immediate'); ?>>立即使用上方状态</option><option value="random_future" <?php selected($rule['publish_mode'],'random_future'); ?>>随机延迟发布</option></select> 延迟 <input name="publish_delay_min" type="number" min="0" value="<?php echo esc_attr($rule['publish_delay_min']); ?>" style="width:80px"> - <input name="publish_delay_max" type="number" min="0" value="<?php echo esc_attr($rule['publish_delay_max']); ?>" style="width:80px"> 分钟 <p class="description">选择随机延迟发布时,会把文章设为 future,并在区间内随机安排发布时间。</p></td></tr>
                    <tr><th>长期采集控制</th><td>每批 <input name="batch_limit" type="number" min="1" max="50" value="<?php echo esc_attr($rule['batch_limit']); ?>" style="width:80px"> 篇;失败重试 <input name="retry_limit" type="number" min="0" max="10" value="<?php echo esc_attr($rule['retry_limit']); ?>" style="width:80px"> 次;请求间隔 <input name="request_delay" type="number" min="0" max="30" value="<?php echo esc_attr($rule['request_delay']); ?>" style="width:80px"> 秒</td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>图片与媒体</h2>
                <table class="form-table" role="presentation">
                    <tr><th>图片</th><td><label><input name="download_images" type="checkbox" value="1" <?php checked($rule['download_images'],1); ?>> 图片本地化</label> &nbsp; <label><input name="set_featured_image" type="checkbox" value="1" <?php checked($rule['set_featured_image'],1); ?>> 第一张图设为特色图</label><p>图片 ALT 模板:<input name="image_alt_template" class="regular-text" value="<?php echo esc_attr($rule['image_alt_template']); ?>" placeholder="{title}"></p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>AI 改写</h2>
                <table class="form-table" role="presentation">
                    <tr><th>发布前 AI 改写</th><td><label><input name="ai_rewrite" type="checkbox" value="1" <?php checked($rule['ai_rewrite'],1); ?>> 启用</label><p class="description">需要先在"WP 采集 → 设置"里启用 AI 并配置 API。AI 会在正文清洗和图片本地化之后、写入文章之前运行。</p></td></tr>
                    <tr><th>失败处理</th><td><select name="ai_rewrite_on_failure"><option value="fallback" <?php selected($rule['ai_rewrite_on_failure'],'fallback'); ?>>AI 失败时发布原文</option><option value="fail" <?php selected($rule['ai_rewrite_on_failure'],'fail'); ?>>AI 失败时标记队列失败</option></select></td></tr>
                    <tr><th>专属 Prompt</th><td><textarea name="ai_rewrite_prompt" rows="6" class="large-text code" placeholder="留空则使用全局默认 Prompt"><?php echo esc_textarea($rule['ai_rewrite_prompt']); ?></textarea><p class="description">建议要求模型只返回 JSON:{"title":"改写标题","content":"改写后的 HTML 正文"}</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>分类与标签</h2>
                <table class="form-table" role="presentation">
                    <tr><th>自动分类规则</th><td><textarea name="category_rules" rows="4" class="large-text code" placeholder="每行一条:关键词=>分类ID,例如:WordPress=>3"><?php echo esc_textarea($rule['category_rules']); ?></textarea><p class="description">匹配标题或正文后,会优先使用命中的分类 ID;没有命中则使用默认分类。</p></td></tr>
                    <tr><th>固定标签</th><td><input name="fixed_tags" class="regular-text" value="<?php echo esc_attr($rule['fixed_tags']); ?>" placeholder="多个标签用英文逗号分隔,例如 SEO,WordPress"></td></tr>
                    <tr><th>自动标签</th><td><label><input name="auto_tags" type="checkbox" value="1" <?php checked($rule['auto_tags'],1); ?>> 根据关键词自动加标签</label><br><textarea name="auto_tag_keywords" rows="3" class="large-text code" placeholder="每行一个关键词,标题或正文出现该词时自动作为标签"><?php echo esc_textarea($rule['auto_tag_keywords']); ?></textarea></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>SEO 与摘要</h2>
                <table class="form-table" role="presentation">
                    <tr><th>摘要</th><td><label><input name="auto_excerpt" type="checkbox" value="1" <?php checked($rule['auto_excerpt'],1); ?>> 自动生成摘要</label> 长度 <input name="excerpt_length" type="number" min="50" max="500" value="<?php echo esc_attr($rule['excerpt_length']); ?>" style="width:80px"> 字</td></tr>
                    <tr><th>SEO 字段</th><td>插件 <select name="seo_plugin"><option value="none" <?php selected($rule['seo_plugin'],'none'); ?>>不写入</option><option value="rank_math" <?php selected($rule['seo_plugin'],'rank_math'); ?>>Rank Math</option><option value="yoast" <?php selected($rule['seo_plugin'],'yoast'); ?>>Yoast SEO</option><option value="aioseo" <?php selected($rule['seo_plugin'],'aioseo'); ?>>All in One SEO</option></select><p>SEO 标题模板:<input name="seo_title_template" class="regular-text" value="<?php echo esc_attr($rule['seo_title_template']); ?>" placeholder="{title} - {site}"></p><p>SEO 描述模板:<input name="seo_desc_template" class="regular-text" value="<?php echo esc_attr($rule['seo_desc_template']); ?>" placeholder="{excerpt}"></p><p class="description">可用变量:{title}、{excerpt}、{site}、{source} <button class="button wp-caiji-template-copy" data-wp-caiji-copy="{title} - {site}">复制标题模板</button> <button class="button wp-caiji-template-copy" data-wp-caiji-copy="{excerpt}">复制描述模板</button></p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>请求头与访问</h2>
                <table class="form-table" role="presentation">
                    <tr><th>请求头配置</th><td><p>User-Agent 池,每行一个:</p><textarea name="ua_list" rows="3" class="large-text code" placeholder="留空则使用默认 User-Agent"><?php echo esc_textarea($rule['ua_list']); ?></textarea><p>Referer:<input name="referer" class="regular-text" value="<?php echo esc_attr($rule['referer']); ?>" placeholder="可留空"></p><p>Cookie:<textarea name="cookie" rows="2" class="large-text code" placeholder="需要登录态/特殊 Cookie 时填写,可留空"><?php echo esc_textarea($rule['cookie']); ?></textarea></p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-modal-footer">
                    <?php if ($editing): ?>
                        <button type="submit" name="wp_caiji_intent" value="list_test" class="button wp-caiji-form-action" data-wp-caiji-action="wp_caiji_test_list">测试发现链接</button>
                        <button type="submit" name="wp_caiji_intent" value="article_test" class="button wp-caiji-form-action" data-wp-caiji-action="wp_caiji_test_rule">测试文章预览</button>
                    <?php endif; ?>
                    <?php submit_button($editing ? '保存规则' : '添加规则', 'primary', 'submit', false); ?>
                    <button type="button" class="button" data-wp-caiji-modal-close>取消</button>
                    <?php if ($editing): ?><span class="description">测试不会保存当前修改；如果刚改了规则，请先保存再测试。</span><?php endif; ?>
                </div>
            </form>
                    </div>
                </div>
            </div>
            <?php if ($has_test_result_modal): ?>
            <div id="wp-caiji-test-result-modal" class="wp-caiji-modal wp-caiji-result-modal is-open" aria-hidden="false" data-wp-caiji-open-after-close="wp-caiji-rule-modal">
                <div class="wp-caiji-modal-backdrop" data-wp-caiji-modal-close></div>
                <div class="wp-caiji-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wp-caiji-test-result-modal-title">
                    <div class="wp-caiji-modal-header">
                        <div>
                            <div class="wp-caiji-kicker"><span></span> TEST RESULT</div>
                            <h2 id="wp-caiji-test-result-modal-title">规则测试结果</h2>
                            <p>这里显示刚刚执行的列表页测试或文章预览结果；关闭后仍可继续编辑当前规则。</p>
                        </div>
                        <button type="button" class="wp-caiji-modal-close" data-wp-caiji-modal-close aria-label="关闭">×</button>
                    </div>
                    <div class="wp-caiji-modal-body wp-caiji-test-result-body" data-wp-caiji-result-target>
                        <?php $this->render_test_result($edit_id); ?>
                        <?php $this->render_list_test_result($edit_id); ?>
                    </div>
                    <div class="wp-caiji-modal-footer">
                        <button type="button" class="button button-primary" data-wp-caiji-modal-close>关闭结果</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="wp-caiji-section-title"><span>规则列表</span></div>
            <table class="widefat striped"><thead><tr><th>ID</th><th>名称</th><th>状态</th><th>待采</th><th>成功</th><th>失败</th><th>最后发现</th><th>最后采集</th><th>操作</th></tr></thead><tbody>
            <?php if (!$rules): ?><tr><td colspan="9">暂无规则</td></tr><?php endif; ?>
            <?php foreach ($rules as $r): ?>
                <tr><td><?php echo intval($r['id']); ?></td><td><?php echo esc_html($r['name']); ?></td><td><?php echo $this->status_badge($r['enabled'] ? 'enabled' : 'disabled'); ?></td><td><?php echo intval($r['pending_count']); ?></td><td><?php echo intval($r['success_count']); ?></td><td><?php echo intval($r['failed_count']); ?></td><td><?php echo esc_html($r['last_discovered_at']); ?></td><td><?php echo esc_html($r['last_collected_at']); ?></td><td>
                    <a class="button wp-caiji-modal-edit" href="<?php echo esc_url($this->page_url('wp-caiji-rules', array('edit'=>$r['id']))); ?>">编辑</a>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_toggle_rule&id='.$r['id']), 'wp_caiji_toggle_rule')); ?>"><?php echo $r['enabled'] ? '停用' : '启用'; ?></a>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_copy_rule&id='.$r['id']), 'wp_caiji_copy_rule')); ?>">复制</a>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_discover_rule&id='.$r['id']), 'wp_caiji_discover_rule')); ?>">发现链接</a>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_collect_rule&id='.$r['id']), 'wp_caiji_collect_rule')); ?>">采集队列</a>
                    <a class="button button-link-delete" onclick="return confirm('确定删除规则?队列和日志也会删除。')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_delete_rule&id='.$r['id']), 'wp_caiji_delete_rule')); ?>">删除</a>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <div class="wp-caiji-section-title"><span>规则导入/导出</span></div>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_export_rules'), 'wp_caiji_export_rules')); ?>">导出全部规则 JSON</a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wp_caiji_import_rules'); ?>
                <input type="hidden" name="action" value="wp_caiji_import_rules">
                <input type="file" name="rules_file" accept="application/json,.json">
                <button class="button" onclick="return confirm('导入会新增规则,不会覆盖现有规则,确定继续?')">导入规则 JSON</button>
            </form>
        </div>
        <?php
    }

    public function render_queue()
    {
        global $wpdb;
        if (!current_user_can('manage_options')) return;
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $due = isset($_GET['due']) ? sanitize_key($_GET['due']) : '';
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;
        $where = array('1=1');
        $params = array();
        if ($status) { $where[] = 'q.status=%s'; $params[] = $status; }
        if ($rule_id) { $where[] = 'q.rule_id=%d'; $params[] = $rule_id; }
        if ($search !== '') { $where[] = 'q.url LIKE %s'; $params[] = '%' . $wpdb->esc_like($search) . '%'; }
        if ($error !== '') { $where[] = 'q.last_error LIKE %s'; $params[] = '%' . $wpdb->esc_like($error) . '%'; }
        if ($due === 'ready') { $where[] = "(q.scheduled_at IS NULL OR q.scheduled_at <= %s)"; $params[] = current_time('mysql'); }
        if ($due === 'scheduled') { $where[] = "q.scheduled_at > %s"; $params[] = current_time('mysql'); }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$this->queue_table} q {$where_sql}";
        $total = (int)($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $sql = "SELECT q.*, r.name rule_name FROM {$this->queue_table} q LEFT JOIN {$this->rules_table} r ON q.rule_id=r.id {$where_sql} ORDER BY q.id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);
        $rules = $wpdb->get_results("SELECT id,name FROM {$this->rules_table} ORDER BY name ASC", ARRAY_A);
        $base_args = array_filter(array('status'=>$status,'rule_id'=>$rule_id,'s'=>$search,'error'=>$error,'due'=>$due), function($v){ return $v !== '' && $v !== 0; });
        ?>
        <div class="wrap wp-caiji-page">
            <?php $this->render_page_header('URL 队列', '查看采集任务状态,筛选失败项,重试或清理队列。'); ?>
            <?php if (!empty($_GET['scheduled'])): ?><div class="notice notice-success inline"><p><?php echo esc_html('采集任务已提交,将在约 1 秒后后台执行。'); ?></p></div><?php endif; ?>
            <p class="wp-caiji-filter-tabs"><a href="<?php echo esc_url($this->page_url('wp-caiji-queue')); ?>">全部</a> <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'pending'))); ?>">待采</a> <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'failed'))); ?>">失败</a> <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'success'))); ?>">成功</a></p>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin:12px 0">
                <input type="hidden" name="page" value="wp-caiji-queue">
                状态 <select name="status"><option value="">全部</option><?php foreach (array('pending'=>'待采','running'=>'运行中','success'=>'成功','failed'=>'失败','skipped'=>'跳过') as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($status,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select>
                规则 <select name="rule_id"><option value="0">全部规则</option><?php foreach ($rules as $r): ?><option value="<?php echo intval($r['id']); ?>" <?php selected($rule_id, (int)$r['id']); ?>><?php echo esc_html($r['name']); ?></option><?php endforeach; ?></select>
                URL <input name="s" value="<?php echo esc_attr($search); ?>" placeholder="URL 关键词">
                错误 <input name="error" value="<?php echo esc_attr($error); ?>" placeholder="错误关键词">
                执行时间 <select name="due"><option value="">全部</option><option value="ready" <?php selected($due,'ready'); ?>>已到期/可执行</option><option value="scheduled" <?php selected($due,'scheduled'); ?>>延迟中</option></select>
                <button class="button">筛选</button> <a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-queue')); ?>">重置</a>
                <p class="wp-caiji-filter-tabs" style="margin:10px 0 0">
                    快捷筛选:
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'failed'))); ?>">失败任务</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'pending','due'=>'ready'))); ?>">等待执行</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('status'=>'pending','due'=>'scheduled'))); ?>">延迟中</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('error'=>'AI'))); ?>">AI 失败</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('error'=>'图片'))); ?>">图片问题</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-queue', array('error'=>'选择器'))); ?>">选择器问题</a>
                </p>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin:12px 0">
                <?php wp_nonce_field('wp_caiji_clean_queue'); ?>
                <input type="hidden" name="action" value="wp_caiji_clean_queue">
                清理队列:规则 <select name="clean_rule_id"><option value="0">全部规则</option><?php foreach ($rules as $r): ?><option value="<?php echo intval($r['id']); ?>"><?php echo esc_html($r['name']); ?></option><?php endforeach; ?></select>
                状态 <select name="clean_status"><option value="success">成功</option><option value="failed">失败</option><option value="skipped">跳过</option><option value="pending">待采</option></select>
                早于 <input name="older_days" type="number" min="0" max="3650" value="30" style="width:80px"> 天
                <button class="button button-link-delete" onclick="return confirm('确定按条件清理队列?')">清理</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wp_caiji_bulk_queue'); ?>
            <input type="hidden" name="action" value="wp_caiji_bulk_queue">
            <p><select name="bulk_action"><option value="">批量操作</option><option value="retry">重试选中</option><option value="delete">删除选中</option><option value="retry_failed">重试全部失败</option><option value="delete_success">删除全部成功</option></select> <button class="button" onclick="return confirm('确定执行批量操作?')">应用</button> <span class="description">共 <?php echo intval($total); ?> 条;当前第 <?php echo intval($paged); ?> 页,每页 <?php echo intval($per_page); ?> 条。</span></p>
            <?php $this->render_pagination('wp-caiji-queue', $base_args, $paged, $per_page, $total); ?>
            <table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.wp-caiji-qid').forEach(cb=>cb.checked=this.checked)"></th><th>ID</th><th>规则</th><th>状态</th><th>次数</th><th>文章</th><th>URL</th><th>错误</th><th>下次执行</th><th>更新时间</th><th>操作</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="11">暂无队列</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr><td><input class="wp-caiji-qid" type="checkbox" name="queue_ids[]" value="<?php echo intval($row['id']); ?>"></td><td><?php echo intval($row['id']); ?></td><td><?php echo esc_html($row['rule_name']); ?></td><td><?php echo $this->status_badge($row['status']); ?></td><td><?php echo intval($row['attempts']); ?></td><td><?php echo $row['post_id'] ? '<a href="'.esc_url(get_edit_post_link($row['post_id'])).'">'.intval($row['post_id']).'</a>' : '-'; ?></td><td><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(wp_html_excerpt($row['url'], 100)); ?></a></td><td><?php echo esc_html(wp_html_excerpt($row['last_error'], 100)); ?></td><td><?php echo esc_html($row['scheduled_at'] ?: '立即'); ?></td><td><?php echo esc_html($row['finished_at'] ?: ($row['started_at'] ?: $row['discovered_at'])); ?></td><td>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_retry_queue&id='.$row['id']), 'wp_caiji_retry_queue')); ?>">重试</a>
                    <a class="button button-link-delete" onclick="return confirm('确定删除?')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_delete_queue&id='.$row['id']), 'wp_caiji_delete_queue')); ?>">删除</a>
                </td></tr>
            <?php endforeach; ?></tbody></table>
            <?php $this->render_pagination('wp-caiji-queue', $base_args, $paged, $per_page, $total); ?>
            </form>
        </div>
        <?php
    }

    public function render_logs()
    {
        global $wpdb;
        if (!current_user_can('manage_options')) return;
        $level = isset($_GET['level']) ? sanitize_key($_GET['level']) : '';
        $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $queue_id = isset($_GET['queue_id']) ? absint($_GET['queue_id']) : 0;
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 80;
        $offset = ($paged - 1) * $per_page;
        $where = array('1=1');
        $params = array();
        if ($level) { $where[] = 'l.level=%s'; $params[] = $level; }
        if ($rule_id) { $where[] = 'l.rule_id=%d'; $params[] = $rule_id; }
        if ($search !== '') { $where[] = '(l.message LIKE %s OR l.url LIKE %s)'; $like = '%' . $wpdb->esc_like($search) . '%'; $params[] = $like; $params[] = $like; }
        if ($queue_id) { $where[] = 'l.queue_id=%d'; $params[] = $queue_id; }
        $where_sql = 'WHERE ' . implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$this->logs_table} l {$where_sql}";
        $total = (int)($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $sql = "SELECT l.*, r.name rule_name FROM {$this->logs_table} l LEFT JOIN {$this->rules_table} r ON l.rule_id=r.id {$where_sql} ORDER BY l.id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);
        $rules = $wpdb->get_results("SELECT id,name FROM {$this->rules_table} ORDER BY name ASC", ARRAY_A);
        $base_args = array_filter(array('level'=>$level,'rule_id'=>$rule_id,'s'=>$search,'queue_id'=>$queue_id), function($v){ return $v !== '' && $v !== 0; });
        ?>
        <div class="wrap wp-caiji-page">
            <?php $this->render_page_header('采集日志', '追踪采集过程、错误原因、警告信息和目标 URL。'); ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:12px;margin:12px 0">
                <input type="hidden" name="page" value="wp-caiji-logs">
                级别 <select name="level"><option value="">全部</option><?php foreach (array('info'=>'info','success'=>'success','warning'=>'warning','error'=>'error') as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($level,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select>
                规则 <select name="rule_id"><option value="0">全部规则</option><?php foreach ($rules as $r): ?><option value="<?php echo intval($r['id']); ?>" <?php selected($rule_id, (int)$r['id']); ?>><?php echo esc_html($r['name']); ?></option><?php endforeach; ?></select>
                关键词 <input name="s" value="<?php echo esc_attr($search); ?>" placeholder="消息或 URL">
                队列ID <input name="queue_id" value="<?php echo esc_attr($queue_id ?: ''); ?>" placeholder="queue_id" style="width:90px">
                <button class="button">筛选</button> <a class="button" href="<?php echo esc_url($this->page_url('wp-caiji-logs')); ?>">重置</a>
                <p class="wp-caiji-filter-tabs" style="margin:10px 0 0">
                    快捷筛选:
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-logs', array('level'=>'error'))); ?>">错误</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-logs', array('level'=>'warning'))); ?>">警告</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-logs', array('s'=>'图片'))); ?>">图片相关</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-logs', array('s'=>'AI'))); ?>">AI 相关</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-logs', array('s'=>'选择器'))); ?>">选择器相关</a>
                    <a href="<?php echo esc_url($this->page_url('wp-caiji-logs', array('s'=>'重复'))); ?>">重复/跳过</a>
                </p>
                <a class="button button-link-delete" style="margin-left:12px" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_clear_logs'), 'wp_caiji_clear_logs')); ?>" onclick="return confirm('确定清空全部日志?')">清空日志</a>
            </form>
            <p class="description">共 <?php echo intval($total); ?> 条;当前第 <?php echo intval($paged); ?> 页,每页 <?php echo intval($per_page); ?> 条。</p>
            <?php $this->render_pagination('wp-caiji-logs', $base_args, $paged, $per_page, $total); ?>
            <table class="widefat striped"><thead><tr><th>时间</th><th>级别</th><th>规则</th><th>队列</th><th>消息</th><th>URL</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="6">暂无日志</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?><tr><td><?php echo esc_html($row['created_at']); ?></td><td><?php echo $this->status_badge($row['level']); ?></td><td><?php echo esc_html($row['rule_name']); ?></td><td><?php echo intval($row['queue_id']); ?></td><td><?php echo esc_html($row['message']); ?></td><td><?php echo esc_html(wp_html_excerpt($row['url'], 120)); ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <?php $this->render_pagination('wp-caiji-logs', $base_args, $paged, $per_page, $total); ?>
        </div>
        <?php
    }

    private function render_pagination($page, $args, $paged, $per_page, $total)
    {
        $pages = max(1, (int)ceil($total / $per_page));
        if ($pages <= 1) return;
        echo '<div class="tablenav"><div class="tablenav-pages">';
        if ($paged > 1) echo '<a class="button" href="' . esc_url($this->page_url($page, array_merge($args, array('paged'=>$paged-1)))) . '">&laquo; 上一页</a> ';
        echo '<span class="button disabled">' . intval($paged) . ' / ' . intval($pages) . '</span> ';
        if ($paged < $pages) echo '<a class="button" href="' . esc_url($this->page_url($page, array_merge($args, array('paged'=>$paged+1)))) . '">下一页 &raquo;</a>';
        echo '</div></div>';
    }

    private static function default_settings()
    {
        return WP_Caiji_DB::default_settings();
    }

    private function get_settings()
    {
        return wp_parse_args(get_option(self::OPTION_SETTINGS, array()), self::default_settings());
    }


    public function render_health()
    {
        WP_Caiji_Health::render($this);
    }

    public function health_action()
    {
        WP_Caiji_Health::handle_action($this);
    }

    public function export_diagnostics()
    {
        WP_Caiji_Health::export_diagnostics($this);
    }

    public function add_post_ai_meta_box()
    {
        add_meta_box('wp-caiji-post-ai', 'WP采集 AI重写', array($this, 'render_post_ai_meta_box'), 'post', 'side', 'high');
    }

    public function render_post_ai_meta_box($post)
    {
        global $wpdb;
        if (!current_user_can('edit_post', $post->ID)) return;
        $source_url = (string)get_post_meta($post->ID, self::META_SOURCE_URL, true);
        $queue = null;
        if ($source_url !== '') {
            $queue = $wpdb->get_row($wpdb->prepare("SELECT q.*, r.name rule_name, r.ai_rewrite, r.ai_rewrite_on_failure FROM {$this->queue_table} q LEFT JOIN {$this->rules_table} r ON r.id=q.rule_id WHERE q.url_hash=%s OR q.url=%s ORDER BY q.id DESC LIMIT 1", md5(esc_url_raw($source_url)), esc_url_raw($source_url)), ARRAY_A);
        }
        if (!$queue) {
            $queue = $wpdb->get_row($wpdb->prepare("SELECT q.*, r.name rule_name, r.ai_rewrite, r.ai_rewrite_on_failure FROM {$this->queue_table} q LEFT JOIN {$this->rules_table} r ON r.id=q.rule_id WHERE q.post_id=%d ORDER BY q.id DESC LIMIT 1", (int)$post->ID), ARRAY_A);
        }
        $result_key = 'wp_caiji_post_ai_rewrite_' . get_current_user_id() . '_' . (int)$post->ID;
        $result = get_transient($result_key);
        if ($result) delete_transient($result_key);
        if ($result) echo '<div class="notice ' . (!empty($result['ok']) ? 'notice-success' : 'notice-error') . ' inline"><p>' . esc_html($result['message'] ?? '') . '</p></div>';
        if ($source_url !== '') echo '<p><strong>来源:</strong><br><a href="' . esc_url($source_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_html_excerpt($source_url, 90)) . '</a></p>';
        else echo '<p>这篇文章没有采集来源 URL,仍可手动用当前标题和正文进行 AI 重写。</p>';
        if ($queue) {
            echo '<p><strong>规则:</strong>' . esc_html($queue['rule_name'] ?: ('#' . (int)$queue['rule_id'])) . '<br><strong>队列:</strong>#' . intval($queue['id']) . ' / ' . esc_html($queue['status']) . '</p>';
            $logs = $wpdb->get_results($wpdb->prepare("SELECT level,message,created_at FROM {$this->logs_table} WHERE queue_id=%d AND message LIKE %s ORDER BY id DESC LIMIT 5", (int)$queue['id'], '%AI%'), ARRAY_A);
            if ($logs) {
                echo '<div class="wp-caiji-post-ai-logs"><strong>最近 AI 日志:</strong><ul>';
                foreach ($logs as $log) echo '<li><span class="wp-caiji-log-' . esc_attr($log['level']) . '">' . esc_html($log['level']) . '</span> ' . esc_html(wp_html_excerpt($log['message'], 90)) . '<br><small>' . esc_html($log['created_at']) . '</small></li>';
                echo '</ul></div>';
            } else echo '<p><strong>最近 AI 日志:</strong>暂无</p>';
        } else echo '<p><strong>采集队列:</strong>未匹配到队列记录</p>';
        $settings = $this->get_settings();
        if (empty($settings['ai_enabled'])) echo '<p style="color:#b32d2e">全局 AI 能力当前未启用,请先到 WP采集设置开启。</p>';
        $url = wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_rewrite_post_ai&post_id=' . (int)$post->ID), 'wp_caiji_rewrite_post_ai_' . (int)$post->ID);
        echo '<p><a class="button button-primary wp-caiji-confirm" data-confirm="确定要用 AI 重写当前文章标题和正文吗?建议先确认文章已保存。" href="' . esc_url($url) . '">手动 AI 重写当前文章</a></p>';
        echo '<p class="description">会直接更新当前文章标题和正文;失败时不会改动原文。</p>';
    }

    public function rewrite_post_ai()
    {
        global $wpdb;
        $post_id = absint($_GET['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id) || !check_admin_referer('wp_caiji_rewrite_post_ai_' . $post_id)) wp_die('权限验证失败');
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') wp_die('文章不存在');
        $settings = $this->get_settings();
        $result_key = 'wp_caiji_post_ai_rewrite_' . get_current_user_id() . '_' . $post_id;
        if (empty($settings['ai_enabled'])) {
            set_transient($result_key, array('ok'=>false, 'message'=>'全局 AI 能力未启用'), 120);
            wp_safe_redirect(get_edit_post_link($post_id, ''));
            exit;
        }
        $source_url = (string)get_post_meta($post_id, self::META_SOURCE_URL, true);
        $queue = null;
        if ($source_url !== '') {
            $queue = $wpdb->get_row($wpdb->prepare("SELECT q.*, r.* FROM {$this->queue_table} q LEFT JOIN {$this->rules_table} r ON r.id=q.rule_id WHERE q.url_hash=%s OR q.url=%s ORDER BY q.id DESC LIMIT 1", md5(esc_url_raw($source_url)), esc_url_raw($source_url)), ARRAY_A);
        }
        if (!$queue) {
            $queue = $wpdb->get_row($wpdb->prepare("SELECT q.*, r.* FROM {$this->queue_table} q LEFT JOIN {$this->rules_table} r ON r.id=q.rule_id WHERE q.post_id=%d ORDER BY q.id DESC LIMIT 1", $post_id), ARRAY_A);
        }
        $rule = $queue ?: WP_Caiji_DB::default_rule();
        $rule['ai_rewrite_prompt'] = $rule['ai_rewrite_prompt'] ?? '';
        $rule['ai_rewrite_on_failure'] = 'fail';
        $original_title = get_the_title($post_id);
        $original_content = (string)$post->post_content;
        $original_image_count = count(WP_Caiji_Parser::extract_image_sources($original_content));
        $ai_result = WP_Caiji_AI::rewrite($original_title, $original_content, $rule, $settings);
        $rule_id = (int)($queue['rule_id'] ?? 0);
        $queue_id = (int)($queue['id'] ?? 0);
        $log_url = $source_url !== '' ? $source_url : get_permalink($post_id);
        if (is_wp_error($ai_result)) {
            $message = '文章编辑页手动 AI 改写失败:' . $ai_result->get_error_message();
            $this->log('warning', $message, $rule_id, $queue_id, $log_url);
            set_transient($result_key, array('ok'=>false, 'message'=>$message), 120);
            wp_safe_redirect(get_edit_post_link($post_id, ''));
            exit;
        }
        $new_title = trim(wp_strip_all_tags((string)($ai_result['title'] ?? '')));
        $new_content = trim((string)($ai_result['content'] ?? ''));
        $min_chars = max(80, (int)(mb_strlen(wp_strip_all_tags($original_content)) * 0.25));
        if ($new_content === '' || mb_strlen(wp_strip_all_tags($new_content)) < $min_chars) {
            $message = '文章编辑页手动 AI 改写失败:结果过短或为空';
            $this->log('warning', $message, $rule_id, $queue_id, $log_url);
            set_transient($result_key, array('ok'=>false, 'message'=>$message), 120);
            wp_safe_redirect(get_edit_post_link($post_id, ''));
            exit;
        }
        $new_image_count = count(WP_Caiji_Parser::extract_image_sources($new_content));
        if ($original_image_count > 0 && $new_image_count === 0) {
            $message = '文章编辑页手动 AI 改写失败:改写后图片全部丢失';
            $this->log('warning', $message, $rule_id, $queue_id, $log_url);
            set_transient($result_key, array('ok'=>false, 'message'=>$message), 120);
            wp_safe_redirect(get_edit_post_link($post_id, ''));
            exit;
        }
        $updated = wp_update_post(array('ID'=>$post_id, 'post_title'=>$new_title !== '' ? $new_title : $original_title, 'post_content'=>wp_kses_post($new_content)), true);
        if (is_wp_error($updated)) {
            $message = '文章编辑页手动 AI 改写失败:' . $updated->get_error_message();
            $this->log('warning', $message, $rule_id, $queue_id, $log_url);
            set_transient($result_key, array('ok'=>false, 'message'=>$message), 120);
            wp_safe_redirect(get_edit_post_link($post_id, ''));
            exit;
        }
        update_post_meta($post_id, '_wp_caiji_ai_rewritten_at', current_time('mysql'));
        update_post_meta($post_id, '_wp_caiji_ai_rewritten_by', get_current_user_id());
        if ($original_image_count > $new_image_count) $this->log('warning', '文章编辑页手动 AI 改写后图片数量减少:原 ' . $original_image_count . ' 张,现 ' . $new_image_count . ' 张', $rule_id, $queue_id, $log_url);
        $this->log('info', '文章编辑页手动 AI 改写完成,文章 ID:' . $post_id, $rule_id, $queue_id, $log_url);
        set_transient($result_key, array('ok'=>true, 'message'=>'AI 重写完成,已更新当前文章'), 120);
        wp_safe_redirect(get_edit_post_link($post_id, ''));
        exit;
    }

    public function render_settings()
    {
        if (!current_user_can('manage_options')) return;
        $settings = $this->get_settings();
        ?>
        <div class="wrap wp-caiji-page">
            <?php $this->render_page_header('WP 采集设置', '调整采集频率、队列运行限制、图片本地化和 AI 改写能力。'); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wp_caiji_save_settings'); ?>
                <input type="hidden" name="action" value="wp_caiji_save_settings">
                <div class="wp-caiji-section"><h2>定时与队列</h2>
                <table class="form-table" role="presentation">
                    <tr><th>发现链接频率</th><td><select name="discover_interval"><option value="wp_caiji_5min" <?php selected($settings['discover_interval'],'wp_caiji_5min'); ?>>每 5 分钟</option><option value="wp_caiji_10min" <?php selected($settings['discover_interval'],'wp_caiji_10min'); ?>>每 10 分钟</option><option value="wp_caiji_30min" <?php selected($settings['discover_interval'],'wp_caiji_30min'); ?>>每 30 分钟</option><option value="hourly" <?php selected($settings['discover_interval'],'hourly'); ?>>每小时</option></select></td></tr>
                    <tr><th>采集文章频率</th><td><select name="collect_interval"><option value="wp_caiji_5min" <?php selected($settings['collect_interval'],'wp_caiji_5min'); ?>>每 5 分钟</option><option value="wp_caiji_10min" <?php selected($settings['collect_interval'],'wp_caiji_10min'); ?>>每 10 分钟</option><option value="wp_caiji_30min" <?php selected($settings['collect_interval'],'wp_caiji_30min'); ?>>每 30 分钟</option><option value="hourly" <?php selected($settings['collect_interval'],'hourly'); ?>>每小时</option></select></td></tr>
                    <tr><th>全局每次最多采集</th><td><input name="global_collect_limit" type="number" min="1" max="100" value="<?php echo esc_attr($settings['global_collect_limit']); ?>"> 篇</td></tr>
                    <tr><th>每次最多处理规则</th><td><input name="max_rules_per_discover" type="number" min="1" max="100" value="<?php echo esc_attr($settings['max_rules_per_discover']); ?>"> 条</td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>运行保护</h2>
                <table class="form-table" role="presentation">
                    <tr><th>单次 Cron 最大运行</th><td><input name="max_runtime_seconds" type="number" min="10" max="300" value="<?php echo esc_attr($settings['max_runtime_seconds']); ?>"> 秒 <p class="description">当前采集规则启用 AI 时，建议大于 AI 超时 + 抓取/图片处理耗时；否则会频繁出现“达到单次 Cron 最大运行时间”。</p></td></tr>
                    <tr><th>运行中超时释放</th><td><input name="running_timeout_minutes" type="number" min="5" max="1440" value="<?php echo esc_attr($settings['running_timeout_minutes']); ?>"> 分钟 <p class="description">队列长时间停留 running 会自动退回 pending，避免卡死。</p></td></tr>
                    <tr><th>任务锁超时</th><td><input name="lock_ttl_seconds" type="number" min="60" max="3600" value="<?php echo esc_attr($settings['lock_ttl_seconds']); ?>"> 秒 <p class="description">防止两个 Cron 同时运行。超过该时间会自动释放旧锁。</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>图片本地化</h2>
                <table class="form-table" role="presentation">
                    <tr><th>图片限制</th><td>每篇最多 <input name="max_images_per_post" type="number" min="0" max="50" value="<?php echo esc_attr($settings['max_images_per_post']); ?>" style="width:80px"> 张；单图最大 <input name="max_image_size_mb" type="number" min="1" max="50" value="<?php echo esc_attr($settings['max_image_size_mb']); ?>" style="width:80px"> MB<p class="description">0 张表示不下载图片；建议生产环境保持 5-10 张、5MB 以内，避免被大图拖慢。</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>AI 改写设置</h2>
                <table class="form-table" role="presentation">
                    <tr><th>启用 AI 能力</th><td><label><input name="ai_enabled" type="checkbox" value="1" <?php checked($settings['ai_enabled'],1); ?>> 允许采集规则在发布前调用 AI 改写</label><p class="description">每条规则仍需单独开启“发布前 AI 改写”。关闭这里会全局禁用 AI。</p></td></tr>
                    <tr><th>AI API Key</th><td><input name="ai_api_key" type="text" class="regular-text code" value="<?php echo esc_attr(WP_Caiji_AI::get_api_key($settings)); ?>" autocomplete="off" placeholder="sk-..."><p class="description">API Key 将按明文保存，便于在后台查看和复制；留空保存会保留原值。请仅在可信、单人管理后台环境使用；诊断导出会自动脱敏。</p></td></tr>
                    <tr><th>AI Endpoint</th><td><input name="ai_endpoint" type="url" class="regular-text" value="<?php echo esc_attr($settings['ai_endpoint']); ?>" placeholder="https://api.openai.com/v1 或 https://api.openai.com/v1/chat/completions"><p class="description">支持 OpenAI 兼容中转站。可填完整 chat/completions 地址，也可只填基础地址，例如 https://api.xxx.com 或 https://api.xxx.com/v1，插件会自动补全 /v1/chat/completions。仅允许公网 HTTPS 地址。</p></td></tr>
                    <tr><th>AI 模型</th><td><input name="ai_model" class="regular-text" value="<?php echo esc_attr($settings['ai_model']); ?>" placeholder="gpt-5.5"> 温度 <input name="ai_temperature" type="number" min="0" max="2" step="0.1" value="<?php echo esc_attr($settings['ai_temperature']); ?>" style="width:90px"></td></tr>
                    <tr><th>API 连接测试</th><td><button class="button button-secondary" formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>" name="action" value="wp_caiji_test_ai_api">测试 API 连接</button><p class="description">会优先使用当前表单里填写的 Key、Endpoint、模型和超时发送一次极小测试请求；不会保存设置，也不会创建文章。</p></td></tr>
                    <?php $this->render_ai_api_test_result(); ?>
                    <tr><th>AI 超时/输入限制</th><td>超时 <input name="ai_timeout_seconds" type="number" min="10" max="120" value="<?php echo esc_attr($settings['ai_timeout_seconds']); ?>" style="width:90px"> 秒；最多提交正文 <input name="ai_max_input_chars" type="number" min="1000" max="60000" value="<?php echo esc_attr($settings['ai_max_input_chars']); ?>" style="width:110px"> 字符<p class="description">如果 AI 经常 504 或 cURL 28，可先把超时降到 30-60 秒，或把单次 Cron 最大运行调到高于 AI 超时。</p></td></tr>
                    <tr><th>默认改写 Prompt</th><td><textarea name="ai_rewrite_prompt" rows="8" class="large-text code"><?php echo esc_textarea($settings['ai_rewrite_prompt']); ?></textarea><p class="description">规则里未填写专属 Prompt 时使用这里。建议要求模型只返回 JSON:{"title":"...","content":"..."}</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>GitHub 更新</h2>
                <table class="form-table" role="presentation">
                    <tr><th>启用 GitHub 更新</th><td><label><input name="github_update_enabled" type="checkbox" value="1" <?php checked($settings['github_update_enabled'],1); ?>> 在 WordPress 后台插件页检测 GitHub Release 并提示更新</label><p class="description">建议每次发布新版本时创建 GitHub Release，并上传 <code>wp-caiji.zip</code> 作为附件。版本号以 Release 标签为准，例如 <code>v2.1.1</code>。</p></td></tr>
                    <tr><th>GitHub 仓库</th><td><input name="github_repo" type="text" class="regular-text code" value="<?php echo esc_attr($settings['github_repo']); ?>" placeholder="owner/wp-caiji"><p class="description">填写 <code>用户名/仓库名</code>，也可粘贴完整 GitHub 仓库地址，保存时会自动规范化。</p></td></tr>
                    <tr><th>私有仓库 Token</th><td><input name="github_token" type="password" class="regular-text code" value="<?php echo esc_attr($settings['github_token']); ?>" autocomplete="off" placeholder="公开仓库留空"><p class="description">公开仓库不需要。私有仓库可填写只读 token；注意 GitHub 资源包下载可能仍需要登录，生产环境更推荐公开 release 资产或填写下面的固定下载地址。</p></td></tr>
                    <tr><th>固定更新包 URL</th><td><input name="github_package_url" type="url" class="regular-text code" value="<?php echo esc_attr($settings['github_package_url']); ?>" placeholder="可选：https://example.com/wp-caiji.zip"><p class="description">可选。留空时自动优先使用 Release 附件 <code>wp-caiji.zip</code>，其次使用 GitHub zipball。更新包 zip 内建议包含 <code>wp-caiji/</code> 目录。</p></td></tr>
                </table>
                </div>
                <div class="wp-caiji-section"><h2>日志与数据</h2>
                <table class="form-table" role="presentation">
                    <tr><th>日志</th><td><label><input name="enable_logs" type="checkbox" value="1" <?php checked($settings['enable_logs'],1); ?>> 启用日志</label>；保留最近 <input name="log_retention" type="number" min="100" max="20000" value="<?php echo esc_attr($settings['log_retention']); ?>"> 条</td></tr>
                    <tr><th>卸载保护</th><td><label class="wp-caiji-danger-option"><input name="delete_data_on_uninstall" type="checkbox" value="1" <?php checked($settings['delete_data_on_uninstall'],1); ?>> 卸载插件时删除规则、队列、日志和设置</label><p class="description">危险选项：默认不删除数据，防止误卸载造成采集规则丢失。只有确认不再需要这些数据时才建议开启。</p></td></tr>
                </table>
                </div>
                <?php submit_button('保存设置'); ?>
            </form>
        </div>
        <?php
    }

    private function default_rule()
    {
        return WP_Caiji_DB::default_rule();
    }

    public function save_rule()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_save_rule')) wp_die('权限验证失败');
        $intent = sanitize_key(wp_unslash($_POST['wp_caiji_intent'] ?? ''));
        if ($intent === 'list_test') {
            $this->test_list();
            exit;
        }
        if ($intent === 'article_test') {
            $this->test_rule();
            exit;
        }
        $id = absint($_POST['id'] ?? 0);
        $now = current_time('mysql');
        $data = array(
            'name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'enabled'=>isset($_POST['enabled']) ? 1 : 0,
            'list_urls'=>sanitize_textarea_field(wp_unslash($_POST['list_urls'] ?? '')),
            'link_selector'=>sanitize_text_field(wp_unslash($_POST['link_selector'] ?? '')),
            'link_before_marker'=>sanitize_textarea_field(wp_unslash($_POST['link_before_marker'] ?? '')),
            'link_after_marker'=>sanitize_textarea_field(wp_unslash($_POST['link_after_marker'] ?? '')),
            'pagination_pattern'=>esc_url_raw(wp_unslash($_POST['pagination_pattern'] ?? '')),
            'page_start'=>max(1, absint($_POST['page_start'] ?? 1)),
            'page_end'=>max(1, absint($_POST['page_end'] ?? 1)),
            'manual_urls'=>sanitize_textarea_field(wp_unslash($_POST['manual_urls'] ?? '')),
            'title_selector'=>sanitize_text_field(wp_unslash($_POST['title_selector'] ?? '//h1')),
            'title_before_marker'=>sanitize_textarea_field(wp_unslash($_POST['title_before_marker'] ?? '')),
            'title_after_marker'=>sanitize_textarea_field(wp_unslash($_POST['title_after_marker'] ?? '')),
            'content_selector'=>sanitize_text_field(wp_unslash($_POST['content_selector'] ?? '//article')),
            'content_before_marker'=>sanitize_textarea_field(wp_unslash($_POST['content_before_marker'] ?? '')),
            'content_after_marker'=>sanitize_textarea_field(wp_unslash($_POST['content_after_marker'] ?? '')),
            'date_selector'=>sanitize_text_field(wp_unslash($_POST['date_selector'] ?? '')),
            'date_before_marker'=>sanitize_textarea_field(wp_unslash($_POST['date_before_marker'] ?? '')),
            'date_after_marker'=>sanitize_textarea_field(wp_unslash($_POST['date_after_marker'] ?? '')),
            'remove_selectors'=>sanitize_textarea_field(wp_unslash($_POST['remove_selectors'] ?? '')),
            'category_id'=>absint($_POST['category_id'] ?? 0),
            'author_id'=>absint($_POST['author_id'] ?? 0),
            'post_status'=>in_array(($_POST['post_status'] ?? 'draft'), array('draft','publish','future','pending'), true) ? sanitize_key($_POST['post_status']) : 'draft',
            'batch_limit'=>max(1, min(50, absint($_POST['batch_limit'] ?? 5))),
            'retry_limit'=>max(0, min(10, absint($_POST['retry_limit'] ?? 3))),
            'request_delay'=>max(0, min(30, absint($_POST['request_delay'] ?? 1))),
            'download_images'=>isset($_POST['download_images']) ? 1 : 0,
            'set_featured_image'=>isset($_POST['set_featured_image']) ? 1 : 0,
            'dedupe_title'=>isset($_POST['dedupe_title']) ? 1 : 0,
            'fixed_tags'=>sanitize_text_field(wp_unslash($_POST['fixed_tags'] ?? '')),
            'replace_rules'=>sanitize_textarea_field(wp_unslash($_POST['replace_rules'] ?? '')),
            'category_rules'=>sanitize_textarea_field(wp_unslash($_POST['category_rules'] ?? '')),
            'auto_tags'=>isset($_POST['auto_tags']) ? 1 : 0,
            'auto_tag_keywords'=>sanitize_textarea_field(wp_unslash($_POST['auto_tag_keywords'] ?? '')),
            'publish_mode'=>in_array(($_POST['publish_mode'] ?? 'immediate'), array('immediate','random_future'), true) ? sanitize_key($_POST['publish_mode']) : 'immediate',
            'publish_delay_min'=>max(0, absint($_POST['publish_delay_min'] ?? 0)),
            'publish_delay_max'=>max(0, absint($_POST['publish_delay_max'] ?? 0)),
            'ua_list'=>sanitize_textarea_field(wp_unslash($_POST['ua_list'] ?? '')),
            'referer'=>esc_url_raw(wp_unslash($_POST['referer'] ?? '')),
            'cookie'=>sanitize_textarea_field(wp_unslash($_POST['cookie'] ?? '')),
            'auto_excerpt'=>isset($_POST['auto_excerpt']) ? 1 : 0,
            'excerpt_length'=>max(50, min(500, absint($_POST['excerpt_length'] ?? 160))),
            'seo_plugin'=>in_array(($_POST['seo_plugin'] ?? 'none'), array('none','rank_math','yoast','aioseo'), true) ? sanitize_key($_POST['seo_plugin']) : 'none',
            'seo_title_template'=>sanitize_text_field(wp_unslash($_POST['seo_title_template'] ?? '')),
            'seo_desc_template'=>sanitize_text_field(wp_unslash($_POST['seo_desc_template'] ?? '')),
            'remove_empty_paragraphs'=>isset($_POST['remove_empty_paragraphs']) ? 1 : 0,
            'remove_external_links'=>isset($_POST['remove_external_links']) ? 1 : 0,
            'remove_paragraph_keywords'=>sanitize_textarea_field(wp_unslash($_POST['remove_paragraph_keywords'] ?? '')),
            'image_alt_template'=>sanitize_text_field(wp_unslash($_POST['image_alt_template'] ?? '')),
            'ai_rewrite'=>isset($_POST['ai_rewrite']) ? 1 : 0,
            'ai_rewrite_prompt'=>wp_kses_post(wp_unslash($_POST['ai_rewrite_prompt'] ?? '')),
            'ai_rewrite_on_failure'=>in_array(($_POST['ai_rewrite_on_failure'] ?? 'fallback'), array('fallback','fail'), true) ? sanitize_key($_POST['ai_rewrite_on_failure']) : 'fallback',
            'updated_at'=>$now,
        );
        if ($data['page_end'] < $data['page_start']) $data['page_end'] = $data['page_start'];
        if ($data['publish_delay_max'] < $data['publish_delay_min']) $data['publish_delay_max'] = $data['publish_delay_min'];
        if ($id) {
            $wpdb->update($this->rules_table, $data, array('id'=>$id));
        } else {
            $data['created_at'] = $now;
            $wpdb->insert($this->rules_table, $data);
            $id = (int)$wpdb->insert_id;
        }
        self::schedule_events();
        $this->enqueue_manual_urls($id, $data['manual_urls']);
        wp_safe_redirect($this->page_url('wp-caiji-rules'));
        exit;
    }

    public function delete_rule()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_delete_rule')) wp_die('权限验证失败');
        $id = absint($_GET['id'] ?? 0);
        $wpdb->delete($this->rules_table, array('id'=>$id));
        $wpdb->delete($this->queue_table, array('rule_id'=>$id));
        $wpdb->delete($this->logs_table, array('rule_id'=>$id));
        wp_safe_redirect($this->page_url('wp-caiji-rules'));
        exit;
    }



    private function test_result_token($type)
    {
        return sanitize_key($type) . '_' . get_current_user_id() . '_' . wp_generate_password(12, false, false);
    }

    private function set_test_result($type, $result, $ttl = 300)
    {
        $token = $this->test_result_token($type);
        set_transient('wp_caiji_' . sanitize_key($type) . '_test_' . $token, $result, $ttl);
        return $token;
    }

    private function get_test_result($type, $query_arg)
    {
        $token = isset($_GET[$query_arg]) ? sanitize_key(wp_unslash($_GET[$query_arg])) : '';
        if ($token === '') return false;
        $prefix = sanitize_key($type) . '_' . get_current_user_id() . '_';
        if (strpos($token, $prefix) !== 0) return false;
        return get_transient('wp_caiji_' . sanitize_key($type) . '_test_' . $token);
    }

    public function test_list()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_save_rule')) wp_die('权限验证失败');
        $id = absint($_POST['id'] ?? 0);
        $url = esc_url_raw(wp_unslash($_POST['test_list_url'] ?? ''));
        if ($url === '') {
            $posted_list_urls = trim((string)wp_unslash($_POST['list_urls'] ?? ''));
            $parsed_urls = $this->parse_urls($posted_list_urls);
            $url = $parsed_urls ? esc_url_raw($parsed_urls[0]) : '';
        }
        $rule = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", $id), ARRAY_A) : null;
        if (!$rule || !WP_Caiji_Utils::is_safe_public_url($url)) {
            $token = $this->set_test_result('list', array('error'=>'规则或列表页 URL 无效'), 60);
            wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'list_test'=>$token)));
            exit;
        }
        $html = $this->fetch($url, $id, 0);
        if (!$html) {
            $token = $this->set_test_result('list', array('error'=>'列表页抓取失败,请查看采集日志'), 60);
            wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'list_test'=>$token)));
            exit;
        }
        $links = array_slice($this->extract_links_by_rule($html, $rule, $url), 0, 50);
        $ready = array();
        $duplicate = array();
        foreach ($links as $link) {
            if ($this->post_exists_by_source($link)) {
                $duplicate[] = $link;
            } else {
                $ready[] = $link;
            }
        }
        $token = $this->set_test_result('list', array('url'=>$url, 'count'=>count($links), 'ready_count'=>count($ready), 'duplicate_count'=>count($duplicate), 'links'=>$links, 'ready_links'=>array_slice($ready, 0, 20), 'duplicate_links'=>array_slice($duplicate, 0, 10)), 300);
        wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'list_test'=>$token)));
        exit;
    }

    private function render_list_test_result($rule_id)
    {
        if (!$rule_id) return;
        $result = $this->get_test_result('list', 'list_test');
        if (!$result) return;
        echo '<div class="wp-caiji-result-card wp-caiji-test-result-card wp-caiji-list-test-result"><div class="wp-caiji-section-title"><span>列表页测试结果</span></div>';
        if (!empty($result['error'])) {
            echo '<p style="color:#b32d2e">' . esc_html($result['error']) . '</p></div>';
            return;
        }
        echo '<p><strong>列表页:</strong><a href="' . esc_url($result['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($result['url']) . '</a></p>';
        echo '<p><strong>本次预览发现:</strong>' . intval($result['count']) . ' 条;可入队 ' . intval($result['ready_count'] ?? 0) . ' 条;来源已存在 ' . intval($result['duplicate_count'] ?? 0) . ' 条。最多显示 50 条。</p><ol>';
        foreach ((array)$result['links'] as $link) {
            echo '<li><a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link) . '</a></li>';
        }
        echo '</ol></div>';
    }


    public function toggle_rule()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_toggle_rule')) wp_die('权限验证失败');
        $id = absint($_GET['id'] ?? 0);
        $enabled = (int)$wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$this->rules_table} WHERE id=%d", $id));
        $wpdb->update($this->rules_table, array('enabled'=>$enabled ? 0 : 1, 'updated_at'=>current_time('mysql')), array('id'=>$id));
        wp_safe_redirect($this->page_url('wp-caiji-rules'));
        exit;
    }

    public function clean_queue()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_clean_queue')) wp_die('权限验证失败');
        $rule_id = absint($_POST['clean_rule_id'] ?? 0);
        $status = sanitize_key($_POST['clean_status'] ?? 'success');
        if (!in_array($status, array('success','failed','skipped','pending'), true)) $status = 'success';
        $days = max(0, min(3650, absint($_POST['older_days'] ?? 30)));
        $where = array('status=%s');
        $params = array($status);
        if ($rule_id) { $where[] = 'rule_id=%d'; $params[] = $rule_id; }
        if ($days > 0) { $where[] = 'COALESCE(finished_at, discovered_at) < %s'; $params[] = date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS)); }
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->queue_table} WHERE " . implode(' AND ', $where), $params));
        wp_safe_redirect($this->page_url('wp-caiji-queue'));
        exit;
    }

    public function save_settings()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_save_settings')) wp_die('权限验证失败');
        $existing_settings = $this->get_settings();
        $settings = $this->settings_from_post($existing_settings);
        update_option(self::OPTION_SETTINGS, $settings, false);
        self::clear_event_public(self::CRON_DISCOVER);
        self::clear_event_public(self::CRON_COLLECT);
        self::schedule_events();
        wp_safe_redirect($this->page_url('wp-caiji-settings'));
        exit;
    }

    public function test_ai_api()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_save_settings')) wp_die('权限验证失败');
        $settings = $this->settings_from_post($this->get_settings());
        $result = WP_Caiji_AI::test_connection($settings);
        set_transient('wp_caiji_ai_api_test_' . get_current_user_id(), $result, 300);
        wp_safe_redirect($this->page_url('wp-caiji-settings'));
        exit;
    }

    private function render_ai_api_test_result()
    {
        $key = 'wp_caiji_ai_api_test_' . get_current_user_id();
        $result = get_transient($key);
        if (!$result) return;
        delete_transient($key);
        $ok = !empty($result['ok']);
        echo '<tr><th>测试结果</th><td><div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' inline wp-caiji-result-card"><p><strong>' . ($ok ? '连接成功' : '连接失败') . '</strong></p><ul>';
        foreach (array('endpoint'=>'Endpoint', 'model'=>'模型', 'http_code'=>'HTTP 状态', 'latency_ms'=>'耗时(ms)', 'message'=>'返回/错误') as $field=>$label) {
            if (isset($result[$field]) && $result[$field] !== '') {
                echo '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html((string)$result[$field]) . '</li>';
            }
        }
        echo '</ul></div></td></tr>';
    }

    private function settings_from_post($existing_settings)
    {
        $existing_settings = wp_parse_args((array)$existing_settings, WP_Caiji_DB::default_settings());
        return array(
            'discover_interval'=>sanitize_key($_POST['discover_interval'] ?? 'wp_caiji_30min'),
            'collect_interval'=>sanitize_key($_POST['collect_interval'] ?? 'wp_caiji_10min'),
            'global_collect_limit'=>max(1, min(100, absint($_POST['global_collect_limit'] ?? 10))),
            'max_runtime_seconds'=>max(10, min(300, absint($_POST['max_runtime_seconds'] ?? 45))),
            'running_timeout_minutes'=>max(5, min(1440, absint($_POST['running_timeout_minutes'] ?? 30))),
            'max_rules_per_discover'=>max(1, min(100, absint($_POST['max_rules_per_discover'] ?? 20))),
            'log_retention'=>max(100, min(20000, absint($_POST['log_retention'] ?? 2000))),
            'enable_logs'=>isset($_POST['enable_logs']) ? 1 : 0,
            'lock_ttl_seconds'=>max(60, min(3600, absint($_POST['lock_ttl_seconds'] ?? 600))),
            'max_images_per_post'=>max(0, min(50, absint($_POST['max_images_per_post'] ?? 10))),
            'max_image_size_mb'=>max(1, min(50, absint($_POST['max_image_size_mb'] ?? 5))),
            'ai_enabled'=>isset($_POST['ai_enabled']) ? 1 : 0,
            'ai_api_key'=>WP_Caiji_AI::prepare_api_key_for_storage(wp_unslash($_POST['ai_api_key'] ?? ''), $existing_settings['ai_api_key'] ?? ''),
            'ai_endpoint'=>WP_Caiji_AI::normalize_endpoint(esc_url_raw(wp_unslash($_POST['ai_endpoint'] ?? 'https://api.openai.com/v1/chat/completions'))),
            'ai_model'=>sanitize_text_field(wp_unslash($_POST['ai_model'] ?? 'gpt-5.5')),
            'ai_temperature'=>max(0, min(2, (float)($_POST['ai_temperature'] ?? 0.7))),
            'ai_timeout_seconds'=>max(10, min(120, absint($_POST['ai_timeout_seconds'] ?? 45))),
            'ai_max_input_chars'=>max(1000, min(60000, absint($_POST['ai_max_input_chars'] ?? 12000))),
            'ai_rewrite_prompt'=>wp_kses_post(wp_unslash($_POST['ai_rewrite_prompt'] ?? WP_Caiji_AI::default_prompt())),
            'github_update_enabled'=>isset($_POST['github_update_enabled']) ? 1 : 0,
            'github_repo'=>WP_Caiji_Updater::normalize_repo(wp_unslash($_POST['github_repo'] ?? '')),
            'github_token'=>sanitize_text_field(wp_unslash($_POST['github_token'] ?? '')),
            'github_package_url'=>esc_url_raw(wp_unslash($_POST['github_package_url'] ?? '')),
            'delete_data_on_uninstall'=>isset($_POST['delete_data_on_uninstall']) ? 1 : 0,
        );
    }

    public function copy_rule()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_copy_rule')) wp_die('权限验证失败');
        $id = absint($_GET['id'] ?? 0);
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", $id), ARRAY_A);
        if ($rule) {
            unset($rule['id']);
            $rule['name'] = $rule['name'] . ' - 副本';
            $rule['created_at'] = current_time('mysql');
            $rule['updated_at'] = current_time('mysql');
            $rule['last_discovered_at'] = null;
            $rule['last_collected_at'] = null;
            $wpdb->insert($this->rules_table, $rule);
        }
        wp_safe_redirect($this->page_url('wp-caiji-rules'));
        exit;
    }

    public function export_rules()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_export_rules')) wp_die('权限验证失败');
        $rules = $wpdb->get_results("SELECT * FROM {$this->rules_table} ORDER BY id ASC", ARRAY_A);
        foreach ($rules as &$rule) {
            unset($rule['id'], $rule['created_at'], $rule['updated_at'], $rule['last_discovered_at'], $rule['last_collected_at']);
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=wp-caiji-rules-' . date('Ymd-His') . '.json');
        echo wp_json_encode(array('version'=>WP_CAIJI_VERSION, 'rules'=>$rules), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function import_rules()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_import_rules')) wp_die('权限验证失败');
        if (empty($_FILES['rules_file']['tmp_name'])) {
            wp_safe_redirect($this->page_url('wp-caiji-rules'));
            exit;
        }
        if (!empty($_FILES['rules_file']['size']) && (int)$_FILES['rules_file']['size'] > 2 * MB_IN_BYTES) {
            wp_die('规则文件过大,请上传 2MB 以内的 JSON 文件。');
        }
        $json = file_get_contents($_FILES['rules_file']['tmp_name']);
        if ($json === false || strlen($json) > 2 * MB_IN_BYTES) {
            wp_die('规则文件读取失败或文件过大。');
        }
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            wp_die('规则 JSON 格式错误:' . esc_html(json_last_error_msg()));
        }
        $rules = isset($data['rules']) && is_array($data['rules']) ? array_slice($data['rules'], 0, 200) : array();
        $allowed = array_keys($this->default_rule());
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['name'])) continue;
            $insert = array();
            foreach ($allowed as $key) {
                if (array_key_exists($key, $rule)) $insert[$key] = is_scalar($rule[$key]) ? (string)$rule[$key] : '';
            }
            $insert = wp_parse_args($insert, $this->default_rule());
            $insert['enabled'] = !empty($insert['enabled']) ? 1 : 0;
            $insert['name'] = sanitize_text_field($insert['name']);
            $insert['pagination_pattern'] = esc_url_raw($insert['pagination_pattern']);
            $insert['referer'] = esc_url_raw($insert['referer']);
            $insert['page_start'] = max(1, absint($insert['page_start']));
            $insert['page_end'] = max($insert['page_start'], absint($insert['page_end']));
            $insert['batch_limit'] = max(1, min(50, absint($insert['batch_limit'])));
            $insert['retry_limit'] = max(0, min(10, absint($insert['retry_limit'])));
            $insert['request_delay'] = max(0, min(30, absint($insert['request_delay'])));
            $insert['created_at'] = current_time('mysql');
            $insert['updated_at'] = current_time('mysql');
            $wpdb->insert($this->rules_table, $insert);
        }
        wp_safe_redirect($this->page_url('wp-caiji-rules'));
        exit;
    }

    public function discover_rule_now()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_discover_rule')) wp_die('权限验证失败');
        $rule_id = absint($_GET['id'] ?? 0);
        if ($rule_id) {
            wp_schedule_single_event(time() + 1, self::CRON_DISCOVER_RULE_ONCE, array($rule_id));
            self::spawn_cron();
            $this->log('info', '手动发现链接任务已提交,约 1 秒后后台执行', $rule_id, 0, '');
        }
        wp_safe_redirect($this->page_url('wp-caiji-rules', array('scheduled'=>'discover')));
        exit;
    }

    public function collect_rule_now()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_collect_rule')) wp_die('权限验证失败');
        $rule_id = absint($_GET['id'] ?? 0);
        if ($rule_id) {
            wp_schedule_single_event(time() + 1, self::CRON_COLLECT_RULE_ONCE, array($rule_id));
            self::spawn_cron();
            $this->log('info', '手动采集队列任务已提交,约 1 秒后后台执行', $rule_id, 0, '');
        }
        wp_safe_redirect($this->page_url('wp-caiji-queue', array('scheduled'=>'collect')));
        exit;
    }

    private static function spawn_cron()
    {
        if (defined('DOING_CRON') && DOING_CRON) return;
        $doing_wp_cron = sprintf('%.22F', microtime(true));
        set_transient('doing_cron', $doing_wp_cron, 60);
        wp_remote_post(site_url('wp-cron.php?doing_wp_cron=' . $doing_wp_cron), array(
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ));
    }

    public function cron_discover_rule_once($rule_id)
    {
        $this->discover_rule(absint($rule_id));
    }

    public function cron_collect_rule_once($rule_id)
    {
        $this->collect_pending(absint($rule_id));
    }

    public function retry_queue()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_retry_queue')) wp_die('权限验证失败');
        $id = absint($_GET['id'] ?? 0);
        $wpdb->update($this->queue_table, array('status'=>'pending','attempts'=>0,'last_error'=>null,'scheduled_at'=>current_time('mysql'),'started_at'=>null,'finished_at'=>null), array('id'=>$id));
        wp_safe_redirect($this->page_url('wp-caiji-queue'));
        exit;
    }

    public function delete_queue()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_delete_queue')) wp_die('权限验证失败');
        $wpdb->delete($this->queue_table, array('id'=>absint($_GET['id'] ?? 0)));
        wp_safe_redirect($this->page_url('wp-caiji-queue'));
        exit;
    }

    public function clear_logs()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_clear_logs')) wp_die('权限验证失败');
        $wpdb->query("TRUNCATE TABLE {$this->logs_table}");
        wp_safe_redirect($this->page_url('wp-caiji-logs'));
        exit;
    }


    public function test_rule()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_save_rule')) wp_die('权限验证失败');
        $id = absint($_POST['id'] ?? 0);
        $url = esc_url_raw(wp_unslash($_POST['test_url'] ?? ''));
        if ($url === '') {
            $posted_manual_urls = trim((string)wp_unslash($_POST['manual_urls'] ?? ''));
            $parsed_urls = $this->parse_urls($posted_manual_urls);
            if ($parsed_urls) {
                $url = esc_url_raw($parsed_urls[0]);
            } else {
                $posted_list_urls = trim((string)wp_unslash($_POST['list_urls'] ?? ''));
                $list_urls = $this->parse_urls($posted_list_urls);
                if ($list_urls) {
                    $rule_for_links = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", $id), ARRAY_A) : null;
                    if ($rule_for_links) {
                        $list_html = $this->fetch($list_urls[0], $id, 0);
                        $links = $list_html ? $this->extract_links_by_rule($list_html, $rule_for_links, $list_urls[0]) : array();
                        if ($links) $url = esc_url_raw($links[0]);
                    }
                }
            }
        }
        $rule = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", $id), ARRAY_A) : null;
        if (!$rule) {
            $token = $this->set_test_result('article', array('error'=>'规则不存在或已被删除'), 60);
            wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'article_test'=>$token)));
            exit;
        }
        if (!WP_Caiji_Utils::is_safe_public_url($url)) {
            $token = $this->set_test_result('article', array('error'=>'测试 URL 无效或被安全策略拒绝:只允许公网 http/https 地址'), 60);
            wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'article_test'=>$token)));
            exit;
        }
        $html = $this->fetch($url, $id, 0);
        if (!$html) {
            $token = $this->set_test_result('article', array('error'=>'页面抓取失败,请查看采集日志'), 60);
            wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'article_test'=>$token)));
            exit;
        }
        $links = $this->extract_links_by_rule($html, $rule, $url);
        $safe_links = array();
        $duplicate_links = array();
        foreach ($links as $link) {
            if (!WP_Caiji_Utils::is_safe_public_url($link)) continue;
            if ($this->post_exists_by_source($link)) {
                $duplicate_links[] = $link;
            } else {
                $safe_links[] = $link;
            }
        }
        $title_raw = $this->extract_field_by_rule($html, $rule, 'title', $rule['title_selector'], true);
        $content_raw = $this->extract_field_by_rule($html, $rule, 'content', $rule['content_selector'], false);
        $selector_counts = array(
            'link'=>WP_Caiji_Parser::link_match_count_by_rule($html, $rule),
            'title'=>WP_Caiji_Parser::field_match_count_by_rule($html, $rule, 'title', $rule['title_selector']),
            'content'=>WP_Caiji_Parser::field_match_count_by_rule($html, $rule, 'content', $rule['content_selector']),
            'date'=>!empty($rule['date_selector']) || !empty($rule['date_before_marker']) || !empty($rule['date_after_marker']) ? WP_Caiji_Parser::field_match_count_by_rule($html, $rule, 'date', $rule['date_selector']) : 0,
        );
        $html_samples = array(
            'title'=>WP_Caiji_Parser::extract_field_outer_html_sample_by_rule($html, $rule, 'title', $rule['title_selector'], 1200),
            'content'=>WP_Caiji_Parser::extract_field_outer_html_sample_by_rule($html, $rule, 'content', $rule['content_selector'], 1800),
        );
        $title = $this->apply_replacements($title_raw, $rule['replace_rules'] ?? '');
        $content = $this->apply_replacements($this->clean_content($content_raw, $rule), $rule['replace_rules'] ?? '');
        $date = (!empty($rule['date_selector']) || !empty($rule['date_before_marker']) || !empty($rule['date_after_marker'])) ? $this->extract_field_by_rule($html, $rule, 'date', $rule['date_selector'], true) : '';
        $raw_text_length = mb_strlen(wp_strip_all_tags($content_raw));
        $clean_text_length = mb_strlen(wp_strip_all_tags($content));
        $image_sources = WP_Caiji_Parser::extract_image_sources($content);
        $image_sources = array_slice($image_sources, 0, 10);
        $warnings = array();
        if (trim(wp_strip_all_tags($title)) === '') $warnings[] = '标题提取为空，请检查标题选择器或标题前后代码';
        if (trim(wp_strip_all_tags($content_raw)) === '') $warnings[] = '正文提取为空，请检查正文选择器或正文前后代码';
        if (trim(wp_strip_all_tags($content)) === '') $warnings[] = '清洗/替换后正文为空,请检查移除选择器、替换规则或正文选择器';
        if ((!empty($rule['date_selector']) || !empty($rule['date_before_marker']) || !empty($rule['date_after_marker'])) && trim($date) === '') $warnings[] = '日期提取为空，请检查日期选择器或日期前后代码';
        if (!empty($rule['download_images'])) {
            $settings = $this->get_settings();
            $warnings[] = '图片本地化限制:每篇最多 ' . intval($settings['max_images_per_post']) . ' 张,单图最大 ' . intval($settings['max_image_size_mb']) . ' MB';
        }
        $token = $this->set_test_result('article', array(
            'url'=>$url,
            'title'=>$title,
            'date'=>$date,
            'content_text'=>wp_html_excerpt(wp_strip_all_tags($content), 1000),
            'raw_text_length'=>$raw_text_length,
            'content_length'=>$clean_text_length,
            'image_count'=>count(WP_Caiji_Parser::extract_image_sources($content)),
            'links_found'=>count($links),
            'links_ready'=>count($safe_links),
            'links_duplicate'=>count($duplicate_links),
            'sample_links'=>array_slice($safe_links, 0, 5),
            'sample_duplicate_links'=>array_slice($duplicate_links, 0, 5),
            'sample_images'=>$image_sources,
            'warnings'=>$warnings,
            'selectors'=>array(
                'link'=>$rule['link_selector'],
                'title'=>$rule['title_selector'],
                'content'=>$rule['content_selector'],
                'date'=>$rule['date_selector'],
            ),
            'link_marker'=>array(
                'before'=>$rule['link_before_marker'] ?? '',
                'after'=>$rule['link_after_marker'] ?? '',
            ),
            'field_markers'=>array(
                'title'=>array('before'=>$rule['title_before_marker'] ?? '', 'after'=>$rule['title_after_marker'] ?? ''),
                'content'=>array('before'=>$rule['content_before_marker'] ?? '', 'after'=>$rule['content_after_marker'] ?? ''),
                'date'=>array('before'=>$rule['date_before_marker'] ?? '', 'after'=>$rule['date_after_marker'] ?? ''),
            ),
            'selector_counts'=>$selector_counts,
            'html_samples'=>$html_samples,
        ), 300);
        wp_safe_redirect($this->page_url('wp-caiji-rules', array('edit'=>$id, 'article_test'=>$token)));
        exit;
    }

    private function render_test_result($rule_id)
    {
        if (!$rule_id) return;
        $result = $this->get_test_result('article', 'article_test');
        if (!$result) return;
        echo '<div class="wp-caiji-result-card wp-caiji-test-result-card wp-caiji-article-test-result"><div class="wp-caiji-section-title"><span>测试预览结果</span></div>';
        if (!empty($result['error'])) {
            echo '<p style="color:#b32d2e">' . esc_html($result['error']) . '</p></div>';
            return;
        }
        echo '<div class="wp-caiji-test-primary">';
        echo '<p><strong>URL:</strong><a href="' . esc_url($result['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($result['url']) . '</a></p>';
        echo '<p><strong>标题:</strong>' . esc_html($result['title'] ?: '未提取到') . '</p>';
        echo '<p><strong>日期:</strong>' . esc_html($result['date'] ?: '未提取到/未设置') . '</p>';
        echo '<p><strong>正文长度:</strong>原始 ' . intval($result['raw_text_length'] ?? 0) . ' 字；清洗后 ' . intval($result['content_length']) . ' 字；<strong>图片数:</strong>' . intval($result['image_count']) . '</p>';
        echo '<p><strong>正文预览:</strong></p>';
        echo '<textarea class="large-text code wp-caiji-content-preview" rows="10" readonly>' . esc_textarea($result['content_text']) . '</textarea>';
        echo '</div>';
        if (!empty($result['warnings']) && is_array($result['warnings'])) {
            echo '<div class="wp-caiji-test-warning"><strong>测试提示:</strong><ul>';
            foreach ($result['warnings'] as $warning) echo '<li>' . esc_html($warning) . '</li>';
            echo '</ul></div>';
        }
        echo '<details class="wp-caiji-test-diagnostics"><summary><strong>查看提取诊断信息</strong></summary>';
        if (!empty($result['selectors']) && is_array($result['selectors'])) {
            echo '<p class="description"><strong>选择器:</strong>链接 ' . esc_html($result['selectors']['link'] ?? '') . '；标题 ' . esc_html($result['selectors']['title']) . '；正文 ' . esc_html($result['selectors']['content']) . (!empty($result['selectors']['date']) ? '；日期 ' . esc_html($result['selectors']['date']) : '') . '</p>';
            if (!empty($result['link_marker']) && (!empty($result['link_marker']['before']) || !empty($result['link_marker']['after']))) {
                echo '<p class="description"><strong>链接截取:</strong>已启用前后代码截取模式</p>';
            }
            if (!empty($result['field_markers']) && is_array($result['field_markers'])) {
                $enabled_markers = array();
                foreach (array('title'=>'标题', 'content'=>'正文', 'date'=>'日期') as $key=>$label) {
                    if (!empty($result['field_markers'][$key]['before']) || !empty($result['field_markers'][$key]['after'])) $enabled_markers[] = $label;
                }
                if ($enabled_markers) echo '<p class="description"><strong>字段截取:</strong>' . esc_html(implode('、', $enabled_markers)) . ' 已启用前后代码截取模式</p>';
            }
        }
        if (!empty($result['selector_counts']) && is_array($result['selector_counts'])) {
            echo '<p><strong>选择器匹配数量:</strong>链接 ' . intval($result['selector_counts']['link'] ?? 0) . '；标题 ' . intval($result['selector_counts']['title'] ?? 0) . '；正文 ' . intval($result['selector_counts']['content'] ?? 0) . '；日期 ' . intval($result['selector_counts']['date'] ?? 0) . '</p>';
        }
        echo '<p><strong>列表链接诊断:</strong>匹配 ' . intval($result['links_found'] ?? 0) . ' 条；可入队 ' . intval($result['links_ready'] ?? 0) . ' 条；来源已存在 ' . intval($result['links_duplicate'] ?? 0) . ' 条。</p>';
        if (!empty($result['sample_links'])) {
            echo '<p><strong>可入队链接示例:</strong></p><ol style="margin-left:20px">';
            foreach ((array)$result['sample_links'] as $link) echo '<li><code>' . esc_html($link) . '</code></li>';
            echo '</ol>';
        }
        if (!empty($result['sample_images'])) {
            echo '<p><strong>图片示例:</strong></p><ol style="margin-left:20px">';
            foreach ((array)$result['sample_images'] as $img) echo '<li><code>' . esc_html($img) . '</code></li>';
            echo '</ol>';
        }
        if (!empty($result['html_samples']['title']) || !empty($result['html_samples']['content'])) {
            echo '<div style="margin-top:12px">';
            if (!empty($result['html_samples']['title'])) echo '<p><strong>标题节点 HTML:</strong></p><textarea class="large-text code" rows="5" readonly>' . esc_textarea($result['html_samples']['title']) . '</textarea>';
            if (!empty($result['html_samples']['content'])) echo '<p><strong>正文节点 HTML:</strong></p><textarea class="large-text code" rows="8" readonly>' . esc_textarea($result['html_samples']['content']) . '</textarea>';
            echo '</div>';
        }
        echo '</details>';
        echo '</div>';
    }

    public function bulk_queue()
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_bulk_queue')) wp_die('权限验证失败');
        $action = sanitize_key($_POST['bulk_action'] ?? '');
        $ids = array_map('absint', (array)($_POST['queue_ids'] ?? array()));
        $ids = array_values(array_filter($ids));
        if ($action === 'retry_failed') {
            $wpdb->query("UPDATE {$this->queue_table} SET status='pending', attempts=0, last_error=NULL, scheduled_at='" . esc_sql(current_time('mysql')) . "', started_at=NULL, finished_at=NULL WHERE status='failed'");
        } elseif ($action === 'delete_success') {
            $wpdb->query("DELETE FROM {$this->queue_table} WHERE status='success'");
        } elseif ($ids) {
            $in = implode(',', array_map('intval', $ids));
            if ($action === 'retry') {
                $wpdb->query("UPDATE {$this->queue_table} SET status='pending', attempts=0, last_error=NULL, scheduled_at='" . esc_sql(current_time('mysql')) . "', started_at=NULL, finished_at=NULL WHERE id IN ({$in})");
            } elseif ($action === 'delete') {
                $wpdb->query("DELETE FROM {$this->queue_table} WHERE id IN ({$in})");
            }
        }
        wp_safe_redirect($this->page_url('wp-caiji-queue'));
        exit;
    }


    private function acquire_lock($name)
    {
        $settings = $this->get_settings();
        $ttl = max(60, (int)$settings['lock_ttl_seconds']);
        $now = time();
        $locked_at = (int)get_transient($name);
        if ($locked_at && ($now - $locked_at) < $ttl) return false;
        set_transient($name, $now, $ttl);
        return true;
    }

    private function release_lock($name)
    {
        delete_transient($name);
    }

    public function cron_discover()
    {
        if (!$this->acquire_lock(self::LOCK_DISCOVER)) {
            $this->log('warning', '发现链接任务已有实例运行,本次跳过', 0, 0, '');
            return;
        }

        try {
            global $wpdb;
            $settings = $this->get_settings();
            $limit = max(1, (int)$settings['max_rules_per_discover']);
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->rules_table} WHERE enabled=1 ORDER BY COALESCE(last_discovered_at, '1970-01-01') ASC LIMIT %d", $limit));
            foreach ($ids as $id) $this->discover_rule((int)$id);
        } finally {
            $this->release_lock(self::LOCK_DISCOVER);
        }
    }

    public function cron_collect()
    {
        if (!$this->acquire_lock(self::LOCK_COLLECT)) {
            $this->log('warning', '采集文章任务已有实例运行,本次跳过', 0, 0, '');
            return;
        }

        try {
            $this->collect_pending(0);
        } finally {
            $this->release_lock(self::LOCK_COLLECT);
        }
    }

    private function discover_rule($rule_id)
    {
        global $wpdb;
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", $rule_id), ARRAY_A);
        if (!$rule) return;
        $added = 0;
        $this->enqueue_manual_urls($rule_id, $rule['manual_urls']);
        $pages = $this->build_list_pages($rule);
        foreach ($pages as $page_url) {
            $html = $this->fetch($page_url, $rule_id, 0);
            if (!$html) continue;
            $links = $this->extract_links_by_rule($html, $rule, $page_url);
            foreach ($links as $link) {
                if ($this->enqueue_url($rule_id, $link, $added, $rule)) $added++;
            }
        }
        $wpdb->update($this->rules_table, array('last_discovered_at'=>current_time('mysql')), array('id'=>$rule_id));
        $this->log('info', '发现链接完成,新增 ' . $added . ' 条', $rule_id, 0, '');
    }

    private function build_list_pages($rule)
    {
        return WP_Caiji_Discovery::build_list_pages($rule);
    }

    private function enqueue_manual_urls($rule_id, $text)
    {
        return WP_Caiji_Discovery::enqueue_manual_urls($this, $rule_id, $text);
    }

    private function enqueue_url($rule_id, $url, $offset = 0, $rule = null)
    {
        return WP_Caiji_Discovery::enqueue_url($this, $rule_id, $url, $offset, $rule);
    }

    private function parse_urls($text)
    {
        return WP_Caiji_Discovery::parse_urls($text);
    }

    private function normalize_url($url)
    {
        return WP_Caiji_Utils::normalize_url($url);
    }

    private function collect_pending($rule_id = 0)
    {
        WP_Caiji_Collector::collect_pending($this, $rule_id);
    }

    public function collect_queue_item_public($item)
    {
        $this->collect_queue_item($item);
    }

    public function get_rule_public($rule_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->rules_table} WHERE id=%d", (int)$rule_id), ARRAY_A);
    }


    private function release_stuck_running($minutes)
    {
        WP_Caiji_Queue::release_stuck_running($this, $minutes);
    }

    private function collect_queue_item($item)
    {
        global $wpdb;
        $queue_id = (int)$item['queue_id'];
        $rule_id = (int)$item['rule_id'];
        $url = $item['queue_url'];
        if (!WP_Caiji_Queue::claim($this, $item)) {
            $this->log('warning', '队列已被其他进程认领或尚未到执行时间,本次跳过', $rule_id, $queue_id, $url);
            return;
        }
        $item['queue_attempts'] = ((int)$item['queue_attempts']) + 1;
        $html = $this->fetch($url, $rule_id, $queue_id);
        if (!$html) {
            $this->mark_failed($item, '页面抓取失败');
            return;
        }
        $title = $this->extract_field_by_rule($html, $item, 'title', $item['title_selector'], true);
        $content = $this->extract_field_by_rule($html, $item, 'content', $item['content_selector'], false);
        $date = (!empty($item['date_selector']) || !empty($item['date_before_marker']) || !empty($item['date_after_marker'])) ? $this->extract_field_by_rule($html, $item, 'date', $item['date_selector'], true) : '';
        if (!$title || !$content) {
            $this->mark_failed($item, '标题或正文提取失败,请检查选择器');
            return;
        }
        $title = $this->apply_replacements($title, $item['replace_rules'] ?? '');
        $content = $this->apply_replacements($content, $item['replace_rules'] ?? '');
        $original_image_count = count(WP_Caiji_Parser::extract_image_sources($content));
        if (!empty($item['dedupe_title']) && ($this->post_exists_by_title($title) || $this->post_exists_by_source($url))) {
            $wpdb->update($this->queue_table, array('status'=>'skipped','last_error'=>'标题或来源 URL 重复','finished_at'=>current_time('mysql')), array('id'=>$queue_id));
            $this->log('warning', '标题或来源 URL 重复,已跳过', $rule_id, $queue_id, $url);
            return;
        }
        $content = $this->clean_content($content, $item);
        $featured_id = 0;
        if (!empty($item['download_images'])) {
            $settings = $this->get_settings();
            $logger = array($this, 'log_public');
            $media_stats = array();
            $content = $this->download_images($content, $url, !empty($item['set_featured_image']), $featured_id, $item['image_alt_template'] ?? '', $title, $settings, $logger, $rule_id, $queue_id, $media_stats);
            if (!empty($media_stats['found'])) {
                $summary = '图片本地化摘要:发现 ' . intval($media_stats['found']) . ' 张,成功 ' . intval($media_stats['downloaded']) . ' 张,失败 ' . intval($media_stats['failed']) . ' 张,跳过 ' . intval($media_stats['skipped']) . ' 张';
                if (!empty($media_stats['featured_id'])) $summary .= ',特色图附件 ID:' . intval($media_stats['featured_id']);
                $this->log(!empty($media_stats['failed']) ? 'warning' : 'info', $summary, $rule_id, $queue_id, $url);
            }
        }
        $settings = isset($settings) ? $settings : $this->get_settings();
        if (!empty($settings['ai_enabled']) && !empty($item['ai_rewrite'])) {
            $ai_result = WP_Caiji_AI::rewrite($title, $content, $item, $settings);
            if (is_wp_error($ai_result)) {
                $message = 'AI 改写失败:' . $ai_result->get_error_message();
                if (($item['ai_rewrite_on_failure'] ?? 'fallback') === 'fail') {
                    $this->mark_failed($item, $message);
                    return;
                }
                $this->log('warning', $message . ';已回退发布原文', $rule_id, $queue_id, $url);
            } else {
                $candidate_title = trim(wp_strip_all_tags((string)($ai_result['title'] ?? '')));
                $candidate_content = trim((string)($ai_result['content'] ?? ''));
                $min_chars = max(80, (int)(mb_strlen(wp_strip_all_tags($content)) * 0.25));
                if ($candidate_content === '' || mb_strlen(wp_strip_all_tags($candidate_content)) < $min_chars) {
                    $message = 'AI 改写结果过短或为空,疑似质量不合格';
                    if (($item['ai_rewrite_on_failure'] ?? 'fallback') === 'fail') {
                        $this->mark_failed($item, $message);
                        return;
                    }
                    $this->log('warning', $message . ';已回退发布原文', $rule_id, $queue_id, $url);
                } else {
                    $candidate_image_count = count(WP_Caiji_Parser::extract_image_sources($candidate_content));
                    if ($original_image_count > 0 && $candidate_image_count === 0) {
                        $message = 'AI 改写后图片全部丢失,疑似质量不合格';
                        if (($item['ai_rewrite_on_failure'] ?? 'fallback') === 'fail') {
                            $this->mark_failed($item, $message);
                            return;
                        }
                        $this->log('warning', $message . ';已回退发布原文', $rule_id, $queue_id, $url);
                    } else {
                        if ($candidate_title !== '') $title = $candidate_title;
                        $content = $candidate_content;
                        if ($original_image_count > $candidate_image_count) $this->log('warning', 'AI 改写后图片数量减少:原 ' . $original_image_count . ' 张,现 ' . $candidate_image_count . ' 张', $rule_id, $queue_id, $url);
                        $this->log('info', 'AI 改写完成', $rule_id, $queue_id, $url);
                    }
                }
            }
        }
        $post_id = WP_Caiji_Publisher::publish($item, $title, $content, $date, $url, $featured_id);
        if (is_wp_error($post_id)) {
            $this->mark_failed($item, $post_id->get_error_message());
            return;
        }
        $wpdb->update($this->queue_table, array('status'=>'success','post_id'=>$post_id,'last_error'=>null,'finished_at'=>current_time('mysql')), array('id'=>$queue_id));
        $wpdb->update($this->rules_table, array('last_collected_at'=>current_time('mysql')), array('id'=>$rule_id));
        $this->log('success', '采集成功,文章 ID:' . $post_id, $rule_id, $queue_id, $url);
    }

    private function download_images($content, $base_url, $set_featured, &$featured_id, $alt_template = '', $title = '', $settings = array(), $logger = null, $rule_id = 0, $queue_id = 0, &$stats = null)
    {
        return WP_Caiji_Media::download_images($content, $base_url, $set_featured, $featured_id, $alt_template, $title, $settings, $logger, $rule_id, $queue_id, $stats);
    }

    private function fetch($url, $rule_id = 0, $queue_id = 0)
    {
        return WP_Caiji_Fetcher::fetch($this, $url, $rule_id, $queue_id);
    }

    private function extract_links_by_rule($html, $rule, $base_url)
    {
        return WP_Caiji_Parser::extract_links_by_rule($html, $rule, $base_url);
    }

    private function extract_links($html, $selector, $base_url)
    {
        return WP_Caiji_Parser::extract_links($html, $selector, $base_url);
    }

    private function extract($html, $selector, $text_only = false)
    {
        return WP_Caiji_Parser::extract($html, $selector, $text_only);
    }

    private function extract_field_by_rule($html, $rule, $field, $selector = '', $text_only = false)
    {
        return WP_Caiji_Parser::extract_field_by_rule($html, $rule, $field, $selector, $text_only);
    }

    private function clean_content($content, $rule_or_selectors)
    {
        return WP_Caiji_Parser::clean_content($content, $rule_or_selectors);
    }

    private function make_excerpt($content, $length = 160)
    {
        return WP_Caiji_Content::make_excerpt($content, $length);
    }

    private function render_template($template, $title, $excerpt, $source)
    {
        return WP_Caiji_Content::render_template($template, $title, $excerpt, $source);
    }

    private function write_seo_meta($post_id, $rule, $title, $excerpt, $source)
    {
        WP_Caiji_Content::write_seo_meta($post_id, $rule, $title, $excerpt, $source);
    }

    private function match_category_id($text, $rules)
    {
        return WP_Caiji_Content::match_category_id($text, $rules);
    }

    private function match_auto_tags($text, $keywords)
    {
        return WP_Caiji_Content::match_auto_tags($text, $keywords);
    }

    private function apply_replacements($text, $rules)
    {
        return WP_Caiji_Content::apply_replacements($text, $rules);
    }

    private function parse_tags($tags)
    {
        return WP_Caiji_Content::parse_tags($tags);
    }

    private function mark_failed($item, $message)
    {
        WP_Caiji_Queue::mark_failed($this, $item, $message);
    }

    private function post_exists_by_source($url)
    {
        return WP_Caiji_Queue::post_exists_by_source($url);
    }

    private function post_exists_by_title($title)
    {
        return WP_Caiji_Queue::post_exists_by_title($title);
    }

    public function log_public($level, $message, $rule_id = 0, $queue_id = 0, $url = '')
    {
        $this->log($level, $message, $rule_id, $queue_id, $url);
    }

    private function log($level, $message, $rule_id = 0, $queue_id = 0, $url = '')
    {
        WP_Caiji_Logger::log($this, $level, $message, $rule_id, $queue_id, $url);
    }
}
