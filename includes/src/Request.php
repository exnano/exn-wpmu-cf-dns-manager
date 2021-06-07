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

final class Request
{
    private static $endpoint = 'https://api.cloudflare.com/client/v4/zones/';

    public static function default_args($param = [])
    {
        $args = [
                'blocking' => true,
                'timeout' => 15,
                'httpversion' => '1.1',
                'user-agent' => 'Mozilla/5.0 (compatible; exnano/wp-plugin; +https://exnano.io)',
                'body' => null,
                'compress' => false,
                'decompress' => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'stream' => false,
                'headers' => [
                    'REFERER' => home_url(),
                    'Cache-Control' => 'no-cache',
                ],
            ];

        if (!empty($param) && \is_array($param)) {
            $args = array_merge($args, $param);
        }

        return $args;
    }

    private static function set_endpoint_path($path = '')
    {
        if (!empty($path)) {
            self::$endpoint = trailingslashit(self::$endpoint).trailingslashit($path);
        }
    }

    public static function fetch($url, $param = [])
    {
        $args = self::default_args($param);

        return wp_remote_get($url, $args);
    }

    public static function post($url, $param = [])
    {
        $args = self::default_args($param);

        return wp_remote_post($url, $args);
    }

    public static function delete($url, $param = [])
    {
        $param['method'] = 'DELETE';
        $args = self::default_args($param);

        return wp_remote_request($url, $args);
    }

    public static function fetch_data($query = [])
    {
        $token = get_site_option('exn_cf_api_token');
        if (empty($token)) {
            return false;
        }

        $url = trailingslashit(self::$endpoint);
        if (!empty($query) && \is_array($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
            ],
        ];

        $response = self::fetch($url, $args);
        $response_code = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || 200 !== (int) $response_code) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);

        if (empty($response_body)) {
            return false;
        }

        $data = json_decode($response_body, true);

        if (!empty($data) && \is_array($data)) {
            return $data;
        }

        return false;
    }

    public static function fetch_domain($domain)
    {
        $domain = str_replace('www.', '', $domain);

        return self::fetch_data(['name' => $domain]);
    }

    public static function fetch_dns_record($domain_id)
    {
        self::set_endpoint_path($domain_id.'/dns_records');

        return self::fetch_data();
    }

    public static function create_dns_record($new_site)
    {
        $token = get_site_option('exn_cf_api_token');
        $zone_id = get_site_option('exn_cf_domain_id');

        if (empty($token) || empty($zone_id) || !\is_object($new_site)) {
            return false;
        }

        $network = get_network();
        $domain = str_replace('www.', '', $network->domain);
        $cname = $new_site->domain;

        // temporary override for development purpose.
        if (\defined('WP_ENV') && 'production' === WP_ENV) {
            $domain = $network->domain;
        }

        // override for development purpose.
        // local domain: aa.local
        // test domain: aa.com
        // network domain: test.aa.local
        // override: test.aa.local -> test.aa.com
        if (\defined('EXNANO_MUCFDNSM_TEST_DOMAIN')) {
            $domain = EXNANO_MUCFDNSM_TEST_DOMAIN;
            $cname = str_replace($network->domain, $domain, $cname);
        }

        $url = trailingslashit(self::$endpoint).$zone_id.'/dns_records/';
        $body = [
            'type' => 'CNAME',
            'name' => $cname,
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

        $response = self::post($url, $args);
        // TODO: display admin notice if failed.
        return $response;
    }

    public static function update_dns_record($new_site, $old_site)
    {
        self::create_dns_record($new_site);
        //self::delete_dns_record($old_site);
    }

    /**
     * delete_dns_record.
     */
    public static function delete_dns_record($old_site)
    {
        // wp_remote_request() with method = DELETE.
        // https://api.cloudflare.com/#dns-records-for-a-zone-delete-dns-record
        // self::delete();
    }
}
