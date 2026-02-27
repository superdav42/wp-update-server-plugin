<?php
/**
 * PayPal Connect Proxy.
 *
 * Handles PayPal Partner Referrals API on behalf of customer sites,
 * keeping partner credentials secure on the ultimatemultisite.com server.
 *
 * Mirrors the Stripe Connect proxy pattern at /wp-json/stripe-connect/v1.
 *
 * REST API namespace: paypal-connect/v1
 *
 * Endpoints:
 *   POST /oauth/init    - Create partner referral URL for merchant onboarding
 *   POST /oauth/verify  - Verify merchant integration status after onboarding
 *   POST /deauthorize   - Notify proxy that a site has disconnected
 *
 * @package WP_Update_Server_Plugin
 * @since 1.0.0
 */

namespace WP_Update_Server_Plugin;

defined('ABSPATH') || exit;

/**
 * PayPal Connect proxy class.
 */
class PayPal_Connect {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'paypal-connect/v1';

	/**
	 * Partner Attribution ID (BN Code).
	 *
	 * @var string
	 */
	const BN_CODE = 'UltimateMultisite_SP_PPCP';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::API_NAMESPACE,
			'/oauth/init',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_oauth_init'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/oauth/verify',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_oauth_verify'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/deauthorize',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_deauthorize'],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Get PayPal API base URL.
	 *
	 * @param bool $test_mode Whether to use sandbox.
	 * @return string
	 */
	protected function get_api_base_url(bool $test_mode): string {

		return $test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
	}

	/**
	 * Get partner credentials for a given mode.
	 *
	 * Reads from constants defined in wp-config.php on the proxy server.
	 *
	 * @param bool $test_mode Whether to use sandbox credentials.
	 * @return array{client_id: string, client_secret: string, merchant_id: string}
	 */
	protected function get_partner_credentials(bool $test_mode): array {

		if ($test_mode) {
			return [
				'client_id'     => defined('WU_PAYPAL_SANDBOX_PARTNER_CLIENT_ID') ? WU_PAYPAL_SANDBOX_PARTNER_CLIENT_ID : '',
				'client_secret' => defined('WU_PAYPAL_SANDBOX_PARTNER_CLIENT_SECRET') ? WU_PAYPAL_SANDBOX_PARTNER_CLIENT_SECRET : '',
				'merchant_id'   => defined('WU_PAYPAL_SANDBOX_PARTNER_MERCHANT_ID') ? WU_PAYPAL_SANDBOX_PARTNER_MERCHANT_ID : '',
			];
		}

		return [
			'client_id'     => defined('WU_PAYPAL_PARTNER_CLIENT_ID') ? WU_PAYPAL_PARTNER_CLIENT_ID : '',
			'client_secret' => defined('WU_PAYPAL_PARTNER_CLIENT_SECRET') ? WU_PAYPAL_PARTNER_CLIENT_SECRET : '',
			'merchant_id'   => defined('WU_PAYPAL_PARTNER_MERCHANT_ID') ? WU_PAYPAL_PARTNER_MERCHANT_ID : '',
		];
	}

	/**
	 * Get a partner access token from PayPal.
	 *
	 * @param bool $test_mode Whether to use sandbox.
	 * @return string|\WP_Error
	 */
	protected function get_partner_access_token(bool $test_mode) {

		$cache_key    = 'wu_pp_proxy_token_' . ($test_mode ? 'sandbox' : 'live');
		$cached_token = get_transient($cache_key);

		if ($cached_token) {
			return $cached_token;
		}

		$credentials = $this->get_partner_credentials($test_mode);

		if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
			return new \WP_Error(
				'missing_credentials',
				'PayPal partner credentials are not configured on the proxy server.'
			);
		}

		$response = wp_remote_post(
			$this->get_api_base_url($test_mode) . '/v1/oauth2/token',
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']), // phpcs:ignore
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => 'grant_type=client_credentials',
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$code = wp_remote_retrieve_response_code($response);

		if (200 !== $code || empty($body['access_token'])) {
			$error_msg = $body['error_description'] ?? 'Failed to obtain PayPal access token';

			return new \WP_Error('token_error', $error_msg);
		}

		$expires_in = isset($body['expires_in']) ? (int) $body['expires_in'] - 300 : 3300;
		set_transient($cache_key, $body['access_token'], $expires_in);

		return $body['access_token'];
	}

	/**
	 * Handle POST /oauth/init
	 *
	 * Creates a PayPal Partner Referral URL for merchant onboarding.
	 * The customer site sends its return URL; the proxy calls PayPal
	 * with the partner credentials and returns the onboarding link.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle_oauth_init(\WP_REST_Request $request): \WP_REST_Response {

		$body = $request->get_json_params();

		$return_url = $body['returnUrl'] ?? '';
		$test_mode  = (bool) ($body['testMode'] ?? true);

		if (empty($return_url)) {
			return new \WP_REST_Response(
				['error' => 'returnUrl is required'],
				400
			);
		}

		$access_token = $this->get_partner_access_token($test_mode);

		if (is_wp_error($access_token)) {
			return new \WP_REST_Response(
				['error' => $access_token->get_error_message()],
				500
			);
		}

		$credentials = $this->get_partner_credentials($test_mode);

		// Generate a tracking ID for this onboarding
		$tracking_id = 'wu_' . wp_generate_uuid4();

		// Store tracking ID for later verification (24 hours)
		set_transient(
			'wu_pp_onboarding_' . $tracking_id,
			[
				'started'    => time(),
				'test_mode'  => $test_mode,
				'return_url' => $return_url,
			],
			DAY_IN_SECONDS
		);

		// Append tracking_id to the return URL so the customer site can verify
		$return_url_with_tracking = add_query_arg('tracking_id', $tracking_id, $return_url);

		// Build the partner referral request
		$referral_data = [
			'tracking_id'             => $tracking_id,
			'partner_config_override' => [
				'return_url' => $return_url_with_tracking,
			],
			'operations'              => [
				[
					'operation'                  => 'API_INTEGRATION',
					'api_integration_preference' => [
						'rest_api_integration' => [
							'integration_method'  => 'PAYPAL',
							'integration_type'    => 'THIRD_PARTY',
							'third_party_details' => [
								'features' => [
									'PAYMENT',
									'REFUND',
									'PARTNER_FEE',
									'DELAY_FUNDS_DISBURSEMENT',
								],
							],
						],
					],
				],
			],
			'products'                => ['EXPRESS_CHECKOUT'],
			'legal_consents'          => [
				[
					'type'    => 'SHARE_DATA_CONSENT',
					'granted' => true,
				],
			],
		];

		$response = wp_remote_post(
			$this->get_api_base_url($test_mode) . '/v2/customer/partner-referrals',
			[
				'headers' => [
					'Authorization'                 => 'Bearer ' . $access_token,
					'Content-Type'                  => 'application/json',
					'PayPal-Partner-Attribution-Id' => self::BN_CODE,
				],
				'body'    => wp_json_encode($referral_data),
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return new \WP_REST_Response(
				['error' => 'Failed to create partner referral: ' . $response->get_error_message()],
				500
			);
		}

		$resp_body = json_decode(wp_remote_retrieve_body($response), true);
		$resp_code = wp_remote_retrieve_response_code($response);

		if (201 !== $resp_code || empty($resp_body['links'])) {
			$error_msg = $resp_body['message'] ?? 'Failed to create partner referral';

			return new \WP_REST_Response(
				['error' => $error_msg],
				500
			);
		}

		// Find the action_url link
		$action_url = '';

		foreach ($resp_body['links'] as $link) {
			if ('action_url' === $link['rel']) {
				$action_url = $link['href'];

				break;
			}
		}

		if (empty($action_url)) {
			return new \WP_REST_Response(
				['error' => 'No action URL returned from PayPal'],
				500
			);
		}

		return new \WP_REST_Response(
			[
				'actionUrl'  => $action_url,
				'trackingId' => $tracking_id,
			],
			200
		);
	}

	/**
	 * Handle POST /oauth/verify
	 *
	 * After merchant completes onboarding and returns to the customer site,
	 * the customer site calls this endpoint to verify the merchant's status
	 * using the partner credentials (which the customer site doesn't have).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle_oauth_verify(\WP_REST_Request $request): \WP_REST_Response {

		$body = $request->get_json_params();

		$merchant_id = $body['merchantId'] ?? '';
		$tracking_id = $body['trackingId'] ?? '';
		$test_mode   = (bool) ($body['testMode'] ?? true);

		if (empty($merchant_id) || empty($tracking_id)) {
			return new \WP_REST_Response(
				['error' => 'merchantId and trackingId are required'],
				400
			);
		}

		// Verify the tracking ID was created by us
		$onboarding_data = get_transient('wu_pp_onboarding_' . $tracking_id);

		if (! $onboarding_data) {
			return new \WP_REST_Response(
				['error' => 'Invalid or expired tracking ID'],
				400
			);
		}

		// Clean up the tracking transient
		delete_transient('wu_pp_onboarding_' . $tracking_id);

		$access_token = $this->get_partner_access_token($test_mode);

		if (is_wp_error($access_token)) {
			return new \WP_REST_Response(
				['error' => $access_token->get_error_message()],
				500
			);
		}

		$credentials = $this->get_partner_credentials($test_mode);

		if (empty($credentials['merchant_id'])) {
			// Without partner merchant ID, return basic success
			return new \WP_REST_Response(
				[
					'merchantId'         => $merchant_id,
					'paymentsReceivable' => true,
					'emailConfirmed'     => true,
				],
				200
			);
		}

		$response = wp_remote_get(
			$this->get_api_base_url($test_mode) . '/v1/customer/partners/' . $credentials['merchant_id'] . '/merchant-integrations/' . $merchant_id,
			[
				'headers' => [
					'Authorization'                 => 'Bearer ' . $access_token,
					'Content-Type'                  => 'application/json',
					'PayPal-Partner-Attribution-Id' => self::BN_CODE,
				],
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return new \WP_REST_Response(
				['error' => 'Failed to verify merchant: ' . $response->get_error_message()],
				500
			);
		}

		$resp_body = json_decode(wp_remote_retrieve_body($response), true);
		$resp_code = wp_remote_retrieve_response_code($response);

		if (200 !== $resp_code) {
			$error_msg = $resp_body['message'] ?? 'Failed to verify merchant status';

			return new \WP_REST_Response(
				['error' => $error_msg],
				$resp_code
			);
		}

		return new \WP_REST_Response(
			[
				'merchantId'         => $resp_body['merchant_id'] ?? $merchant_id,
				'trackingId'         => $resp_body['tracking_id'] ?? $tracking_id,
				'paymentsReceivable' => $resp_body['payments_receivable'] ?? false,
				'emailConfirmed'     => $resp_body['primary_email_confirmed'] ?? false,
			],
			200
		);
	}

	/**
	 * Handle POST /deauthorize
	 *
	 * Notification that a customer site has disconnected.
	 * Used for logging/cleanup. Non-blocking from the client side.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function handle_deauthorize(\WP_REST_Request $request): \WP_REST_Response {

		$body = $request->get_json_params();

		$site_url  = $body['siteUrl'] ?? 'unknown';
		$test_mode = (bool) ($body['testMode'] ?? true);
		$mode      = $test_mode ? 'sandbox' : 'live';

		// Log the disconnect for auditing
		error_log(sprintf('[PayPal Connect] Site disconnected: %s (mode: %s)', $site_url, $mode));

		return new \WP_REST_Response(
			['success' => true],
			200
		);
	}
}
