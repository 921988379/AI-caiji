<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Health check, maintenance actions, and diagnostics export.
 */
class WP_Caiji_Health
{
    public static function render($plugin)
    {
        global $wpdb;
        if (!current_user_can('manage_options')) return;
        $rules_table = $plugin->rules_table();
        $queue_table = $plugin->queue_table();
        $logs_table = $plugin->logs_table();
        $tables = array('规则表'=>$rules_table, '队列表'=>$queue_table, '日志表'=>$logs_table);
        $table_status = array();
        foreach ($tables as $label=>$table) {
            $exists = (bool)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            $table_status[$label] = array('table'=>$table, 'exists'=>$exists);
        }
        $counts = array();
        foreach (array('pending','running','success','failed','skipped') as $status) {
            $counts[$status] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status=%s", $status));
        }
        $settings = wp_parse_args(get_option(WP_Caiji::OPTION_SETTINGS, array()), WP_Caiji_DB::default_settings());
        $running_cutoff = date('Y-m-d H:i:s', current_time('timestamp') - ((int)$settings['running_timeout_minutes'] * 60));
        $stuck_running = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status='running' AND started_at < %s", $running_cutoff));
        $last_success = $wpdb->get_var("SELECT finished_at FROM {$queue_table} WHERE status='success' ORDER BY finished_at DESC LIMIT 1");
        $last_error = $wpdb->get_row("SELECT created_at,message,url FROM {$logs_table} WHERE level='error' ORDER BY id DESC LIMIT 1", ARRAY_A);
        $next_discover = wp_next_scheduled(WP_Caiji::CRON_DISCOVER);
        $next_collect = wp_next_scheduled(WP_Caiji::CRON_COLLECT);
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $cron_is_stale = ($next_discover && $next_discover < time() - 300) || ($next_collect && $next_collect < time() - 300);
        $cron_discover_error = get_option('wp_caiji_cron_discover_error', '');
        $cron_collect_error = get_option('wp_caiji_cron_collect_error', '');
        $lock_discover = get_transient(WP_Caiji::LOCK_DISCOVER);
        $lock_collect = get_transient(WP_Caiji::LOCK_COLLECT);
        $total_done = max(1, $counts['success'] + $counts['failed']);
        $fail_rate = round(($counts['failed'] / $total_done) * 100, 2);
        ?>
        <div class="wrap wp-caiji-page">
            <?php $plugin->render_page_header('WP 采集健康检查', '检查数据表、队列状态、Cron 计划、任务锁和最近错误。'); ?>
            <div class="wp-caiji-section-title"><span>状态概览</span></div>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th>数据库表</th><td><?php foreach ($table_status as $label=>$info): ?><?php echo esc_html($label . '：' . ($info['exists'] ? '正常' : '缺失') . '（' . $info['table'] . '）'); ?><br><?php endforeach; ?></td></tr>
                <tr><th>队列状态</th><td>待采 <?php echo intval($counts['pending']); ?>，运行中 <?php echo intval($counts['running']); ?>，成功 <?php echo intval($counts['success']); ?>，失败 <?php echo intval($counts['failed']); ?>，跳过 <?php echo intval($counts['skipped']); ?></td></tr>
                <tr><th>失败率</th><td><?php echo esc_html($fail_rate); ?>%</td></tr>
                <tr><th>卡住 running</th><td><?php echo intval($stuck_running); ?> 条</td></tr>
                <tr><th>最近成功采集</th><td><?php echo esc_html($last_success ?: '暂无'); ?></td></tr>
                <tr><th>最近错误</th><td><?php echo $last_error ? esc_html($last_error['created_at'] . ' - ' . $last_error['message'] . ' - ' . $last_error['url']) : '暂无'; ?></td></tr>
                <tr><th>Cron</th><td>发现链接：<?php echo $next_discover ? esc_html(date_i18n('Y-m-d H:i:s', $next_discover)) : '未计划'; ?>；采集文章：<?php echo $next_collect ? esc_html(date_i18n('Y-m-d H:i:s', $next_collect)) : '未计划'; ?><?php if ($cron_discover_error || $cron_collect_error): ?><br><span style="color:#b32d2e">最近一次计划任务注册失败：发现 <?php echo esc_html($cron_discover_error ?: '无'); ?>；采集 <?php echo esc_html($cron_collect_error ?: '无'); ?></span><?php endif; ?><?php if ($wp_cron_disabled): ?><br><span style="color:#b32d2e">检测到 DISABLE_WP_CRON=true：请确认服务器计划任务定期请求 <?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?>，否则队列不会自动继续。</span><?php endif; ?><?php if ($cron_is_stale): ?><br><span style="color:#b32d2e">检测到 WP-Cron 已过计划时间超过 5 分钟，可能没有被系统 Cron 正常触发。</span><?php endif; ?></td></tr>
                <tr><th>任务锁</th><td>发现锁：<?php echo $lock_discover ? esc_html(date_i18n('Y-m-d H:i:s', (int)$lock_discover)) : '无'; ?>；采集锁：<?php echo $lock_collect ? esc_html(date_i18n('Y-m-d H:i:s', (int)$lock_collect)) : '无'; ?></td></tr>
            </tbody></table>
            <div class="wp-caiji-section-title"><span>维护工具</span></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <?php wp_nonce_field('wp_caiji_health_action'); ?>
                <input type="hidden" name="action" value="wp_caiji_health_action">
                <button class="button" name="health_action" value="repair_tables">修复/创建数据表</button>
                <button class="button" name="health_action" value="release_running" onclick="return confirm('确定释放 running 队列？')">释放 running 队列</button>
                <button class="button" name="health_action" value="reset_cron">重建定时任务</button>
                <button class="button" name="health_action" value="clear_locks">清理任务锁</button>
                <button class="button button-link-delete" name="health_action" value="clear_logs" onclick="return confirm('确定清空日志？')">清空日志</button>
            </form>
            <div class="wp-caiji-section-title"><span>诊断报告</span></div>
            <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_caiji_export_diagnostics'), 'wp_caiji_export_diagnostics')); ?>">导出诊断 JSON</a></p>
        </div>
        <?php
    }

    public static function handle_action($plugin)
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_health_action')) wp_die('权限验证失败');
        $queue_table = $plugin->queue_table();
        $logs_table = $plugin->logs_table();
        $action = sanitize_key($_POST['health_action'] ?? '');
        if ($action === 'repair_tables') {
            WP_Caiji_DB::create_tables();
        } elseif ($action === 'release_running') {
            $wpdb->query("UPDATE {$queue_table} SET status='pending', last_error='手动释放 running', scheduled_at='" . esc_sql(current_time('mysql')) . "', finished_at='" . esc_sql(current_time('mysql')) . "' WHERE status='running'");
        } elseif ($action === 'reset_cron') {
            WP_Caiji::clear_event_public(WP_Caiji::CRON_DISCOVER);
            WP_Caiji::clear_event_public(WP_Caiji::CRON_COLLECT);
            WP_Caiji::schedule_events();
            delete_option('wp_caiji_cron_discover_error');
            delete_option('wp_caiji_cron_collect_error');
        } elseif ($action === 'clear_locks') {
            delete_transient(WP_Caiji::LOCK_DISCOVER);
            delete_transient(WP_Caiji::LOCK_COLLECT);
        } elseif ($action === 'clear_logs') {
            $wpdb->query("TRUNCATE TABLE {$logs_table}");
        }
        wp_safe_redirect($plugin->admin_page_url('wp-caiji-health'));
        exit;
    }

    public static function export_diagnostics($plugin)
    {
        global $wpdb;
        if (!current_user_can('manage_options') || !check_admin_referer('wp_caiji_export_diagnostics')) wp_die('权限验证失败');
        $rules_table = $plugin->rules_table();
        $queue_table = $plugin->queue_table();
        $logs_table = $plugin->logs_table();
        $queue_counts = array();
        foreach (array('pending','running','success','failed','skipped') as $status) {
            $queue_counts[$status] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status=%s", $status));
        }
        $settings = wp_parse_args(get_option(WP_Caiji::OPTION_SETTINGS, array()), WP_Caiji_DB::default_settings());
        if (!empty($settings['ai_api_key'])) {
            $settings['ai_api_key'] = WP_Caiji_AI::mask_secret(WP_Caiji_AI::get_plain_api_key_from_value($settings['ai_api_key']));
        }
        if (!empty($settings['github_token'])) {
            $settings['github_token'] = WP_Caiji_AI::mask_secret($settings['github_token']);
        }
        $report = array(
            'generated_at' => current_time('mysql'),
            'plugin_version' => WP_CAIJI_VERSION,
            'site' => home_url(),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'settings' => $settings,
            'tables' => array(
                'rules' => $rules_table,
                'queue' => $queue_table,
                'logs' => $logs_table,
            ),
            'counts' => array(
                'rules' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$rules_table}"),
                'queue' => $queue_counts,
                'logs' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$logs_table}"),
            ),
            'cron' => array(
                'discover_next' => wp_next_scheduled(WP_Caiji::CRON_DISCOVER),
                'collect_next' => wp_next_scheduled(WP_Caiji::CRON_COLLECT),
                'discover_lock' => get_transient(WP_Caiji::LOCK_DISCOVER),
                'collect_lock' => get_transient(WP_Caiji::LOCK_COLLECT),
            ),
            'recent_errors' => $wpdb->get_results("SELECT created_at,rule_id,queue_id,level,message,url FROM {$logs_table} WHERE level IN ('error','warning') ORDER BY id DESC LIMIT 20", ARRAY_A),
            'failed_samples' => $wpdb->get_results("SELECT id,rule_id,url,attempts,last_error,finished_at FROM {$queue_table} WHERE status='failed' ORDER BY id DESC LIMIT 20", ARRAY_A),
        );
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=wp-caiji-diagnostics-' . date('Ymd-His') . '.json');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }


}
