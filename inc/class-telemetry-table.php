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
	 * Get the total number of subsites across all reporting networks.
	 *
	 * Sums `network.total_sites` from the most recent payload per site.
	 * Only counts records from tracker version 2.0.0+ that include exact counts.
	 *
	 * @param int $days Number of days to look back.
	 * @return int Total subsite count across all networks.
	 */
	public static function get_total_subsites_across_network(int $days = 30): int {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.network.total_sites')) AS UNSIGNED))
				FROM (
					SELECT payload
					FROM {$table_name}
					WHERE data_type = 'usage'
					AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
					AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
					AND JSON_EXTRACT(payload, '$.network.total_sites') IS NOT NULL
					GROUP BY site_hash
					HAVING MAX(created_at)
				) AS latest",
				$days
			)
		);

		return (int) $result;
	}

	/**
	 * Get a histogram of subsite counts across reporting networks.
	 *
	 * Buckets: 1, 2-5, 6-10, 11-25, 26-50, 51-100, 101-250, 251+
	 * Uses the most recent payload per site within the window.
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['bucket' => string, 'count' => int].
	 */
	public static function get_subsite_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN total_sites = 1    THEN '1'
						WHEN total_sites <= 5   THEN '2-5'
						WHEN total_sites <= 10  THEN '6-10'
						WHEN total_sites <= 25  THEN '11-25'
						WHEN total_sites <= 50  THEN '26-50'
						WHEN total_sites <= 100 THEN '51-100'
						WHEN total_sites <= 250 THEN '101-250'
						ELSE '251+'
					END AS bucket,
					COUNT(*) AS count
				FROM (
					SELECT
						CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.network.total_sites')) AS UNSIGNED) AS total_sites
					FROM {$table_name}
					WHERE data_type = 'usage'
					AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
					AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
					AND JSON_EXTRACT(payload, '$.network.total_sites') IS NOT NULL
					GROUP BY site_hash
					HAVING MAX(created_at)
				) AS latest
				GROUP BY bucket
				ORDER BY MIN(total_sites)",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get a histogram of 30-day revenue across reporting networks.
	 *
	 * Buckets (in site's base currency): $0, $1-$50, $51-$100, $101-$500, $501-$1000, $1001-$5000, $5001+
	 * Note: revenue is reported in the site's base currency; no FX conversion is applied.
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['bucket' => string, 'count' => int].
	 */
	public static function get_revenue_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN revenue = 0          THEN '\$0'
						WHEN revenue <= 50        THEN '\$1-\$50'
						WHEN revenue <= 100       THEN '\$51-\$100'
						WHEN revenue <= 500       THEN '\$101-\$500'
						WHEN revenue <= 1000      THEN '\$501-\$1000'
						WHEN revenue <= 5000      THEN '\$1001-\$5000'
						ELSE '\$5001+'
					END AS bucket,
					COUNT(*) AS count
				FROM (
					SELECT
						CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.usage.total_revenue_30d')) AS DECIMAL(12,2)) AS revenue
					FROM {$table_name}
					WHERE data_type = 'usage'
					AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
					AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
					AND JSON_EXTRACT(payload, '$.usage.total_revenue_30d') IS NOT NULL
					GROUP BY site_hash
					HAVING MAX(created_at)
				) AS latest
				GROUP BY bucket
				ORDER BY MIN(revenue)",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get a histogram of checkout conversion rates across reporting networks.
	 *
	 * Buckets: 0%, 1-10%, 11-25%, 26-50%, 51-75%, 76-100%
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['bucket' => string, 'count' => int, 'avg_rate_pct' => float].
	 */
	public static function get_conversion_rate_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN rate = 0          THEN '0%%'
						WHEN rate <= 0.10      THEN '1-10%%'
						WHEN rate <= 0.25      THEN '11-25%%'
						WHEN rate <= 0.50      THEN '26-50%%'
						WHEN rate <= 0.75      THEN '51-75%%'
						ELSE '76-100%%'
					END AS bucket,
					COUNT(*) AS count,
					ROUND(AVG(rate) * 100, 1) AS avg_rate_pct
				FROM (
					SELECT
						CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.usage.conversion_rate_30d')) AS DECIMAL(5,4)) AS rate
					FROM {$table_name}
					WHERE data_type = 'usage'
					AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
					AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
					AND JSON_EXTRACT(payload, '$.usage.conversion_rate_30d') IS NOT NULL
					GROUP BY site_hash
					HAVING MAX(created_at)
				) AS latest
				GROUP BY bucket
				ORDER BY MIN(rate)",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get Connect gateway adoption rates.
	 *
	 * Returns the count and percentage of sites using Stripe Connect and/or PayPal Connect.
	 *
	 * @param int $days Number of days to look back.
	 * @return array {
	 *     @type int   $total_reporting      Sites reporting gateway data.
	 *     @type int   $stripe_connect       Sites with Stripe Connect enabled.
	 *     @type int   $paypal_connect       Sites with PayPal Connect enabled.
	 *     @type float $stripe_connect_pct   Percentage using Stripe Connect.
	 *     @type float $paypal_connect_pct   Percentage using PayPal Connect.
	 * }
	 */
	public static function get_connect_adoption(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_reporting,
					SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.gateways.stripe_connect_enabled')) = 'true' THEN 1 ELSE 0 END) AS stripe_connect,
					SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.gateways.paypal_connect_enabled')) = 'true' THEN 1 ELSE 0 END) AS paypal_connect
				FROM (
					SELECT payload
					FROM {$table_name}
					WHERE data_type = 'usage'
					AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
					AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
					AND (
						JSON_EXTRACT(payload, '$.gateways.stripe_connect_enabled') IS NOT NULL
						OR JSON_EXTRACT(payload, '$.gateways.paypal_connect_enabled') IS NOT NULL
					)
					GROUP BY site_hash
					HAVING MAX(created_at)
				) AS latest",
				$days
			),
			ARRAY_A
		);

		if (empty($row) || (int) $row['total_reporting'] === 0) {
			return [
				'total_reporting'    => 0,
				'stripe_connect'     => 0,
				'paypal_connect'     => 0,
				'stripe_connect_pct' => 0.0,
				'paypal_connect_pct' => 0.0,
			];
		}

		$total = (int) $row['total_reporting'];

		return [
			'total_reporting'    => $total,
			'stripe_connect'     => (int) $row['stripe_connect'],
			'paypal_connect'     => (int) $row['paypal_connect'],
			'stripe_connect_pct' => $total > 0 ? round(((int) $row['stripe_connect'] / $total) * 100, 1) : 0.0,
			'paypal_connect_pct' => $total > 0 ? round(((int) $row['paypal_connect'] / $total) * 100, 1) : 0.0,
		];
	}

	/**
	 * Get hosting provider distribution.
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['provider' => string, 'count' => int].
	 */
	public static function get_hosting_provider_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.hosting.provider')), 'Unknown') AS provider,
					COUNT(DISTINCT site_hash) AS count
				FROM {$table_name}
				WHERE data_type = 'usage'
				AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
				GROUP BY provider
				ORDER BY count DESC",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get active membership count distribution.
	 *
	 * Buckets: 0, 1-10, 11-50, 51-100, 101-500, 501-1000, 1001+
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['bucket' => string, 'count' => int].
	 */
	public static function get_membership_count_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN memberships = 0     THEN '0'
						WHEN memberships <= 10   THEN '1-10'
						WHEN memberships <= 50   THEN '11-50'
						WHEN memberships <= 100  THEN '51-100'
						WHEN memberships <= 500  THEN '101-500'
						WHEN memberships <= 1000 THEN '501-1000'
						ELSE '1001+'
					END AS bucket,
					COUNT(*) AS count
				FROM (
					SELECT
						CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.usage.active_memberships_exact')) AS UNSIGNED) AS memberships
					FROM {$table_name}
					WHERE data_type = 'usage'
					AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
					AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tracker_version')) >= '2.0.0'
					AND JSON_EXTRACT(payload, '$.usage.active_memberships_exact') IS NOT NULL
					GROUP BY site_hash
					HAVING MAX(created_at)
				) AS latest
				GROUP BY bucket
				ORDER BY MIN(memberships)",
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
