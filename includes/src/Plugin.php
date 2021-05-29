<?php
/**
 * EXN Multisite Cloudflare DNS Manager.
 *
 * @author  Exnano Creative
 * @license MIT
 *
 * @see    https://github.com/exnano/exn-wpmu-cf-dns-manager
 */

namespace ExnanoCreative\ExnWpmuCfDnsManager;

\defined('ABSPATH') || exit;

final class Plugin
{
    private $title;
    private $description;
    private $menu_parent;
    private $plugin_page;
    private $nonce_key;
    private $cf_api_url;
    private $hook;
    private $path;
    private $screen;
    private $plugin_dir;

    /**
     * constructor.
     */
    public function __construct()
    {
        $this->title = 'Multisite Cloudflare DNS Manager';
        $this->description = 'Update Cloudflare DNS with new CNAME entry (subdomain) when create new Site.';
        $this->menu_parent = 'settings.php';
        $this->plugin_page = 'exn-wpmu-cf-dns-manager';
        $this->nonce_key = 'exn-validate';
        $this->cf_api_url = 'https://api.cloudflare.com/client/v4/';

        $this->hook = plugin_basename(EXNANO_MUCFDNSM_FILE);
        $this->path = realpath(plugin_dir_path(EXNANO_MUCFDNSM_FILE));
        $this->plugin_url = plugin_dir_url(EXNANO_MUCFDNSM_FILE);
        $this->screen = 'settings_page_exn-wpmu-cf-dns-manager';
    }

    private function init_actions()
    {
        // unofficial constant: possible to disable nag notices
        !\defined('DISABLE_NAG_NOTICES') && \define('DISABLE_NAG_NOTICES', true);

        add_action('network_admin_edit_'.$this->plugin_page, [$this, 'settings_save']);
        add_action('network_admin_notices', [$this, 'custom_notices']);

        add_action('wp_insert_site', [$this, 'create_dns_record']);
        // add_action( 'wp_delete_site', [$this, 'delete_dns_record' ] );
        // do_action( 'wp_insert_site', WP_Site $new_site );

        add_action(
            'admin_enqueue_scripts',
            function ($hook) {
                $plugin_url = $this->plugin_url;
                $version = \defined('EXNANO_MUCFDNSM_VERSION') ? EXNANO_MUCFDNSM_VERSION : date('ymdh');

                if ($hook === $this->screen) {
                    wp_enqueue_style($this->plugin_page.'-core', $plugin_url.'includes/admin/exnano.css', null, $version);
                }
            }
        );

        add_action('network_admin_menu', function () {
            add_submenu_page(
                $this->menu_parent, // Parent element
                'Cloudflare DNS Manager', // Text in browser title bar
                'CF DNS Manager', // Text to be displayed in the menu.
                'manage_network_options', // Capability
                $this->plugin_page, // Page slug, will be displayed in URL
                [$this, 'settings_page'], // Callback function which displays the page
            );
        });

        add_filter(
            'network_admin_plugin_action_links_'.$this->hook,
            function ($links) {
                $new = [
                    'exnwpmucfdnsmanager-settings' => sprintf('<a href="%s">%s</a>', network_admin_url($this->menu_parent.'/?page='.$this->plugin_page), __('Settings', 'exn-wpmu-cf-dns-manager')),
                ];

                return array_merge($new, $links);
            }
        );
    }

    public function settings_page()
    {
        include_once $this->path.'/includes/admin/settings.php';
    }

    public function settings_save()
    {
        check_admin_referer($this->nonce_key);

        update_site_option('exn_cf_api_token', sanitize_text_field($_POST['exn_cf_api_token']));

        $redirect = add_query_arg(
            [
            'page' => $this->plugin_page,
            'updated' => true,
            ],
            network_admin_url($this->menu_parent)
        );

        wp_redirect($redirect);

        exit;
    }

    /**
     * gcustom_notices.
     */
    public function custom_notices()
    {
        if (isset($_GET['page']) && $_GET['page'] == $this->plugin_page && isset($_GET['updated'])) {
            echo '<div id="message" class="updated notice notice-success is-dismissible"><p>'.esc_html__('Settings updated', 'exn-wpmu-cf-dns-manager').'</p></div>';
        }
    }

    /**
     * get_zones_list.
     */
    public function get_zones_list()
    {
        $network = get_network();
        $domain = $network->domain;

        if (\defined('EXNANO_MUCFDNSM_TEST_DOMAIN')) {
            $domain = EXNANO_MUCFDNSM_TEST_DOMAIN;
        }

        $response = null;
        $token = get_site_option('exn_cf_api_token');
        $url = $this->cf_api_url.'zones/';
        $args = [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
            ],
        ];

        if (empty($token)) {
            return false;
        }

        // Make request to Cloudflare API zones endpoint.
        $response = wp_remote_get($url, $args);
        $response_code = wp_remote_retrieve_response_code($response);

        if (200 === $response_code) {
            $response = json_decode(wp_remote_retrieve_body($response), true);

            if (1 < $response['result_info']['total_count']) {
                $total_zones = $response['result_info']['total_count'];
                /* translators: %1$s = zones, %2$s = domain */
                printf(__("<p>It seems this API Token can access other domains (%1$s) than <strong>%2$s</strong>.</p>", 'exn-wpmu-cf-dns-manager'), $total_zones, $network->domain);

                /* translators: %s = domain */
                printf(__('<p>Please limit the token Zone Resources to only <strong>%s</strong> to improve security.</p>', 'exn-wpmu-cf-dns-manager'), $network->domain);
            }

            // Now we can search if our domain is correctly assign permission to the token.
            $domain = str_replace('www.', '', $domain);
            $url_search = add_query_arg('name', $domain, $url);
            $response = wp_remote_get($url_search, $args);
            $response = json_decode(wp_remote_retrieve_body($response), true);

            // echo '<pre>' . print_r( $response, true ) . '</pre>';

            if (!isset($response['result'][0])) {
                /* translators: %s = domain */
                printf(__('<p>Invalid token for <strong>%s</strong>.<p>', 'exn-wpmu-cf-dns-manager'), $network->domain);
                exit;
            }

            $domain_id = $response['result'][0]['id'];

            // temporary saving domain id.
            update_site_option('exn_cf_domain_id', $domain_id);

            // Re-request for the exact zone.
            $url_dns = $url.$domain_id.'/dns_records/';
            $response = wp_remote_get($url_dns, $args);
            $response = json_decode(wp_remote_retrieve_body($response), true);

            // echo '<pre>' . print_r( $response, true ) . '</pre>';

            // DNS records more than 0. Not Empty.
            if (0 < $response['result_info']['total_count']) {
                $dns_records = $response['result'];
                $count = 1;
                // echo '<pre>' . print_r( $zones, true ) . '</pre>';

                echo '<table class="wp-list-table widefat striped">';
                echo '<thead>';
                echo '<tr>';
                echo '<td class="check-column"></td>';
                echo '<th class="">Type</th>';
                echo '<th class="">Name</th>';
                echo '<th class="">Content</th>';
                echo '<th class="">TTL</th>';
                echo '<th class="">Proxy Status</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($dns_records as $dns_record) {
                    $type = $dns_record['type'];
                    $name = $dns_record['name'];
                    $content = \array_key_exists('content', $dns_record) ? $dns_record['content'] : '';
                    $ttl = \array_key_exists('ttl', $dns_record) ? $dns_record['ttl'] : '';
                    $proxied = $dns_record['proxied'] ? '&check; Enabled' : '&#x2715; Disabled';

                    echo '<tr>';
                    echo '<td><strong>'.$count++.'.</strong></td>';
                    echo '<td>'.$type.'</td>';
                    echo '<td>'.$name.'</td>';
                    echo '<td>'.wordwrap($content, 80, '<br>', true).'</td>';
                    echo '<td>'.(1 === $ttl ? 'Auto' : $ttl).'</td>';
                    echo '<td>'.$proxied.'</td>';
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
            }
        } else {
            /* translators: %s = response code */
            printf(__('<p>Response code: %s</p>', 'exn-wpmu-cf-dns-manager'), $response_code);
            echo '<p>'.wp_remote_retrieve_response_message($response).'</p>';
        }
    }

    /**
     * create_dns_record.
     */
    public function create_dns_record($new_site)
    {
        $token = get_site_option('exn_cf_api_token');
        $zone_id = get_site_option('exn_cf_domain_id');

        $network = get_network();
        $domain = str_replace('www.', '', $network->domain);

        // temporary override for development purpose.
        if (\defined('WP_ENV') && 'production' === WP_ENV) {
            $domain = $network->domain;
        }

        if (!empty($token)) {
            $url = $this->cf_api_url.'zones/'.$zone_id.'/dns_records/';
            $body = [
                'type' => 'CNAME',
                'name' => $new_site->domain,
                'content' => $domain,
                'ttl' => 1,
                'proxied' => true,
            ];

            $args = [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ];

            $response = wp_remote_post($url, $args);
            $response_code = wp_remote_retrieve_response_code($response);
        }
    }

    /**
     * delete_dns_record.
     */
    public function delete_dns_record($old_site)
    {
        // wp_remote_request() with method = DELETE.
        // https://api.cloudflare.com/#dns-records-for-a-zone-delete-dns-record
    }

    /**
     * deactivate_cleanup.
     */
    private function deactivate_cleanup($is_uninstall = false)
    {
        delete_site_option('exn_cf_api_token');
        delete_site_option('exn_cf_domain_id');
    }

    /**
     * plugin uninstall.
     */
    public static function uninstall()
    {
        ( new self() )->deactivate_cleanup(true);
    }

    /**
     * plugin deactivate.
     */
    public function deactivate()
    {
        $this->deactivate_cleanup();
    }

    /**
     * plugin activate.
     */
    public function activate()
    {
    }

    private function init_hooks()
    {
        register_activation_hook($this->hook, [$this, 'activate']);
        register_deactivation_hook($this->hook, [$this, 'deactivate']);
        register_uninstall_hook($this->hook, [__CLASS__, 'uninstall']);
    }

    /**
     * initialize.
     */
    public function init()
    {
        $this->init_hooks();
        $this->init_actions();
    }
}
