<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queue collection batch runner for WP Caiji.
 */
class WP_Caiji_Collector
{
    public static function collect_pending($plugin, $rule_id = 0)
    {
        global $wpdb;

        $settings = wp_parse_args(get_option(WP_Caiji::OPTION_SETTINGS, array()), WP_Caiji_DB::default_settings());
        $start_time = time();
        $released = WP_Caiji_Queue::release_stuck_running($plugin, (int)$settings['running_timeout_minutes']);
        if ($released > 0) {
            $plugin->log_public('warning', '自动释放 running 超时队列 ' . $released . ' 条', 0, 0, '');
        }

        $where = "q.status='pending' AND r.enabled=1 AND (q.scheduled_at IS NULL OR q.scheduled_at <= %s)";
        $params = array(current_time('mysql'));
        if ($rule_id) {
            $where .= ' AND q.rule_id=%d';
            $params[] = (int)$rule_id;
        }

        $rules_table = $plugin->rules_table();
        $queue_table = $plugin->queue_table();
        $limit = $rule_id ? (int)$wpdb->get_var($wpdb->prepare("SELECT batch_limit FROM {$rules_table} WHERE id=%d", $rule_id)) : (int)$settings['global_collect_limit'];
        $sql = "SELECT q.*, r.*,
            q.id queue_id, q.url queue_url, q.attempts queue_attempts
            FROM {$queue_table} q INNER JOIN {$rules_table} r ON q.rule_id=r.id
            WHERE {$where}
            ORDER BY q.id ASC LIMIT %d";
        $params[] = max(1, min(50, $limit));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        foreach ($rows as $index => $row) {
            if ((time() - $start_time) >= (int)$settings['max_runtime_seconds']) {
                $plugin->log_public('warning', '达到单次 Cron 最大运行时间，剩余队列下次继续', 0, 0, '');
                break;
            }
            if ($index > 0) {
                $delay = max(0, min(30, (int)($row['request_delay'] ?? 0)));
                if ($delay > 0) {
                    $scheduled_at = date('Y-m-d H:i:s', current_time('timestamp') + ($delay * $index));
                    $wpdb->update($queue_table, array('scheduled_at'=>$scheduled_at), array('id'=>(int)$row['queue_id'], 'status'=>'pending'));
                    $plugin->log_public('info', '按请求间隔延后采集至 ' . $scheduled_at, (int)$row['rule_id'], (int)$row['queue_id'], (string)$row['queue_url']);
                    continue;
                }
            }
            $plugin->collect_queue_item_public($row);
        }
    }
}
