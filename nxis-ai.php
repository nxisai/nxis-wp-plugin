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
        add_action('wp_ajax_nxis_test_connection', array($this, 'ajax_test_connection'));
        
        // Pages table hooks
        add_filter('manage_pages_columns', array($this, 'add_nxis_columns'));
        add_action('manage_pages_custom_column', array($this, 'render_nxis_column'), 10, 2);
        add_action('admin_footer-edit.php', array($this, 'add_pages_table_script'));

        // Frontend hooks
        add_action('wp_head', array($this, 'inject_structured_data'), 5); // Run early in wp_head
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
        echo '<p>Configure your Nxis AI integration to automatically enhance your site for search engines and AI agents.</p>';
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
     * Add Nxis AI column to the Pages table
     */
    public function add_nxis_columns($columns)
    {
        $columns['nxis_ssr'] = 'Nxis SSR';
        return $columns;
    }

    /**
     * Render the Nxis AI column in the Pages table
     */
    public function render_nxis_column($column, $post_id)
    {
        if ($column === 'nxis_ssr') {
            // Ensure the URI uses https for the API and cache key
            $raw_uri = get_permalink($post_id);
            if (!preg_match('~^https?://~i', $raw_uri)) {
                if (strpos($raw_uri, '/') !== 0) {
                    $raw_uri = '/' . $raw_uri;
                }
                $raw_uri = home_url($raw_uri);
            }
            $uri = set_url_scheme($raw_uri, 'https');
            $cache_key = 'nxis_ssr_' . md5($uri);
            $is_cached = get_transient($cache_key) !== false;

            if ($is_cached) {
                echo '<span style="display: inline-block; padding: 3px 8px; background: #d4edda; color: #155724; border-radius: 3px; font-size: 11px; margin-bottom: 5px; font-weight: 500;">Cached</span><br>';
            } else {
                echo '<span style="display: inline-block; padding: 3px 8px; background: #e2e3e5; color: #383d41; border-radius: 3px; font-size: 11px; margin-bottom: 5px; font-weight: 500;">Not Cached</span><br>';
            }

            echo '<button type="button" class="button button-small nxis-test-btn" data-uri="' . esc_attr($uri) . '">Test API</button>';
        }
    }

    /**
     * Add inline script for the Pages table Test API button
     */
    public function add_pages_table_script()
    {
        global $typenow;
        if ($typenow !== 'page') {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.nxis-test-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var uri = btn.data('uri');
                var originalText = btn.text();

                btn.text('Testing...').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'nxis_test_connection',
                    nonce: '<?php echo wp_create_nonce("nxis_test_nonce"); ?>',
                    uri: uri
                }, function(response) {
                    btn.text(originalText).prop('disabled', false);
                    if (response.success) {
                        console.log('Nxis SSR Response:', response.data);
                        var reqStr = response.data.request ? "Endpoint: " + response.data.request.endpoint + "\n\n" : "";
                        var resStr = JSON.stringify(response.data.data, null, 2).substring(0, 300) + '...';
                        alert("Success! Data fetched.\n\n--- Request ---\n" + reqStr + "--- Response ---\n" + resStr + "\n\n(See browser console for full JSON)");
                    } else {
                        console.error('Nxis SSR Error:', response.data);
                        var reqStr = response.data.request ? "Endpoint: " + response.data.request.endpoint + "\n\n" : "";
                        var errorMsg = response.data.error || response.data;
                        alert("Error: " + errorMsg + "\n\n--- Request ---\n" + reqStr);
                    }
                }).fail(function(xhr, status, error) {
                    btn.text(originalText).prop('disabled', false);
                    alert('AJAX Error: ' + error);
                });
            });
        });
        </script>
        <?php
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
            
            <hr style="margin-top: 30px; margin-bottom: 30px;">
            <h2>Test Connection</h2>
            <p>Test your Nxis SSR integration for a specific page. This will use your <strong>saved</strong> credentials.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nxis_test_uri">Page URI</label></th>
                    <td>
                        <input type="text" id="nxis_test_uri" class="regular-text" placeholder="https://example.com/about" />
                        <button type="button" id="nxis_test_button" class="button button-secondary">Test Connection</button>
                        <span class="spinner" id="nxis_test_spinner" style="float: none; margin-top: 0;"></span>
                        <p class="description">Enter the full URI of a page to test fetching data.</p>
                    </td>
                </tr>
            </table>
            <div id="nxis_test_result" style="margin-top: 15px; display: none; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #00a0d2;">
                <h3 style="margin-top:0;">Response:</h3>
                <pre id="nxis_test_output" style="white-space: pre-wrap; word-wrap: break-word;"></pre>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#nxis_test_button').on('click', function() {
                var uri = $('#nxis_test_uri').val();
                if (!uri) {
                    alert('Please enter a Page URI to test.');
                    return;
                }

                $('#nxis_test_button').prop('disabled', true);
                $('#nxis_test_spinner').addClass('is-active');
                $('#nxis_test_result').hide();

                $.post(ajaxurl, {
                    action: 'nxis_test_connection',
                    nonce: '<?php echo wp_create_nonce("nxis_test_nonce"); ?>',
                    uri: uri
                }, function(response) {
                    $('#nxis_test_button').prop('disabled', false);
                    $('#nxis_test_spinner').removeClass('is-active');
                    $('#nxis_test_result').show();
                    
                    var borderColor = response.success ? '#46b450' : '#dc3232';
                    $('#nxis_test_result').css('border-left-color', borderColor);
                    
                    var output = '';
                    if (response.data) {
                        if (response.data.request) {
                            output += "--- Request Details ---\n";
                            output += "Endpoint: " + response.data.request.endpoint + "\n";
                            output += "Method: " + response.data.request.method + "\n";
                            output += "Headers: " + JSON.stringify(response.data.request.headers, null, 2) + "\n";
                            output += "Body: " + (response.data.request.body ? JSON.stringify(response.data.request.body, null, 2) : "None (GET request)") + "\n\n";
                            output += "--- Response Data ---\n";
                        }
                        
                        if (typeof response.data === 'string') {
                            output += response.data;
                        } else {
                            var resData = Object.assign({}, response.data);
                            delete resData.request; // remove request from the raw response view
                            output += JSON.stringify(resData, null, 2);
                        }
                    } else {
                        output = 'Unknown error occurred.';
                    }
                    $('#nxis_test_output').text(output);
                }).fail(function(xhr, status, error) {
                    $('#nxis_test_button').prop('disabled', false);
                    $('#nxis_test_spinner').removeClass('is-active');
                    $('#nxis_test_result').show().css('border-left-color', '#dc3232');
                    $('#nxis_test_output').text('AJAX Error: ' + error);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle connection testing via AJAX
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('nxis_test_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $uri = isset($_POST['uri']) ? sanitize_text_field(wp_unslash($_POST['uri'])) : '';
        if (empty($uri)) {
            wp_send_json_error('URI is required.');
        }

        // If the user entered a relative path, prepend the site URL
        if (!preg_match('~^https?://~i', $uri)) {
            if (strpos($uri, '/') !== 0) {
                $uri = '/' . $uri;
            }
            $uri = home_url($uri);
        }
        $uri = set_url_scheme($uri, 'https');

        $options = get_option('nxis_ai_settings');
        if (empty($options['client_id']) || empty($options['client_secret']) || empty($options['site_id'])) {
            wp_send_json_error('Please save your Client ID, Client Secret, and Site ID before testing.');
        }

        $token = $this->get_access_token($options);
        if (!$token) {
            wp_send_json_error('Failed to retrieve OAuth token. Please check your Client ID and Secret.');
        }

        $url = $this->api_host . '/v1/nxis:ssr?uri=' . urlencode($uri) . '&site_id=' . urlencode($options['site_id']);

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'text/plain'
            ),
            'timeout' => 10
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            wp_send_json_error('HTTP Request Error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $decoded = json_decode($body, true);
        
        $request_info = array(
            'endpoint' => $url,
            'method' => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer [REDACTED]',
                'Content-Type' => 'text/plain'
            ),
            'body' => null // GET request has no body
        );

        if ($code !== 200) {
            wp_send_json_error(array(
                'error' => "API Error (Code: $code)",
                'request' => $request_info,
                'response' => $decoded ? $decoded : $body
            ));
        }

        if (isset($decoded['data']) && is_array($decoded['data']) && !empty($decoded['data'])) {
            wp_send_json_success(array(
                'message' => 'Successfully fetched SSR data.',
                'request' => $request_info,
                'data' => $decoded['data']
            ));
        }

        wp_send_json_error(array(
            'error' => 'No SSR data returned for this URI.',
            'request' => $request_info
        ));
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

        $this->log("OAuth Token Response Code: $code, Body: $body");

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

        $this->log("SSR Fetch Response Code: $code, Body: $body");

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
