<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub release based updater for WP Caiji.
 *
 * Recommended release package:
 * - Upload an asset named wp-caiji.zip to each GitHub release.
 * - The zip should contain the plugin folder as the root directory: wp-caiji/wp-caiji.php
 */
class WP_Caiji_Updater
{
    const CACHE_KEY = 'wp_caiji_github_release_cache';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    private static function plugin_basename()
    {
        return plugin_basename(WP_CAIJI_FILE);
    }

    private static function slug()
    {
        return dirname(self::plugin_basename());
    }

    public static function init($settings)
    {
        $settings = (array)$settings;
        if (empty($settings['github_update_enabled']) || empty($settings['github_repo'])) {
            return;
        }
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugins_api'), 20, 3);
        add_action('upgrader_process_complete', array(__CLASS__, 'clear_cache_after_upgrade'), 10, 2);
    }

    public static function normalize_repo($repo)
    {
        $repo = trim((string)$repo);
        if ($repo === '') return '';
        $repo = preg_replace('#^https?://github\.com/#i', '', $repo);
        $repo = preg_replace('#\.git$#i', '', $repo);
        $repo = trim($repo, "/ \t\n\r\0\x0B");
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) return '';
        return $repo;
    }

    public static function clear_cache()
    {
        delete_site_transient(self::CACHE_KEY);
    }

    public static function clear_cache_after_upgrade($upgrader, $hook_extra)
    {
        if (!empty($hook_extra['type']) && $hook_extra['type'] === 'plugin') {
            self::clear_cache();
        }
    }

    public static function check_for_update($transient)
    {
        if (!is_object($transient)) return $transient;
        if (empty($transient->checked)) return $transient;

        $release = self::get_latest_release();
        if (empty($release['version']) || version_compare($release['version'], WP_CAIJI_VERSION, '<=')) {
            return $transient;
        }

        $package = self::get_package_url($release);
        if (!$package) return $transient;

        $transient->response[self::plugin_basename()] = (object)array(
            'id' => self::plugin_basename(),
            'slug' => self::slug(),
            'plugin' => self::plugin_basename(),
            'new_version' => $release['version'],
            'url' => $release['html_url'] ?? self::repo_url(),
            'package' => $package,
            'tested' => $release['tested'] ?? '',
            'requires_php' => $release['requires_php'] ?? '',
            'icons' => array(),
            'banners' => array(),
        );
        return $transient;
    }

    public static function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::slug()) {
            return $result;
        }
        $release = self::get_latest_release();
        if (!$release) return $result;

        $sections = array(
            'description' => 'WP 采集助手：规则采集、队列、定时任务、AI 改写与 GitHub 自动更新。',
            'changelog' => !empty($release['body']) ? wp_kses_post(nl2br($release['body'])) : '暂无更新说明。',
        );

        return (object)array(
            'name' => 'WP 采集助手',
            'slug' => self::slug(),
            'version' => $release['version'] ?? WP_CAIJI_VERSION,
            'author' => '<a href="https://www.seoyh.net/">一点优化</a>',
            'homepage' => self::repo_url(),
            'download_link' => self::get_package_url($release),
            'requires' => '',
            'tested' => $release['tested'] ?? '',
            'requires_php' => $release['requires_php'] ?? '',
            'last_updated' => $release['published_at'] ?? '',
            'sections' => $sections,
        );
    }

    public static function get_latest_release($force = false)
    {
        $settings = self::settings();
        $repo = self::normalize_repo($settings['github_repo'] ?? '');
        if ($repo === '') return false;

        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached['repo']) && $cached['repo'] === $repo) return $cached;
        }

        $endpoint = 'https://api.github.com/repos/' . rawurlencode(dirname($repo)) . '/' . rawurlencode(basename($repo)) . '/releases/latest';
        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WP-Caiji-Updater/' . WP_CAIJI_VERSION,
            ),
        );
        if (!empty($settings['github_token'])) {
            $args['headers']['Authorization'] = 'Bearer ' . trim((string)$settings['github_token']);
        }

        $response = wp_remote_get($endpoint, $args);
        if (is_wp_error($response)) return false;
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) return false;

        $version = self::version_from_release($data);
        if ($version === '') return false;

        $release = array(
            'repo' => $repo,
            'version' => $version,
            'tag_name' => (string)($data['tag_name'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'body' => (string)($data['body'] ?? ''),
            'html_url' => (string)($data['html_url'] ?? self::repo_url()),
            'zipball_url' => (string)($data['zipball_url'] ?? ''),
            'published_at' => (string)($data['published_at'] ?? ''),
            'assets' => isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array(),
        );
        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private static function version_from_release($data)
    {
        $tag = (string)($data['tag_name'] ?? '');
        $name = (string)($data['name'] ?? '');
        foreach (array($tag, $name) as $value) {
            if (preg_match('/v?([0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9_.-]+)?)/', $value, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    private static function get_package_url($release)
    {
        $settings = self::settings();
        if (!empty($settings['github_package_url'])) {
            return esc_url_raw($settings['github_package_url']);
        }
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();
        foreach ($assets as $asset) {
            $name = strtolower((string)($asset['name'] ?? ''));
            if ($name === 'wp-caiji.zip' && !empty($asset['browser_download_url'])) {
                return esc_url_raw($asset['browser_download_url']);
            }
        }
        foreach ($assets as $asset) {
            $name = strtolower((string)($asset['name'] ?? ''));
            if (substr($name, -4) === '.zip' && !empty($asset['browser_download_url'])) {
                return esc_url_raw($asset['browser_download_url']);
            }
        }
        return !empty($release['zipball_url']) ? esc_url_raw($release['zipball_url']) : '';
    }

    private static function repo_url()
    {
        $settings = self::settings();
        $repo = self::normalize_repo($settings['github_repo'] ?? '');
        return $repo ? 'https://github.com/' . $repo : 'https://github.com/';
    }

    private static function settings()
    {
        if (class_exists('WP_Caiji_DB')) {
            return wp_parse_args((array)get_option(WP_Caiji::OPTION_SETTINGS, array()), WP_Caiji_DB::default_settings());
        }
        return (array)get_option(WP_Caiji::OPTION_SETTINGS, array());
    }
}
