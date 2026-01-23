<?php
/*
Plugin Name: WP Update Server Plugin
Description: All the addon store stuff.
Version: 1.0
Author: David Stone
*/

define( 'WP_UPDATE_SERVER_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/vendor/yahnis-elsts/wp-update-server/loader.php';
require_once __DIR__ . '/inc/class-update-server.php';
require_once __DIR__ . '/inc/class-request-endpoint.php';
require_once __DIR__ . '/inc/class-product-icon.php';
require_once __DIR__ . '/inc/class-store-api.php';
require_once __DIR__ . '/inc/class-telemetry-table.php';
require_once __DIR__ . '/inc/class-telemetry-receiver.php';
require_once __DIR__ . '/inc/class-telemetry-admin.php';
require_once __DIR__ . '/inc/class-composer-token-table.php';
require_once __DIR__ . '/inc/class-composer-token.php';
require_once __DIR__ . '/inc/class-product-versions.php';
require_once __DIR__ . '/inc/class-composer-repository.php';
require_once __DIR__ . '/inc/class-downloads-page.php';
require_once __DIR__ . '/inc/class-changelog-manager.php';

$wp_update_server_plugin_request_endpoint = new \WP_Update_Server_Plugin\Request_Endpoint();
$wp_update_server_plugin_product_icon     = new \WP_Update_Server_Plugin\Product_Icon();
$wp_update_server_plugin_store_api        = new \WP_Update_Server_Plugin\Store_Api();

// Telemetry components
$wp_update_server_plugin_telemetry_table    = new \WP_Update_Server_Plugin\Telemetry_Table();
$wp_update_server_plugin_telemetry_receiver = new \WP_Update_Server_Plugin\Telemetry_Receiver();
$wp_update_server_plugin_telemetry_admin    = new \WP_Update_Server_Plugin\Telemetry_Admin();

// Composer repository components
$wp_update_server_plugin_composer_token_table = new \WP_Update_Server_Plugin\Composer_Token_Table();
$wp_update_server_plugin_composer_repository  = new \WP_Update_Server_Plugin\Composer_Repository();
$wp_update_server_plugin_downloads_page       = new \WP_Update_Server_Plugin\Downloads_Page();

// Release notification components
$wp_update_server_plugin_changelog_manager = new \WP_Update_Server_Plugin\Changelog_Manager();

add_action('woocommerce_loaded', function () {
	require_once __DIR__ . '/inc/class-release-notifier.php';
	$wp_update_server_plugin_release_notifier  = new \WP_Update_Server_Plugin\Release_Notifier();
});
