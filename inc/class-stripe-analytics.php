<?php
/**
 * Stripe Analytics
 *
 * Syncs Stripe Connect platform data (application fees, connected accounts)
 * to local DB tables for fast dashboard queries.
 *
 * Sync schedule:
 *   - Daily cron: sync previous day's application fees per connected account
 *   - Weekly cron: refresh connected account statuses
 *   - On-demand: REST endpoint for manual refresh (admin only)
 *
 * Stripe API keys are read from wp-config.php constants:
 *   WU_STRIPE_SECRET_KEY       (live)
 *   WU_STRIPE_TEST_SECRET_KEY  (test/sandbox)
 *
 * @package WP_Update_Server_Plugin
 * @since 1.0.0
 */

namespace WP_Update_Server_Plugin;

defined('ABSPATH') || exit;

/**
 * Stripe Analytics sync and query class.
 */
class Stripe_Analytics {

	/**
	 * REST API namespace for on-demand refresh.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'wu-stripe-analytics/v1';

	/**
	 * Daily sync cron hook.
	 *
	 * @var string
	 */
	const CRON_DAILY = 'wu_stripe_analytics_daily_sync';

	/**
	 * Weekly account refresh cron hook.
	 *
	 * @var string
	 */
	const CRON_WEEKLY = 'wu_stripe_analytics_weekly_accounts';

	/**
	 * Stripe API base URL.
	 *
	 * @var string
	 */
	const STRIPE_API_BASE = 'https://api.stripe.com';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('rest_api_init', [$this, 'register_routes']);
		add_action(self::CRON_DAILY, [$this, 'run_daily_sync']);
		add_action(self::CRON_WEEKLY, [$this, 'run_weekly_account_refresh']);
		add_action('admin_init', [$this, 'schedule_crons']);
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::REST_NAMESPACE,
			'/refresh',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_manual_refresh'],
				'permission_callback' => [$this, 'check_admin_permission'],
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
	 * Check admin permission.
	 *
	 * @return bool
	 */
	public function check_admin_permission(): bool {

		return current_user_can('manage_options');
	}

	/**
	 * Schedule cron jobs.
	 *
	 * @return void
	 */
	public function schedule_crons(): void {

		if ( ! wp_next_scheduled(self::CRON_DAILY)) {
			// Run daily at 02:00 UTC
			wp_schedule_event(strtotime('tomorrow 02:00:00 UTC'), 'daily', self::CRON_DAILY);
		}

		if ( ! wp_next_scheduled(self::CRON_WEEKLY)) {
			wp_schedule_event(time(), 'weekly', self::CRON_WEEKLY);
		}
	}

	/**
	 * Get the Stripe secret key.
	 *
	 * @param bool $test_mode Whether to use test key.
	 * @return string Empty string if not configured.
	 */
	protected function get_stripe_secret_key(bool $test_mode = false): string {

		if ($test_mode) {
			return defined('WU_STRIPE_TEST_SECRET_KEY') ? WU_STRIPE_TEST_SECRET_KEY : '';
		}

		return defined('WU_STRIPE_SECRET_KEY') ? WU_STRIPE_SECRET_KEY : '';
	}

	/**
	 * Make an authenticated request to the Stripe API.
	 *
	 * @param string $endpoint  API endpoint path (e.g. '/v1/application_fees').
	 * @param array  $params    Query parameters.
	 * @param bool   $test_mode Whether to use test key.
	 * @return array|false Decoded response body or false on error.
	 */
	protected function stripe_get(string $endpoint, array $params = [], bool $test_mode = false) {

		$secret_key = $this->get_stripe_secret_key($test_mode);

		if (empty($secret_key)) {
			return false;
		}

		$url = self::STRIPE_API_BASE . $endpoint;

		if ( ! empty($params)) {
			$url = add_query_arg($params, $url);
		}

		$response = wp_remote_get(
			$url,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $secret_key,
					'Stripe-Version' => '2024-06-20',
				],
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			error_log('[Stripe Analytics] API error: ' . $response->get_error_message());

			return false;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code < 200 || $code >= 300) {
			$error = $body['error']['message'] ?? 'Unknown Stripe API error';
			error_log(sprintf('[Stripe Analytics] API %d: %s', $code, $error));

			return false;
		}

		return $body;
	}

	/**
	 * Paginate through all results from a Stripe list endpoint.
	 *
	 * @param string $endpoint  API endpoint path.
	 * @param array  $params    Base query parameters.
	 * @param bool   $test_mode Whether to use test key.
	 * @param int    $max_pages Safety limit on pages fetched.
	 * @return array All items from all pages.
	 */
	protected function stripe_get_all(string $endpoint, array $params = [], bool $test_mode = false, int $max_pages = 50): array {

		$all_items = [];
		$params    = array_merge(['limit' => 100], $params);
		$pages     = 0;

		do {
			$response = $this->stripe_get($endpoint, $params, $test_mode);

			if ( ! $response || empty($response['data'])) {
				break;
			}

			$all_items = array_merge($all_items, $response['data']);
			$pages++;

			if ( ! empty($response['has_more']) && ! empty($response['data'])) {
				$last_item        = end($response['data']);
				$params['starting_after'] = $last_item['id'];
			} else {
				break;
			}
		} while ($pages < $max_pages);

		return $all_items;
	}

	/**
	 * Run the daily sync: fetch yesterday's application fees and aggregate by account.
	 *
	 * @param bool $test_mode Whether to use test mode.
	 * @return int Number of account-day records upserted.
	 */
	public function run_daily_sync(bool $test_mode = false): int {

		$yesterday_start = strtotime('yesterday midnight UTC');
		$yesterday_end   = strtotime('today midnight UTC') - 1;
		$period_date     = gmdate('Y-m-d', $yesterday_start);

		$fees = $this->stripe_get_all(
			'/v1/application_fees',
			[
				'created[gte]' => $yesterday_start,
				'created[lte]' => $yesterday_end,
			],
			$test_mode
		);

		if (empty($fees)) {
			return 0;
		}

		// Aggregate by account + currency
		$by_account = [];

		foreach ($fees as $fee) {
			$account_id = $fee['account'] ?? '';
			$currency   = strtolower($fee['currency'] ?? 'usd');
			$amount     = (int) ($fee['amount'] ?? 0);
			$refunded   = (int) ($fee['amount_refunded'] ?? 0);

			if (empty($account_id)) {
				continue;
			}

			$key = $account_id . '|' . $currency;

			if ( ! isset($by_account[$key])) {
				$by_account[$key] = [
					'stripe_account_id' => $account_id,
					'currency'          => $currency,
					'transaction_count' => 0,
					'gross_volume'      => 0,
					'application_fees'  => 0,
					'refund_count'      => 0,
					'refund_volume'     => 0,
				];
			}

			$by_account[$key]['transaction_count']++;
			$by_account[$key]['application_fees'] += $amount;

			// Gross volume: fetch from the originating charge if available
			if ( ! empty($fee['originating_transaction'])) {
				$charge_amount = (int) ($fee['originating_transaction']['amount'] ?? 0);
				$by_account[$key]['gross_volume'] += $charge_amount;
			}

			if ($refunded > 0) {
				$by_account[$key]['refund_count']++;
				$by_account[$key]['refund_volume'] += $refunded;
			}
		}

		$upserted = 0;

		foreach ($by_account as $row) {
			$result = Stripe_Analytics_Table::upsert_analytics(
				$row['stripe_account_id'],
				$period_date,
				$row['transaction_count'],
				$row['gross_volume'],
				$row['application_fees'],
				$row['refund_count'],
				$row['refund_volume'],
				$row['currency']
			);

			if ($result) {
				$upserted++;

				// Update account total volume
				$this->update_account_total_volume($row['stripe_account_id'], $test_mode);
			}
		}

		error_log(sprintf('[Stripe Analytics] Daily sync complete: %d account-day records for %s', $upserted, $period_date));

		return $upserted;
	}

	/**
	 * Refresh total lifetime volume for a single connected account.
	 *
	 * @param string $stripe_account_id The Stripe account ID.
	 * @param bool   $test_mode         Whether to use test mode.
	 * @return void
	 */
	protected function update_account_total_volume(string $stripe_account_id, bool $test_mode = false): void {

		global $wpdb;

		$table = Stripe_Analytics_Table::get_analytics_table();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(gross_volume), 0) FROM {$table} WHERE stripe_account_id = %s",
				$stripe_account_id
			)
		);

		Stripe_Analytics_Table::update_account_volume($stripe_account_id, $total);
	}

	/**
	 * Run the weekly account refresh: sync connected account metadata.
	 *
	 * @param bool $test_mode Whether to use test mode.
	 * @return int Number of accounts upserted.
	 */
	public function run_weekly_account_refresh(bool $test_mode = false): int {

		$accounts = $this->stripe_get_all('/v1/accounts', [], $test_mode);

		if (empty($accounts)) {
			return 0;
		}

		$upserted = 0;

		foreach ($accounts as $account) {
			$account_id      = $account['id'] ?? '';
			$business_name   = $account['business_profile']['name'] ?? ($account['settings']['dashboard']['display_name'] ?? null);
			$email           = $account['email'] ?? null;
			$country         = $account['country'] ?? null;
			$charges_enabled = (bool) ($account['charges_enabled'] ?? false);
			$payouts_enabled = (bool) ($account['payouts_enabled'] ?? false);

			if (empty($account_id)) {
				continue;
			}

			$result = Stripe_Analytics_Table::upsert_account(
				$account_id,
				$business_name,
				$email,
				$country,
				$charges_enabled,
				$payouts_enabled
			);

			if ($result) {
				$upserted++;
			}
		}

		error_log(sprintf('[Stripe Analytics] Weekly account refresh: %d accounts synced', $upserted));

		return $upserted;
	}

	/**
	 * Handle POST /refresh — manual on-demand sync.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle_manual_refresh(\WP_REST_Request $request): \WP_REST_Response {

		$body      = $request->get_json_params();
		$test_mode = (bool) ($body['testMode'] ?? false);
		$scope     = sanitize_text_field($body['scope'] ?? 'all');

		$results = [];

		if ('accounts' === $scope || 'all' === $scope) {
			$results['accounts_synced'] = $this->run_weekly_account_refresh($test_mode);
		}

		if ('fees' === $scope || 'all' === $scope) {
			$results['fee_records_synced'] = $this->run_daily_sync($test_mode);
		}

		$results['synced_at'] = current_time('mysql');

		return new \WP_REST_Response($results, 200);
	}

	/**
	 * Handle GET /stats — return aggregated analytics for the dashboard.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle_stats_request(\WP_REST_Request $request): \WP_REST_Response {

		$days     = (int) ($request->get_param('days') ?: 30);
		$days     = max(1, min($days, 365));
		$currency = sanitize_text_field($request->get_param('currency') ?: 'usd');

		return new \WP_REST_Response(
			[
				'platform_totals'   => Stripe_Analytics_Table::get_platform_totals($days, $currency),
				'account_counts'    => Stripe_Analytics_Table::get_account_counts($days),
				'daily_trends'      => Stripe_Analytics_Table::get_daily_trends($days, $currency),
				'top_accounts'      => Stripe_Analytics_Table::get_top_accounts(10, $days, $currency),
				'fee_waiver_impact' => Stripe_Analytics_Table::get_fee_waiver_impact($days, $currency),
				'period_days'       => $days,
				'currency'          => $currency,
			],
			200
		);
	}
}
