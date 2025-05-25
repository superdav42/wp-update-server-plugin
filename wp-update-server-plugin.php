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

$wp_update_server_plugin_request_endpoint = new \WP_Update_Server_Plugin\Request_Endpoint();