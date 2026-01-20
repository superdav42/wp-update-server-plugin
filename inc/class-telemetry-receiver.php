<?php
/**
 * Telemetry REST API Receiver
 *
 * Handles incoming telemetry data from Ultimate Multisite installations.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Telemetry_Receiver {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'wu-telemetry/v1';

	/**
	 * Rate limit option prefix.
	 *
	 * @var string
	 */
	const RATE_LIMIT_PREFIX = 'wu_telemetry_rate_';

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 3600; // 1 hour

	/**
	 * Maximum requests per window.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX = 10;

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('rest_api_init', [$this, 'register_routes']);
		add_action('wu_telemetry_cleanup', [$this, 'cleanup_old_records']);
		add_action('admin_init', [$this, 'schedule_cleanup']);
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::REST_NAMESPACE,
			'/track',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_track_request'],
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => [
					'type' => [
						'required'          => false,
						'default'           => 'usage',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ($param) {

							return in_array($param, ['usage', 'error'], true);
						},
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_stats_request'],
				'permission_callback' => [$this, 'check_admin_permission'],
			]
		);
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @return bool
	 */
	public function check_admin_permission(): bool {

		return current_user_can('manage_options');
	}

	/**
	 * Handle incoming tracking request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_track_request(\WP_REST_Request $request) {

		$type = $request->get_param('type') ?: 'usage';
		$body = $request->get_body();

		// Validate JSON
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new \WP_Error(
				'invalid_json',
				'Invalid JSON payload',
				['status' => 400]
			);
		}

		// Validate required fields
		if (empty($data['site_hash'])) {
			return new \WP_Error(
				'missing_site_hash',
				'Site hash is required',
				['status' => 400]
			);
		}

		$site_hash = sanitize_text_field($data['site_hash']);

		// Rate limiting
		if ($this->is_rate_limited($site_hash)) {
			return new \WP_Error(
				'rate_limited',
				'Too many requests',
				['status' => 429]
			);
		}

		// Validate tracker version
		if (empty($data['tracker_version'])) {
			return new \WP_Error(
				'missing_version',
				'Tracker version is required',
				['status' => 400]
			);
		}

		// Store the data
		$result = Telemetry_Table::insert($site_hash, $type, $data);

		if (false === $result) {
			return new \WP_Error(
				'insert_failed',
				'Failed to store telemetry data',
				['status' => 500]
			);
		}

		// Update rate limit counter
		$this->increment_rate_limit($site_hash);

		return new \WP_REST_Response(
			[
				'success' => true,
				'id'      => $result,
			],
			201
		);
	}

	/**
	 * Handle stats request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_stats_request(\WP_REST_Request $request): \WP_REST_Response {

		$days = (int) ($request->get_param('days') ?: 30);
		$days = max(1, min($days, 365)); // Clamp between 1 and 365

		return new \WP_REST_Response(
			[
				'unique_sites'    => Telemetry_Table::get_unique_site_count($days),
				'php_versions'    => Telemetry_Table::get_php_version_distribution($days),
				'wp_versions'     => Telemetry_Table::get_wp_version_distribution($days),
				'plugin_versions' => Telemetry_Table::get_plugin_version_distribution($days),
				'network_types'   => Telemetry_Table::get_network_type_distribution($days),
				'gateways'        => Telemetry_Table::get_gateway_usage($days),
				'addons'          => Telemetry_Table::get_addon_usage($days),
				'error_summary'   => Telemetry_Table::get_error_summary($days),
				'period_days'     => $days,
			],
			200
		);
	}

	/**
	 * Check if a site is rate limited.
	 *
	 * @param string $site_hash The site hash.
	 * @return bool
	 */
	protected function is_rate_limited(string $site_hash): bool {

		$key   = self::RATE_LIMIT_PREFIX . substr($site_hash, 0, 16);
		$count = (int) get_transient($key);

		return $count >= self::RATE_LIMIT_MAX;
	}

	/**
	 * Increment the rate limit counter for a site.
	 *
	 * @param string $site_hash The site hash.
	 * @return void
	 */
	protected function increment_rate_limit(string $site_hash): void {

		$key   = self::RATE_LIMIT_PREFIX . substr($site_hash, 0, 16);
		$count = (int) get_transient($key);

		set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
	}

	/**
	 * Schedule the cleanup cron job.
	 *
	 * @return void
	 */
	public function schedule_cleanup(): void {

		if ( ! wp_next_scheduled('wu_telemetry_cleanup')) {
			wp_schedule_event(time(), 'daily', 'wu_telemetry_cleanup');
		}
	}

	/**
	 * Clean up old records.
	 *
	 * @return void
	 */
	public function cleanup_old_records(): void {

		$deleted = Telemetry_Table::cleanup_old_records(90);

		if ($deleted > 0) {
			error_log(sprintf('Ultimate Multisite Telemetry: Cleaned up %d old records', $deleted));
		}
	}
}
