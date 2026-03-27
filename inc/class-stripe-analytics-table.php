<?php
/**
 * Stripe Analytics Database Tables
 *
 * Creates and manages the custom DB tables for storing Stripe Connect
 * analytics data synced from the Stripe API.
 *
 * Tables:
 *   wp_wu_stripe_analytics  - Daily per-account transaction aggregates
 *   wp_wu_stripe_accounts   - Connected account registry
 *
 * @package WP_Update_Server_Plugin
 * @since 1.0.0
 */

namespace WP_Update_Server_Plugin;

defined('ABSPATH') || exit;

/**
 * Stripe Analytics Table manager.
 */
class Stripe_Analytics_Table {

	/**
	 * Analytics table name (without prefix).
	 *
	 * @var string
	 */
	const ANALYTICS_TABLE = 'wu_stripe_analytics';

	/**
	 * Accounts table name (without prefix).
	 *
	 * @var string
	 */
	const ACCOUNTS_TABLE = 'wu_stripe_accounts';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('admin_init', [$this, 'maybe_create_tables']);
	}

	/**
	 * Get the full analytics table name with prefix.
	 *
	 * @return string
	 */
	public static function get_analytics_table(): string {

		global $wpdb;

		return $wpdb->prefix . self::ANALYTICS_TABLE;
	}

	/**
	 * Get the full accounts table name with prefix.
	 *
	 * @return string
	 */
	public static function get_accounts_table(): string {

		global $wpdb;

		return $wpdb->prefix . self::ACCOUNTS_TABLE;
	}

	/**
	 * Create tables if they don't exist.
	 *
	 * @return void
	 */
	public function maybe_create_tables(): void {

		global $wpdb;

		$analytics_table = self::get_analytics_table();
		$accounts_table  = self::get_accounts_table();
		$charset_collate = $wpdb->get_charset_collate();

		$analytics_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $analytics_table)
		);

		$accounts_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $accounts_table)
		);

		if ($analytics_exists === $analytics_table && $accounts_exists === $accounts_table) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ($analytics_exists !== $analytics_table) {
			$sql = "CREATE TABLE {$analytics_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				stripe_account_id VARCHAR(50) NOT NULL,
				period_date DATE NOT NULL,
				transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
				gross_volume BIGINT NOT NULL DEFAULT 0,
				application_fees BIGINT NOT NULL DEFAULT 0,
				refund_count INT UNSIGNED NOT NULL DEFAULT 0,
				refund_volume BIGINT NOT NULL DEFAULT 0,
				currency VARCHAR(3) NOT NULL DEFAULT 'usd',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY account_period (stripe_account_id, period_date, currency),
				KEY period_date (period_date)
			) {$charset_collate};";

			dbDelta($sql);
		}

		if ($accounts_exists !== $accounts_table) {
			$sql = "CREATE TABLE {$accounts_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				stripe_account_id VARCHAR(50) NOT NULL,
				business_name VARCHAR(255) DEFAULT NULL,
				email VARCHAR(255) DEFAULT NULL,
				country VARCHAR(2) DEFAULT NULL,
				charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
				payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
				has_addon_purchase TINYINT(1) NOT NULL DEFAULT 0,
				first_seen DATETIME DEFAULT NULL,
				last_transaction DATETIME DEFAULT NULL,
				total_volume BIGINT NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY stripe_account_id (stripe_account_id),
				KEY charges_enabled (charges_enabled),
				KEY last_transaction (last_transaction)
			) {$charset_collate};";

			dbDelta($sql);
		}
	}

	/**
	 * Upsert a daily analytics record for a connected account.
	 *
	 * @param string $stripe_account_id The Stripe connected account ID.
	 * @param string $period_date       Date string (YYYY-MM-DD).
	 * @param int    $transaction_count Number of charges.
	 * @param int    $gross_volume      Gross volume in cents.
	 * @param int    $application_fees  Application fees collected in cents.
	 * @param int    $refund_count      Number of refunds.
	 * @param int    $refund_volume     Refund volume in cents.
	 * @param string $currency          ISO currency code.
	 * @return int|false Inserted/updated row ID or false on failure.
	 */
	public static function upsert_analytics(
		string $stripe_account_id,
		string $period_date,
		int $transaction_count,
		int $gross_volume,
		int $application_fees,
		int $refund_count,
		int $refund_volume,
		string $currency = 'usd'
	) {
		global $wpdb;

		$table = self::get_analytics_table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(stripe_account_id, period_date, transaction_count, gross_volume, application_fees, refund_count, refund_volume, currency)
				VALUES (%s, %s, %d, %d, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE
					transaction_count = VALUES(transaction_count),
					gross_volume      = VALUES(gross_volume),
					application_fees  = VALUES(application_fees),
					refund_count      = VALUES(refund_count),
					refund_volume     = VALUES(refund_volume)",
				$stripe_account_id,
				$period_date,
				$transaction_count,
				$gross_volume,
				$application_fees,
				$refund_count,
				$refund_volume,
				$currency
			)
		);

		if (false === $result) {
			return false;
		}

		return $wpdb->insert_id ?: true;
	}

	/**
	 * Upsert a connected account record.
	 *
	 * @param string      $stripe_account_id The Stripe account ID.
	 * @param string|null $business_name     Business display name.
	 * @param string|null $email             Account email.
	 * @param string|null $country           Two-letter country code.
	 * @param bool        $charges_enabled   Whether charges are enabled.
	 * @param bool        $payouts_enabled   Whether payouts are enabled.
	 * @return bool
	 */
	public static function upsert_account(
		string $stripe_account_id,
		?string $business_name,
		?string $email,
		?string $country,
		bool $charges_enabled,
		bool $payouts_enabled
	): bool {
		global $wpdb;

		$table = self::get_accounts_table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(stripe_account_id, business_name, email, country, charges_enabled, payouts_enabled, first_seen)
				VALUES (%s, %s, %s, %s, %d, %d, NOW())
				ON DUPLICATE KEY UPDATE
					business_name    = VALUES(business_name),
					email            = VALUES(email),
					country          = VALUES(country),
					charges_enabled  = VALUES(charges_enabled),
					payouts_enabled  = VALUES(payouts_enabled)",
				$stripe_account_id,
				$business_name,
				$email,
				$country,
				(int) $charges_enabled,
				(int) $payouts_enabled
			)
		);

		return false !== $result;
	}

	/**
	 * Update the total_volume and last_transaction for an account.
	 *
	 * @param string $stripe_account_id The Stripe account ID.
	 * @param int    $total_volume      Total lifetime volume in cents.
	 * @return bool
	 */
	public static function update_account_volume(string $stripe_account_id, int $total_volume): bool {

		global $wpdb;

		$table = self::get_accounts_table();

		$result = $wpdb->update(
			$table,
			[
				'total_volume'     => $total_volume,
				'last_transaction' => current_time('mysql'),
			],
			['stripe_account_id' => $stripe_account_id],
			['%d', '%s'],
			['%s']
		);

		return false !== $result;
	}

	/**
	 * Get total platform volume for a period.
	 *
	 * @param int    $days     Number of days to look back.
	 * @param string $currency Currency filter.
	 * @return array{gross_volume: int, application_fees: int, transaction_count: int, refund_volume: int}
	 */
	public static function get_platform_totals(int $days = 30, string $currency = 'usd'): array {

		global $wpdb;

		$table = self::get_analytics_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(gross_volume), 0)      AS gross_volume,
					COALESCE(SUM(application_fees), 0)  AS application_fees,
					COALESCE(SUM(transaction_count), 0) AS transaction_count,
					COALESCE(SUM(refund_volume), 0)     AS refund_volume
				FROM {$table}
				WHERE period_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  AND currency = %s",
				$days,
				$currency
			),
			ARRAY_A
		);

		return $row ?: [
			'gross_volume'      => 0,
			'application_fees'  => 0,
			'transaction_count' => 0,
			'refund_volume'     => 0,
		];
	}

	/**
	 * Get daily volume trend data.
	 *
	 * @param int    $days     Number of days to look back.
	 * @param string $currency Currency filter.
	 * @return array<int, array{period_date: string, gross_volume: int, application_fees: int, transaction_count: int}>
	 */
	public static function get_daily_trends(int $days = 30, string $currency = 'usd'): array {

		global $wpdb;

		$table = self::get_analytics_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					period_date,
					SUM(gross_volume)      AS gross_volume,
					SUM(application_fees)  AS application_fees,
					SUM(transaction_count) AS transaction_count
				FROM {$table}
				WHERE period_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  AND currency = %s
				GROUP BY period_date
				ORDER BY period_date ASC",
				$days,
				$currency
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Get top accounts by volume.
	 *
	 * @param int    $limit    Maximum number of accounts to return.
	 * @param int    $days     Number of days to look back.
	 * @param string $currency Currency filter.
	 * @return array<int, array{stripe_account_id: string, business_name: string, gross_volume: int, application_fees: int, transaction_count: int}>
	 */
	public static function get_top_accounts(int $limit = 10, int $days = 30, string $currency = 'usd'): array {

		global $wpdb;

		$analytics = self::get_analytics_table();
		$accounts  = self::get_accounts_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					a.stripe_account_id,
					acc.business_name,
					acc.country,
					SUM(a.gross_volume)      AS gross_volume,
					SUM(a.application_fees)  AS application_fees,
					SUM(a.transaction_count) AS transaction_count
				FROM {$analytics} a
				LEFT JOIN {$accounts} acc ON acc.stripe_account_id = a.stripe_account_id
				WHERE a.period_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  AND a.currency = %s
				GROUP BY a.stripe_account_id, acc.business_name, acc.country
				ORDER BY gross_volume DESC
				LIMIT %d",
				$days,
				$currency,
				$limit
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Get connected account counts.
	 *
	 * @param int $active_days Days threshold to consider an account "active".
	 * @return array{total: int, active: int, charges_enabled: int}
	 */
	public static function get_account_counts(int $active_days = 30): array {

		global $wpdb;

		$table = self::get_accounts_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*)                                                                                AS total,
					SUM(CASE WHEN last_transaction >= DATE_SUB(NOW(), INTERVAL %d DAY) THEN 1 ELSE 0 END) AS active,
					SUM(charges_enabled)                                                                   AS charges_enabled
				FROM {$table}",
				$active_days
			),
			ARRAY_A
		);

		return [
			'total'           => (int) ($row['total'] ?? 0),
			'active'          => (int) ($row['active'] ?? 0),
			'charges_enabled' => (int) ($row['charges_enabled'] ?? 0),
		];
	}

	/**
	 * Get fee waiver impact: volume from accounts with addon purchases.
	 *
	 * @param int    $days     Number of days to look back.
	 * @param string $currency Currency filter.
	 * @return array{waived_volume: int, waived_fees_estimate: int, waived_account_count: int}
	 */
	public static function get_fee_waiver_impact(int $days = 30, string $currency = 'usd'): array {

		global $wpdb;

		$analytics = self::get_analytics_table();
		$accounts  = self::get_accounts_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(a.gross_volume), 0)     AS waived_volume,
					COALESCE(SUM(a.application_fees), 0) AS waived_fees_estimate,
					COUNT(DISTINCT a.stripe_account_id)  AS waived_account_count
				FROM {$analytics} a
				INNER JOIN {$accounts} acc ON acc.stripe_account_id = a.stripe_account_id
				WHERE acc.has_addon_purchase = 1
				  AND a.period_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  AND a.currency = %s",
				$days,
				$currency
			),
			ARRAY_A
		);

		return [
			'waived_volume'         => (int) ($row['waived_volume'] ?? 0),
			'waived_fees_estimate'  => (int) ($row['waived_fees_estimate'] ?? 0),
			'waived_account_count'  => (int) ($row['waived_account_count'] ?? 0),
		];
	}
}
