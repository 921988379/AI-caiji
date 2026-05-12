<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight module loader for WP Caiji.
 *
 * The historical plugin logic currently lives in includes/class-wp-caiji.php.
 * New code should be placed in focused classes and loaded here, then gradually
 * wired into WP_Caiji to avoid risky big-bang refactors.
 */
class WP_Caiji_Loader
{
    public static function load()
    {
        $files = array(
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-utils.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-schema.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-db.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-health.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-parser.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-fetcher.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-media.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-ai.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-updater.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-content.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-queue.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-logger.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-publisher.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-discovery.php',
            WP_CAIJI_DIR . 'includes/core/class-wp-caiji-collector.php',
        );

        foreach ($files as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}
