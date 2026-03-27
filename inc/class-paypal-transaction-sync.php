<?php
/**
 * PayPal Transaction Sync.
 *
 * Performs a daily sync of partner-fee transaction data from the PayPal
 * Transaction Search API (/v1/reporting/transactions) and stores aggregated
 * results in wp_wu_paypal_analytics.
 *
 * Scheduled via WP-Cron (daily). Can also be triggered manually via WP-CLI
 * or the admin dashboard.
 *
 * @package WP_Update_Server_Plugin
 * @since 1.0.0
 */

namespace WP_Update_Server_Plugin;

defined('ABSPATH') || exit;

/**
 * PayPal transaction sync class.
 */
class PayPal_Transaction_Sync {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wu_paypal_transaction_sync';

	/**
	 * Option key for last successful sync timestamp.
	 *
	 * @var string
	 */
	const LAST_SYNC_OPTION = 'wu_paypal_last_sync';

	/**
	 * Maximum date range per API request (PayPal limit: 31 days).
	 *
	 * @var int
	 */
	const MAX_DAYS_PER_REQUEST = 31;

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action(self::CRON_HOOK, [$this, 'run_sync']);
		add_action('admin_init', [$this, 'schedule_cron']);
	}

	/**
	 * Schedule the daily cron job if not already scheduled.
	 *
	 * @return void
	 */
	public function schedule_cron(): void {

		if ( ! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time(), 'daily', self::CRON_HOOK);
		}
	}

	/**
	 * Run the transaction sync for yesterday (default daily run).
	 *
	 * @param string|null $date_override Optional YYYY-MM-DD date to sync instead of yesterday.
	 * @return array{synced: int, errors: string[]} Sync result summary.
	 */
	public function run_sync(?string $date_override = null): array {

		$date = $date_override ?? gmdate('Y-m-d', strtotime('-1 day'));

		$result = [
			'synced' => 0,
			'errors' => [],
		];

		// Sync live and sandbox separately.
		foreach ([false, true] as $test_mode) {
			$mode_result = $this->sync_for_date($date, $test_mode);

			$result['synced'] += $mode_result['synced'];

			if ( ! empty($mode_result['error'])) {
				$result['errors'][] = ($test_mode ? '[sandbox] ' : '[live] ') . $mode_result['error'];
			}
		}

		if (empty($result['errors'])) {
			update_option(self::LAST_SYNC_OPTION, current_time('mysql'));
		}

		return $result;
	}

	/**
	 * Sync partner-fee transactions for a specific date and mode.
	 *
	 * @param string $date      Date in YYYY-MM-DD format.
	 * @param bool   $test_mode Whether to use sandbox credentials.
	 * @return array{synced: int, error: string}
	 */
	protected function sync_for_date(string $date, bool $test_mode): array {

		$result = ['synced' => 0, 'error' => ''];

		// Resolve partner credentials via PayPal_Connect.
		$connect     = new PayPal_Connect();
		$access_token = $connect->get_partner_access_token($test_mode);

		if (is_wp_error($access_token)) {
			$result['error'] = $access_token->get_error_message();

			return $result;
		}

		$credentials = $connect->get_partner_credentials($test_mode);

		if (empty($credentials['merchant_id'])) {
			// Partner merchant ID required for transaction search.
			$result['error'] = 'Partner merchant ID not configured — cannot query Transaction Search API.';

			return $result;
		}

		$api_base = $connect->get_api_base_url($test_mode);

		// Build date range: full UTC day.
		$start_time = $date . 'T00:00:00-0000';
		$end_time   = $date . 'T23:59:59-0000';

		$page       = 1;
		$page_size  = 500;
		$aggregated = [];

		do {
			$url = add_query_arg(
				[
					'start_date'        => $start_time,
					'end_date'          => $end_time,
					'transaction_type'  => 'T0007', // Partner fee transactions
					'fields'            => 'transaction_info,payer_info',
					'page_size'         => $page_size,
					'page'              => $page,
				],
				$api_base . '/v1/reporting/transactions'
			);

			$response = wp_remote_get(
				$url,
				[
					'headers' => [
						'Authorization'                 => 'Bearer ' . $access_token,
						'Content-Type'                  => 'application/json',
						'PayPal-Partner-Attribution-Id' => PayPal_Connect::BN_CODE,
					],
					'timeout' => 30,
				]
			);

			if (is_wp_error($response)) {
				$result['error'] = 'HTTP error: ' . $response->get_error_message();

				return $result;
			}

			$resp_code = wp_remote_retrieve_response_code($response);
			$resp_body = json_decode(wp_remote_retrieve_body($response), true);

			if (200 !== $resp_code) {
				// 403 typically means the partner doesn't have Transaction Search scope yet.
				if (403 === $resp_code) {
					$result['error'] = 'Transaction Search API access not granted for this partner account (HTTP 403). Request TRANSACTION_SEARCH scope from PayPal.';
				} else {
					$result['error'] = sprintf(
						'Transaction Search API returned HTTP %d: %s',
						$resp_code,
						$resp_body['message'] ?? 'unknown error'
					);
				}

				return $result;
			}

			$transactions = $resp_body['transaction_details'] ?? [];

			foreach ($transactions as $txn) {
				$info        = $txn['transaction_info'] ?? [];
				$payer_info  = $txn['payer_info'] ?? [];
				$merchant_id = $payer_info['payer_id'] ?? '';

				if (empty($merchant_id)) {
					continue;
				}

				$amount_value = (float) ($info['transaction_amount']['value'] ?? 0);
				$fee_value    = (float) ($info['fee_amount']['value'] ?? 0);
				$currency     = $info['transaction_amount']['currency_code'] ?? 'USD';

				// Convert to smallest unit (cents).
				$amount_cents = (int) round(abs($amount_value) * 100);
				$fee_cents    = (int) round(abs($fee_value) * 100);

				$key = $merchant_id . '|' . $currency;

				if ( ! isset($aggregated[ $key ])) {
					$aggregated[ $key ] = [
						'merchant_id'       => $merchant_id,
						'currency'          => $currency,
						'transaction_count' => 0,
						'gross_volume'      => 0,
						'partner_fees'      => 0,
					];
				}

				$aggregated[ $key ]['transaction_count']++;
				$aggregated[ $key ]['gross_volume'] += $amount_cents;
				$aggregated[ $key ]['partner_fees'] += $fee_cents;
			}

			$total_pages = (int) ($resp_body['total_pages'] ?? 1);
			$page++;

		} while ($page <= $total_pages);

		// Persist aggregated rows.
		foreach ($aggregated as $row) {
			$saved = PayPal_Merchants_Table::upsert_analytics(
				$row['merchant_id'],
				$date,
				$row['transaction_count'],
				$row['gross_volume'],
				$row['partner_fees'],
				$row['currency']
			);

			if ($saved) {
				// Mark merchant as active since we have transaction data.
				PayPal_Merchants_Table::mark_active($row['merchant_id'], $test_mode);
				$result['synced']++;
			}
		}

		return $result;
	}

	/**
	 * Get the timestamp of the last successful sync.
	 *
	 * @return string|false MySQL datetime string or false if never synced.
	 */
	public static function get_last_sync_time() {

		return get_option(self::LAST_SYNC_OPTION, false);
	}
}
