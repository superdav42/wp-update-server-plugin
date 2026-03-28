<?php
/**
 * Telemetry Admin Dashboard
 *
 * Unified analytics view combining opt-in telemetry, passive install tracking,
 * Stripe Connect analytics, and PayPal Connect analytics. Renders KPI cards and
 * Chart.js charts for all key metrics.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Telemetry_Admin {

	/**
	 * Chart.js version loaded from the WordPress CDN.
	 *
	 * @var string
	 */
	const CHARTJS_VERSION = '4.4.3';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
	}

	/**
	 * Add admin menu page.
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {

		add_menu_page(
			__('Telemetry Dashboard', 'wp-update-server-plugin'),
			__('Telemetry', 'wp-update-server-plugin'),
			'manage_options',
			'wu-telemetry',
			[$this, 'render_dashboard'],
			'dashicons-chart-area',
			30
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_scripts(string $hook): void {

		if ('toplevel_page_wu-telemetry' !== $hook) {
			return;
		}

		// External stylesheet.
		wp_enqueue_style(
			'wu-telemetry-admin',
			plugins_url('assets/css/telemetry-admin.css', __DIR__),
			[],
			'2.0.0'
		);

		// Chart.js from the WordPress.org CDN mirror (no external CDN dependency).
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@' . self::CHARTJS_VERSION . '/dist/chart.umd.min.js',
			[],
			self::CHARTJS_VERSION,
			true
		);

		// Dashboard JS.
		wp_enqueue_script(
			'wu-telemetry-admin',
			plugins_url('assets/js/telemetry-admin.js', __DIR__),
			['chartjs'],
			'2.0.0',
			true
		);

		// Pass chart data to JS.
		wp_localize_script('wu-telemetry-admin', 'wuTelemetry', $this->get_chart_data());
	}

	/**
	 * Collect and format all chart data for wp_localize_script.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_chart_data(): array {

		$days = isset($_GET['days']) ? absint($_GET['days']) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days = max(1, min($days, 365));

		// PHP versions.
		$php_versions = Telemetry_Table::get_php_version_distribution($days);
		$php_labels   = array_column($php_versions, 'php_version');
		$php_values   = array_map('intval', array_column($php_versions, 'count'));

		// WP versions.
		$wp_versions = Telemetry_Table::get_wp_version_distribution($days);
		$wp_labels   = array_column($wp_versions, 'wp_version');
		$wp_values   = array_map('intval', array_column($wp_versions, 'count'));

		// Plugin versions.
		$plugin_versions = Telemetry_Table::get_plugin_version_distribution($days);
		$pv_labels       = array_column($plugin_versions, 'plugin_version');
		$pv_values       = array_map('intval', array_column($plugin_versions, 'count'));

		// Gateways.
		$gateways     = Telemetry_Table::get_gateway_usage($days);
		$gw_labels    = array_map('ucfirst', array_keys($gateways));
		$gw_values    = array_values($gateways);

		// Addons.
		$addons      = Telemetry_Table::get_addon_usage($days);
		$addon_labels = array_keys($addons);
		$addon_values = array_values($addons);

		// Subsite distribution.
		$subsite_dist = Telemetry_Table::get_subsite_distribution($days);
		$ss_labels    = array_column($subsite_dist, 'bucket');
		$ss_values    = array_map('intval', array_column($subsite_dist, 'count'));

		// Revenue distribution.
		$rev_dist   = Telemetry_Table::get_revenue_distribution($days);
		$rev_labels = array_column($rev_dist, 'bucket');
		$rev_values = array_map('intval', array_column($rev_dist, 'count'));

		// Hosting providers.
		$hosting        = Telemetry_Table::get_hosting_provider_distribution($days);
		$host_labels    = array_column($hosting, 'provider');
		$host_values    = array_map('intval', array_column($hosting, 'count'));

		// Stripe daily trend (currency defaults to USD).
		$stripe_trend  = Stripe_Analytics_Table::get_daily_trends($days, 'usd');
		$stripe_labels = array_column($stripe_trend, 'period_date');
		$stripe_gross  = array_map(
			function ($r) { return round((int) $r['gross_volume'] / 100, 2); },
			$stripe_trend
		);
		$stripe_fees   = array_map(
			function ($r) { return round((int) $r['application_fees'] / 100, 2); },
			$stripe_trend
		);

		return [
			'phpVersions'     => ['labels' => $php_labels, 'values' => $php_values],
			'wpVersions'      => ['labels' => $wp_labels, 'values' => $wp_values],
			'pluginVersions'  => ['labels' => $pv_labels, 'values' => $pv_values],
			'gateways'        => ['labels' => $gw_labels, 'values' => $gw_values],
			'addons'          => ['labels' => $addon_labels, 'values' => $addon_values],
			'subsiteDist'     => ['labels' => $ss_labels, 'values' => $ss_values],
			'revenueDist'     => ['labels' => $rev_labels, 'values' => $rev_values],
			'hostingProviders'=> ['labels' => $host_labels, 'values' => $host_values],
			'stripeTrend'     => [
				'labels'      => $stripe_labels,
				'grossVolume' => $stripe_gross,
				'appFees'     => $stripe_fees,
			],
		];
	}

	/**
	 * Render the unified analytics dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {

		$days = isset($_GET['days']) ? absint($_GET['days']) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days = max(1, min($days, 365));

		// ── Opt-in telemetry ──────────────────────────────────────────────────
		$unique_sites            = Telemetry_Table::get_unique_site_count($days);
		$total_sites             = Telemetry_Table::get_unique_site_count();
		$php_versions            = Telemetry_Table::get_php_version_distribution($days);
		$wp_versions             = Telemetry_Table::get_wp_version_distribution($days);
		$plugin_versions         = Telemetry_Table::get_plugin_version_distribution($days);
		$network_types           = Telemetry_Table::get_network_type_distribution($days);
		$gateways                = Telemetry_Table::get_gateway_usage($days);
		$addons                  = Telemetry_Table::get_addon_usage($days);
		$error_summary           = Telemetry_Table::get_error_summary($days);
		$recent_errors           = Telemetry_Table::get_recent_errors(20);

		// Enhanced telemetry (tracker v2.0.0+).
		$total_subsites          = Telemetry_Table::get_total_subsites_across_network($days);
		$subsite_distribution    = Telemetry_Table::get_subsite_distribution($days);
		$revenue_distribution    = Telemetry_Table::get_revenue_distribution($days);
		$conversion_distribution = Telemetry_Table::get_conversion_rate_distribution($days);
		$connect_adoption        = Telemetry_Table::get_connect_adoption($days);
		$hosting_providers       = Telemetry_Table::get_hosting_provider_distribution($days);
		$membership_distribution = Telemetry_Table::get_membership_count_distribution($days);

		// ── Passive install tracking ──────────────────────────────────────────
		$passive_total         = Passive_Installs_Table::get_unique_install_count();
		$passive_period        = Passive_Installs_Table::get_unique_install_count($days);
		$passive_authenticated = Passive_Installs_Table::get_authenticated_install_count($days);
		$passive_slugs         = Passive_Installs_Table::get_slug_distribution($days, 20);
		$passive_wp_versions   = Passive_Installs_Table::get_wp_version_distribution($days);
		$passive_recent        = Passive_Installs_Table::get_recent_installs(20);

		// ── Stripe Connect analytics ──────────────────────────────────────────
		$stripe_totals    = Stripe_Analytics_Table::get_platform_totals($days, 'usd');
		$stripe_accounts  = Stripe_Analytics_Table::get_account_counts($days);
		$stripe_top       = Stripe_Analytics_Table::get_top_accounts(10, $days, 'usd');
		$stripe_fee_waiver= Stripe_Analytics_Table::get_fee_waiver_impact($days, 'usd');

		// ── PayPal Connect analytics ──────────────────────────────────────────
		$paypal_merchant_counts  = PayPal_Merchants_Table::get_merchant_counts();
		$paypal_platform_totals  = PayPal_Merchants_Table::get_platform_totals($days);
		$paypal_merchant_details = PayPal_Merchants_Table::get_merchant_analytics($days, 20);
		$paypal_recent_merchants = PayPal_Merchants_Table::get_recent_merchants(20);
		$paypal_last_sync        = PayPal_Transaction_Sync::get_last_sync_time();

		// ── Derived values ────────────────────────────────────────────────────
		$stripe_gross_display = '$' . number_format($stripe_totals['gross_volume'] / 100, 2);
		$stripe_fees_display  = '$' . number_format($stripe_totals['application_fees'] / 100, 2);
		$paypal_fees_display  = $paypal_platform_totals['partner_fees'] > 0
			? '$' . number_format($paypal_platform_totals['partner_fees'] / 100, 2)
			: '—';

		?>
		<div class="wrap wu-telemetry-dashboard">
			<h1><?php esc_html_e('Analytics Dashboard', 'wp-update-server-plugin'); ?></h1>

			<!-- Period selector -->
			<div class="wu-telemetry-period-selector">
				<form method="get">
					<input type="hidden" name="page" value="wu-telemetry">
					<label for="days"><?php esc_html_e('Period:', 'wp-update-server-plugin'); ?></label>
					<select name="days" id="days" onchange="this.form.submit()">
						<option value="7" <?php selected($days, 7); ?>><?php esc_html_e('Last 7 days', 'wp-update-server-plugin'); ?></option>
						<option value="30" <?php selected($days, 30); ?>><?php esc_html_e('Last 30 days', 'wp-update-server-plugin'); ?></option>
						<option value="90" <?php selected($days, 90); ?>><?php esc_html_e('Last 90 days', 'wp-update-server-plugin'); ?></option>
						<option value="365" <?php selected($days, 365); ?>><?php esc_html_e('Last year', 'wp-update-server-plugin'); ?></option>
					</select>
				</form>
				<a href="<?php echo esc_url(admin_url('admin.php?page=wu-stripe-analytics')); ?>" class="button button-secondary">
					<?php esc_html_e('Stripe Analytics Detail', 'wp-update-server-plugin'); ?>
				</a>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 1: Platform KPIs
			     ═══════════════════════════════════════════════════════════════ -->
			<h2 class="wu-dashboard-section-title"><?php esc_html_e('Platform Overview', 'wp-update-server-plugin'); ?></h2>

			<div class="wu-kpi-grid">

				<!-- Opt-in installs -->
				<div class="wu-kpi-card">
					<h3><?php esc_html_e('Active Opt-in Sites', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($unique_sites)); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-kpi-card">
					<h3><?php esc_html_e('Total Opt-in (All Time)', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($total_sites)); ?></div>
					<div class="wu-kpi-label"><?php esc_html_e('unique sites ever', 'wp-update-server-plugin'); ?></div>
				</div>

				<!-- Passive installs -->
				<div class="wu-kpi-card wu-kpi-card--passive">
					<h3><?php esc_html_e('Passive Installs', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($passive_period)); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('unique sites in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-kpi-card wu-kpi-card--passive">
					<h3><?php esc_html_e('Total Passive (All Time)', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($passive_total)); ?></div>
					<div class="wu-kpi-label"><?php esc_html_e('unique sites ever seen', 'wp-update-server-plugin'); ?></div>
				</div>

				<div class="wu-kpi-card wu-kpi-card--passive">
					<h3><?php esc_html_e('Authenticated Passive', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($passive_authenticated)); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('with valid token in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<!-- Stripe -->
				<div class="wu-kpi-card wu-kpi-card--stripe">
					<h3><?php esc_html_e('Stripe GMV', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html($stripe_gross_display); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('gross volume in last %d days (USD)', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-kpi-card wu-kpi-card--stripe">
					<h3><?php esc_html_e('Stripe Fee Revenue', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html($stripe_fees_display); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('application fees in last %d days (USD)', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<div class="wu-kpi-card wu-kpi-card--stripe">
					<h3><?php esc_html_e('Stripe Accounts', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($stripe_accounts['total'])); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of active accounts */
							esc_html__('%d active in period', 'wp-update-server-plugin'),
							esc_html($stripe_accounts['active'])
						);
						?>
					</div>
				</div>

				<!-- PayPal -->
				<div class="wu-kpi-card wu-kpi-card--paypal">
					<h3><?php esc_html_e('PayPal Merchants (Live)', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($paypal_merchant_counts['live_onboarded'] + $paypal_merchant_counts['live_active'])); ?></div>
					<div class="wu-kpi-label"><?php esc_html_e('onboarded / active', 'wp-update-server-plugin'); ?></div>
				</div>

				<div class="wu-kpi-card wu-kpi-card--paypal">
					<h3><?php esc_html_e('PayPal Partner Fees', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html($paypal_fees_display); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('last %d days (USD)', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>

				<!-- Errors -->
				<div class="wu-kpi-card wu-kpi-card--warning">
					<h3><?php esc_html_e('Error Reports', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(count($recent_errors)); ?></div>
					<div class="wu-kpi-label"><?php esc_html_e('recent errors', 'wp-update-server-plugin'); ?></div>
				</div>

				<?php if ( ! empty($connect_adoption) && $connect_adoption['total_reporting'] > 0) : ?>
				<div class="wu-kpi-card wu-kpi-card--enhanced">
					<h3><?php esc_html_e('Stripe Connect Adoption', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html($connect_adoption['stripe_connect_pct']); ?>%</div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %1$d is count, %2$d is total */
							esc_html__('%1$d of %2$d reporting networks', 'wp-update-server-plugin'),
							esc_html($connect_adoption['stripe_connect']),
							esc_html($connect_adoption['total_reporting'])
						);
						?>
					</div>
				</div>

				<div class="wu-kpi-card wu-kpi-card--enhanced">
					<h3><?php esc_html_e('PayPal Connect Adoption', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html($connect_adoption['paypal_connect_pct']); ?>%</div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %1$d is count, %2$d is total */
							esc_html__('%1$d of %2$d reporting networks', 'wp-update-server-plugin'),
							esc_html($connect_adoption['paypal_connect']),
							esc_html($connect_adoption['total_reporting'])
						);
						?>
					</div>
				</div>
				<?php endif; ?>

				<?php if ($total_subsites > 0) : ?>
				<div class="wu-kpi-card wu-kpi-card--enhanced">
					<h3><?php esc_html_e('Total Subsites Reported', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-kpi-value"><?php echo esc_html(number_format($total_subsites)); ?></div>
					<div class="wu-kpi-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('across all networks in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>
				<?php endif; ?>

			</div><!-- .wu-kpi-grid -->

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 2: Stripe Connect Analytics
			     ═══════════════════════════════════════════════════════════════ -->
			<h2 class="wu-dashboard-section-title">
				<?php esc_html_e('Stripe Connect Analytics', 'wp-update-server-plugin'); ?>
				<a href="<?php echo esc_url(admin_url('admin.php?page=wu-stripe-analytics')); ?>" class="wu-section-link">
					<?php esc_html_e('Full detail →', 'wp-update-server-plugin'); ?>
				</a>
			</h2>

			<div class="wu-section-grid">

				<!-- Stripe daily trend chart -->
				<div class="wu-section wu-section--full">
					<div class="wu-section-header">
						<h2><?php esc_html_e('Daily Volume Trend (USD)', 'wp-update-server-plugin'); ?></h2>
					</div>
					<?php if ( ! empty(Stripe_Analytics_Table::get_daily_trends($days, 'usd'))) : ?>
						<div class="wu-chart-container wu-chart-container--trend">
							<canvas id="wu-chart-stripe-trend"></canvas>
						</div>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No Stripe trend data yet. Run a sync from the Stripe Analytics page.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Top accounts -->
				<div class="wu-section wu-section--full">
					<h2><?php esc_html_e('Top 10 Accounts by Volume (USD)', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($stripe_top)) : ?>
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
								<?php foreach ($stripe_top as $account) : ?>
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
										<td>$<?php echo esc_html(number_format((int) $account['gross_volume'] / 100, 2)); ?></td>
										<td>$<?php echo esc_html(number_format((int) $account['application_fees'] / 100, 2)); ?></td>
										<td><?php echo esc_html(number_format((int) $account['transaction_count'])); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No Stripe account data yet. Run a refresh from the Stripe Analytics page.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Fee waiver summary -->
				<div class="wu-section">
					<h2><?php esc_html_e('Fee Waiver Impact (USD)', 'wp-update-server-plugin'); ?></h2>
					<p class="wu-section-description"><?php esc_html_e('Accounts with addon purchases have the 3% application fee waived.', 'wp-update-server-plugin'); ?></p>
					<table class="widefat">
						<tbody>
							<tr>
								<th><?php esc_html_e('Addon Accounts', 'wp-update-server-plugin'); ?></th>
								<td><?php echo esc_html(number_format($stripe_fee_waiver['waived_account_count'])); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e('Volume from Addon Accounts', 'wp-update-server-plugin'); ?></th>
								<td>$<?php echo esc_html(number_format($stripe_fee_waiver['waived_volume'] / 100, 2)); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e('Fees Waived (estimate)', 'wp-update-server-plugin'); ?></th>
								<td>$<?php echo esc_html(number_format($stripe_fee_waiver['waived_fees_estimate'] / 100, 2)); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

			</div><!-- .wu-section-grid (Stripe) -->

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 3: PayPal Connect Analytics
			     ═══════════════════════════════════════════════════════════════ -->
			<h2 class="wu-dashboard-section-title"><?php esc_html_e('PayPal Connect Analytics', 'wp-update-server-plugin'); ?></h2>
			<p class="wu-section-description">
				<?php esc_html_e('Merchant onboarding events and partner fee data from the PayPal Connect proxy.', 'wp-update-server-plugin'); ?>
				<?php if ($paypal_last_sync) : ?>
					<?php
					printf(
						/* translators: %s is the datetime of last sync */
						esc_html__('Last transaction sync: %s.', 'wp-update-server-plugin'),
						esc_html($paypal_last_sync)
					);
					?>
				<?php else : ?>
					<?php esc_html_e('Transaction sync has not run yet (requires PayPal Transaction Search API access).', 'wp-update-server-plugin'); ?>
				<?php endif; ?>
			</p>

			<div class="wu-section-grid">

				<!-- Merchant status -->
				<div class="wu-section">
					<h2><?php esc_html_e('Merchant Status', 'wp-update-server-plugin'); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Mode', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Onboarded', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Active', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Disconnected', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_html_e('Live', 'wp-update-server-plugin'); ?></td>
								<td><?php echo esc_html(number_format($paypal_merchant_counts['live_onboarded'])); ?></td>
								<td><?php echo esc_html(number_format($paypal_merchant_counts['live_active'])); ?></td>
								<td><?php echo esc_html(number_format($paypal_merchant_counts['live_disconnected'])); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e('Sandbox', 'wp-update-server-plugin'); ?></td>
								<td><?php echo esc_html(number_format($paypal_merchant_counts['sandbox_onboarded'])); ?></td>
								<td><?php echo esc_html(number_format($paypal_merchant_counts['sandbox_active'])); ?></td>
								<td><?php echo esc_html(number_format($paypal_merchant_counts['sandbox_disconnected'])); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Platform totals -->
				<div class="wu-section">
					<h2>
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('Platform Totals (Last %d Days)', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Metric', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Value', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php esc_html_e('Transactions', 'wp-update-server-plugin'); ?></td>
								<td><?php echo esc_html(number_format($paypal_platform_totals['total_transactions'])); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e('Gross Volume (USD)', 'wp-update-server-plugin'); ?></td>
								<td>$<?php echo esc_html(number_format($paypal_platform_totals['gross_volume'] / 100, 2)); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e('Partner Fees (USD)', 'wp-update-server-plugin'); ?></td>
								<td>$<?php echo esc_html(number_format($paypal_platform_totals['partner_fees'] / 100, 2)); ?></td>
							</tr>
						</tbody>
					</table>
					<?php if (0 === $paypal_platform_totals['total_transactions']) : ?>
						<p class="wu-section-description" style="margin-top: 10px;">
							<?php esc_html_e('No transaction data yet. Data populates after the daily sync runs.', 'wp-update-server-plugin'); ?>
						</p>
					<?php endif; ?>
				</div>

			</div><!-- .wu-section-grid (PayPal summary) -->

			<!-- Per-merchant analytics -->
			<?php if ( ! empty($paypal_merchant_details)) : ?>
			<div class="wu-section" style="margin-bottom: 24px;">
				<h2>
					<?php
					printf(
						/* translators: %d is the number of days */
						esc_html__('Per-Merchant Analytics (Last %d Days)', 'wp-update-server-plugin'),
						esc_html($days)
					);
					?>
				</h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 20%;"><?php esc_html_e('Merchant ID', 'wp-update-server-plugin'); ?></th>
							<th style="width: 10%;"><?php esc_html_e('Status', 'wp-update-server-plugin'); ?></th>
							<th style="width: 8%;"><?php esc_html_e('Mode', 'wp-update-server-plugin'); ?></th>
							<th style="width: 10%;"><?php esc_html_e('Transactions', 'wp-update-server-plugin'); ?></th>
							<th style="width: 15%;"><?php esc_html_e('Gross Volume', 'wp-update-server-plugin'); ?></th>
							<th style="width: 15%;"><?php esc_html_e('Partner Fees', 'wp-update-server-plugin'); ?></th>
							<th style="width: 10%;"><?php esc_html_e('Currency', 'wp-update-server-plugin'); ?></th>
							<th style="width: 12%;"><?php esc_html_e('Last Transaction', 'wp-update-server-plugin'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($paypal_merchant_details as $row) : ?>
							<tr>
								<td><code><?php echo esc_html($row['merchant_id']); ?></code></td>
								<td><?php echo esc_html(ucfirst($row['status'] ?? '—')); ?></td>
								<td>
									<?php if ($row['test_mode']) : ?>
										<span class="wu-badge wu-badge--sandbox"><?php esc_html_e('Sandbox', 'wp-update-server-plugin'); ?></span>
									<?php else : ?>
										<span class="wu-badge wu-badge--live"><?php esc_html_e('Live', 'wp-update-server-plugin'); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html(number_format((int) $row['total_transactions'])); ?></td>
								<td>$<?php echo esc_html(number_format((int) $row['gross_volume'] / 100, 2)); ?></td>
								<td>$<?php echo esc_html(number_format((int) $row['partner_fees'] / 100, 2)); ?></td>
								<td><?php echo esc_html($row['currency']); ?></td>
								<td><?php echo esc_html($row['last_transaction'] ?: '—'); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<!-- Recent merchant onboarding events -->
			<div class="wu-section" style="margin-bottom: 24px;">
				<h2><?php esc_html_e('Recent Merchant Onboarding Events', 'wp-update-server-plugin'); ?></h2>
				<?php if ( ! empty($paypal_recent_merchants)) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 20%;"><?php esc_html_e('Merchant ID', 'wp-update-server-plugin'); ?></th>
								<th style="width: 20%;"><?php esc_html_e('Tracking ID', 'wp-update-server-plugin'); ?></th>
								<th style="width: 8%;"><?php esc_html_e('Mode', 'wp-update-server-plugin'); ?></th>
								<th style="width: 10%;"><?php esc_html_e('Status', 'wp-update-server-plugin'); ?></th>
								<th style="width: 14%;"><?php esc_html_e('Onboarded At', 'wp-update-server-plugin'); ?></th>
								<th style="width: 14%;"><?php esc_html_e('Disconnected At', 'wp-update-server-plugin'); ?></th>
								<th style="width: 14%;"><?php esc_html_e('Last Transaction', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($paypal_recent_merchants as $row) : ?>
								<tr>
									<td><code><?php echo esc_html($row['merchant_id']); ?></code></td>
									<td><code style="font-size: 11px;"><?php echo esc_html($row['tracking_id'] ?: '—'); ?></code></td>
									<td>
										<?php if ($row['test_mode']) : ?>
											<span class="wu-badge wu-badge--sandbox"><?php esc_html_e('Sandbox', 'wp-update-server-plugin'); ?></span>
										<?php else : ?>
											<span class="wu-badge wu-badge--live"><?php esc_html_e('Live', 'wp-update-server-plugin'); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html(ucfirst($row['status'])); ?></td>
									<td><?php echo esc_html($row['onboarded_at']); ?></td>
									<td><?php echo esc_html($row['disconnected_at'] ?: '—'); ?></td>
									<td><?php echo esc_html($row['last_transaction'] ?: '—'); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="wu-empty"><?php esc_html_e('No merchant onboarding events recorded yet.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 4: Opt-in Telemetry — Environment Distributions
			     ═══════════════════════════════════════════════════════════════ -->
			<h2 class="wu-dashboard-section-title"><?php esc_html_e('Opt-in Telemetry — Environment', 'wp-update-server-plugin'); ?></h2>

			<div class="wu-section-grid">

				<!-- PHP versions chart -->
				<div class="wu-section">
					<h2><?php esc_html_e('PHP Versions', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($php_versions)) : ?>
						<div class="wu-chart-container">
							<canvas id="wu-chart-php"></canvas>
						</div>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- WP versions chart -->
				<div class="wu-section">
					<h2><?php esc_html_e('WordPress Versions', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($wp_versions)) : ?>
						<div class="wu-chart-container">
							<canvas id="wu-chart-wp"></canvas>
						</div>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Plugin versions chart -->
				<div class="wu-section">
					<h2><?php esc_html_e('Plugin Versions', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($plugin_versions)) : ?>
						<div class="wu-chart-container">
							<canvas id="wu-chart-plugin"></canvas>
						</div>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Network types table -->
				<div class="wu-section">
					<h2><?php esc_html_e('Network Types', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($network_types)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Type', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('%', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($network_types as $row) : ?>
									<?php $pct = $unique_sites > 0 ? round(($row['count'] / $unique_sites) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['network_type']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-bar-cell">
												<div class="wu-bar" style="width: <?php echo esc_attr(min($pct * 1.5, 100)); ?>px;"></div>
												<?php echo esc_html($pct); ?>%
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Gateway usage doughnut -->
				<div class="wu-section">
					<h2><?php esc_html_e('Payment Gateways', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($gateways)) : ?>
						<div class="wu-chart-container">
							<canvas id="wu-chart-gateways"></canvas>
						</div>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Addon usage doughnut -->
				<div class="wu-section">
					<h2><?php esc_html_e('Active Addons', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($addons)) : ?>
						<div class="wu-chart-container">
							<canvas id="wu-chart-addons"></canvas>
						</div>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No addon data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

			</div><!-- .wu-section-grid (environment) -->

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 5: Enhanced Network Telemetry (tracker v2.0.0+)
			     ═══════════════════════════════════════════════════════════════ -->
			<?php if ($total_subsites > 0 || ! empty($subsite_distribution) || ! empty($revenue_distribution)) : ?>
			<h2 class="wu-dashboard-section-title"><?php esc_html_e('Enhanced Network Telemetry', 'wp-update-server-plugin'); ?></h2>
			<p class="wu-section-description">
				<?php esc_html_e('Data from opt-in installations running tracker v2.0.0+. Exact counts reported by consenting networks.', 'wp-update-server-plugin'); ?>
			</p>

			<div class="wu-section-grid">

				<!-- Subsite distribution chart -->
				<?php if ( ! empty($subsite_distribution)) : ?>
				<div class="wu-section">
					<h2><?php esc_html_e('Subsite Count Distribution', 'wp-update-server-plugin'); ?></h2>
					<div class="wu-chart-container">
						<canvas id="wu-chart-subsites"></canvas>
					</div>
				</div>
				<?php endif; ?>

				<!-- Revenue distribution chart -->
				<?php if ( ! empty($revenue_distribution)) : ?>
				<div class="wu-section">
					<h2><?php esc_html_e('30-Day Revenue Distribution', 'wp-update-server-plugin'); ?></h2>
					<p class="wu-section-description"><?php esc_html_e("In site's base currency. No FX conversion applied.", 'wp-update-server-plugin'); ?></p>
					<div class="wu-chart-container">
						<canvas id="wu-chart-revenue"></canvas>
					</div>
				</div>
				<?php endif; ?>

				<!-- Checkout conversion rates -->
				<?php if ( ! empty($conversion_distribution)) : ?>
				<div class="wu-section">
					<h2><?php esc_html_e('Checkout Conversion Rates', 'wp-update-server-plugin'); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Conversion Rate', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Networks', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Avg Rate', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($conversion_distribution as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['bucket']); ?></td>
									<td><?php echo esc_html(number_format((int) $row['count'])); ?></td>
									<td><?php echo esc_html($row['avg_rate_pct']); ?>%</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Active memberships distribution -->
				<?php if ( ! empty($membership_distribution)) : ?>
				<div class="wu-section">
					<h2><?php esc_html_e('Active Memberships Distribution', 'wp-update-server-plugin'); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Active Memberships', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Networks', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($membership_distribution as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['bucket']); ?></td>
									<td><?php echo esc_html(number_format((int) $row['count'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Hosting providers chart -->
				<?php if ( ! empty($hosting_providers)) : ?>
				<div class="wu-section">
					<h2><?php esc_html_e('Hosting Providers', 'wp-update-server-plugin'); ?></h2>
					<div class="wu-chart-container">
						<canvas id="wu-chart-hosting"></canvas>
					</div>
				</div>
				<?php endif; ?>

			</div><!-- .wu-section-grid (enhanced) -->
			<?php endif; ?>

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 6: Passive Install Tracking
			     ═══════════════════════════════════════════════════════════════ -->
			<h2 class="wu-dashboard-section-title"><?php esc_html_e('Passive Install Tracking', 'wp-update-server-plugin'); ?></h2>
			<p class="wu-section-description">
				<?php esc_html_e('Recorded from update check requests (equivalent to server access log data). No opt-in required.', 'wp-update-server-plugin'); ?>
			</p>

			<div class="wu-section-grid">

				<!-- Slug distribution -->
				<div class="wu-section">
					<h2><?php esc_html_e('Addon/Plugin Checks', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($passive_slugs)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Slug', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Unique Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Total Checks', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($passive_slugs as $row) : ?>
									<tr>
										<td><code><?php echo esc_html($row['slug_requested']); ?></code></td>
										<td><?php echo esc_html(number_format((int) $row['unique_sites'])); ?></td>
										<td><?php echo esc_html(number_format((int) $row['total_checks'])); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No passive install data yet. Data will appear once sites check for updates.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- WP version distribution (passive) -->
				<div class="wu-section">
					<h2><?php esc_html_e('WordPress Versions (Passive)', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($passive_wp_versions)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Version', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('%', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($passive_wp_versions as $row) : ?>
									<?php $pct = $passive_period > 0 ? round(((int) $row['count'] / $passive_period) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['wp_version']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-bar-cell">
												<div class="wu-bar wu-bar--green" style="width: <?php echo esc_attr(min($pct * 1.5, 100)); ?>px;"></div>
												<?php echo esc_html($pct); ?>%
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="wu-empty"><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

			</div><!-- .wu-section-grid (passive) -->

			<!-- Recent passive installs -->
			<div class="wu-section" style="margin-bottom: 24px;">
				<h2><?php esc_html_e('Recent Passive Installs', 'wp-update-server-plugin'); ?></h2>
				<?php if ( ! empty($passive_recent)) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 20%;"><?php esc_html_e('Site URL', 'wp-update-server-plugin'); ?></th>
								<th style="width: 12%;"><?php esc_html_e('IP / Domain', 'wp-update-server-plugin'); ?></th>
								<th style="width: 8%;"><?php esc_html_e('WP Version', 'wp-update-server-plugin'); ?></th>
								<th style="width: 15%;"><?php esc_html_e('Slug', 'wp-update-server-plugin'); ?></th>
								<th style="width: 8%;"><?php esc_html_e('Auth', 'wp-update-server-plugin'); ?></th>
								<th style="width: 12%;"><?php esc_html_e('First Seen', 'wp-update-server-plugin'); ?></th>
								<th style="width: 12%;"><?php esc_html_e('Last Seen', 'wp-update-server-plugin'); ?></th>
								<th style="width: 8%;"><?php esc_html_e('Checks', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($passive_recent as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['site_url'] ?: '—'); ?></td>
									<td>
										<?php if ( ! empty($row['domain'])) : ?>
											<?php echo esc_html($row['domain']); ?>
										<?php else : ?>
											<code><?php echo esc_html($row['ip_address']); ?></code>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html($row['wp_version'] ?: '—'); ?></td>
									<td><code><?php echo esc_html($row['slug_requested']); ?></code></td>
									<td>
										<?php if ($row['is_authenticated']) : ?>
											<span class="wu-badge wu-badge--yes"><?php esc_html_e('Yes', 'wp-update-server-plugin'); ?></span>
										<?php else : ?>
											<span class="wu-badge wu-badge--no"><?php esc_html_e('No', 'wp-update-server-plugin'); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html($row['first_seen']); ?></td>
									<td><?php echo esc_html($row['last_seen']); ?></td>
									<td><?php echo esc_html(number_format((int) $row['check_count'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="wu-empty"><?php esc_html_e('No passive install records yet.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     SECTION 7: Error Reporting
			     ═══════════════════════════════════════════════════════════════ -->
			<h2 class="wu-dashboard-section-title"><?php esc_html_e('Error Reporting', 'wp-update-server-plugin'); ?></h2>

			<div class="wu-section" style="margin-bottom: 24px;">
				<h2><?php esc_html_e('Error Summary', 'wp-update-server-plugin'); ?></h2>
				<?php if ( ! empty($error_summary)) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Handle', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Message Preview', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Count', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Last Seen', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($error_summary as $row) : ?>
								<tr>
									<td><code><?php echo esc_html($row['handle']); ?></code></td>
									<td><?php echo esc_html($row['message_preview']); ?></td>
									<td><?php echo esc_html($row['count']); ?></td>
									<td><?php echo esc_html($row['last_seen']); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="wu-empty"><?php esc_html_e('No errors reported.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>

			<div class="wu-section" style="margin-bottom: 24px;">
				<h2><?php esc_html_e('Recent Errors', 'wp-update-server-plugin'); ?></h2>
				<?php if ( ! empty($recent_errors)) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 15%;"><?php esc_html_e('Time', 'wp-update-server-plugin'); ?></th>
								<th style="width: 10%;"><?php esc_html_e('Version', 'wp-update-server-plugin'); ?></th>
								<th style="width: 10%;"><?php esc_html_e('Handle', 'wp-update-server-plugin'); ?></th>
								<th style="width: 65%;"><?php esc_html_e('Message', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($recent_errors as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['created_at']); ?></td>
									<td><?php echo esc_html($row['plugin_version'] ?: '-'); ?></td>
									<td><code><?php echo esc_html($row['handle']); ?></code></td>
									<td><code style="word-break: break-word;"><?php echo esc_html($row['message']); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="wu-empty"><?php esc_html_e('No recent errors.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>

		</div><!-- .wu-telemetry-dashboard -->
		<?php
	}
}
