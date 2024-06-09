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
    private $hook;
    private $path;
    private $screen;
    private $plugin_dir;
    private $plugin_url;

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

        $this->hook = plugin_basename(EXNANO_MUCFDNSM_FILE);
        $this->path = realpath(plugin_dir_path(EXNANO_MUCFDNSM_FILE));
        $this->plugin_url = plugin_dir_url(EXNANO_MUCFDNSM_FILE);
        $this->screen = 'settings_page_exn-wpmu-cf-dns-manager';
    }

    private function init_actions()
    {
        // unofficial constant: possible to disable nag notices
        !\defined('DISABLE_NAG_NOTICES') && \define('DISABLE_NAG_NOTICES', true);

        add_action('network_admin_edit_' . $this->plugin_page, [$this, 'settings_save']);
        add_action('network_admin_notices', [$this, 'custom_notices']);

        add_action('wp_insert_site', function ($site) {
            Request::create_dns_record($site);
            $this->remove_transient();
        });

        add_action('wp_update_site', function ($new_site, $old_site) {
            Request::update_dns_record($new_site, $old_site);
            $this->remove_transient();
        }, PHP_INT_MAX, 2);

        add_action('wp_delete_site', function ($old_site) {
            Request::delete_dns_record($old_site);
            $this->remove_transient();
        }, PHP_INT_MAX);

        add_action(
            'admin_enqueue_scripts',
            function ($hook) {
                $plugin_url = $this->plugin_url;
                $version = \defined('EXNANO_MUCFDNSM_VERSION') ? EXNANO_MUCFDNSM_VERSION : date('ymdh');

                if ($hook === $this->screen) {
                    wp_enqueue_style($this->plugin_page . '-core', $plugin_url . 'includes/admin/exnano.css', null, $version . 'x' . date('md'));
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
            'network_admin_plugin_action_links_' . $this->hook,
            function ($links) {
                $new = [
                    'exnwpmucfdnsmanager-settings' => sprintf('<a href="%s">%s</a>', network_admin_url($this->menu_parent . '/?page=' . $this->plugin_page), __('Settings', 'exn-wpmu-cf-dns-manager')),
                ];

                return array_merge($new, $links);
            }
        );
    }

    /**
     * settings_page.
     */
    public function settings_page()
    {
        include_once $this->path . '/includes/admin/settings.php';
    }

    /**
     * settings_save.
     */
    public function settings_save()
    {
        check_admin_referer($this->nonce_key);
        $this->remove_transient();

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
     * has_token.
     */
    public function has_token()
    {
        $token = get_site_option('exn_cf_api_token');

        return !empty($token);
    }

    /**
     * custom_notices.
     */
    public function custom_notices()
    {
        if (isset($_GET['updated']) && !empty($_GET['page']) && $this->plugin_page === sanitize_text_field($_GET['page'])) {
            echo '<div id="message" class="updated notice notice-success is-dismissible"><p>' . esc_html__('Settings updated', 'exn-wpmu-cf-dns-manager') . '</p></div>';
        }
    }

    /*public function notice($msg, $type = 'info', $is_dismiss = true)
    {
        if (!empty($msg) && !empty($type)) {
            add_action('network_admin_notices', function () {
                $html = '<div id="exn-wpmu-cf-dns-manager-notice"';
                $html .= 'class="notice notice-'.$type.($is_dismiss ? ' is-dismissible' : '').'"> ';
                $html .= '<p>'.$msg.'</p>';
                $html .= '</div>';
                echo $html;
            }, PHP_INT_MAX);
        }
    }*/

    /**
     * display_zones.
     */
    public function display_zones()
    {
        $response = get_site_transient('exncf/fetchdata');
        if (empty($response) || !\is_array($response)) {
            $response = Request::fetch_data();
        }

        $network = get_network();
        $domain = $network->domain;

        if (\defined('EXNANO_MUCFDNSM_TEST_DOMAIN')) {
            $domain = EXNANO_MUCFDNSM_TEST_DOMAIN;
        }

        if (false === $response || (!\is_array($response) && 200 !== (int) $response)) {
            /* translators: %s = domain */
            return sprintf(__('<p>Invalid token for <strong>%1$s</strong>.<p>', 'exn-wpmu-cf-dns-manager'), $domain);
        }

        set_site_transient('exncf/fetchdata', $response, 300);

        $html = '';
        if (1 < $response['result_info']['total_count']) {
            $total_zones = $response['result_info']['total_count'];
            /* translators: %1$s = zones, %2$s = domain */
            $html .= sprintf(__('<p>It seems this API Token can access other domains (%1$s) than <strong>%2$s</strong>.</p>', 'exn-wpmu-cf-dns-manager'), $total_zones, $network->domain);

            /* translators: %s = domain */
            $html .= sprintf(__('<p>Please limit the token Zone Resources to only <strong>%s</strong> to improve security.</p>', 'exn-wpmu-cf-dns-manager'), $network->domain);
        }

        // Now we can search if our domain is correctly assign permission to the token.
        $response = get_site_transient('exncf/fetchdomain');
        if (empty($response) || !\is_array($response)) {
            $response = Request::fetch_domain($domain);
        }

        if (empty($response) || !isset($response['result'][0])) {
            $html .= sprintf(
                /* translators: 1: Total zones 2: Domain */
                __('<p>It seems this API Token can access other domains (%1$s) than <strong>%2$s</strong>.</p>', 'exn-wpmu-cf-dns-manager'),
                $total_zones,
                $network->domain
            );

            return $html;
        }

        set_site_transient('exncf/fetchdomain', $response, 300);

        $domain_id = $response['result'][0]['id'];

        // temporary saving domain id.
        update_site_option('exn_cf_domain_id', $domain_id);

        $response = get_site_transient('exncf/dnsrecord');
        if (empty($response) || !\is_array($response)) {
            $response = Request::fetch_dns_record($domain_id);
        }

        if (empty($response) || !\is_array($response) || empty($response['result_info']['total_count']) || !isset($response['result'][0])) {
            /* translators: %s = domain */
            $html .= sprintf(__('<p>No DNS Record Available for <strong>%s</strong>.<p>', 'exn-wpmu-cf-dns-manager'), $domain);

            return $html;
        }

        set_site_transient('exncf/dnsrecord', $response, 300);

        $dns_records = $response['result'];
        $count = 1;

        $html .= '<table class="wp-list-table widefat striped">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<td class="check-column"></td>';
        $html .= '<th class="">Type</th>';
        $html .= '<th class="">Name</th>';
        $html .= '<th class="">Content</th>';
        $html .= '<th class="">TTL</th>';
        $html .= '<th class="">Proxy Status</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($dns_records as $dns_record) {
            $type = $dns_record['type'];
            $name = $dns_record['name'];
            $content = \array_key_exists('content', $dns_record) ? $dns_record['content'] : '';
            $ttl = \array_key_exists('ttl', $dns_record) ? $dns_record['ttl'] : '';
            $is_proxied = $dns_record['proxied'] ? true : false;
            $proxied = $is_proxied ? '<span class="dashicons dashicons-yes"></span> Enabled' : '<span class="dashicons dashicons-no-alt"></span> Disabled';
            $proxied_color = $is_proxied ? 'text-green' : 'text-red';

            $html .= '<tr>';
            $html .= '<td><strong>' . $count++ . '.</strong></td>';
            $html .= '<td>' . $type . '</td>';
            $html .= '<td>' . $name . '</td>';
            $html .= '<td>' . wordwrap($content, 80, '<br>', true) . '</td>';
            $html .= '<td>' . (1 === $ttl ? 'Auto' : $ttl) . '</td>';
            $html .= '<td class="' . $proxied_color . '">' . $proxied . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }

    private function remove_transient()
    {
        delete_site_transient('exncf/fetchdata');
        delete_site_transient('exncf/fetchdomain');
        delete_site_transient('exncf/dnsrecord');
    }

    /**
     * deactivate_cleanup.
     */
    private function deactivate_cleanup($is_uninstall = false)
    {
        delete_site_option('exn_cf_api_token');
        delete_site_option('exn_cf_domain_id');
        $this->remove_transient();
    }

    /**
     * plugin uninstall.
     */
    public static function uninstall()
    {
        (new self())->deactivate_cleanup(true);
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
