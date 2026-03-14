<?php
/**
 * WonderShield GitHub Updater
 *
 * Checks GitHub releases for plugin updates and serves them
 * through the standard WordPress update mechanism.
 *
 * Requires WONDERSHIELD_GITHUB_TOKEN to be defined in wp-config.php
 * for private repos.
 */
if (!defined('ABSPATH')) exit;

class WonderShield_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $plugin_basename;
    private $repo = 'adamastbury/wondershield';
    private $version;
    private $github_response;

    public function __construct($plugin_file) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname(plugin_basename($plugin_file));
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->version         = WS_VERSION;
    }

    public function init() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
    }

    private function get_token() {
        return defined('WONDERSHIELD_GITHUB_TOKEN') ? WONDERSHIELD_GITHUB_TOKEN : '';
    }

    private function fetch_github_release() {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        $url  = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $args = [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WonderShield/' . $this->version,
            ],
        ];

        $token = $this->get_token();
        if ($token) {
            $args['headers']['Authorization'] = 'token ' . $token;
        }

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

        // Prefer a .zip asset attached to the release
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    $url = $asset->url;
                    $token = $this->get_token();
                    if ($token) {
                        $url = add_query_arg('access_token', $token, $asset->browser_download_url);
                    }
                    return $asset->browser_download_url;
                }
            }
        }

        // Fall back to the source zip
        $url = $release->zipball_url;
        $token = $this->get_token();
        if ($token) {
            $url = add_query_arg('access_token', $token, $url);
        }
        return $url;
    }

    /**
     * Hook into WordPress update check.
     */
    public function check_update($transient) {
        if (empty($transient->checked)) return $transient;

        $remote_version = $this->get_remote_version();
        if (!$remote_version) return $transient;

        if (version_compare($remote_version, $this->version, '>')) {
            $transient->response[$this->plugin_basename] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote_version,
                'url'         => "https://github.com/{$this->repo}",
                'package'     => $this->get_download_url(),
            ];
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
                'description'  => 'Security hardening and brute force protection by Wonder Media.',
                'changelog'    => nl2br(esc_html($release->body ?? 'No changelog provided.')),
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
