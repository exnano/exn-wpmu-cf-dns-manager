<?php
/**
 * EXN Multisite Cloudflare DNS Manager.
 *
 * @author  Exnano Creative
 * @license MIT
 *
 * @see    https://github.com/exnano/exn-wpmu-cf-dns-manager
 */

/**
 * @wordpress-plugin
 * Plugin Name:         EXN Multisite Cloudflare DNS Manager
 * Plugin URI:          https://github.com/exnano/exn-wpmu-cf-dns-manager/
 * Description:         Update Cloudflare DNS with new subdomain when create new Site.
 * GitHub Plugin URI:   https://github.com/exnano/exn-wpmu-cf-dns-manager
 * Version:             0.0.3
 * Author:              Exnano Creative
 * Author URI:          https://github.com/exnano/exn-wpmu-cf-dns-manager/
 * License:             MIT
 * License URI:         https://raw.githubusercontent.com/exnano/exn-wpmu-cf-dns-manager/master/LICENSE
 * Network:             true
 * Text Domain:         exn-wpmu-cf-dns-manager
 * Domain Path:         /languages
 * Requires at least:   5.6
 * Requires PHP:        7.4
 */

namespace ExnanoCreative\ExnWpmuCfDnsManager;

\defined('ABSPATH') && !\defined('EXNANO_MUCFDNSM_FILE') || exit;

\define('EXNANO_MUCFDNSM_FILE', __FILE__);
\define('EXNANO_MUCFDNSM_VERSION', '0.0.3');

require __DIR__.'/includes/load.php';

add_action(
    'plugins_loaded',
    function () {
        if (is_multisite()) {
            ( new Plugin() )->init();
        }
    },
    PHP_INT_MAX
);
