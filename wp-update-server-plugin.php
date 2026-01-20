<?php
/*
Plugin Name: WP Update Server Plugin
Description: An example plugin that runs the update API.
Version: 1.0
Author: David Stone
*/
require_once __DIR__ . '/vendor/yahnis-elsts/wp-update-server/loader.php';
require_once __DIR__ . '/inc/class-update-server.php';
require_once __DIR__ . '/inc/class-request-endpoint.php';
require_once __DIR__ . '/inc/class-product-icon.php';
require_once __DIR__ . '/inc/class-store-api.php';
require_once __DIR__ . '/inc/class-telemetry-table.php';
require_once __DIR__ . '/inc/class-telemetry-receiver.php';
require_once __DIR__ . '/inc/class-telemetry-admin.php';

$wp_update_server_plugin_request_endpoint = new \WP_Update_Server_Plugin\Request_Endpoint();
$wp_update_server_plugin_product_icon     = new \WP_Update_Server_Plugin\Product_Icon();
$wp_update_server_plugin_store_api        = new \WP_Update_Server_Plugin\Store_Api();

// Telemetry components
$wp_update_server_plugin_telemetry_table    = new \WP_Update_Server_Plugin\Telemetry_Table();
$wp_update_server_plugin_telemetry_receiver = new \WP_Update_Server_Plugin\Telemetry_Receiver();
$wp_update_server_plugin_telemetry_admin    = new \WP_Update_Server_Plugin\Telemetry_Admin();