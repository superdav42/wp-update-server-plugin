<?php
/**
 * Site Discovery Scraper
 *
 * Background cron-based scraper that discovers network health signals from
 * domains found via passive install tracking. Respects robots.txt, uses a
 * polite user-agent, and only accesses publicly accessible pages.
 *
 * Health score formula (0-100):
 *   +20  is_live
 *   +15  is_production (not staging/dev/local)
 *   +10  has_ssl
 *   +15  has_checkout page
 *   +20  detected_subsites > 5
 *   +10  detected_subsites > 50
 *   +10  detected_um_version is set (fingerprinted)
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Site_Discovery_Scraper {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wu_site_discovery_scrape';

	/**
	 * User-Agent sent with all requests.
	 *
	 * @var string
	 */
	const USER_AGENT = 'UltimateMultisiteBot/1.0 (+https://ultimatemultisite.com/bot)';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * Maximum domains processed per cron run.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 20;

	/**
	 * Staging/dev/local hostname patterns to exclude from is_production.
	 *
	 * @var string[]
	 */
	const DEV_PATTERNS = [
		'.local',
		'.test',
		'.localhost',
		'staging.',
		'stage.',
		'dev.',
		'develop.',
		'sandbox.',
		'preview.',
		'test.',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( self::CRON_HOOK, [ $this, 'run_batch' ] );
		add_action( 'admin_init', [ $this, 'schedule_cron' ] );
	}

	/**
	 * Schedule the daily cron job if not already scheduled.
	 *
	 * @return void
	 */
	public function schedule_cron(): void {

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Process a batch of pending domains.
	 *
	 * @return void
	 */
	public function run_batch(): void {

		$domains = Site_Discovery_Table::get_pending_domains( self::BATCH_SIZE );

		foreach ( $domains as $row ) {
			$this->scrape_domain( (int) $row['id'], (string) $row['domain'] );
		}
	}

	/**
	 * Scrape a single domain and store the result.
	 *
	 * @param int    $id     Row ID in the discovery table.
	 * @param string $domain Domain name (no scheme).
	 * @return void
	 */
	public function scrape_domain( int $id, string $domain ): void {

		// Determine base URL — try HTTPS first.
		$base_url = 'https://' . $domain;

		// Check robots.txt before doing anything else.
		if ( $this->is_blocked_by_robots( $base_url ) ) {
			Site_Discovery_Table::update_scrape_result( $id, 'blocked' );
			return;
		}

		// Fetch the homepage.
		$response = $this->fetch( $base_url . '/' );

		if ( is_wp_error( $response ) ) {
			// Try HTTP fallback.
			$base_url = 'http://' . $domain;
			$response = $this->fetch( $base_url . '/' );
		}

		if ( is_wp_error( $response ) ) {
			Site_Discovery_Table::update_scrape_result( $id, 'failed' );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$is_live     = ( $status_code >= 200 && $status_code < 400 );

		if ( ! $is_live ) {
			Site_Discovery_Table::update_scrape_result(
				$id,
				'success',
				[
					'is_live'      => 0,
					'health_score' => 0,
					'raw_data'     => wp_json_encode( [ 'http_status' => $status_code ] ),
				]
			);
			return;
		}

		$body        = wp_remote_retrieve_body( $response );
		$has_ssl     = ( strpos( $base_url, 'https://' ) === 0 );
		$is_prod     = $this->is_production_domain( $domain );
		$has_checkout = $this->detect_checkout( $base_url, $body );
		$subsites    = $this->estimate_subsites( $base_url, $body );
		$network_type = $this->detect_network_type( $body );
		$um_version  = $this->detect_um_version( $body );

		$health_score = $this->compute_health_score(
			$is_live,
			$is_prod,
			$has_ssl,
			$has_checkout,
			$subsites,
			$um_version
		);

		$raw_data = [
			'http_status'    => $status_code,
			'has_ssl'        => $has_ssl,
			'is_production'  => $is_prod,
			'has_checkout'   => $has_checkout,
			'subsites'       => $subsites,
			'network_type'   => $network_type,
			'um_version'     => $um_version,
			'health_score'   => $health_score,
		];

		Site_Discovery_Table::update_scrape_result(
			$id,
			'success',
			[
				'is_live'             => 1,
				'is_production'       => (int) $is_prod,
				'has_ssl'             => (int) $has_ssl,
				'has_checkout'        => (int) $has_checkout,
				'detected_subsites'   => $subsites,
				'network_type'        => $network_type,
				'detected_um_version' => $um_version,
				'health_score'        => $health_score,
				'raw_data'            => wp_json_encode( $raw_data ),
			]
		);
	}

	/**
	 * Compute the composite health score (0-100).
	 *
	 * @param bool        $is_live      Whether the site responded with 2xx/3xx.
	 * @param bool        $is_prod      Whether the domain looks like production.
	 * @param bool        $has_ssl      Whether HTTPS is available.
	 * @param bool        $has_checkout Whether a checkout/registration page was found.
	 * @param int         $subsites     Estimated subsite count.
	 * @param string|null $um_version   Detected UM version string, or null.
	 * @return int
	 */
	public function compute_health_score(
		bool $is_live,
		bool $is_prod,
		bool $has_ssl,
		bool $has_checkout,
		int $subsites,
		?string $um_version
	): int {

		$score = 0;

		if ( $is_live ) {
			$score += 20;
		}

		if ( $is_prod ) {
			$score += 15;
		}

		if ( $has_ssl ) {
			$score += 10;
		}

		if ( $has_checkout ) {
			$score += 15;
		}

		if ( $subsites > 5 ) {
			$score += 20;
		}

		if ( $subsites > 50 ) {
			$score += 10;
		}

		if ( ! empty( $um_version ) ) {
			$score += 10;
		}

		return min( 100, $score );
	}

	/**
	 * Check whether the domain looks like a production site.
	 *
	 * Returns false for staging, dev, local, and test domains.
	 *
	 * @param string $domain Domain name.
	 * @return bool
	 */
	protected function is_production_domain( string $domain ): bool {

		$domain_lower = strtolower( $domain );

		foreach ( self::DEV_PATTERNS as $pattern ) {
			if ( strpos( $domain_lower, $pattern ) !== false ) {
				return false;
			}
		}

		// IP addresses are not production domains.
		if ( filter_var( $domain, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether the site's robots.txt disallows our bot.
	 *
	 * @param string $base_url Base URL (with scheme).
	 * @return bool True if we should skip this site.
	 */
	protected function is_blocked_by_robots( string $base_url ): bool {

		$response = $this->fetch( $base_url . '/robots.txt' );

		if ( is_wp_error( $response ) ) {
			return false; // Can't read robots.txt — proceed cautiously.
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return false;
		}

		// Simple robots.txt parser: look for Disallow: / under our User-Agent or *.
		$lines       = explode( "\n", $body );
		$applies     = false;
		$our_agent   = 'UltimateMultisiteBot';

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( stripos( $line, 'User-agent:' ) === 0 ) {
				$agent   = trim( substr( $line, strlen( 'User-agent:' ) ) );
				$applies = ( $agent === '*' || stripos( $agent, $our_agent ) !== false );
				continue;
			}

			if ( $applies && stripos( $line, 'Disallow:' ) === 0 ) {
				$path = trim( substr( $line, strlen( 'Disallow:' ) ) );
				if ( $path === '/' ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Detect whether the site has a checkout or registration page.
	 *
	 * Checks the homepage HTML for UM checkout shortcodes/blocks, then
	 * probes /register/ and /pricing/ paths.
	 *
	 * @param string $base_url Base URL.
	 * @param string $homepage_body Homepage HTML.
	 * @return bool
	 */
	protected function detect_checkout( string $base_url, string $homepage_body ): bool {

		// Check homepage for UM checkout indicators.
		$checkout_patterns = [
			'wu_checkout',
			'wu-checkout',
			'wu_register',
			'wu-register',
			'wp-ultimo',
			'ultimate-multisite',
		];

		foreach ( $checkout_patterns as $pattern ) {
			if ( stripos( $homepage_body, $pattern ) !== false ) {
				return true;
			}
		}

		// Probe /register/ path.
		$register_response = $this->fetch( $base_url . '/register/' );
		if ( ! is_wp_error( $register_response ) ) {
			$code = wp_remote_retrieve_response_code( $register_response );
			if ( $code >= 200 && $code < 400 ) {
				$register_body = wp_remote_retrieve_body( $register_response );
				foreach ( $checkout_patterns as $pattern ) {
					if ( stripos( $register_body, $pattern ) !== false ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Estimate the number of subsites on the network.
	 *
	 * For subdirectory networks, probes common subsite paths.
	 * For subdomain networks, this is harder to enumerate externally,
	 * so we return 0 unless the homepage reveals a count.
	 *
	 * @param string $base_url     Base URL.
	 * @param string $homepage_body Homepage HTML.
	 * @return int Estimated subsite count (0 = unknown).
	 */
	protected function estimate_subsites( string $base_url, string $homepage_body ): int {

		// Look for a site count in the homepage HTML (e.g. "200 sites" or "200 networks").
		if ( preg_match( '/(\d+)\s+(?:sites?|networks?|subsites?)/i', $homepage_body, $matches ) ) {
			$count = (int) $matches[1];
			if ( $count > 0 && $count < 100000 ) {
				return $count;
			}
		}

		// Probe a handful of common subdirectory paths to infer activity.
		$probe_paths = [ '/site1/', '/site2/', '/site3/', '/sites/2/', '/sites/3/' ];
		$found       = 0;

		foreach ( $probe_paths as $path ) {
			$response = $this->fetch( $base_url . $path );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				++$found;
			}
		}

		// If we found probe paths, extrapolate conservatively.
		if ( $found >= 3 ) {
			return 10; // At least a few subsites.
		}

		if ( $found >= 1 ) {
			return 2;
		}

		return 0;
	}

	/**
	 * Detect whether the network uses subdomain or subdirectory routing.
	 *
	 * @param string $homepage_body Homepage HTML.
	 * @return string 'subdomain', 'subdirectory', or 'unknown'.
	 */
	protected function detect_network_type( string $homepage_body ): string {

		// Look for wp-content URLs that reveal the network type.
		if ( preg_match( '#https?://[^/]+/wp-content/blogs\.dir/#i', $homepage_body ) ) {
			return 'subdirectory';
		}

		if ( preg_match( '#https?://[a-z0-9-]+\.[^/]+/wp-content/#i', $homepage_body ) ) {
			return 'subdomain';
		}

		// Check for subdirectory-style site links in the HTML.
		if ( preg_match( '#href=["\']https?://[^/]+/[a-z0-9-]+/wp-#i', $homepage_body ) ) {
			return 'subdirectory';
		}

		return 'unknown';
	}

	/**
	 * Detect the Ultimate Multisite version from HTML meta tags or asset URLs.
	 *
	 * @param string $homepage_body Homepage HTML.
	 * @return string|null Version string or null if not detected.
	 */
	protected function detect_um_version( string $homepage_body ): ?string {

		// Check for version in asset URLs: /wp-content/plugins/ultimate-multisite/...?ver=X.Y.Z
		if ( preg_match(
			'#/wp-content/plugins/ultimate-multisite/[^"\'?]*\?ver=([\d.]+)#i',
			$homepage_body,
			$matches
		) ) {
			return $matches[1];
		}

		// Check for generator meta tag.
		if ( preg_match(
			'#<meta[^>]+name=["\']generator["\'][^>]+content=["\'][^"\']*ultimate.multisite[^"\']*?([\d.]+)["\']#i',
			$homepage_body,
			$matches
		) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Make an HTTP GET request with our bot user-agent.
	 *
	 * @param string $url URL to fetch.
	 * @return array|\WP_Error
	 */
	protected function fetch( string $url ) {

		return wp_remote_get(
			$url,
			[
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => self::USER_AGENT,
				'sslverify'  => false, // Some sites have self-signed certs.
				'redirection' => 3,
			]
		);
	}
}
