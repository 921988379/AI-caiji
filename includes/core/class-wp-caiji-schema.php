<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central place for table names and future schema metadata.
 */
class WP_Caiji_Schema
{
    public static function tables()
    {
        global $wpdb;
        return array(
            'rules' => $wpdb->prefix . 'caiji_rules',
            'queue' => $wpdb->prefix . 'caiji_queue',
            'logs'  => $wpdb->prefix . 'caiji_logs',
        );
    }

    public static function rule_export_fields()
    {
        return array(
            'name','enabled','list_urls','link_selector','link_before_marker','link_after_marker','pagination_pattern','page_start','page_end','manual_urls',
            'title_selector','title_before_marker','title_after_marker','content_selector','content_before_marker','content_after_marker','date_selector','date_before_marker','date_after_marker','remove_selectors','category_id','author_id','post_status',
            'batch_limit','retry_limit','request_delay','download_images','set_featured_image','dedupe_title','fixed_tags',
            'replace_rules','category_rules','auto_tags','auto_tag_keywords','publish_mode','publish_delay_min','publish_delay_max',
            'ua_list','referer','cookie','auto_excerpt','excerpt_length','seo_plugin','seo_title_template','seo_desc_template',
            'remove_empty_paragraphs','remove_external_links','remove_paragraph_keywords','image_alt_template',
            'ai_rewrite','ai_rewrite_prompt','ai_rewrite_on_failure'
        );
    }
}
