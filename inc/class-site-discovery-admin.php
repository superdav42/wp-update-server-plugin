<?php
/**
 * Site Discovery Admin Dashboard
 *
 * Admin sub-page under Telemetry showing network health scores,
 * subsite distribution, and scrape status for discovered domains.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Site_Discovery_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'admin_menu', [ $this, 'add_submenu' ] );
		add_action( 'admin_post_wu_trigger_scrape', [ $this, 'handle_trigger_scrape' ] );
	}

	/**
	 * Register the sub-menu page under the Telemetry menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {

		add_submenu_page(
			'wu-telemetry',
			__( 'Site Discovery', 'wp-update-server-plugin' ),
			__( 'Site Discovery', 'wp-update-server-plugin' ),
			'manage_options',
			'wu-site-discovery',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handle the manual "Run Scraper Now" action.
	 *
	 * @return void
	 */
	public function handle_trigger_scrape(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-update-server-plugin' ) );
		}

		check_admin_referer( 'wu_trigger_scrape' );

		$scraper = new Site_Discovery_Scraper();
		$scraper->run_batch();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'wu-site-discovery', 'scraped' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the site discovery dashboard page.
	 *
	 * @return void
	 */
	public function render_page(): void {

		$stats        = Site_Discovery_Table::get_summary_stats();
		$score_dist   = Site_Discovery_Table::get_health_score_distribution();
		$recent       = Site_Discovery_Table::get_recent_results( 100 );
		$prod_10plus  = Site_Discovery_Table::get_production_networks_with_subsites( 10 );
		$with_checkout = Site_Discovery_Table::get_networks_with_checkout();

		$scraped_notice = isset( $_GET['scraped'] ) && '1' === $_GET['scraped'];

		?>
		<div class="wrap wu-site-discovery-dashboard">
			<h1><?php esc_html_e( 'Site Discovery — Network Health', 'wp-update-server-plugin' ); ?></h1>

			<?php if ( $scraped_notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Scraper batch completed successfully.', 'wp-update-server-plugin' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Manual trigger -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 20px;">
				<input type="hidden" name="action" value="wu_trigger_scrape">
				<?php wp_nonce_field( 'wu_trigger_scrape' ); ?>
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Run Scraper Now (batch of 20)', 'wp-update-server-plugin' ); ?>
				</button>
			</form>

			<!-- Key questions answered -->
			<div class="wu-sd-cards">
				<div class="wu-sd-card">
					<h3><?php esc_html_e( 'Total Domains Tracked', 'wp-update-server-plugin' ); ?></h3>
					<div class="wu-sd-card-value"><?php echo esc_html( number_format( (int) ( $stats['total_domains'] ?? 0 ) ) ); ?></div>
					<div class="wu-sd-card-label"><?php esc_html_e( 'discovered via passive installs', 'wp-update-server-plugin' ); ?></div>
				</div>
				<div class="wu-sd-card">
					<h3><?php esc_html_e( 'Production Networks', 'wp-update-server-plugin' ); ?></h3>
					<div class="wu-sd-card-value"><?php echo esc_html( number_format( (int) ( $stats['production'] ?? 0 ) ) ); ?></div>
					<div class="wu-sd-card-label"><?php esc_html_e( 'not staging/dev/local', 'wp-update-server-plugin' ); ?></div>
				</div>
				<div class="wu-sd-card">
					<h3><?php esc_html_e( 'Production with 10+ Subsites', 'wp-update-server-plugin' ); ?></h3>
					<div class="wu-sd-card-value"><?php echo esc_html( number_format( $prod_10plus ) ); ?></div>
					<div class="wu-sd-card-label"><?php esc_html_e( 'real businesses', 'wp-update-server-plugin' ); ?></div>
				</div>
				<div class="wu-sd-card">
					<h3><?php esc_html_e( 'Networks with Checkout', 'wp-update-server-plugin' ); ?></h3>
					<div class="wu-sd-card-value"><?php echo esc_html( number_format( $with_checkout ) ); ?></div>
					<div class="wu-sd-card-label"><?php esc_html_e( 'selling to customers', 'wp-update-server-plugin' ); ?></div>
				</div>
				<div class="wu-sd-card">
					<h3><?php esc_html_e( 'Avg Health Score', 'wp-update-server-plugin' ); ?></h3>
					<div class="wu-sd-card-value"><?php echo esc_html( round( (float) ( $stats['avg_health_score'] ?? 0 ), 1 ) ); ?></div>
					<div class="wu-sd-card-label"><?php esc_html_e( 'out of 100', 'wp-update-server-plugin' ); ?></div>
				</div>
				<div class="wu-sd-card">
					<h3><?php esc_html_e( 'Scrape Status', 'wp-update-server-plugin' ); ?></h3>
					<div class="wu-sd-card-value" style="font-size: 18px; line-height: 1.6;">
						<span style="color: #2271b1;"><?php echo esc_html( (int) ( $stats['scraped'] ?? 0 ) ); ?> ok</span> /
						<span style="color: #f0a500;"><?php echo esc_html( (int) ( $stats['pending'] ?? 0 ) ); ?> pending</span> /
						<span style="color: #d63638;"><?php echo esc_html( (int) ( $stats['failed'] ?? 0 ) ); ?> failed</span>
					</div>
					<div class="wu-sd-card-label"><?php esc_html_e( 'success / pending / failed', 'wp-update-server-plugin' ); ?></div>
				</div>
			</div>

			<div class="wu-sd-grid">
				<!-- Health Score Distribution -->
				<div class="wu-sd-section">
					<h2><?php esc_html_e( 'Health Score Distribution', 'wp-update-server-plugin' ); ?></h2>
					<?php if ( ! empty( $score_dist ) ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Score Band', 'wp-update-server-plugin' ); ?></th>
									<th><?php esc_html_e( 'Networks', 'wp-update-server-plugin' ); ?></th>
									<th><?php esc_html_e( 'Distribution', 'wp-update-server-plugin' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$total_scraped = (int) ( $stats['scraped'] ?? 1 );
								foreach ( $score_dist as $row ) :
									$pct = $total_scraped > 0 ? round( ( (int) $row['count'] / $total_scraped ) * 100, 1 ) : 0;
									?>
									<tr>
										<td><?php echo esc_html( $row['score_band'] ); ?></td>
										<td><?php echo esc_html( $row['count'] ); ?></td>
										<td>
											<div class="wu-sd-bar" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
											<?php echo esc_html( $pct ); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No scraped data yet. Run the scraper to populate.', 'wp-update-server-plugin' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Signal Summary -->
				<div class="wu-sd-section">
					<h2><?php esc_html_e( 'Signal Summary', 'wp-update-server-plugin' ); ?></h2>
					<?php if ( ! empty( $stats ) && (int) ( $stats['scraped'] ?? 0 ) > 0 ) : ?>
						<?php
						$scraped = max( 1, (int) ( $stats['scraped'] ?? 1 ) );
						$signals = [
							__( 'Live sites', 'wp-update-server-plugin' )         => (int) ( $stats['live'] ?? 0 ),
							__( 'Production domains', 'wp-update-server-plugin' ) => (int) ( $stats['production'] ?? 0 ),
							__( 'SSL / HTTPS', 'wp-update-server-plugin' )        => (int) ( $stats['has_ssl'] ?? 0 ),
							__( 'Has checkout page', 'wp-update-server-plugin' )  => (int) ( $stats['has_checkout'] ?? 0 ),
						];
						?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Signal', 'wp-update-server-plugin' ); ?></th>
									<th><?php esc_html_e( 'Count', 'wp-update-server-plugin' ); ?></th>
									<th><?php esc_html_e( '% of Scraped', 'wp-update-server-plugin' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $signals as $label => $count ) : ?>
									<?php $pct = round( ( $count / $scraped ) * 100, 1 ); ?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td><?php echo esc_html( $count ); ?></td>
										<td>
											<div class="wu-sd-bar" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
											<?php echo esc_html( $pct ); ?>%
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No scraped data yet.', 'wp-update-server-plugin' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Discovered Networks Table -->
			<div class="wu-sd-section wu-sd-full-width">
				<h2><?php esc_html_e( 'Discovered Networks (Top 100 by Health Score)', 'wp-update-server-plugin' ); ?></h2>
				<?php if ( ! empty( $recent ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 20%;"><?php esc_html_e( 'Domain', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 8%;"><?php esc_html_e( 'Score', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 6%;"><?php esc_html_e( 'Live', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 8%;"><?php esc_html_e( 'Production', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 6%;"><?php esc_html_e( 'SSL', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 8%;"><?php esc_html_e( 'Checkout', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 8%;"><?php esc_html_e( 'Subsites', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'Network Type', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'UM Version', 'wp-update-server-plugin' ); ?></th>
								<th style="width: 16%;"><?php esc_html_e( 'Last Scraped', 'wp-update-server-plugin' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $row ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( 'https://' . $row['domain'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( $row['domain'] ); ?>
										</a>
									</td>
									<td>
										<?php
										$score     = (int) $row['health_score'];
										$score_cls = $score >= 60 ? 'wu-sd-score-high' : ( $score >= 30 ? 'wu-sd-score-mid' : 'wu-sd-score-low' );
										?>
										<span class="wu-sd-score <?php echo esc_attr( $score_cls ); ?>">
											<?php echo esc_html( $score ); ?>
										</span>
									</td>
									<td><?php echo $row['is_live'] ? '✓' : '✗'; ?></td>
									<td><?php echo $row['is_production'] ? '✓' : '✗'; ?></td>
									<td><?php echo $row['has_ssl'] ? '✓' : '✗'; ?></td>
									<td><?php echo $row['has_checkout'] ? '✓' : '✗'; ?></td>
									<td><?php echo esc_html( $row['detected_subsites'] > 0 ? $row['detected_subsites'] : '—' ); ?></td>
									<td><?php echo esc_html( $row['network_type'] ); ?></td>
									<td><?php echo esc_html( $row['detected_um_version'] ?: '—' ); ?></td>
									<td><?php echo esc_html( $row['last_scraped'] ?: '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No domains have been scraped yet. Run the scraper or wait for the daily cron job.', 'wp-update-server-plugin' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<style>
			.wu-site-discovery-dashboard {
				max-width: 1400px;
			}
			.wu-sd-cards {
				display: flex;
				flex-wrap: wrap;
				gap: 16px;
				margin-bottom: 24px;
			}
			.wu-sd-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 16px 20px;
				flex: 1;
				min-width: 160px;
				text-align: center;
			}
			.wu-sd-card h3 {
				margin: 0 0 8px;
				font-size: 13px;
				color: #1d2327;
			}
			.wu-sd-card-value {
				font-size: 32px;
				font-weight: 600;
				color: #2271b1;
			}
			.wu-sd-card-label {
				color: #646970;
				font-size: 12px;
				margin-top: 4px;
			}
			.wu-sd-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
				gap: 20px;
				margin-bottom: 20px;
			}
			.wu-sd-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 4px;
				padding: 20px;
			}
			.wu-sd-section h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.wu-sd-full-width {
				margin-top: 20px;
			}
			.wu-sd-bar {
				background: #2271b1;
				height: 8px;
				border-radius: 4px;
				display: inline-block;
				margin-right: 8px;
				min-width: 2px;
				vertical-align: middle;
			}
			.wu-sd-score {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-weight: 600;
				font-size: 13px;
			}
			.wu-sd-score-high  { background: #d1e7dd; color: #0a3622; }
			.wu-sd-score-mid   { background: #fff3cd; color: #664d03; }
			.wu-sd-score-low   { background: #f8d7da; color: #58151c; }
		</style>
		<?php
	}
}
