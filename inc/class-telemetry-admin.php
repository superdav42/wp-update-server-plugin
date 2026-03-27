<?php
/**
 * Telemetry Admin Dashboard
 *
 * Admin page for viewing telemetry statistics.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Telemetry_Admin {

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

		wp_enqueue_style(
			'wu-telemetry-admin',
			plugins_url('assets/css/telemetry-admin.css', __DIR__),
			[],
			'1.0.0'
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {

		$days = isset($_GET['days']) ? absint($_GET['days']) : 30;
		$days = max(1, min($days, 365));

		$unique_sites    = Telemetry_Table::get_unique_site_count($days);
		$total_sites     = Telemetry_Table::get_unique_site_count();
		$php_versions    = Telemetry_Table::get_php_version_distribution($days);
		$wp_versions     = Telemetry_Table::get_wp_version_distribution($days);
		$plugin_versions = Telemetry_Table::get_plugin_version_distribution($days);
		$network_types   = Telemetry_Table::get_network_type_distribution($days);
		$gateways        = Telemetry_Table::get_gateway_usage($days);
		$addons          = Telemetry_Table::get_addon_usage($days);
		$error_summary   = Telemetry_Table::get_error_summary($days);
		$recent_errors   = Telemetry_Table::get_recent_errors(20);

		// Enhanced telemetry (tracker v2.0.0+).
		$total_subsites          = Telemetry_Table::get_total_subsites_across_network($days);
		$subsite_distribution    = Telemetry_Table::get_subsite_distribution($days);
		$revenue_distribution    = Telemetry_Table::get_revenue_distribution($days);
		$conversion_distribution = Telemetry_Table::get_conversion_rate_distribution($days);
		$connect_adoption        = Telemetry_Table::get_connect_adoption($days);
		$hosting_providers       = Telemetry_Table::get_hosting_provider_distribution($days);
		$membership_distribution = Telemetry_Table::get_membership_count_distribution($days);

		// Passive install tracking data.
		$passive_total         = Passive_Installs_Table::get_unique_install_count();
		$passive_period        = Passive_Installs_Table::get_unique_install_count($days);
		$passive_authenticated = Passive_Installs_Table::get_authenticated_install_count($days);
		$passive_slugs         = Passive_Installs_Table::get_slug_distribution($days, 20);
		$passive_wp_versions   = Passive_Installs_Table::get_wp_version_distribution($days);
		$passive_recent        = Passive_Installs_Table::get_recent_installs(20);

		?>
		<div class="wrap wu-telemetry-dashboard">
			<h1><?php esc_html_e('Ultimate Multisite Telemetry Dashboard', 'wp-update-server-plugin'); ?></h1>

			<div class="wu-telemetry-period-selector">
				<form method="get">
					<input type="hidden" name="page" value="wu-telemetry">
					<label for="days"><?php esc_html_e('Time Period:', 'wp-update-server-plugin'); ?></label>
					<select name="days" id="days" onchange="this.form.submit()">
						<option value="7" <?php selected($days, 7); ?>><?php esc_html_e('Last 7 days', 'wp-update-server-plugin'); ?></option>
						<option value="30" <?php selected($days, 30); ?>><?php esc_html_e('Last 30 days', 'wp-update-server-plugin'); ?></option>
						<option value="90" <?php selected($days, 90); ?>><?php esc_html_e('Last 90 days', 'wp-update-server-plugin'); ?></option>
						<option value="365" <?php selected($days, 365); ?>><?php esc_html_e('Last year', 'wp-update-server-plugin'); ?></option>
					</select>
				</form>
			</div>

			<!-- Overview Cards -->
			<div class="wu-telemetry-cards">
				<div class="wu-telemetry-card">
					<h3><?php esc_html_e('Active Installations', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(number_format($unique_sites)); ?></div>
					<div class="wu-telemetry-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('in last %d days (opt-in)', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>
				<div class="wu-telemetry-card">
					<h3><?php esc_html_e('Total Sites Ever', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(number_format($total_sites)); ?></div>
					<div class="wu-telemetry-card-label"><?php esc_html_e('all time (opt-in)', 'wp-update-server-plugin'); ?></div>
				</div>
				<div class="wu-telemetry-card wu-telemetry-card--passive">
					<h3><?php esc_html_e('Passive Installs', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(number_format($passive_period)); ?></div>
					<div class="wu-telemetry-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('unique sites in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>
				<div class="wu-telemetry-card wu-telemetry-card--passive">
					<h3><?php esc_html_e('Total Passive (All Time)', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(number_format($passive_total)); ?></div>
					<div class="wu-telemetry-card-label"><?php esc_html_e('unique sites ever seen', 'wp-update-server-plugin'); ?></div>
				</div>
				<div class="wu-telemetry-card wu-telemetry-card--passive">
					<h3><?php esc_html_e('Authenticated Passive', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(number_format($passive_authenticated)); ?></div>
					<div class="wu-telemetry-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('with valid token in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>
				<div class="wu-telemetry-card">
					<h3><?php esc_html_e('Error Reports', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(count($recent_errors)); ?></div>
					<div class="wu-telemetry-card-label"><?php esc_html_e('recent errors', 'wp-update-server-plugin'); ?></div>
				</div>
			</div>

			<!-- Passive Install Tracking -->
			<h2><?php esc_html_e('Passive Install Tracking', 'wp-update-server-plugin'); ?></h2>
			<p class="description">
				<?php esc_html_e('Recorded from update check requests (equivalent to server access log data). No opt-in required.', 'wp-update-server-plugin'); ?>
			</p>

			<div class="wu-telemetry-grid">
				<!-- Slug Distribution -->
				<div class="wu-telemetry-section">
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
						<p><?php esc_html_e('No passive install data yet. Data will appear once sites check for updates.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- WP Version Distribution (Passive) -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('WordPress Versions (Passive)', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($passive_wp_versions)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Version', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Percentage', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($passive_wp_versions as $row) : ?>
									<?php $pct = $passive_period > 0 ? round(((int) $row['count'] / $passive_period) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['wp_version']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-telemetry-bar" style="width: <?php echo esc_attr($pct); ?>%;"></div>
											<?php echo esc_html($pct); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Recent Passive Installs -->
			<div class="wu-telemetry-section wu-telemetry-full-width" style="margin-bottom: 20px;">
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
					<p><?php esc_html_e('No passive install records yet.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>

			<div class="wu-telemetry-grid">
				<!-- PHP Versions -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('PHP Versions', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($php_versions)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Version', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Percentage', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($php_versions as $row) : ?>
									<?php $percentage = $unique_sites > 0 ? round(($row['count'] / $unique_sites) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['php_version']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-telemetry-bar" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
											<?php echo esc_html($percentage); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- WordPress Versions -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('WordPress Versions', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($wp_versions)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Version', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Percentage', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($wp_versions as $row) : ?>
									<?php $percentage = $unique_sites > 0 ? round(($row['count'] / $unique_sites) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['wp_version']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-telemetry-bar" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
											<?php echo esc_html($percentage); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Plugin Versions -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('Plugin Versions', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($plugin_versions)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Version', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Percentage', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($plugin_versions as $row) : ?>
									<?php $percentage = $unique_sites > 0 ? round(($row['count'] / $unique_sites) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['plugin_version']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-telemetry-bar" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
											<?php echo esc_html($percentage); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Network Types -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('Network Types', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($network_types)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Type', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Percentage', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($network_types as $row) : ?>
									<?php $percentage = $unique_sites > 0 ? round(($row['count'] / $unique_sites) * 100, 1) : 0; ?>
									<tr>
										<td><?php echo esc_html($row['network_type']); ?></td>
										<td><?php echo esc_html($row['count']); ?></td>
										<td>
											<div class="wu-telemetry-bar" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
											<?php echo esc_html($percentage); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Payment Gateways -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('Payment Gateways', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($gateways)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Gateway', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites Using', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($gateways as $gateway => $count) : ?>
									<tr>
										<td><?php echo esc_html(ucfirst($gateway)); ?></td>
										<td><?php echo esc_html($count); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>

				<!-- Active Addons -->
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('Active Addons', 'wp-update-server-plugin'); ?></h2>
					<?php if ( ! empty($addons)) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e('Addon', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Sites Using', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($addons as $addon => $count) : ?>
									<tr>
										<td><?php echo esc_html($addon); ?></td>
										<td><?php echo esc_html($count); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e('No addon data available.', 'wp-update-server-plugin'); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Enhanced Telemetry (tracker v2.0.0+) -->
			<?php if ($total_subsites > 0 || ! empty($subsite_distribution) || ! empty($revenue_distribution)) : ?>
			<h2><?php esc_html_e('Enhanced Network Telemetry', 'wp-update-server-plugin'); ?></h2>
			<p class="description">
				<?php esc_html_e('Data from opt-in installations running tracker v2.0.0+. Exact counts reported by consenting networks.', 'wp-update-server-plugin'); ?>
			</p>

			<!-- Enhanced overview cards -->
			<div class="wu-telemetry-cards" style="margin-bottom: 30px;">
				<div class="wu-telemetry-card wu-telemetry-card--enhanced">
					<h3><?php esc_html_e('Total Subsites Reported', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html(number_format($total_subsites)); ?></div>
					<div class="wu-telemetry-card-label">
						<?php
						printf(
							/* translators: %d is the number of days */
							esc_html__('across all networks in last %d days', 'wp-update-server-plugin'),
							esc_html($days)
						);
						?>
					</div>
				</div>
				<?php if ( ! empty($connect_adoption) && $connect_adoption['total_reporting'] > 0) : ?>
				<div class="wu-telemetry-card wu-telemetry-card--enhanced">
					<h3><?php esc_html_e('Stripe Connect Adoption', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html($connect_adoption['stripe_connect_pct']); ?>%</div>
					<div class="wu-telemetry-card-label">
						<?php
						printf(
							/* translators: %d is the number of sites */
							esc_html__('%d of %d reporting networks', 'wp-update-server-plugin'),
							esc_html($connect_adoption['stripe_connect']),
							esc_html($connect_adoption['total_reporting'])
						);
						?>
					</div>
				</div>
				<div class="wu-telemetry-card wu-telemetry-card--enhanced">
					<h3><?php esc_html_e('PayPal Connect Adoption', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-telemetry-card-value"><?php echo esc_html($connect_adoption['paypal_connect_pct']); ?>%</div>
					<div class="wu-telemetry-card-label">
						<?php
						printf(
							/* translators: %d is the number of sites */
							esc_html__('%d of %d reporting networks', 'wp-update-server-plugin'),
							esc_html($connect_adoption['paypal_connect']),
							esc_html($connect_adoption['total_reporting'])
						);
						?>
					</div>
				</div>
				<?php endif; ?>
			</div>

			<div class="wu-telemetry-grid">
				<!-- Subsite Distribution -->
				<?php if ( ! empty($subsite_distribution)) : ?>
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('Subsite Count Distribution', 'wp-update-server-plugin'); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Subsites per Network', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Networks', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($subsite_distribution as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['bucket']); ?></td>
									<td><?php echo esc_html(number_format((int) $row['count'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Revenue Distribution -->
				<?php if ( ! empty($revenue_distribution)) : ?>
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('30-Day Revenue Distribution', 'wp-update-server-plugin'); ?></h2>
					<p class="description"><?php esc_html_e('In site\'s base currency. No FX conversion applied.', 'wp-update-server-plugin'); ?></p>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Revenue Range', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Networks', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($revenue_distribution as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['bucket']); ?></td>
									<td><?php echo esc_html(number_format((int) $row['count'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Checkout Conversion Rate Distribution -->
				<?php if ( ! empty($conversion_distribution)) : ?>
				<div class="wu-telemetry-section">
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

				<!-- Active Membership Distribution -->
				<?php if ( ! empty($membership_distribution)) : ?>
				<div class="wu-telemetry-section">
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

				<!-- Hosting Providers -->
				<?php if ( ! empty($hosting_providers)) : ?>
				<div class="wu-telemetry-section">
					<h2><?php esc_html_e('Hosting Providers', 'wp-update-server-plugin'); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Provider', 'wp-update-server-plugin'); ?></th>
								<th><?php esc_html_e('Networks', 'wp-update-server-plugin'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($hosting_providers as $row) : ?>
								<tr>
									<td><?php echo esc_html($row['provider']); ?></td>
									<td><?php echo esc_html(number_format((int) $row['count'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Error Summary -->
			<div class="wu-telemetry-section wu-telemetry-full-width">
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
					<p><?php esc_html_e('No errors reported.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>

			<!-- Recent Errors -->
			<div class="wu-telemetry-section wu-telemetry-full-width">
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
					<p><?php esc_html_e('No recent errors.', 'wp-update-server-plugin'); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<style>
			.wu-telemetry-dashboard {
				max-width: 1400px;
			}
			.wu-telemetry-period-selector {
				margin: 20px 0;
			}
			.wu-telemetry-cards {
				display: flex;
				flex-wrap: wrap;
				gap: 20px;
				margin-bottom: 30px;
			}
			.wu-telemetry-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
				flex: 1;
				min-width: 160px;
				text-align: center;
			}
			.wu-telemetry-card--passive {
				border-color: #00a32a;
				background: #f0fdf4;
			}
			.wu-telemetry-card--passive .wu-telemetry-card-value {
				color: #00a32a;
			}
			.wu-telemetry-card--enhanced {
				border-color: #7c3aed;
				background: #f5f3ff;
			}
			.wu-telemetry-card--enhanced .wu-telemetry-card-value {
				color: #7c3aed;
			}
			.wu-telemetry-card h3 {
				margin: 0 0 10px;
				color: #1d2327;
			}
			.wu-telemetry-card-value {
				font-size: 36px;
				font-weight: 600;
				color: #2271b1;
			}
			.wu-telemetry-card-label {
				color: #646970;
				font-size: 13px;
			}
			.wu-telemetry-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
				gap: 20px;
				margin-bottom: 20px;
			}
			.wu-telemetry-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
			}
			.wu-telemetry-section h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.wu-telemetry-full-width {
				grid-column: 1 / -1;
			}
			.wu-telemetry-bar {
				background: #2271b1;
				height: 8px;
				border-radius: 4px;
				display: inline-block;
				margin-right: 8px;
				min-width: 2px;
			}
			.wu-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.wu-badge--yes {
				background: #d1fae5;
				color: #065f46;
			}
			.wu-badge--no {
				background: #f3f4f6;
				color: #6b7280;
			}
		</style>
		<?php
	}
}
