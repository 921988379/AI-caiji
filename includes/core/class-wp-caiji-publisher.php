<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post publishing helper for WP Caiji.
 */
class WP_Caiji_Publisher
{
    public static function publish($item, $title, $content, $date, $source_url, $featured_id = 0)
    {
        $post_status = $item['post_status'];
        $post_date = null;

        if (($item['publish_mode'] ?? '') === 'random_future') {
            $post_status = 'future';
            $min = max(0, (int)($item['publish_delay_min'] ?? 0));
            $max = max($min, (int)($item['publish_delay_max'] ?? $min));
            $delay = $max > $min ? wp_rand($min, $max) : $min;
            $post_date = date('Y-m-d H:i:s', current_time('timestamp') + ($delay * 60));
        }

        $excerpt = WP_Caiji_Content::make_excerpt($content, (int)($item['excerpt_length'] ?? 160));
        $plain_context = $title . "\n" . wp_strip_all_tags($content);
        $postarr = array(
            'post_title' => wp_strip_all_tags($title),
            'post_content' => wp_kses_post($content),
            'post_type' => 'post',
        );

        if (!empty($item['auto_excerpt'])) $postarr['post_excerpt'] = $excerpt;

        $matched_category = WP_Caiji_Content::match_category_id($plain_context, $item['category_rules'] ?? '');
        if ($matched_category) $postarr['post_category'] = array($matched_category);
        elseif (!empty($item['category_id'])) $postarr['post_category'] = array((int)$item['category_id']);

        if (!empty($item['author_id'])) $postarr['post_author'] = (int)$item['author_id'];

        $tags = !empty($item['fixed_tags']) ? WP_Caiji_Content::parse_tags($item['fixed_tags']) : array();
        if (!empty($item['auto_tags'])) {
            $tags = array_merge($tags, WP_Caiji_Content::match_auto_tags($plain_context, $item['auto_tag_keywords'] ?? ''));
        }
        if ($tags) $postarr['tags_input'] = array_values(array_unique($tags));

        $valid_statuses = array('draft', 'publish', 'future', 'pending', 'private');
        if (!in_array($post_status, $valid_statuses, true)) $post_status = 'draft';
        if ($post_status === 'future' && !$post_date) $post_status = 'draft';
        $postarr['post_status'] = $post_status;

        if ($post_date) $postarr['post_date'] = $post_date;
        elseif ($date) {
            $date_string = trim((string)$date);
            if (preg_match('/^\d{13}$/', $date_string)) {
                $postarr['post_date'] = date('Y-m-d H:i:s', ((int)$date_string) / 1000);
            } elseif (preg_match('/^\d{10}$/', $date_string)) {
                $postarr['post_date'] = date('Y-m-d H:i:s', (int)$date_string);
            } elseif ($ts = strtotime($date_string)) {
                $postarr['post_date'] = date('Y-m-d H:i:s', $ts);
            }
        }

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, WP_Caiji::META_SOURCE_URL, esc_url_raw($source_url));
        WP_Caiji_Content::write_seo_meta($post_id, $item, $title, $excerpt, $source_url);
        if ($featured_id) set_post_thumbnail($post_id, $featured_id);

        return (int)$post_id;
    }
}
