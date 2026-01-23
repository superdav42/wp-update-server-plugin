<?php
/**
 * Downloads Page Handler
 *
 * Overrides the WooCommerce account downloads page with an enhanced Freemius-like interface.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Downloads_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_filter('woocommerce_locate_template', [$this, 'override_downloads_template'], 10, 3);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

		// AJAX handlers
		add_action('wp_ajax_wu_get_composer_data', [$this, 'ajax_get_composer_data']);
		add_action('wp_ajax_wu_generate_token', [$this, 'ajax_generate_token']);
		add_action('wp_ajax_wu_revoke_token', [$this, 'ajax_revoke_token']);
	}

	/**
	 * Override the WooCommerce downloads template.
	 *
	 * @param string $template      The template path.
	 * @param string $template_name The template name.
	 * @param string $template_path The template directory path.
	 * @return string The filtered template path.
	 */
	public function override_downloads_template(string $template, string $template_name, string $template_path): string {

		if ($template_name === 'myaccount/downloads.php') {
			$custom_template = dirname(__DIR__) . '/templates/downloads-table.php';

			if (file_exists($custom_template)) {
				return $custom_template;
			}
		}

		return $template;
	}

	/**
	 * Enqueue CSS and JS on the my-account page.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {

		if ( ! is_account_page()) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'wu-downloads-page',
			plugins_url('assets/css/downloads-page.css', dirname(__FILE__)),
			[],
			'1.0.0'
		);

		// Enqueue JS
		wp_enqueue_script(
			'wu-downloads-page',
			plugins_url('assets/js/downloads-page.js', dirname(__FILE__)),
			['jquery'],
			'1.0.0',
			true
		);

		// Localize script
		wp_localize_script(
			'wu-downloads-page',
			'wuDownloads',
			[
				'ajaxUrl'          => admin_url('admin-ajax.php'),
				'nonce'            => wp_create_nonce('wu_downloads_nonce'),
				'repositoryUrl'    => Composer_Repository::get_repository_url(),
				'i18n'             => [
					'copied'           => __('Copied to clipboard!', 'wp-update-server-plugin'),
					'copyFailed'       => __('Copy failed. Please select and copy manually.', 'wp-update-server-plugin'),
					'generating'       => __('Generating...', 'wp-update-server-plugin'),
					'tokenGenerated'   => __('Token generated successfully! Copy it now - it won\'t be shown again.', 'wp-update-server-plugin'),
					'tokenRevoked'     => __('Token revoked successfully.', 'wp-update-server-plugin'),
					'error'            => __('An error occurred. Please try again.', 'wp-update-server-plugin'),
					'confirmRevoke'    => __('Are you sure you want to revoke this token? Any composer.json or auth.json files using this token will stop working.', 'wp-update-server-plugin'),
				],
			]
		);
	}

	/**
	 * Get enhanced downloads data for the current user.
	 *
	 * @return array The downloads data.
	 */
	public static function get_downloads_data(): array {

		$user_id = get_current_user_id();

		if ( ! $user_id) {
			return [];
		}

		return Product_Versions::get_user_products_with_versions($user_id);
	}

	/**
	 * AJAX handler: Get composer configuration data.
	 *
	 * @return void
	 */
	public function ajax_get_composer_data(): void {

		check_ajax_referer('wu_downloads_nonce', 'nonce');

		if ( ! is_user_logged_in()) {
			wp_send_json_error(['message' => 'Not logged in.'], 401);
		}

		$user_id = get_current_user_id();
		$tokens  = Composer_Token::get_user_tokens($user_id);

		wp_send_json_success([
			'tokens'         => $tokens,
			'repository_url' => Composer_Repository::get_repository_url(),
		]);
	}

	/**
	 * AJAX handler: Generate a new token.
	 *
	 * @return void
	 */
	public function ajax_generate_token(): void {

		check_ajax_referer('wu_downloads_nonce', 'nonce');

		if ( ! is_user_logged_in()) {
			wp_send_json_error(['message' => 'Not logged in.'], 401);
		}

		$user_id = get_current_user_id();
		$name    = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : 'Default';

		// Limit tokens per user
		$existing_count = Composer_Token_Table::count_user_tokens($user_id);

		if ($existing_count >= 10) {
			wp_send_json_error([
				'message' => 'Maximum number of tokens (10) reached. Please revoke an existing token first.',
			], 400);
		}

		$raw_token = Composer_Token::generate($user_id, $name);

		if ($raw_token === false) {
			wp_send_json_error(['message' => 'Failed to generate token.'], 500);
		}

		// Get updated token list (without the raw token for security)
		$tokens = Composer_Token::get_user_tokens($user_id);

		wp_send_json_success([
			'token'  => $raw_token, // This is the only time the full token is available
			'tokens' => $tokens,
		]);
	}

	/**
	 * AJAX handler: Revoke a token.
	 *
	 * @return void
	 */
	public function ajax_revoke_token(): void {

		check_ajax_referer('wu_downloads_nonce', 'nonce');

		if ( ! is_user_logged_in()) {
			wp_send_json_error(['message' => 'Not logged in.'], 401);
		}

		$user_id  = get_current_user_id();
		$token_id = isset($_POST['token_id']) ? absint($_POST['token_id']) : 0;

		if ( ! $token_id) {
			wp_send_json_error(['message' => 'Invalid token ID.'], 400);
		}

		$result = Composer_Token::revoke($token_id, $user_id);

		if ( ! $result) {
			wp_send_json_error(['message' => 'Failed to revoke token.'], 500);
		}

		// Get updated token list
		$tokens = Composer_Token::get_user_tokens($user_id);

		wp_send_json_success([
			'tokens' => $tokens,
		]);
	}
}
