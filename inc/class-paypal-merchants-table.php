<?php
/**
 * PayPal Merchants Analytics Database Tables.
 *
 * Manages two tables:
 *   - wp_wu_paypal_merchants  — one row per onboarded merchant
 *   - wp_wu_paypal_analytics  — daily aggregated partner-fee data per merchant
 *
 * @package WP_Update_Server_Plugin
 * @since 1.0.0
 */

namespace WP_Update_Server_Plugin;

defined('ABSPATH') || exit;

/**
 * PayPal merchants analytics table manager.
 */
class PayPal_Merchants_Table {

	/**
	 * Merchants table name (without prefix).
	 *
	 * @var string
	 */
	const MERCHANTS_TABLE = 'wu_paypal_merchants';

	/**
	 * Analytics table name (without prefix).
	 *
	 * @var string
	 */
	const ANALYTICS_TABLE = 'wu_paypal_analytics';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('admin_init', [$this, 'maybe_create_tables']);
	}

	/**
	 * Get the full merchants table name with prefix.
	 *
	 * @return string
	 */
	public static function get_merchants_table(): string {

		global $wpdb;

		return $wpdb->prefix . self::MERCHANTS_TABLE;
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
	 * Create tables if they do not exist.
	 *
	 * @return void
	 */
	public function maybe_create_tables(): void {

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$merchants_table = self::get_merchants_table();
		$analytics_table = self::get_analytics_table();

		$merchants_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $merchants_table)
		);

		$analytics_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $analytics_table)
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ($merchants_exists !== $merchants_table) {
			$sql = "CREATE TABLE {$merchants_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				merchant_id VARCHAR(50) NOT NULL,
				tracking_id VARCHAR(100) DEFAULT NULL,
				test_mode TINYINT(1) NOT NULL DEFAULT 0,
				status ENUM('onboarded','active','disconnected') NOT NULL DEFAULT 'onboarded',
				onboarded_at DATETIME NOT NULL,
				disconnected_at DATETIME DEFAULT NULL,
				last_transaction DATETIME DEFAULT NULL,
				total_volume BIGINT NOT NULL DEFAULT 0,
				currency VARCHAR(3) NOT NULL DEFAULT 'USD',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY merchant_id_mode (merchant_id, test_mode),
				KEY status (status)
			) {$charset_collate};";

			dbDelta($sql);
		}

		if ($analytics_exists !== $analytics_table) {
			$sql = "CREATE TABLE {$analytics_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				merchant_id VARCHAR(50) NOT NULL,
				period_date DATE NOT NULL,
				transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
				gross_volume BIGINT NOT NULL DEFAULT 0,
				partner_fees BIGINT NOT NULL DEFAULT 0,
				currency VARCHAR(3) NOT NULL DEFAULT 'USD',
				PRIMARY KEY (id),
				UNIQUE KEY merchant_period (merchant_id, period_date, currency),
				KEY period_date (period_date)
			) {$charset_collate};";

			dbDelta($sql);
		}
	}

	// -------------------------------------------------------------------------
	// Merchant record helpers
	// -------------------------------------------------------------------------

	/**
	 * Upsert a merchant record on successful onboarding.
	 *
	 * @param string $merchant_id PayPal merchant/payer ID.
	 * @param string $tracking_id Our internal tracking UUID.
	 * @param bool   $test_mode   Whether this is a sandbox merchant.
	 * @return int|false Inserted/updated row ID or false on failure.
	 */
	public static function upsert_merchant(string $merchant_id, string $tracking_id, bool $test_mode) {

		global $wpdb;

		$table = self::get_merchants_table();

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, status FROM {$table} WHERE merchant_id = %s AND test_mode = %d",
				$merchant_id,
				(int) $test_mode
			)
		);

		if ($existing) {
			// Re-onboarding a previously disconnected merchant → set back to onboarded.
			$wpdb->update(
				$table,
				[
					'tracking_id'      => $tracking_id,
					'status'           => 'onboarded',
					'disconnected_at'  => null,
					'onboarded_at'     => current_time('mysql'),
				],
				[
					'merchant_id' => $merchant_id,
					'test_mode'   => (int) $test_mode,
				],
				['%s', '%s', null, '%s'],
				['%s', '%d']
			);

			return (int) $existing->id;
		}

		$result = $wpdb->insert(
			$table,
			[
				'merchant_id'  => $merchant_id,
				'tracking_id'  => $tracking_id,
				'test_mode'    => (int) $test_mode,
				'status'       => 'onboarded',
				'onboarded_at' => current_time('mysql'),
			],
			['%s', '%s', '%d', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Mark a merchant as disconnected.
	 *
	 * @param string $merchant_id PayPal merchant/payer ID.
	 * @param bool   $test_mode   Whether this is a sandbox merchant.
	 * @return bool
	 */
	public static function mark_disconnected(string $merchant_id, bool $test_mode): bool {

		global $wpdb;

		$table = self::get_merchants_table();

		$result = $wpdb->update(
			$table,
			[
				'status'          => 'disconnected',
				'disconnected_at' => current_time('mysql'),
			],
			[
				'merchant_id' => $merchant_id,
				'test_mode'   => (int) $test_mode,
			],
			['%s', '%s'],
			['%s', '%d']
		);

		return false !== $result;
	}

	/**
	 * Mark a merchant as active and update last_transaction timestamp.
	 *
	 * @param string $merchant_id PayPal merchant/payer ID.
	 * @param bool   $test_mode   Whether this is a sandbox merchant.
	 * @return bool
	 */
	public static function mark_active(string $merchant_id, bool $test_mode): bool {

		global $wpdb;

		$table = self::get_merchants_table();

		$result = $wpdb->update(
			$table,
			[
				'status'           => 'active',
				'last_transaction' => current_time('mysql'),
			],
			[
				'merchant_id' => $merchant_id,
				'test_mode'   => (int) $test_mode,
			],
			['%s', '%s'],
			['%s', '%d']
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Analytics record helpers
	// -------------------------------------------------------------------------

	/**
	 * Upsert a daily analytics row for a merchant.
	 *
	 * Amounts are stored in the smallest currency unit (e.g. cents for USD).
	 *
	 * @param string $merchant_id       PayPal merchant/payer ID.
	 * @param string $period_date       Date string in YYYY-MM-DD format.
	 * @param int    $transaction_count Number of transactions.
	 * @param int    $gross_volume      Gross volume in smallest currency unit.
	 * @param int    $partner_fees      Partner fees in smallest currency unit.
	 * @param string $currency          ISO 4217 currency code.
	 * @return bool
	 */
	public static function upsert_analytics(
		string $merchant_id,
		string $period_date,
		int $transaction_count,
		int $gross_volume,
		int $partner_fees,
		string $currency = 'USD'
	): bool {

		global $wpdb;

		$table = self::get_analytics_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(merchant_id, period_date, transaction_count, gross_volume, partner_fees, currency)
				VALUES
					(%s, %s, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE
					transaction_count = VALUES(transaction_count),
					gross_volume      = VALUES(gross_volume),
					partner_fees      = VALUES(partner_fees)",
				$merchant_id,
				$period_date,
				$transaction_count,
				$gross_volume,
				$partner_fees,
				$currency
			)
		);

		return false !== $result;
	}

	// -------------------------------------------------------------------------
	// Query helpers for the dashboard
	// -------------------------------------------------------------------------

	/**
	 * Get total merchant counts by status and mode.
	 *
	 * @return array{live_onboarded: int, live_active: int, live_disconnected: int, sandbox_onboarded: int, sandbox_active: int, sandbox_disconnected: int}
	 */
	public static function get_merchant_counts(): array {

		global $wpdb;

		$table = self::get_merchants_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT test_mode, status, COUNT(*) as cnt FROM {$table} GROUP BY test_mode, status",
			ARRAY_A
		);

		$counts = [
			'live_onboarded'      => 0,
			'live_active'         => 0,
			'live_disconnected'   => 0,
			'sandbox_onboarded'   => 0,
			'sandbox_active'      => 0,
			'sandbox_disconnected' => 0,
		];

		foreach ($rows as $row) {
			$prefix = $row['test_mode'] ? 'sandbox' : 'live';
			$key    = $prefix . '_' . $row['status'];

			if (isset($counts[ $key ])) {
				$counts[ $key ] = (int) $row['cnt'];
			}
		}

		return $counts;
	}

	/**
	 * Get aggregated platform partner fees for a period.
	 *
	 * @param int    $days     Number of days to look back.
	 * @param string $currency ISO 4217 currency code.
	 * @return array{total_transactions: int, gross_volume: int, partner_fees: int}
	 */
	public static function get_platform_totals(int $days = 30, string $currency = 'USD'): array {

		global $wpdb;

		$table = self::get_analytics_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(transaction_count) as total_transactions,
					SUM(gross_volume)      as gross_volume,
					SUM(partner_fees)      as partner_fees
				FROM {$table}
				WHERE currency = %s
				AND period_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$currency,
				$days
			),
			ARRAY_A
		);

		return [
			'total_transactions' => (int) ($row['total_transactions'] ?? 0),
			'gross_volume'       => (int) ($row['gross_volume'] ?? 0),
			'partner_fees'       => (int) ($row['partner_fees'] ?? 0),
		];
	}

	/**
	 * Get per-merchant analytics summary for a period.
	 *
	 * @param int $days   Number of days to look back.
	 * @param int $limit  Maximum rows to return.
	 * @return array
	 */
	public static function get_merchant_analytics(int $days = 30, int $limit = 50): array {

		global $wpdb;

		$analytics_table = self::get_analytics_table();
		$merchants_table = self::get_merchants_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					a.merchant_id,
					m.status,
					m.test_mode,
					m.onboarded_at,
					m.last_transaction,
					SUM(a.transaction_count) as total_transactions,
					SUM(a.gross_volume)      as gross_volume,
					SUM(a.partner_fees)      as partner_fees,
					a.currency
				FROM {$analytics_table} a
				LEFT JOIN {$merchants_table} m ON m.merchant_id = a.merchant_id AND m.test_mode = 0
				WHERE a.period_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY a.merchant_id, a.currency
				ORDER BY gross_volume DESC
				LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Get recent merchant onboarding events.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array
	 */
	public static function get_recent_merchants(int $limit = 20): array {

		global $wpdb;

		$table = self::get_merchants_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					id,
					merchant_id,
					tracking_id,
					test_mode,
					status,
					onboarded_at,
					disconnected_at,
					last_transaction,
					total_volume,
					currency
				FROM {$table}
				ORDER BY onboarded_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $results ?: [];
	}
}
