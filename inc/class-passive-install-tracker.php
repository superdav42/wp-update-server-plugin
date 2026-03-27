<?php
/**
 * Passive Install Tracker
 *
 * Hooks into update check requests and logs lightweight data to the
 * wp_wu_passive_installs table. Equivalent to server access-log level data.
 *
 * Data captured per check:
 *  - IP address of the requesting site
 *  - Site URL and WP version (parsed from WordPress User-Agent)
 *  - Slug being checked
 *  - Whether the request carried a valid OAuth token
 *  - Timestamp (first_seen / last_seen with check_count increment)
 *
 * Reverse DNS resolution is deferred to a daily cron job to avoid blocking
 * the update response.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Passive_Install_Tracker {

	/**
	 * Cron hook name for DNS backfill.
	 *
	 * @var string
	 */
	const CRON_DNS_BACKFILL = 'wu_passive_installs_dns_backfill';

	/**
	 * Cron hook name for record purge.
	 *
	 * @var string
	 */
	const CRON_PURGE = 'wu_passive_installs_purge';

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {

		// Hook into the update API request handler.
		add_action('wu_before_update_api_response', [$this, 'track_update_check'], 10, 2);

		// Schedule cron jobs.
		add_action('admin_init', [$this, 'schedule_crons']);
		add_action(self::CRON_DNS_BACKFILL, [$this, 'run_dns_backfill']);
		add_action(self::CRON_PURGE, [$this, 'run_purge']);
	}

	/**
	 * Record a passive install from an update check request.
	 *
	 * Called via the wu_before_update_api_response action fired from
	 * Request_Endpoint::handleUpdateApiRequest() before the response is sent.
	 *
	 * @param string $slug            The plugin/addon slug being checked.
	 * @param bool   $is_authenticated Whether the request carried a valid OAuth token.
	 * @return void
	 */
	public function track_update_check(string $slug, bool $is_authenticated): void {

		if (empty($slug)) {
			return;
		}

		$ip_address = $this->get_client_ip();
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

		[$site_url, $wp_version] = $this->parse_wordpress_user_agent($user_agent);

		// Silently skip on failure — never block the update response.
		Passive_Installs_Table::upsert(
			$site_url,
			$ip_address,
			$slug,
			$is_authenticated,
			$wp_version,
			$user_agent
		);
	}

	/**
	 * Parse the WordPress User-Agent header to extract site URL and WP version.
	 *
	 * WordPress sends: "WordPress/6.7.2; https://example.com"
	 *
	 * @param string $user_agent Raw User-Agent string.
	 * @return array{0: string, 1: string} [site_url, wp_version]. Empty strings on parse failure.
	 */
	public function parse_wordpress_user_agent(string $user_agent): array {

		if (empty($user_agent)) {
			return ['', ''];
		}

		// Match "WordPress/X.Y.Z; https://..." pattern.
		if (preg_match('/^WordPress\/([0-9.]+);\s*(https?:\/\/[^\s]+)/i', $user_agent, $matches)) {
			$wp_version = sanitize_text_field($matches[1]);
			$site_url   = esc_url_raw(rtrim($matches[2], '/'));

			return [$site_url, $wp_version];
		}

		return ['', ''];
	}

	/**
	 * Get the real client IP address, respecting common proxy headers.
	 *
	 * @return string IP address, or empty string if unavailable.
	 */
	protected function get_client_ip(): string {

		// Ordered by reliability. Only trust forwarded headers if behind a known proxy.
		$headers = [
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_REAL_IP',        // nginx proxy
			'HTTP_X_FORWARDED_FOR',  // standard proxy (may be comma-separated)
			'REMOTE_ADDR',           // direct connection
		];

		foreach ($headers as $header) {
			if ( ! empty($_SERVER[ $header ])) {
				// X-Forwarded-For may contain a list; take the first (client) IP.
				$ip = sanitize_text_field(wp_unslash($_SERVER[ $header ]));
				$ip = trim(explode(',', $ip)[0]);

				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}

		return '';
	}

	/**
	 * Schedule the DNS backfill and purge cron jobs.
	 *
	 * @return void
	 */
	public function schedule_crons(): void {

		if ( ! wp_next_scheduled(self::CRON_DNS_BACKFILL)) {
			wp_schedule_event(time(), 'daily', self::CRON_DNS_BACKFILL);
		}

		if ( ! wp_next_scheduled(self::CRON_PURGE)) {
			// Run purge weekly — no need for daily.
			wp_schedule_event(time(), 'weekly', self::CRON_PURGE);
		}
	}

	/**
	 * Backfill domain names for IPs that haven't been resolved yet.
	 *
	 * Runs daily via cron. Processes up to 200 IPs per run to avoid timeouts.
	 * Results are cached in the domain column; IPs that fail resolution are
	 * left as NULL and retried on the next run.
	 *
	 * @return void
	 */
	public function run_dns_backfill(): void {

		$ips = Passive_Installs_Table::get_ips_without_domain(200);

		if (empty($ips)) {
			return;
		}

		foreach ($ips as $ip) {
			$domain = $this->reverse_dns_lookup($ip);

			if ( ! empty($domain)) {
				Passive_Installs_Table::update_domain_by_ip($ip, $domain);
			}
		}
	}

	/**
	 * Perform a reverse DNS lookup for an IP address.
	 *
	 * Returns the hostname on success, or empty string on failure.
	 * Uses gethostbyaddr() which is synchronous but acceptable in a cron context.
	 *
	 * @param string $ip The IP address to look up.
	 * @return string Resolved hostname, or empty string.
	 */
	protected function reverse_dns_lookup(string $ip): string {

		if (empty($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
			return '';
		}

		// gethostbyaddr returns the IP unchanged on failure.
		$host = gethostbyaddr($ip);

		if ($host === $ip || $host === false) {
			return '';
		}

		return sanitize_text_field($host);
	}

	/**
	 * Purge records older than the retention window.
	 *
	 * @return void
	 */
	public function run_purge(): void {

		$deleted = Passive_Installs_Table::purge_old_records();

		if ($deleted > 0) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(sprintf('Ultimate Multisite Passive Installs: Purged %d old records', $deleted));
		}
	}
}
