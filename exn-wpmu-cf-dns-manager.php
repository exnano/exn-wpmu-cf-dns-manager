<?php
/**
 * Plugin Name: EXN Multisite Cloudflare DNS Manager
 * Plugin URI:  https://github.com/exnano/exn-wpmu-cf-dns-manager/
 * Description: Update Cloudflare DNS with new subdomain when create new Site.
 * Version:     0.0.3
 * Author:      Exnano Creative
 * Author URI:  https://github.com/exnano/exn-wpmu-cf-dns-manager/
 * License:     GPLv2 or later
 * License URI: https://raw.githubusercontent.com/exnano/exn-wpmu-cf-dns-manager/master/LICENSE
 * Text Domain: exn-wpmu-cf-dns-manager
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

if ( ! class_exists( 'ExnWpmuCfDnsManager' ) ) :

class ExnWpmuCfDnsManager
{
	private static $title       = 'Multisite Cloudflare DNS Manager';
	private static $description = 'Update Cloudflare DNS with new CNAME entry (subdomain) when create new Site.';
	private static $menu_parent = 'settings.php';
	private static $plugin_page = 'exn-wpmu-cf-dns-manager';
	private static $nonce_key   = 'exn-validate';
	private static $cf_api_url  = 'https://api.cloudflare.com/client/v4/';

	private function __construct() {}

	public static function init_actions() {
		if ( is_multisite() ) { 
			add_action( 'network_admin_menu', [ __CLASS__, 'settings_menu' ] );
			add_action( 'network_admin_edit_' . self::$plugin_page, [ __CLASS__, 'settings_save' ] );
			add_action( 'network_admin_notices',  [ __CLASS__, 'custom_notices' ] );

			add_action( 'wp_insert_site', [ __CLASS__, 'create_dns_record' ] );
			// add_action( 'wp_delete_site', [ __CLASS__, 'delete_dns_record' ] );
			// do_action( 'wp_insert_site', WP_Site $new_site );
		}
	}

	public static function settings_menu() {
		add_submenu_page(
			self::$menu_parent, // Parent element
			'Cloudflare DNS Manager', // Text in browser title bar
			'CF DNS Manager', // Text to be displayed in the menu.
			'manage_network_options', // Capability
			self::$plugin_page, // Page slug, will be displayed in URL
			[ __CLASS__, 'settings_page' ], // Callback function which displays the page
		);
	}

	public static function settings_page() {
		$action = add_query_arg( 'action', self::$plugin_page, 'edit.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( self::$title, 'exn-wpmu-cf-dns-manager' ); ?></h1>
			<p><?php esc_html_e( self::$description, 'exn-wpmu-cf-dns-manager' ); ?></p>
			<form action="<?php echo esc_html( $action ); ?>" method="post">
				<?php wp_nonce_field( self::$nonce_key ); ?>
				<div>
					<h3 scope="row">
						<?php esc_html_e( 'API Token', 'exn-wpmu-cf-dns-manager' ); ?>
					</h3>
					<div>
						<input name="exn_cf_api_token" type="text" id="exn_cf_api_token" class="regular-text" value="<?php esc_attr_e( get_site_option( 'exn_cf_api_token' ) ); ?>">
						<p><?php esc_html_e( 'Create Cloudflare API Token to communicate with your account with permission: Zone > DNS > Edit.', 'exn-wpmu-cf-dns-manager' ); ?></p>
						<p><?php esc_html_e( 'Please restrict the token\'s Zone Resources to specific zone (domain) for extra security.', 'exn-wpmu-cf-dns-manager' ); ?></p>
					</div>
				</div>
				<?php submit_button(); ?>
			</form>
			<br>
			<div>
				<h3 scope="row">
					<?php esc_html_e( 'Token Zone Status', 'exn-wpmu-cf-dns-manager' ); ?>
				</h3>
				<div>
					<!-- <p><?php esc_html_e( 'List of zones (domains) that above API Token can access.', 'exn-wpmu-cf-dns-manager' ); ?></p> -->
					<?php self::get_zones_list(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function settings_save() {
		check_admin_referer( self::$nonce_key );

		update_site_option( 'exn_cf_api_token', sanitize_text_field( $_POST['exn_cf_api_token'] ) );

		$redirect = add_query_arg(
			[
			'page' => self::$plugin_page,
			'updated' => true,
			],
			network_admin_url( self::$menu_parent )
		);

		wp_redirect( $redirect );
	 
		exit;
	}

	public static function custom_notices() {
		if( isset($_GET['page']) && $_GET['page'] == self::$plugin_page && isset( $_GET['updated'] )  ) {
			echo '<div id="message" class="updated notice notice-success is-dismissible"><p>Settings updated.</p></div>';
		}
	}

	public static function get_zones_list() {
		$network  = get_network();
		$domain   = $network->domain;
		$response = null;
		$token    = get_site_option( 'exn_cf_api_token' );
		$url      = self::$cf_api_url . 'zones/';
		$args     = [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
			]
		];

		if ( empty( $token ) ) {
			return false;
		}

		// Make request to Cloudflare API zones endpoint.
		$response      = wp_remote_get( $url, $args );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code ) {
			$response = json_decode( wp_remote_retrieve_body( $response ), true );
			
			if ( 1 < $response['result_info']['total_count'] ) {
				$total_zones = $response['result_info']['total_count'];

				echo "<p>It seems this API Token can access other domains ($total_zones) than <strong>$network->domain</strong>.</p>";
				echo "<p>Please limit the token Zone Resources to only <strong>$network->domain</strong> to improve security.";
			}
			
			// Now we can search if our domain is correctly assign permission to the token.
			$domain     = str_replace( 'www.','', $domain );
			$url_search = add_query_arg( 'name', $domain, $url );
			$response   = wp_remote_get( $url_search, $args );
			$response   = json_decode( wp_remote_retrieve_body( $response ), true );

			// echo '<pre>' . print_r( $response, true ) . '</pre>';

			// temporary saving domain id.
			update_site_option( 'exn_cf_domain_id', $response['result'][0]['id'] );

			// Re-request for the exact zone.
			$url_dns  = $url . $response['result'][0]['id'] . '/dns_records/';
			$response = wp_remote_get( $url_dns, $args );
			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			// echo '<pre>' . print_r( $response, true ) . '</pre>';
			
			// DNS records more than 0. Not Empty.
			if ( 0 < $response['result_info']['total_count'] ) {
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

				foreach( $dns_records as $dns_record ) {
					$type    = $dns_record['type'];
					$name    = $dns_record['name'];
					$content = array_key_exists( 'content', $dns_record ) ? $dns_record['content'] : '';
					$ttl     = array_key_exists( 'ttl', $dns_record ) ? $dns_record['ttl'] : '';
					$proxied = $dns_record['proxied'] ? '&check; Enabled' : '&#x2715; Disabled';

					echo '<tr>';
					echo '<td><strong>' . $count++ .'.</strong></td>';
					echo '<td>' . $type . '</td>';
					echo '<td>' . $name . '</td>';
					echo '<td>' . $content . '</td>';
					echo '<td>' . ( 1 === $ttl ? 'Auto' : $ttl ) . '</td>';
					echo '<td>' . $proxied . '</td>';
					echo '</tr>';
				}
				echo '</tbody>';
				echo '</table>';
			}
		} else {
			echo '<p>Response code: ' . $response_code . '</p>';
			echo '<p>' . wp_remote_retrieve_response_message( $response ) . '</p>'; 
		}
	}

	public static function create_dns_record( $new_site ) {
		$token   = get_site_option( 'exn_cf_api_token' );
		$zone_id = get_site_option( 'exn_cf_domain_id' );
		$network = get_network();
		$domain  = str_replace( 'www.', '', $network->domain );

		// temporary override for development purpose.
		if ( defined( 'WP_ENV' ) && 'production' === WP_ENV ) {
			$domain = $network->domain;
		}

		if ( ! empty( $token ) ) {
			$url     = self::$cf_api_url . 'zones/' . $zone_id . '/dns_records/';
			$body    = [
				'type'    => 'CNAME',
				'name'    => $new_site->domain,
				'content' => $domain,
				'ttl'     => 1,
				'proxied' => true,
			];
	
			$args = [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode( $body ),
			];
	
			$response      = wp_remote_post( $url, $args );
			$response_code = wp_remote_retrieve_response_code( $response );
		}
	}

	public static function delete_dns_record( $old_site ) {
		// wp_remote_request() with method = DELETE.
		// https://api.cloudflare.com/#dns-records-for-a-zone-delete-dns-record
	}

}
add_action( 'plugins_loaded', array( 'ExnWpmuCfDnsManager', 'init_actions' ) );

endif;
