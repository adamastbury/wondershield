<?php
/**
 * WonderShield GitHub Updater
 *
 * Checks the public GitHub repo for new releases and serves updates
 * through the standard WordPress update mechanism.
 */
if (!defined('ABSPATH')) exit;

class WonderShield_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $plugin_basename;
    private $repo = 'adamastbury/wondershield';
    private $version;
    private $github_response;

    const CACHE_KEY = 'wondershield_github_release';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public function __construct($plugin_file) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname(plugin_basename($plugin_file));
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->version         = WS_VERSION;
    }

    public function init() {
        // Fires when WP does a fresh update check (saves new transient)
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        // Fires on every read of the cached transient — ensures update shows immediately
        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
    }

    private function fetch_github_release() {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        // Use our own persistent cache to avoid hitting GitHub on every page load
        $cached = get_site_transient(self::CACHE_KEY);
        if ($cached !== false) {
            $this->github_response = $cached;
            return $cached;
        }

        $url  = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $args = [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WonderShield/' . $this->version,
            ],
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->github_response = false;
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (empty($body->tag_name)) {
            $this->github_response = false;
            return false;
        }

        set_site_transient(self::CACHE_KEY, $body, self::CACHE_TTL);
        $this->github_response = $body;
        return $body;
    }

    private function get_remote_version() {
        $release = $this->fetch_github_release();
        if (!$release) return false;
        return ltrim($release->tag_name, 'v');
    }

    private function get_download_url() {
        $release = $this->fetch_github_release();
        if (!$release) return '';

        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        return $release->zipball_url;
    }

    /**
     * Hook into WordPress update check.
     * Hooked to both pre_set and get transient filters so it works with cached reads too.
     */
    public function check_update($transient) {
        if (empty($transient->checked)) return $transient;

        $remote_version = $this->get_remote_version();
        if (!$remote_version) return $transient;

        $item = (object) [
            'id'           => $this->plugin_basename,
            'slug'         => $this->plugin_slug,
            'plugin'       => $this->plugin_basename,
            'new_version'  => $remote_version,
            'url'          => "https://github.com/{$this->repo}",
            'package'      => $this->get_download_url(),
            'icons'        => [],
            'banners'      => [],
            'requires'     => '5.6',
            'tested'       => '6.7',
            'requires_php' => '7.4',
        ];

        if (version_compare($remote_version, $this->version, '>')) {
            $transient->response[$this->plugin_basename] = $item;
            unset($transient->no_update[$this->plugin_basename]);
        } else {
            $transient->no_update[$this->plugin_basename] = $item;
            unset($transient->response[$this->plugin_basename]);
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View Details" popup.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) return $result;

        $release = $this->fetch_github_release();
        if (!$release) return $result;

        $remote_version = ltrim($release->tag_name, 'v');

        return (object) [
            'name'          => 'WonderShield',
            'slug'          => $this->plugin_slug,
            'version'       => $remote_version,
            'author'        => '<a href="https://wondermedia.co.uk">Wonder Media Ltd</a>',
            'homepage'      => 'https://wondermedia.co.uk',
            'requires'      => '5.6',
            'tested'        => '6.7',
            'requires_php'  => '7.4',
            'download_link' => $this->get_download_url(),
            'sections'      => [
                'description' => 'Security hardening and brute force protection by Wonder Media.',
                'changelog'   => nl2br(esc_html($release->body ?? 'No changelog provided.')),
            ],
        ];
    }

    /**
     * After install, rename the extracted folder to match the plugin slug.
     * GitHub's zipball extracts to "user-repo-hash/" which won't match.
     */
    public function post_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        global $wp_filesystem;

        $install_dir = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_dir);
        $result['destination'] = $install_dir;

        activate_plugin($this->plugin_basename);

        return $result;
    }
}
