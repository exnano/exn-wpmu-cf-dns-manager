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

$action = add_query_arg('action', $this->plugin_page, 'edit.php');
?>
<div id="exnano" class="wrap">
    <h1><?php esc_html_e($this->title, 'exn-wpmu-cf-dns-manager'); ?></h1>
    <p><?php esc_html_e($this->description, 'exn-wpmu-cf-dns-manager'); ?></p>
    <form action="<?php echo esc_html($action); ?>" method="post">
        <?php wp_nonce_field($this->nonce_key); ?>
        <div>
            <h3 scope="row">
                <?php esc_html_e('API Token', 'exn-wpmu-cf-dns-manager'); ?>
            </h3>
            <div>
                <input name="exn_cf_api_token" type="text" id="exn_cf_api_token" class="regular-text" value="<?php esc_attr_e(get_site_option('exn_cf_api_token')); ?>">
                <p><?php esc_html_e('Create Cloudflare API Token to communicate with your account with permission: Zone > DNS > Edit.', 'exn-wpmu-cf-dns-manager'); ?></p>
                <p><?php esc_html_e('Please restrict the token\'s Zone Resources to specific zone (domain) for extra security.', 'exn-wpmu-cf-dns-manager'); ?></p>
            </div>
        </div>
        <?php submit_button(); ?>
    </form>
    <?php if ($this->has_token()) : ?>
    <br>
    <div>
        <h3 scope="row">
            <?php esc_html_e('Token Zone Status', 'exn-wpmu-cf-dns-manager'); ?>
        </h3>
        <div>
            <!-- <p><?php esc_html_e('List of zones (domains) that above API Token can access.', 'exn-wpmu-cf-dns-manager'); ?></p> -->
            <?php echo $this->display_zones(); ?>
        </div>
    </div>
    <?php endif; ?>
</div>
