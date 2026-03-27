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
			$action = get_query_var('update_action');
			$slug   = get_query_var('update_slug');

			// Fire passive install tracking for metadata checks (not downloads).
			// Downloads are authenticated separately; metadata checks are the
			// primary signal for passive install detection.
			if ('get_metadata' === $action && ! empty($slug)) {
				$is_authenticated = (bool) apply_filters('determine_current_user', null);

				/**
				 * Fires before the update API response is sent.
				 *
				 * Used by Passive_Install_Tracker to log lightweight install data
				 * without blocking the response.
				 *
				 * @param string $slug             The plugin/addon slug being checked.
				 * @param bool   $is_authenticated Whether the request carries a valid auth token.
				 */
				do_action('wu_before_update_api_response', $slug, $is_authenticated);
			}

			$this->updateServer->handleRequest(array_merge(
				$_GET,
				[
					'action' => $action,
					'slug'   => $slug,
				]
			));
		}
	}
}