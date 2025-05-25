<?php

namespace WP_Update_Server_Plugin;

class Request_Endpoint {
	protected $updateServer;

	public function __construct() {
		$this->updateServer = new Update_Server(home_url('/'));

		// The "action" and "slug" query parameters are often used by the WordPress core
		// or other plugins, so lets use different parameter names to avoid conflict.
		add_filter('query_vars', array($this, 'addQueryVariables'));
		add_action('template_redirect', array($this, 'handleUpdateApiRequest'));
	}

	public function addQueryVariables($query_variables) {
		$query_variables = array_merge(
			$query_variables,
			[
				'update_action',
				'update_slug',
			]
		);
		return $query_variables;
	}

	public function handleUpdateApiRequest() {
		if ( get_query_var('update_action') ) {
			$this->updateServer->handleRequest(array_merge(
				$_GET,
				[
				'action' => get_query_var('update_action'),
				'slug'   => get_query_var('update_slug'),
			]
			)
			);
		}
	}
}