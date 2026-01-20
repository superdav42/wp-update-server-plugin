<?php
/**
 * Telemetry Database Table
 *
 * Handles the custom database table for storing telemetry data.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Telemetry_Table {

	/**
	 * Table name (without prefix)
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wu_telemetry';

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action('admin_init', [$this, 'maybe_create_table']);
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {

		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the table if it doesn't exist.
	 *
	 * @return void
	 */
	public function maybe_create_table(): void {

		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Check if table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ($table_exists === $table_name) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_hash varchar(64) NOT NULL,
			data_type varchar(20) NOT NULL DEFAULT 'usage',
			plugin_version varchar(20) DEFAULT NULL,
			payload longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY site_hash (site_hash),
			KEY data_type (data_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta($sql);
	}

	/**
	 * Insert a telemetry record.
	 *
	 * @param string $site_hash The anonymized site hash.
	 * @param string $data_type The type of data (usage|error).
	 * @param array  $payload The telemetry data.
	 * @return int|false The inserted ID or false on failure.
	 */
	public static function insert(string $site_hash, string $data_type, array $payload) {

		global $wpdb;

		$plugin_version = $payload['plugin']['version'] ?? ($payload['environment']['plugin_version'] ?? null);

		$result = $wpdb->insert(
			self::get_table_name(),
			[
				'site_hash'      => $site_hash,
				'data_type'      => $data_type,
				'plugin_version' => $plugin_version,
				'payload'        => wp_json_encode($payload),
				'created_at'     => current_time('mysql'),
			],
			['%s', '%s', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get unique site count.
	 *
	 * @param int|null $days Optional number of days to look back.
	 * @return int
	 */
	public static function get_unique_site_count(?int $days = null): int {

		global $wpdb;

		$table_name = self::get_table_name();

		$where = '';

		if ($days !== null) {
			$where = $wpdb->prepare(
				' WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)',
				$days
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var("SELECT COUNT(DISTINCT site_hash) FROM {$table_name}{$where}");
	}

	/**
	 * Get PHP version distribution.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_php_version_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.environment.php_version')) as php_version,
					COUNT(DISTINCT site_hash) as count
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY php_version
				ORDER BY count DESC",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get WordPress version distribution.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_wp_version_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.environment.wp_version')) as wp_version,
					COUNT(DISTINCT site_hash) as count
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY wp_version
				ORDER BY count DESC",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get plugin version distribution.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_plugin_version_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					plugin_version,
					COUNT(DISTINCT site_hash) as count
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND plugin_version IS NOT NULL
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY plugin_version
				ORDER BY count DESC",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get network type distribution.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_network_type_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.network.is_subdomain')) = 'true' THEN 'Subdomain'
						ELSE 'Subdirectory'
					END as network_type,
					COUNT(DISTINCT site_hash) as count
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY network_type
				ORDER BY count DESC",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get gateway usage.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_gateway_usage(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// This is more complex since gateways is an array
		// We'll get the raw data and process in PHP
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					site_hash,
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.gateways.active_gateways')) as gateways
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY site_hash",
				$days
			),
			ARRAY_A
		);

		$gateway_counts = [];

		foreach ($results as $row) {
			$gateways = json_decode($row['gateways'], true);

			if (is_array($gateways)) {
				foreach ($gateways as $gateway) {
					if ( ! isset($gateway_counts[ $gateway ])) {
						$gateway_counts[ $gateway ] = 0;
					}

					++$gateway_counts[ $gateway ];
				}
			}
		}

		arsort($gateway_counts);

		return $gateway_counts;
	}

	/**
	 * Get addon usage.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_addon_usage(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					site_hash,
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.plugin.active_addons')) as addons
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY site_hash",
				$days
			),
			ARRAY_A
		);

		$addon_counts = [];

		foreach ($results as $row) {
			$addons = json_decode($row['addons'], true);

			if (is_array($addons)) {
				foreach ($addons as $addon) {
					if ( ! isset($addon_counts[ $addon ])) {
						$addon_counts[ $addon ] = 0;
					}

					++$addon_counts[ $addon ];
				}
			}
		}

		arsort($addon_counts);

		return $addon_counts;
	}

	/**
	 * Get recent errors.
	 *
	 * @param int $limit Number of errors to return.
	 * @return array
	 */
	public static function get_recent_errors(int $limit = 50): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					site_hash,
					plugin_version,
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.handle')) as handle,
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.message')) as message,
					created_at
				FROM {$table_name}
				WHERE data_type = 'error'
				ORDER BY created_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get error counts grouped by message pattern.
	 *
	 * @param int $days Number of days to look back.
	 * @return array
	 */
	public static function get_error_summary(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					JSON_UNQUOTE(JSON_EXTRACT(payload, '$.handle')) as handle,
					LEFT(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.message')), 100) as message_preview,
					COUNT(*) as count,
					MAX(created_at) as last_seen
				FROM {$table_name}
				WHERE data_type = 'error'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY handle, message_preview
				ORDER BY count DESC
				LIMIT 20",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Clean up old records.
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public static function cleanup_old_records(int $days = 90): int {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
