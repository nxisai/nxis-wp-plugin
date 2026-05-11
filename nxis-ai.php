<?php
/**
 * Plugin Name: Nxis AI
 * Plugin URI: https://nxis.ai
 * Description: Dynamically inject JSON-LD structured data into your WordPress site to enhance visibility for search engines, AI agents, and large language models (LLMs).
 * Version: 1.0.1
 * Author: Nxis AI
 * Author URI: https://nxis.ai
 * License: GPL-2.0+
 * Text Domain: nxis-ai
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Nxis_AI
{

    /**
     * Plugin version
     *
     * @var string
     */
    private $version = '1.0.0';

    /**
     * API Host
     *
     * @var string
     */
    private $api_host = 'https://api.nxis.ai';

    /**
     * Cache group
     */
    private $cache_group = 'nxis_ai_ssr';

    /**
     * Initialize the plugin
     */
    public function __construct()
    {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_init', array($this, 'handle_disconnect'));
        add_action('admin_notices', array($this, 'display_admin_notices'));

        // Frontend hooks
        add_action('wp_head', array($this, 'inject_structured_data'), 5); // Run early in wp_head
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'nxis-ai') {
            if (isset($_GET['nxis_connected'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Successfully connected to Nxis AI!</p></div>';
            }
            if (isset($_GET['nxis_disconnected'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Disconnected from Nxis AI.</p></div>';
            }
            if (isset($_GET['nxis_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>Failed to connect to Nxis AI. Please try again.</p></div>';
            }
        }
    }

    /**
     * Handle OAuth Callback
     */
    public function handle_oauth_callback()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'nxis-ai' && isset($_GET['code'])) {
            $code = sanitize_text_field($_GET['code']);
            
            // Mock exchange for testing
            if ($code === 'test_123') {
                $options = get_option('nxis_ai_settings', array());
                $options['client_id'] = 'mock_client_id';
                $options['client_secret'] = 'mock_client_secret';
                $options['site_id'] = 'mock_site_id';
                update_option('nxis_ai_settings', $options);
                wp_redirect(admin_url('options-general.php?page=nxis-ai&nxis_connected=1'));
                exit;
            }

            $url = $this->api_host . '/v1/oauth/exchange';
            $args = array(
                'body' => array(
                    'code' => $code,
                ),
                'timeout' => 15
            );
            $response = wp_remote_post($url, $args);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 200) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if (isset($data['client_id'], $data['client_secret'], $data['site_id'])) {
                        $options = get_option('nxis_ai_settings', array());
                        $options['client_id'] = sanitize_text_field($data['client_id']);
                        $options['client_secret'] = sanitize_text_field($data['client_secret']);
                        $options['site_id'] = sanitize_text_field($data['site_id']);
                        update_option('nxis_ai_settings', $options);
                        wp_redirect(admin_url('options-general.php?page=nxis-ai&nxis_connected=1'));
                        exit;
                    }
                }
            }
            
            wp_redirect(admin_url('options-general.php?page=nxis-ai&nxis_error=1'));
            exit;
        }
    }

    /**
     * Handle Disconnect
     */
    public function handle_disconnect()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'nxis-ai' && isset($_GET['nxis_disconnect']) && isset($_GET['_wpnonce'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'nxis_disconnect_action')) {
                $options = get_option('nxis_ai_settings', array());
                unset($options['client_id']);
                unset($options['client_secret']);
                unset($options['site_id']);
                update_option('nxis_ai_settings', $options);
                
                // clear cache
                global $wpdb;
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nxis_ssr_%'");
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_nxis_ssr_%'");
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nxis_oauth_token_%'");
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_nxis_oauth_token_%'");
                
                wp_redirect(admin_url('options-general.php?page=nxis-ai&nxis_disconnected=1'));
                exit;
            }
        }
    }

    /**
     * Add settings page
     */
    public function add_settings_page()
    {
        add_options_page(
            'Nxis AI Settings',
            'Nxis AI',
            'manage_options',
            'nxis-ai',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings fields
     */
    public function register_settings()
    {
        register_setting('nxis_ai_options_group', 'nxis_ai_settings', array($this, 'sanitize_settings'));

        add_settings_section(
            'nxis_ai_main_section',
            'General Configuration',
            array($this, 'render_main_section'),
            'nxis-ai'
        );

        $options = get_option('nxis_ai_settings');
        $is_connected = !empty($options['client_id']) && !empty($options['client_secret']) && !empty($options['site_id']);
        $show_legacy = (defined('WP_DEBUG') && WP_DEBUG) || isset($_GET['nxis_legacy']);

        if ($show_legacy || !$is_connected) {
            add_settings_field(
                'nxis_client_id',
                'Client ID',
                array($this, 'render_client_id_field'),
                'nxis-ai',
                'nxis_ai_main_section'
            );

            add_settings_field(
                'nxis_client_secret',
                'Client Secret',
                array($this, 'render_client_secret_field'),
                'nxis-ai',
                'nxis_ai_main_section'
            );

            add_settings_field(
                'nxis_site_id',
                'Site ID',
                array($this, 'render_site_id_field'),
                'nxis-ai',
                'nxis_ai_main_section'
            );
        }



        add_settings_field(
            'nxis_ssr_cache',
            'SSR Cache (Hours)',
            array($this, 'render_cache_field'),
            'nxis-ai',
            'nxis_ai_main_section'
        );

        add_settings_field(
            'nxis_verification_meta',
            'Verification Meta Tag',
            array($this, 'render_verification_meta_field'),
            'nxis-ai',
            'nxis_ai_main_section'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input)
    {
        $sanitized = array();



        if (isset($input['client_id'])) {
            $sanitized['client_id'] = sanitize_text_field(trim($input['client_id']));
        }

        if (isset($input['client_secret'])) {
            $sanitized['client_secret'] = sanitize_text_field(trim($input['client_secret']));
        }

        if (isset($input['site_id'])) {
            $sanitized['site_id'] = sanitize_text_field(trim($input['site_id']));
        }

        if (isset($input['verification_meta'])) {
            // Allow only <meta> tags with specific attributes
            $allowed_html = array(
                'meta' => array(
                    'name' => true,
                    'content' => true,
                    'charset' => true,
                    'property' => true,
                )
            );
            $sanitized['verification_meta'] = wp_kses($input['verification_meta'], $allowed_html);
        }



        if (isset($input['cache_hours'])) {
            $cache = (int) $input['cache_hours'];
            $sanitized['cache_hours'] = ($cache > 0 && $cache <= 168) ? $cache : 24; // max 1 week
        }

        // Clear transient cache when settings are saved
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nxis_ssr_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_nxis_ssr_%'");

        return $sanitized;
    }

    /**
     * Settings section description
     */
    public function render_main_section()
    {
        $options = get_option('nxis_ai_settings');
        $is_connected = !empty($options['client_id']) && !empty($options['client_secret']) && !empty($options['site_id']);
        $show_legacy = (defined('WP_DEBUG') && WP_DEBUG) || isset($_GET['nxis_legacy']);

        echo '<p>Configure your Nxis AI integration to automatically enhance your site for search engines and AI agents.</p>';

        echo '<div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-left: 4px solid #6366f1;">';
        if ($is_connected) {
            echo '<h3><span style="color: #46b450;">✔</span> Connected to Nxis AI</h3>';
            echo '<p><strong>Site ID:</strong> ' . esc_html($options['site_id']) . '</p>';
            $disconnect_url = wp_nonce_url(admin_url('options-general.php?page=nxis-ai&nxis_disconnect=1'), 'nxis_disconnect_action');
            echo '<a href="' . esc_url($disconnect_url) . '" class="button button-secondary" onclick="return confirm(\'Are you sure you want to disconnect? This will stop SSR data fetching.\');">Disconnect</a>';
            if ($show_legacy) {
                echo '<p class="description" style="margin-top:20px;"><em>Legacy inputs are still visible below because WP_DEBUG is true or nxis_legacy=1 is in the URL.</em></p>';
            }
        } else {
            echo '<h3>Not Connected</h3>';
            echo '<p>Connect your WordPress site to your Nxis App to automatically sync structured data.</p>';
            $return_url = urlencode(admin_url('options-general.php?page=nxis-ai'));
            $connect_url = "http://localhost:3000/callback/wordpress?return_url={$return_url}"; // using localhost for testing, change to app.nxis.io in production
            echo '<a href="' . esc_url($connect_url) . '" class="button button-primary button-large" style="background: #6366f1; border-color: #4f46e5;">Connect to Nxis</a>';
            if (!$show_legacy) {
                echo '<p class="description" style="margin-top:10px;"><a href="' . esc_url(admin_url('options-general.php?page=nxis-ai&nxis_legacy=1')) . '">Enter credentials manually</a></p>';
            }
        }
        echo '</div>';

        if (!$show_legacy && !$is_connected) {
            echo '<style>
                tr:has(input[name="nxis_ai_settings[client_id]"]),
                tr:has(input[name="nxis_ai_settings[client_secret]"]),
                tr:has(input[name="nxis_ai_settings[site_id]"]) {
                    display: none;
                }
            </style>';
        }
    }



    /**
     * Render Client ID Field
     */
    public function render_client_id_field()
    {
        $options = get_option('nxis_ai_settings');
        $client_id = isset($options['client_id']) ? $options['client_id'] : '';
        echo "<input type='text' name='nxis_ai_settings[client_id]' value='" . esc_attr($client_id) . "' class='regular-text' style='width: 100%;' />";
        echo "<p class='description'>Used for <strong>Server-side Rendering</strong>.</p>";
    }

    /**
     * Render Client Secret Field
     */
    public function render_client_secret_field()
    {
        $options = get_option('nxis_ai_settings');
        $client_secret = isset($options['client_secret']) ? $options['client_secret'] : '';
        echo "<input type='password' name='nxis_ai_settings[client_secret]' value='" . esc_attr($client_secret) . "' class='regular-text' style='width: 100%;' />";
        echo "<p class='description'>Used for <strong>Server-side Rendering</strong>. Keep this secret.</p>";
    }

    /**
     * Render Site ID Field
     */
    public function render_site_id_field()
    {
        $options = get_option('nxis_ai_settings');
        $site_id = isset($options['site_id']) ? $options['site_id'] : '';
        echo "<input type='text' name='nxis_ai_settings[site_id]' value='" . esc_attr($site_id) . "' class='regular-text' style='width: 100%;' />";
        echo "<p class='description'>Unique ID for this site in the Nxis AI platform. Required for SSR.</p>";
    }



    /**
     * Render Cache Field
     */
    public function render_cache_field()
    {
        $options = get_option('nxis_ai_settings');
        $cache = isset($options['cache_hours']) ? $options['cache_hours'] : 24;
        echo "<input type='number' name='nxis_ai_settings[cache_hours]' value='" . esc_attr($cache) . "' min='1' max='168' />";
        echo "<p class='description'>Only applies to Server-side Rendering. Caching prevents rate-limits and speeds up page load times.</p>";
    }

    /**
     * Render Verification Meta Field
     */
    public function render_verification_meta_field()
    {
        $options = get_option('nxis_ai_settings');
        $meta = isset($options['verification_meta']) ? $options['verification_meta'] : '';
        echo "<textarea name='nxis_ai_settings[verification_meta]' class='regular-text' style='width: 100%; height: 60px;'>" . esc_textarea($meta) . "</textarea>";
        echo "<p class='description'>Paste your site verification meta tag here (e.g., <code>&lt;meta name=\"nxis-verification\" content=\"...\" /&gt;</code>).</p>";
    }

    /**
     * Render Admin Page
     */
    public function render_settings_page()
    {
        ?>
        <div class="wrap">
            <h1>Nxis AI Integration</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('nxis_ai_options_group');
                do_settings_sections('nxis-ai');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Inject frontend code
     */
    public function inject_structured_data()
    {
        $options = get_option('nxis_ai_settings');

        // Inject verification meta tag if present
        if (!empty($options['verification_meta'])) {
            echo "\n<!-- Nxis AI Verification -->\n";
            echo $options['verification_meta'] . "\n";
        }

        // Always use SSR injection
        if (!empty($options['client_id']) && !empty($options['client_secret']) && !empty($options['site_id'])) {
            $this->inject_server_side($options);
        }
    }



    /**
     * Output SSR loaded JSON-LD
     */
    private function inject_server_side($options)
    {
        $uri = $this->get_current_url();
        $cache_hours = isset($options['cache_hours']) ? intval($options['cache_hours']) : 24;
        $cache_key = 'nxis_ssr_' . md5($uri);

        $json_ld_data = get_transient($cache_key);

        if (false === $json_ld_data) {
            $json_ld_data = $this->fetch_ssr_data($options, $uri);
            if ($json_ld_data !== false) {
                set_transient($cache_key, $json_ld_data, $cache_hours * HOUR_IN_SECONDS);
            }
        }

        if ($json_ld_data && is_array($json_ld_data)) {
            echo "\n<!-- Nxis.ai -->\n";
            echo "<script type=\"application/ld+json\">\n";
            echo wp_json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "\n</script>\n";
            echo "<!-- End Nxis.ai -->\n";
        }
    }

    /**
     * Get OAuth Access Token
     */
    private function get_access_token($options)
    {
        $cache_key = 'nxis_oauth_token_' . md5($options['client_id'] . $options['client_secret']);
        $token = get_transient($cache_key);

        if ($token) {
            return $token;
        }

        $url = 'https://authorization.nxis.ai/v1/oauth/token';
        $args = array(
            'body' => array(
                'grant_type' => 'client_credentials',
                'client_id' => $options['client_id'],
                'client_secret' => $options['client_secret'],
                'scope' => 'jsonld:read'
            ),
            'timeout' => 10
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $this->log('OAuth Token Request Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $this->log("OAuth Token Request Failed. Code: $code. Body: $body");
            return false;
        }

        $data = json_decode($body, true);

        if (isset($data['access_token'])) {
            $expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 3600;
            // Cache with a 60 second buffer
            set_transient($cache_key, $data['access_token'], $expires_in - 60);
            return $data['access_token'];
        }

        $this->log('OAuth Token Missing in response: ' . $body);
        return false;
    }

    /**
     * Fetch structured data via API
     */
    private function fetch_ssr_data($options, $uri)
    {
        $token = $this->get_access_token($options);
        if (!$token) {
            return false;
        }

        $url = $this->api_host . '/v1/nxis:ssr?uri=' . urlencode($uri) . '&site_id=' . urlencode($options['site_id']);

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'text/plain'
            ),
            'timeout' => 5
        );

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $args['headers']['User-Agent'] = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $this->log('SSR Fetch Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $this->log("SSR Fetch Failed. Code: $code. URI: $uri");
            return false;
        }

        $data = json_decode($body, true);

        if (isset($data['data']) && is_array($data['data']) && !empty($data['data'])) {
            return $data['data'];
        }

        $this->log("No SSR data returned for URI: $uri");
        return false;
    }

    /**
     * Helper to get full current URL
     */
    private function get_current_url()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        return $protocol . $host . $request_uri;
    }

    /**
     * Log helper
     */
    private function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Nxis AI] ' . $message);
        }
    }
}

// Initialize the plugin
new Nxis_AI();
