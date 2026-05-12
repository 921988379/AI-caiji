<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Queue helper methods for WP Caiji.
 */
class WP_Caiji_Queue
{
    public static function claim($plugin, $item)
    {
        global $wpdb;
        $queue_table = $plugin->queue_table();
        $queue_id = (int)$item['queue_id'];
        $attempts = ((int)$item['queue_attempts']) + 1;
        $now = current_time('mysql');
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$queue_table}
             SET status='running', attempts=%d, started_at=%s, finished_at=NULL, last_error=NULL
             WHERE id=%d AND status='pending' AND (scheduled_at IS NULL OR scheduled_at <= %s)",
            $attempts,
            $now,
            $queue_id,
            $now
        ));
        return $affected === 1;
    }

    public static function release_stuck_running($plugin, $minutes)
    {
        global $wpdb;
        $minutes = max(5, (int)$minutes);
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * 60));
        $queue_table = $plugin->queue_table();
        $affected = $wpdb->query($wpdb->prepare("UPDATE {$queue_table} SET status='pending', last_error='running 超时自动释放', scheduled_at=%s, finished_at=%s WHERE status='running' AND started_at < %s", current_time('mysql'), current_time('mysql'), $cutoff));
        return max(0, (int)$affected);
    }

    public static function mark_failed($plugin, $item, $message)
    {
        global $wpdb;
        $attempts = max(1, (int)$item['queue_attempts']);
        $status = $attempts >= (int)$item['retry_limit'] ? 'failed' : 'pending';
        $delay = $status === 'pending' ? min(3600, max(60, $attempts * 300)) : 0;
        $scheduled_at = date('Y-m-d H:i:s', current_time('timestamp') + $delay);
        $queue_table = $plugin->queue_table();
        $wpdb->update($queue_table, array('status'=>$status,'attempts'=>$attempts,'last_error'=>$message,'scheduled_at'=>$scheduled_at,'finished_at'=>current_time('mysql')), array('id'=>(int)$item['queue_id']));
        $plugin->log_public('error', $message, (int)$item['rule_id'], (int)$item['queue_id'], $item['queue_url']);
    }

    public static function post_exists_by_source($url)
    {
        $q = new WP_Query(array('post_type'=>'post','post_status'=>'any','meta_key'=>WP_Caiji::META_SOURCE_URL,'meta_value'=>esc_url_raw($url),'fields'=>'ids','posts_per_page'=>1,'no_found_rows'=>true));
        return $q->have_posts();
    }

    public static function post_exists_by_title($title)
    {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title=%s AND post_type='post' AND post_status NOT IN ('trash','auto-draft') LIMIT 1", wp_strip_all_tags($title)));
    }
}
