<?php
/**
 * Plugin Name: WP 采集助手
 * Description: 长期自动化文章采集插件：规则、列表页发现、URL 队列、失败重试、日志、定时采集。
 * Version: 2.1.1
 * Author: 一点优化
 * Author URI: https://www.seoyh.net/
 * Text Domain: wp-caiji
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_CAIJI_VERSION', '2.1.1');
define('WP_CAIJI_FILE', __FILE__);
define('WP_CAIJI_DIR', plugin_dir_path(__FILE__));
define('WP_CAIJI_URL', plugin_dir_url(__FILE__));

require_once WP_CAIJI_DIR . 'includes/core/class-wp-caiji-loader.php';
WP_Caiji_Loader::load();
require_once WP_CAIJI_DIR . 'includes/class-wp-caiji.php';

register_activation_hook(__FILE__, array('WP_Caiji', 'activate'));
register_deactivation_hook(__FILE__, array('WP_Caiji', 'deactivate'));

add_action('plugins_loaded', function () {
    WP_Caiji::instance();
});
