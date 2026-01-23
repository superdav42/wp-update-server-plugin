<?php
/**
 * Composer Repository REST API
 *
 * Provides a Composer-compatible packages.json endpoint with token authentication.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

class Composer_Repository {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'wu-composer/v1';

	/**
	 * Composer package vendor prefix.
	 *
	 * @var string
	 */
	const VENDOR_PREFIX = 'ultimate-multisite';

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
			self::REST_NAMESPACE,
			'/packages.json',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_packages_request'],
				'permission_callback' => [$this, 'check_token_permission'],
			]
		);
	}

	/**
	 * Check token permission for the request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function check_token_permission(\WP_REST_Request $request) {

		$token = $this->extract_token($request);

		if (empty($token)) {
			return new \WP_Error(
				'missing_token',
				'Authentication token is required. Provide via Authorization header or token query parameter.',
				['status' => 401]
			);
		}

		$user_id = Composer_Token::validate($token);

		if ($user_id === null) {
			return new \WP_Error(
				'invalid_token',
				'Invalid or expired authentication token.',
				['status' => 401]
			);
		}

		// Store user_id for use in callback
		$request->set_param('_authenticated_user_id', $user_id);

		return true;
	}

	/**
	 * Extract token from request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return string|null The token or null.
	 */
	private function extract_token(\WP_REST_Request $request): ?string {

		// Try Authorization header first (Bearer token)
		$auth_header = $request->get_header('Authorization');

		if ($auth_header && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
			return trim($matches[1]);
		}

		// Fall back to query parameter
		$token = $request->get_param('token');

		if ( ! empty($token)) {
			return sanitize_text_field($token);
		}

		return null;
	}

	/**
	 * Handle packages.json request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_packages_request(\WP_REST_Request $request): \WP_REST_Response {

		$user_id = $request->get_param('_authenticated_user_id');

		$packages_json = $this->build_packages_json($user_id);

		$response = new \WP_REST_Response($packages_json, 200);

		// Set appropriate caching headers
		$response->header('Cache-Control', 'private, max-age=300'); // 5 minutes
		$response->header('Content-Type', 'application/json');

		return $response;
	}

	/**
	 * Build the packages.json structure for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return array The packages.json structure.
	 */
	private function build_packages_json(int $user_id): array {

		$products = Product_Versions::get_user_products_with_versions($user_id);
		$packages = [];

		foreach ($products as $product) {
			$package_name = $this->get_composer_package_name($product['sku']);

			if (empty($package_name)) {
				continue;
			}

			$packages[$package_name] = [];

			foreach ($product['versions'] as $version_data) {
				$version = $version_data['version'];

				$packages[$package_name][$version] = [
					'name'    => $package_name,
					'version' => $version,
					'type'    => $product['type'] === 'theme' ? 'wordpress-theme' : 'wordpress-plugin',
					'dist'    => [
						'url'  => $version_data['download_url'],
						'type' => 'zip',
					],
					'require' => [
						'php' => '>=7.4',
					],
				];

				// Add WordPress version requirement if specified
				if ( ! empty($product['requires'])) {
					$packages[$package_name][$version]['require']['wordpress/core'] = '>=' . $product['requires'];
				}
			}
		}

		return [
			'packages' => $packages,
		];
	}

	/**
	 * Convert product SKU to Composer package name.
	 *
	 * @param string $sku The product SKU.
	 * @return string The Composer package name.
	 */
	private function get_composer_package_name(string $sku): string {

		if (empty($sku)) {
			return '';
		}

		// Convert SKU to lowercase, replace underscores with hyphens
		$slug = strtolower(str_replace('_', '-', $sku));

		return self::VENDOR_PREFIX . '/' . $slug;
	}

	/**
	 * Get the repository URL for composer.json configuration.
	 *
	 * @return string The repository URL.
	 */
	public static function get_repository_url(): string {

		return rest_url(self::REST_NAMESPACE . '/packages.json');
	}
}
