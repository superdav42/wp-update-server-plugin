<?php
/**
 * Site Discovery Database Table
 *
 * Handles the custom database table for storing site discovery / network health data.
 * Builds on passive install tracking (issue #3) to understand how many subsites
 * each network is running and whether they are production businesses.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Site_Discovery_Table {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wu_site_discovery';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'admin_init', [ $this, 'maybe_create_table' ] );
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
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		if ( $table_exists === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			domain varchar(255) NOT NULL,
			is_live tinyint(1) NOT NULL DEFAULT 0,
			is_production tinyint(1) NOT NULL DEFAULT 0,
			has_ssl tinyint(1) NOT NULL DEFAULT 0,
			has_checkout tinyint(1) NOT NULL DEFAULT 0,
			detected_subsites int(11) NOT NULL DEFAULT 0,
			network_type enum('subdomain','subdirectory','unknown') NOT NULL DEFAULT 'unknown',
			detected_um_version varchar(20) DEFAULT NULL,
			health_score tinyint(3) unsigned NOT NULL DEFAULT 0,
			last_scraped datetime DEFAULT NULL,
			scrape_status enum('pending','success','failed','blocked') NOT NULL DEFAULT 'pending',
			raw_data longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY domain (domain(191)),
			KEY scrape_status (scrape_status),
			KEY health_score (health_score),
			KEY last_scraped (last_scraped)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}

	/**
	 * Upsert a domain into the discovery table.
	 *
	 * Inserts a new row or updates the existing one for the given domain.
	 *
	 * @param string $domain The domain to upsert.
	 * @param array  $data   Associative array of column => value pairs.
	 * @return int|false The row ID on success, false on failure.
	 */
	public static function upsert( string $domain, array $data = [] ) {

		global $wpdb;

		$table_name = self::get_table_name();

		// Check if domain already exists.
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE domain = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$domain
			)
		);

		$row = array_merge(
			[ 'domain' => $domain ],
			$data
		);

		if ( $existing_id ) {
			unset( $row['created_at'] );
			$wpdb->update(
				$table_name,
				$row,
				[ 'id' => $existing_id ]
			);
			return (int) $existing_id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$result            = $wpdb->insert( $table_name, $row );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get domains pending scraping, ordered by priority.
	 *
	 * Authenticated (addon purchaser) domains are prioritised first,
	 * then by recency of last passive install check.
	 *
	 * @param int $limit Maximum number of domains to return.
	 * @return array
	 */
	public static function get_pending_domains( int $limit = 50 ): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, domain
				FROM {$table_name}
				WHERE scrape_status IN ('pending', 'failed')
				   OR last_scraped < DATE_SUB(NOW(), INTERVAL 24 HOUR)
				ORDER BY
					CASE WHEN scrape_status = 'pending' THEN 0 ELSE 1 END ASC,
					last_scraped ASC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Update the scrape result for a domain.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status One of: success, failed, blocked.
	 * @param array  $data   Scrape result data.
	 * @return bool
	 */
	public static function update_scrape_result( int $id, string $status, array $data = [] ): bool {

		global $wpdb;

		$table_name = self::get_table_name();

		$row = array_merge(
			$data,
			[
				'scrape_status' => $status,
				'last_scraped'  => current_time( 'mysql' ),
			]
		);

		$result = $wpdb->update(
			$table_name,
			$row,
			[ 'id' => $id ]
		);

		return false !== $result;
	}

	/**
	 * Get health score distribution.
	 *
	 * Returns counts bucketed into score bands: 0-19, 20-39, 40-59, 60-79, 80-100.
	 *
	 * @return array
	 */
	public static function get_health_score_distribution(): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT
				CASE
					WHEN health_score < 20  THEN '0-19'
					WHEN health_score < 40  THEN '20-39'
					WHEN health_score < 60  THEN '40-59'
					WHEN health_score < 80  THEN '60-79'
					ELSE '80-100'
				END AS score_band,
				COUNT(*) AS count
			FROM {$table_name}
			WHERE scrape_status = 'success'
			GROUP BY score_band
			ORDER BY score_band ASC",
			ARRAY_A
		);
	}

	/**
	 * Get count of production networks with at least N subsites.
	 *
	 * @param int $min_subsites Minimum subsite count.
	 * @return int
	 */
	public static function get_production_networks_with_subsites( int $min_subsites = 10 ): int {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$table_name}
				WHERE is_production = 1
				  AND detected_subsites >= %d
				  AND scrape_status = 'success'",
				$min_subsites
			)
		);
	}

	/**
	 * Get count of networks with a checkout page.
	 *
	 * @return int
	 */
	public static function get_networks_with_checkout(): int {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$table_name}
			WHERE has_checkout = 1
			  AND scrape_status = 'success'"
		);
	}

	/**
	 * Get summary statistics for the dashboard.
	 *
	 * @return array
	 */
	public static function get_summary_stats(): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total_domains,
				SUM(CASE WHEN scrape_status = 'success' THEN 1 ELSE 0 END) AS scraped,
				SUM(CASE WHEN scrape_status = 'pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN scrape_status = 'failed'  THEN 1 ELSE 0 END) AS failed,
				SUM(CASE WHEN scrape_status = 'blocked' THEN 1 ELSE 0 END) AS blocked,
				SUM(CASE WHEN is_live = 1 THEN 1 ELSE 0 END) AS live,
				SUM(CASE WHEN is_production = 1 THEN 1 ELSE 0 END) AS production,
				SUM(CASE WHEN has_ssl = 1 THEN 1 ELSE 0 END) AS has_ssl,
				SUM(CASE WHEN has_checkout = 1 THEN 1 ELSE 0 END) AS has_checkout,
				AVG(health_score) AS avg_health_score,
				MAX(health_score) AS max_health_score
			FROM {$table_name}",
			ARRAY_A
		);

		return $row ?: [];
	}

	/**
	 * Get recent scrape results for the dashboard table.
	 *
	 * @param int $limit Number of rows to return.
	 * @return array
	 */
	public static function get_recent_results( int $limit = 50 ): array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id, domain, is_live, is_production, has_ssl, has_checkout,
					detected_subsites, network_type, detected_um_version,
					health_score, scrape_status, last_scraped
				FROM {$table_name}
				WHERE scrape_status = 'success'
				ORDER BY health_score DESC, last_scraped DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Queue a domain for scraping if it is not already tracked.
	 *
	 * Called from the passive install tracker when a new domain is discovered.
	 *
	 * @param string $domain The domain to queue.
	 * @return void
	 */
	public static function queue_domain( string $domain ): void {

		if ( empty( $domain ) ) {
			return;
		}

		// Normalise: strip scheme and trailing slash.
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = rtrim( $domain, '/' );
		$domain = strtolower( $domain );

		if ( empty( $domain ) ) {
			return;
		}

		self::upsert( $domain, [ 'scrape_status' => 'pending' ] );
	}
}
