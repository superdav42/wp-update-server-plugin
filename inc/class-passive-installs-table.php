<?php
/**
 * Passive Installs Database Table
 *
 * Handles the custom database table for storing passive install tracking data
 * captured from update check requests.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Passive_Installs_Table {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wu_passive_installs';

	/**
	 * Auto-purge records older than this many months.
	 *
	 * @var int
	 */
	const PURGE_MONTHS = 12;

	/**
	 * Constructor.
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
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_url VARCHAR(255) NOT NULL,
			ip_address VARCHAR(45) NOT NULL,
			domain VARCHAR(255) DEFAULT NULL,
			wp_version VARCHAR(20) DEFAULT NULL,
			slug_requested VARCHAR(100) NOT NULL,
			is_authenticated TINYINT(1) NOT NULL DEFAULT 0,
			user_agent TEXT DEFAULT NULL,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			check_count INT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY site_url_slug (site_url(191), slug_requested),
			KEY domain (domain(191)),
			KEY last_seen (last_seen),
			KEY is_authenticated (is_authenticated),
			KEY slug_requested (slug_requested)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta($sql);
	}

	/**
	 * Upsert a passive install record.
	 *
	 * On first encounter, inserts a new row. On subsequent checks from the same
	 * (site_url, slug_requested) pair, updates last_seen and increments check_count.
	 *
	 * @param string $site_url       The site URL parsed from User-Agent or empty string.
	 * @param string $ip_address     The requesting IP address.
	 * @param string $slug_requested The plugin/addon slug being checked.
	 * @param bool   $is_authenticated Whether the request carried a valid OAuth token.
	 * @param string $wp_version     WordPress version parsed from User-Agent.
	 * @param string $user_agent     Raw User-Agent header value.
	 * @return int|false Inserted/updated row ID, or false on failure.
	 */
	public static function upsert(
		string $site_url,
		string $ip_address,
		string $slug_requested,
		bool $is_authenticated,
		string $wp_version = '',
		string $user_agent = ''
	) {
		global $wpdb;

		$table_name = self::get_table_name();
		$now        = current_time('mysql');

		// Attempt INSERT first; on duplicate key update counters.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name}
					(site_url, ip_address, domain, wp_version, slug_requested, is_authenticated, user_agent, first_seen, last_seen, check_count)
				VALUES
					(%s, %s, NULL, %s, %s, %d, %s, %s, %s, 1)
				ON DUPLICATE KEY UPDATE
					last_seen = VALUES(last_seen),
					check_count = check_count + 1,
					is_authenticated = GREATEST(is_authenticated, VALUES(is_authenticated)),
					user_agent = COALESCE(VALUES(user_agent), user_agent),
					wp_version = COALESCE(NULLIF(VALUES(wp_version), ''), wp_version)",
				$site_url,
				$ip_address,
				$wp_version ?: null,
				$slug_requested,
				$is_authenticated ? 1 : 0,
				$user_agent ?: null,
				$now,
				$now
			)
		);

		if (false === $result) {
			return false;
		}

		// For INSERT: insert_id is the new row. For UPDATE: insert_id is 0 on some MySQL
		// versions; fetch the actual row id.
		if ($wpdb->insert_id > 0) {
			return $wpdb->insert_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE site_url = %s AND slug_requested = %s LIMIT 1",
				$site_url,
				$slug_requested
			)
		);
	}

	/**
	 * Update the domain field for a row identified by IP address (used by DNS backfill cron).
	 *
	 * @param string $ip_address The IP address to look up.
	 * @param string $domain     The resolved domain name.
	 * @return int Number of rows updated.
	 */
	public static function update_domain_by_ip(string $ip_address, string $domain): int {

		global $wpdb;

		return (int) $wpdb->update(
			self::get_table_name(),
			['domain' => $domain],
			['ip_address' => $ip_address],
			['%s'],
			['%s']
		);
	}

	/**
	 * Get IPs that have no domain resolved yet (for DNS backfill).
	 *
	 * @param int $limit Maximum number of IPs to return.
	 * @return string[] List of distinct IP addresses.
	 */
	public static function get_ips_without_domain(int $limit = 200): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT ip_address FROM {$table_name} WHERE domain IS NULL LIMIT %d",
				$limit
			)
		);

		return $results ?: [];
	}

	/**
	 * Get unique install count (distinct site_url values).
	 *
	 * @param int|null $days Optional look-back window in days.
	 * @return int
	 */
	public static function get_unique_install_count(?int $days = null): int {

		global $wpdb;

		$table_name = self::get_table_name();
		$where      = '';

		if ($days !== null) {
			$where = $wpdb->prepare(
				' WHERE last_seen >= DATE_SUB(NOW(), INTERVAL %d DAY)',
				$days
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var("SELECT COUNT(DISTINCT site_url) FROM {$table_name}{$where}");
	}

	/**
	 * Get unique install count for authenticated installs only.
	 *
	 * @param int|null $days Optional look-back window in days.
	 * @return int
	 */
	public static function get_authenticated_install_count(?int $days = null): int {

		global $wpdb;

		$table_name = self::get_table_name();
		$where      = 'WHERE is_authenticated = 1';

		if ($days !== null) {
			$where .= $wpdb->prepare(
				' AND last_seen >= DATE_SUB(NOW(), INTERVAL %d DAY)',
				$days
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var("SELECT COUNT(DISTINCT site_url) FROM {$table_name} {$where}");
	}

	/**
	 * Get slug popularity (how many unique sites checked for each slug).
	 *
	 * @param int $days  Number of days to look back.
	 * @param int $limit Maximum rows to return.
	 * @return array Array of ['slug_requested' => string, 'unique_sites' => int].
	 */
	public static function get_slug_distribution(int $days = 30, int $limit = 50): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					slug_requested,
					COUNT(DISTINCT site_url) AS unique_sites,
					SUM(check_count) AS total_checks
				FROM {$table_name}
				WHERE last_seen >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY slug_requested
				ORDER BY unique_sites DESC
				LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get WordPress version distribution from passive installs.
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of ['wp_version' => string, 'count' => int].
	 */
	public static function get_wp_version_distribution(int $days = 30): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					wp_version,
					COUNT(DISTINCT site_url) AS count
				FROM {$table_name}
				WHERE wp_version IS NOT NULL
				AND last_seen >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY wp_version
				ORDER BY count DESC",
				$days
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get recent passive install records for the admin table view.
	 *
	 * @param int $limit  Number of records to return.
	 * @param int $offset Pagination offset.
	 * @return array
	 */
	public static function get_recent_installs(int $limit = 50, int $offset = 0): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					site_url,
					ip_address,
					domain,
					wp_version,
					slug_requested,
					is_authenticated,
					first_seen,
					last_seen,
					check_count
				FROM {$table_name}
				ORDER BY last_seen DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Purge records older than PURGE_MONTHS months.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function purge_old_records(): int {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE last_seen < DATE_SUB(NOW(), INTERVAL %d MONTH)",
				self::PURGE_MONTHS
			)
		);
	}
}
