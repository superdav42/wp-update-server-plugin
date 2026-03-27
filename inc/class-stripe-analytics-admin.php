<?php
/**
 * Stripe Analytics Admin Dashboard
 *
 * Admin page for viewing Stripe Connect platform analytics:
 * - Total platform volume and fee revenue
 * - Connected account counts (total, active, charges-enabled)
 * - Daily volume trend table
 * - Top 10 accounts by volume
 * - Fee waiver impact (addon purchasers)
 *
 * @package WP_Update_Server_Plugin
 * @since 1.0.0
 */

namespace WP_Update_Server_Plugin;

defined('ABSPATH') || exit;

/**
 * Stripe Analytics Admin class.
 */
class Stripe_Analytics_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_post_wu_stripe_analytics_refresh', [$this, 'handle_manual_refresh']);
	}

	/**
	 * Add admin submenu page under the Telemetry menu.
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {

		add_submenu_page(
			'wu-telemetry',
			__('Stripe Analytics', 'wp-update-server-plugin'),
			__('Stripe Analytics', 'wp-update-server-plugin'),
			'manage_options',
			'wu-stripe-analytics',
			[$this, 'render_dashboard']
		);
	}

	/**
	 * Handle manual refresh form submission.
	 *
	 * @return void
	 */
	public function handle_manual_refresh(): void {

		if ( ! current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions.', 'wp-update-server-plugin'));
		}

		check_admin_referer('wu_stripe_analytics_refresh');

		$analytics = new Stripe_Analytics();
		$scope     = sanitize_text_field($_POST['scope'] ?? 'all');

		if ('accounts' === $scope || 'all' === $scope) {
			$analytics->run_weekly_account_refresh();
		}

		if ('fees' === $scope || 'all' === $scope) {
			$analytics->run_daily_sync();
		}

		wp_safe_redirect(
			add_query_arg(
				['page' => 'wu-stripe-analytics', 'refreshed' => '1'],
				admin_url('admin.php')
			)
		);

		exit;
	}

	/**
	 * Format cents as a currency string.
	 *
	 * @param int    $cents    Amount in cents.
	 * @param string $currency ISO currency code.
	 * @return string Formatted string e.g. "$1,234.56".
	 */
	protected function format_currency(int $cents, string $currency = 'usd'): string {

		$amount = $cents / 100;

		return strtoupper($currency) . ' ' . number_format($amount, 2);
	}

	/**
	 * Render the Stripe Analytics dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {

		if ( ! current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions.', 'wp-update-server-plugin'));
		}

		$days     = isset($_GET['days']) ? absint($_GET['days']) : 30;
		$days     = max(1, min($days, 365));
		$currency = isset($_GET['currency']) ? sanitize_text_field($_GET['currency']) : 'usd';

		$totals       = Stripe_Analytics_Table::get_platform_totals($days, $currency);
		$account_cnts = Stripe_Analytics_Table::get_account_counts($days);
		$daily_trends = Stripe_Analytics_Table::get_daily_trends($days, $currency);
		$top_accounts = Stripe_Analytics_Table::get_top_accounts(10, $days, $currency);
		$fee_waiver   = Stripe_Analytics_Table::get_fee_waiver_impact($days, $currency);

		$refreshed = ! empty($_GET['refreshed']);

		?>
		<div class="wrap wu-stripe-analytics-dashboard">
			<h1><?php esc_html_e('Stripe Connect Analytics', 'wp-update-server-plugin'); ?></h1>

			<?php if ($refreshed) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e('Stripe analytics data refreshed successfully.', 'wp-update-server-plugin'); ?></p>
				</div>
			<?php endif; ?>

			<!-- Filters -->
			<div class="wu-stripe-filters">
				<form method="get">
					<input type="hidden" name="page" value="wu-stripe-analytics">
					<label for="days"><?php esc_html_e('Period:', 'wp-update-server-plugin'); ?></label>
					<select name="days" id="days" onchange="this.form.submit()">
						<option value="7" <?php selected($days, 7); ?>><?php esc_html_e('Last 7 days', 'wp-update-server-plugin'); ?></option>
						<option value="30" <?php selected($days, 30); ?>><?php esc_html_e('Last 30 days', 'wp-update-server-plugin'); ?></option>
						<option value="90" <?php selected($days, 90); ?>><?php esc_html_e('Last 90 days', 'wp-update-server-plugin'); ?></option>
						<option value="365" <?php selected($days, 365); ?>><?php esc_html_e('Last year', 'wp-update-server-plugin'); ?></option>
					</select>
					&nbsp;
					<label for="currency"><?php esc_html_e('Currency:', 'wp-update-server-plugin'); ?></label>
					<select name="currency" id="currency" onchange="this.form.submit()">
						<option value="usd" <?php selected($currency, 'usd'); ?>>USD</option>
						<option value="eur" <?php selected($currency, 'eur'); ?>>EUR</option>
						<option value="gbp" <?php selected($currency, 'gbp'); ?>>GBP</option>
						<option value="aud" <?php selected($currency, 'aud'); ?>>AUD</option>
						<option value="cad" <?php selected($currency, 'cad'); ?>>CAD</option>
					</select>
				</form>

				<!-- Manual refresh -->
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left: 20px;">
					<input type="hidden" name="action" value="wu_stripe_analytics_refresh">
					<input type="hidden" name="scope" value="all">
					<?php wp_nonce_field('wu_stripe_analytics_refresh'); ?>
					<button type="submit" class="button">
						<?php esc_html_e('Refresh Now', 'wp-update-server-plugin'); ?>
					</button>
				</form>
			</div>

			<!-- Overview Cards -->
			<div class="wu-stripe-cards">
				<div class="wu-stripe-card wu-stripe-card--volume">
					<h3><?php esc_html_e('Platform Volume', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-stripe-card-value"><?php echo esc_html($this->format_currency((int) $totals['gross_volume'], $currency)); ?></div>
					<div class="wu-stripe-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('gross GMV in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-stripe-card wu-stripe-card--fees">
					<h3><?php esc_html_e('Fee Revenue', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-stripe-card-value"><?php echo esc_html($this->format_currency((int) $totals['application_fees'], $currency)); ?></div>
					<div class="wu-stripe-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('application fees in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-stripe-card">
					<h3><?php esc_html_e('Transactions', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-stripe-card-value"><?php echo esc_html(number_format((int) $totals['transaction_count'])); ?></div>
					<div class="wu-stripe-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('charges in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-stripe-card">
					<h3><?php esc_html_e('Connected Accounts', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-stripe-card-value"><?php echo esc_html(number_format($account_cnts['total'])); ?></div>
					<div class="wu-stripe-card-label">
						<?php
						printf(
							/* translators: %d is the number of active accounts */
							esc_html__('%d active (transacted in period)', 'wp-update-server-plugin'),
							esc_html($account_cnts['active'])
						);
						?>
					</div>
				</div>

				<div class="wu-stripe-card wu-stripe-card--waiver">
					<h3><?php esc_html_e('Fee Waiver Impact', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-stripe-card-value"><?php echo esc_html($this->format_currency((int) $fee_waiver['waived_fees_estimate'], $currency)); ?></div>
					<div class="wu-stripe-card-label">
						<?php
						printf(
							/* translators: %d is the number of addon accounts */
							esc_html__('waived for %d addon accounts', 'wp-update-server-plugin'),
							esc_html($fee_waiver['waived_account_count'])
						);
						?>
					</div>
				</div>
			</div>

			<div class="wu-stripe-grid">
				<!-- Daily Volume Trend -->
				<div class="wu-stripe-section wu-stripe-full-width">
					<h2><?php esc_html_e('Daily Volume Trend', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($daily_trends)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Date', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Gross Volume', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Application Fees', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Transactions', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Effective Rate', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (array_reverse($daily_trends) as $row) : ?>
									<?php
									$gross = (int) $row['gross_volume'];
									$fees  = (int) $row['application_fees'];
									$rate  = $gross > 0 ? round(($fees / $gross) * 100, 2) : 0;
									?>
									<tr>
										<td><?php echo esc_html($row['period_date']); ?></td>
										<td><?php echo esc_html($this->format_currency($gross, $currency)); ?></td>
										<td><?php echo esc_html($this->format_currency($fees, $currency)); ?></td>
										<td><?php echo esc_html(number_format((int) $row['transaction_count'])); ?></td>
										<td><?php echo esc_html($rate); ?>%</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e('No trend data yet. Data will appear after the daily sync runs or after a manual refresh.', 'wp-update-server-plugin'); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Top Accounts by Volume -->
				<div class="wu-stripe-section wu-stripe-full-width">
					<h2><?php esc_html_e('Top 10 Accounts by Volume', 'wp-update-server-plugin'); ?></h2>
					<p class="description">
						<?php esc_html_e('Aggregate view only. Individual transaction details are not stored.', 'wp-update-server-plugin'); ?>
					</p>
					<?php if ( ! empty($top_accounts)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Account', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Country', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Gross Volume', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Application Fees', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Transactions', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($top_accounts as $account) : ?>
									<tr>
										<td>
											<?php if ( ! empty($account['business_name'])) : ?>
												<?php echo esc_html($account['business_name']); ?>
												<br><code style="font-size:11px;"><?php echo esc_html($account['stripe_account_id']); ?></code>
											<?php else : ?>
												<code><?php echo esc_html($account['stripe_account_id']); ?></code>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html(strtoupper((string) $account['country'])); ?></td>
										<td><?php echo esc_html($this->format_currency((int) $account['gross_volume'], $currency)); ?></td>
										<td><?php echo esc_html($this->format_currency((int) $account['application_fees'], $currency)); ?></td>
										<td><?php echo esc_html(number_format((int) $account['transaction_count'])); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e('No account data yet. Run a refresh to sync connected accounts from Stripe.', 'wp-update-server-plugin'); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Fee Waiver Detail -->
			<div class="wu-stripe-section" style="margin-top: 20px;">
				<h2><?php esc_html_e('Fee Waiver Summary', 'wp-update-server-plugin'); ?></h2>
				<p class="description">
					<?php esc_html_e('Accounts with addon purchases have the 3% application fee waived. This shows the revenue impact.', 'wp-update-server-plugin'); ?>
				</p>
				<table class="widefat" style="max-width: 500px;">
					<tbody>
						<tr>
							<th><?php esc_html_e('Addon Accounts', 'wp-update-server-plugin'); ?></th>
							<td><?php echo esc_html(number_format($fee_waiver['waived_account_count'])); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e('Volume from Addon Accounts', 'wp-update-server-plugin'); ?></th>
							<td><?php echo esc_html($this->format_currency((int) $fee_waiver['waived_volume'], $currency)); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e('Fees Waived (estimate)', 'wp-update-server-plugin'); ?></th>
							<td><?php echo esc_html($this->format_currency((int) $fee_waiver['waived_fees_estimate'], $currency)); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<style>
			.wu-stripe-analytics-dashboard {
				max-width: 1400px;
			}
			.wu-stripe-filters {
				margin: 20px 0;
				display: flex;
				align-items: center;
				gap: 10px;
				flex-wrap: wrap;
			}
			.wu-stripe-cards {
				display: flex;
				flex-wrap: wrap;
				gap: 20px;
				margin-bottom: 30px;
			}
			.wu-stripe-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				flex: 1;
				min-width: 160px;
				text-align: center;
			}
			.wu-stripe-card--volume {
				border-color: #2271b1;
				background: #f0f6fc;
			}
			.wu-stripe-card--volume .wu-stripe-card-value {
				color: #2271b1;
			}
			.wu-stripe-card--fees {
				border-color: #00a32a;
				background: #f0fdf4;
			}
			.wu-stripe-card--fees .wu-stripe-card-value {
				color: #00a32a;
			}
			.wu-stripe-card--waiver {
				border-color: #dba617;
				background: #fef9e7;
			}
			.wu-stripe-card--waiver .wu-stripe-card-value {
				color: #9a6700;
			}
			.wu-stripe-card h3 {
				margin: 0 0 10px;
				color: #1d2327;
			}
			.wu-stripe-card-value {
				font-size: 28px;
				font-weight: 600;
				color: #1d2327;
			}
			.wu-stripe-card-label {
				color: #646970;
				font-size: 13px;
				margin-top: 5px;
			}
			.wu-stripe-grid {
				display: grid;
				grid-template-columns: 1fr;
				gap: 20px;
				margin-bottom: 20px;
			}
			.wu-stripe-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
			}
			.wu-stripe-section h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.wu-stripe-full-width {
				grid-column: 1 / -1;
			}
		</style>
		<?php
	}
}
