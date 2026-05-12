<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database/schema/defaults layer for WP Caiji.
 */
class WP_Caiji_DB
{
    public static function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $rules = $wpdb->prefix . 'caiji_rules';
        $queue = $wpdb->prefix . 'caiji_queue';
        $logs = $wpdb->prefix . 'caiji_logs';

        dbDelta("CREATE TABLE {$rules} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            list_urls LONGTEXT NULL,
            link_selector VARCHAR(255) NULL,
            link_before_marker TEXT NULL,
            link_after_marker TEXT NULL,
            json_source VARCHAR(100) NULL,
            link_json_path VARCHAR(255) NULL,
            link_json_url_field VARCHAR(100) NULL,
            pagination_pattern VARCHAR(255) NULL,
            page_start INT UNSIGNED NOT NULL DEFAULT 1,
            page_end INT UNSIGNED NOT NULL DEFAULT 1,
            manual_urls LONGTEXT NULL,
            title_selector VARCHAR(255) NOT NULL DEFAULT '//h1',
            title_before_marker TEXT NULL,
            title_after_marker TEXT NULL,
            title_json_path VARCHAR(255) NULL,
            content_selector VARCHAR(255) NOT NULL DEFAULT '//article',
            content_before_marker TEXT NULL,
            content_after_marker TEXT NULL,
            content_json_path VARCHAR(255) NULL,
            date_selector VARCHAR(255) NULL,
            date_before_marker TEXT NULL,
            date_after_marker TEXT NULL,
            date_json_path VARCHAR(255) NULL,
            remove_selectors TEXT NULL,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_status VARCHAR(20) NOT NULL DEFAULT 'draft',
            batch_limit INT UNSIGNED NOT NULL DEFAULT 5,
            retry_limit INT UNSIGNED NOT NULL DEFAULT 3,
            request_delay INT UNSIGNED NOT NULL DEFAULT 1,
            download_images TINYINT(1) NOT NULL DEFAULT 0,
            set_featured_image TINYINT(1) NOT NULL DEFAULT 0,
            dedupe_title TINYINT(1) NOT NULL DEFAULT 1,
            fixed_tags VARCHAR(255) NULL,
            replace_rules LONGTEXT NULL,
            category_rules LONGTEXT NULL,
            auto_tags TINYINT(1) NOT NULL DEFAULT 0,
            auto_tag_keywords LONGTEXT NULL,
            publish_mode VARCHAR(20) NOT NULL DEFAULT 'immediate',
            publish_delay_min INT UNSIGNED NOT NULL DEFAULT 0,
            publish_delay_max INT UNSIGNED NOT NULL DEFAULT 0,
            ua_list TEXT NULL,
            referer VARCHAR(255) NULL,
            cookie TEXT NULL,
            auto_excerpt TINYINT(1) NOT NULL DEFAULT 1,
            excerpt_length INT UNSIGNED NOT NULL DEFAULT 160,
            seo_plugin VARCHAR(20) NOT NULL DEFAULT 'none',
            seo_title_template VARCHAR(255) NULL,
            seo_desc_template VARCHAR(255) NULL,
            remove_empty_paragraphs TINYINT(1) NOT NULL DEFAULT 1,
            remove_external_links TINYINT(1) NOT NULL DEFAULT 0,
            remove_paragraph_keywords TEXT NULL,
            image_alt_template VARCHAR(255) NULL,
            ai_rewrite TINYINT(1) NOT NULL DEFAULT 0,
            ai_rewrite_prompt LONGTEXT NULL,
            ai_rewrite_on_failure VARCHAR(20) NOT NULL DEFAULT 'fallback',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_discovered_at DATETIME NULL,
            last_collected_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY enabled (enabled)
        ) {$charset};");

        dbDelta("CREATE TABLE {$queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            url TEXT NOT NULL,
            url_hash CHAR(32) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            discovered_at DATETIME NOT NULL,
            scheduled_at DATETIME NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY rule_url (rule_id, url_hash),
            KEY status_rule (status, rule_id),
            KEY scheduled_at (scheduled_at),
            KEY post_id (post_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            queue_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            url TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY queue_id (queue_id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset};");

        self::maybe_add_index($queue, 'post_id', "ALTER TABLE {$queue} ADD KEY post_id (post_id)");
        self::maybe_add_index($logs, 'queue_id', "ALTER TABLE {$logs} ADD KEY queue_id (queue_id)");

        self::maybe_add_column($rules, 'link_before_marker', "ALTER TABLE {$rules} ADD link_before_marker TEXT NULL");
        self::maybe_add_column($rules, 'link_after_marker', "ALTER TABLE {$rules} ADD link_after_marker TEXT NULL");
        self::maybe_add_column($rules, 'json_source', "ALTER TABLE {$rules} ADD json_source VARCHAR(100) NULL");
        self::maybe_add_column($rules, 'link_json_path', "ALTER TABLE {$rules} ADD link_json_path VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'link_json_url_field', "ALTER TABLE {$rules} ADD link_json_url_field VARCHAR(100) NULL");
        self::maybe_add_column($rules, 'title_before_marker', "ALTER TABLE {$rules} ADD title_before_marker TEXT NULL");
        self::maybe_add_column($rules, 'title_after_marker', "ALTER TABLE {$rules} ADD title_after_marker TEXT NULL");
        self::maybe_add_column($rules, 'title_json_path', "ALTER TABLE {$rules} ADD title_json_path VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'content_before_marker', "ALTER TABLE {$rules} ADD content_before_marker TEXT NULL");
        self::maybe_add_column($rules, 'content_after_marker', "ALTER TABLE {$rules} ADD content_after_marker TEXT NULL");
        self::maybe_add_column($rules, 'content_json_path', "ALTER TABLE {$rules} ADD content_json_path VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'date_before_marker', "ALTER TABLE {$rules} ADD date_before_marker TEXT NULL");
        self::maybe_add_column($rules, 'date_after_marker', "ALTER TABLE {$rules} ADD date_after_marker TEXT NULL");
        self::maybe_add_column($rules, 'date_json_path', "ALTER TABLE {$rules} ADD date_json_path VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'fixed_tags', "ALTER TABLE {$rules} ADD fixed_tags VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'replace_rules', "ALTER TABLE {$rules} ADD replace_rules LONGTEXT NULL");
        self::maybe_add_column($rules, 'category_rules', "ALTER TABLE {$rules} ADD category_rules LONGTEXT NULL");
        self::maybe_add_column($rules, 'auto_tags', "ALTER TABLE {$rules} ADD auto_tags TINYINT(1) NOT NULL DEFAULT 0");
        self::maybe_add_column($rules, 'auto_tag_keywords', "ALTER TABLE {$rules} ADD auto_tag_keywords LONGTEXT NULL");
        self::maybe_add_column($rules, 'publish_mode', "ALTER TABLE {$rules} ADD publish_mode VARCHAR(20) NOT NULL DEFAULT 'immediate'");
        self::maybe_add_column($rules, 'publish_delay_min', "ALTER TABLE {$rules} ADD publish_delay_min INT UNSIGNED NOT NULL DEFAULT 0");
        self::maybe_add_column($rules, 'publish_delay_max', "ALTER TABLE {$rules} ADD publish_delay_max INT UNSIGNED NOT NULL DEFAULT 0");
        self::maybe_add_column($rules, 'ua_list', "ALTER TABLE {$rules} ADD ua_list TEXT NULL");
        self::maybe_add_column($rules, 'referer', "ALTER TABLE {$rules} ADD referer VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'cookie', "ALTER TABLE {$rules} ADD cookie TEXT NULL");
        self::maybe_add_column($rules, 'auto_excerpt', "ALTER TABLE {$rules} ADD auto_excerpt TINYINT(1) NOT NULL DEFAULT 1");
        self::maybe_add_column($rules, 'excerpt_length', "ALTER TABLE {$rules} ADD excerpt_length INT UNSIGNED NOT NULL DEFAULT 160");
        self::maybe_add_column($rules, 'seo_plugin', "ALTER TABLE {$rules} ADD seo_plugin VARCHAR(20) NOT NULL DEFAULT 'none'");
        self::maybe_add_column($rules, 'seo_title_template', "ALTER TABLE {$rules} ADD seo_title_template VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'seo_desc_template', "ALTER TABLE {$rules} ADD seo_desc_template VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'remove_empty_paragraphs', "ALTER TABLE {$rules} ADD remove_empty_paragraphs TINYINT(1) NOT NULL DEFAULT 1");
        self::maybe_add_column($rules, 'remove_external_links', "ALTER TABLE {$rules} ADD remove_external_links TINYINT(1) NOT NULL DEFAULT 0");
        self::maybe_add_column($rules, 'remove_paragraph_keywords', "ALTER TABLE {$rules} ADD remove_paragraph_keywords TEXT NULL");
        self::maybe_add_column($rules, 'image_alt_template', "ALTER TABLE {$rules} ADD image_alt_template VARCHAR(255) NULL");
        self::maybe_add_column($rules, 'ai_rewrite', "ALTER TABLE {$rules} ADD ai_rewrite TINYINT(1) NOT NULL DEFAULT 0");
        self::maybe_add_column($rules, 'ai_rewrite_prompt', "ALTER TABLE {$rules} ADD ai_rewrite_prompt LONGTEXT NULL");
        self::maybe_add_column($rules, 'ai_rewrite_on_failure', "ALTER TABLE {$rules} ADD ai_rewrite_on_failure VARCHAR(20) NOT NULL DEFAULT 'fallback'");
    }

    public static function maybe_add_column($table, $column, $sql)
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        if (!$exists) {
            $wpdb->query($sql);
        }
    }

    public static function maybe_add_index($table, $index, $sql)
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index));
        if (!$exists) {
            $wpdb->query($sql);
        }
    }


    public static function default_settings()
    {
        return array(
            'discover_interval' => 'wp_caiji_30min',
            'collect_interval' => 'wp_caiji_10min',
            'global_collect_limit' => 10,
            'max_runtime_seconds' => 45,
            'running_timeout_minutes' => 30,
            'max_rules_per_discover' => 20,
            'log_retention' => 2000,
            'enable_logs' => 1,
            'lock_ttl_seconds' => 600,
            'max_images_per_post' => 10,
            'max_image_size_mb' => 5,
            'ai_enabled' => 0,
            'ai_api_key' => '',
            'ai_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'ai_model' => 'gpt-5.5',
            'ai_temperature' => 0.7,
            'ai_timeout_seconds' => 45,
            'ai_max_input_chars' => 12000,
            'ai_rewrite_prompt' => WP_Caiji_AI::default_prompt(),
            'github_update_enabled' => 1,
            'github_repo' => '921988379/AI-caiji',
            'github_token' => '',
            'github_package_url' => '',
            'delete_data_on_uninstall' => 0,
        );
    }


    public static function default_rule()
    {
        return array('name'=>'','enabled'=>1,'list_urls'=>'','link_selector'=>'','link_before_marker'=>'','link_after_marker'=>'','json_source'=>'__NEXT_DATA__','link_json_path'=>'','link_json_url_field'=>'','pagination_pattern'=>'','page_start'=>1,'page_end'=>1,'manual_urls'=>'','title_selector'=>'//h1','title_before_marker'=>'','title_after_marker'=>'','title_json_path'=>'','content_selector'=>'//article','content_before_marker'=>'','content_after_marker'=>'','content_json_path'=>'','date_selector'=>'','date_before_marker'=>'','date_after_marker'=>'','date_json_path'=>'','remove_selectors'=>'','category_id'=>0,'author_id'=>0,'post_status'=>'draft','batch_limit'=>5,'retry_limit'=>3,'request_delay'=>1,'download_images'=>0,'set_featured_image'=>0,'dedupe_title'=>1,'fixed_tags'=>'','replace_rules'=>'','category_rules'=>'','auto_tags'=>0,'auto_tag_keywords'=>'','publish_mode'=>'immediate','publish_delay_min'=>0,'publish_delay_max'=>0,'ua_list'=>'','referer'=>'','cookie'=>'','auto_excerpt'=>1,'excerpt_length'=>160,'seo_plugin'=>'none','seo_title_template'=>'','seo_desc_template'=>'','remove_empty_paragraphs'=>1,'remove_external_links'=>0,'remove_paragraph_keywords'=>'','image_alt_template'=>'','ai_rewrite'=>0,'ai_rewrite_prompt'=>'','ai_rewrite_on_failure'=>'fallback');
    }


}
