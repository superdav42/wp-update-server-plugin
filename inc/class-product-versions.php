<?php
/**
 * Product Versions Handler
 *
 * Handles retrieval of all versions for downloadable products.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

class Product_Versions {

	/**
	 * Cache group for version data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wu_product_versions';

	/**
	 * Cache expiration in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Get all versions of a product by SKU.
	 *
	 * @param string $sku The product SKU.
	 * @return array Array of version data: [['version' => '2.0.0', 'file_id' => 'abc', 'name' => '...'], ...]
	 */
	public static function get_all_versions(string $sku): array {

		$product_id = wc_get_product_id_by_sku($sku);

		if ( ! $product_id) {
			return [];
		}

		return self::get_all_versions_by_product_id($product_id);
	}

	/**
	 * Get all versions of a product by product ID.
	 *
	 * @param int $product_id The product ID.
	 * @return array Array of version data.
	 */
	public static function get_all_versions_by_product_id(int $product_id): array {

		$product = wc_get_product($product_id);

		if ( ! $product || ! $product->exists() || ! $product->is_downloadable()) {
			return [];
		}

		// Check cache
		$cache_key = 'versions_' . $product_id;
		$cached    = get_transient($cache_key);

		if ($cached !== false) {
			return $cached;
		}

		$versions = [];
		$files    = $product->get_downloads();

		foreach ($files as $file_id => $file) {
			if ( ! $file->get_enabled()) {
				continue;
			}

			$version_info = self::extract_version_from_file($product, $file_id, $file);

			if ($version_info) {
				$versions[] = array_merge(
					$version_info,
					[
						'file_id' => $file_id,
						'name'    => $file->get_name(),
					]
				);
			}
		}

		// Sort by version descending (latest first)
		usort($versions, function ($a, $b) {
			return version_compare($b['version'], $a['version']);
		});

		// Cache the results
		set_transient($cache_key, $versions, self::CACHE_EXPIRATION);

		return $versions;
	}

	/**
	 * Extract version information from a download file.
	 *
	 * @param \WC_Product              $product The product.
	 * @param string                   $file_id The file ID.
	 * @param \WC_Product_Download     $file    The download file.
	 * @return array|null Version info or null if extraction failed.
	 */
	private static function extract_version_from_file(\WC_Product $product, string $file_id, \WC_Product_Download $file): ?array {

		$file_info = \WC_Download_Handler::parse_file_path($product->get_file_download_path($file_id));
		$filepath  = $file_info['file_path'];

		// Handle remote files
		if ($file_info['remote_file']) {
			// Try to extract version from filename first to avoid downloading
			$version = self::extract_version_from_filename($file->get_name());

			if ($version) {
				return ['version' => $version];
			}

			// Download temporarily if we need to inspect the archive
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = wp_tempnam($file->get_name());
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			file_put_contents($tmp, file_get_contents($filepath));
			$filepath = $tmp;
		}

		if ( ! is_file($filepath) || ! is_readable($filepath)) {
			return null;
		}

		// Try to get version from Wpup_Package
		try {
			$package  = \Wpup_Package::fromArchive($filepath);
			$metadata = $package->getMetadata();

			$version_info = ['version' => $metadata['version'] ?? '0.0.0'];

			// Clean up temp file if we created one
			if (isset($tmp) && $filepath === $tmp && file_exists($tmp)) {
				wp_delete_file($tmp);
			}

			return $version_info;
		} catch (\Exception $e) {
			// Fallback to filename extraction
			$version = self::extract_version_from_filename($file->get_name());

			// Clean up temp file if we created one
			if (isset($tmp) && file_exists($tmp)) {
				wp_delete_file($tmp);
			}

			return $version ? ['version' => $version] : null;
		}
	}

	/**
	 * Extract version from filename.
	 *
	 * @param string $filename The filename.
	 * @return string|null The version or null.
	 */
	private static function extract_version_from_filename(string $filename): ?string {

		// Match patterns like: plugin-name-1.2.3.zip or plugin-name-v1.2.3.zip
		if (preg_match('/-v?(\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.]+)?)\.zip$/i', $filename, $matches)) {
			return $matches[1];
		}

		// Match pattern from file name like "Plugin Name - 1.2.3"
		if (preg_match('/ - (\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.]+)?)$/i', $filename, $matches)) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Get user's accessible products with all their versions.
	 *
	 * @param int $user_id The user ID.
	 * @return array Array of products with versions and download URLs.
	 */
	public static function get_user_products_with_versions(int $user_id): array {

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		// Get all download permissions for this user
		$permissions = $downloads_data_store->get_downloads([
			'user_id' => $user_id,
		]);

		// Group by product
		$products_map = [];

		foreach ($permissions as $permission) {
			$product_id = $permission->get_product_id();

			if (isset($products_map[$product_id])) {
				continue; // Already processed this product
			}

			$product = wc_get_product($product_id);

			if ( ! $product || ! $product->exists()) {
				continue;
			}

			$versions = self::get_all_versions_by_product_id($product_id);

			// Build download URLs for each version
			$versions_with_urls = [];
			foreach ($versions as $version_data) {
				$versions_with_urls[] = array_merge(
					$version_data,
					[
						'download_url' => self::get_version_download_url_by_permission($permission, $version_data['file_id']),
					]
				);
			}

			$products_map[$product_id] = [
				'product_id'    => $product_id,
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'icon'          => Product_Icon::get_product_icon($product_id, 'thumbnail'),
				'type'          => self::get_product_type($product_id),
				'requires'      => get_post_meta($product_id, '_requires_wp', true),
				'tested_up_to'  => get_post_meta($product_id, '_tested_up_to', true),
				'versions'      => $versions_with_urls,
				'latest_version' => ! empty($versions_with_urls) ? $versions_with_urls[0]['version'] : null,
			];
		}

		return array_values($products_map);
	}

	/**
	 * Get download URL for a specific version using permission.
	 *
	 * @param \WC_Customer_Download $permission The download permission.
	 * @param string                $file_id    The specific file ID for the version.
	 * @return string The download URL.
	 */
	private static function get_version_download_url_by_permission(\WC_Customer_Download $permission, string $file_id): string {

		return add_query_arg(
			[
				'download_file' => $permission->get_product_id(),
				'order'         => $permission->get_order_key(),
				'email'         => rawurlencode($permission->get_user_email()),
				'key'           => $file_id,
			],
			home_url('/')
		);
	}

	/**
	 * Get download URL for specific product version.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $sku     The product SKU.
	 * @param string $version The version to download.
	 * @return string|null The download URL or null if not accessible.
	 */
	public static function get_version_download_url(int $user_id, string $sku, string $version): ?string {

		$product_id = wc_get_product_id_by_sku($sku);

		if ( ! $product_id) {
			return null;
		}

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		$permissions = $downloads_data_store->get_downloads([
			'product_id' => $product_id,
			'user_id'    => $user_id,
			'limit'      => 1,
		]);

		if (empty($permissions)) {
			return null;
		}

		$permission = $permissions[0];

		// Find the file ID for the requested version
		$versions = self::get_all_versions_by_product_id($product_id);

		foreach ($versions as $version_data) {
			if ($version_data['version'] === $version) {
				return self::get_version_download_url_by_permission($permission, $version_data['file_id']);
			}
		}

		return null;
	}

	/**
	 * Determine product type (plugin or theme).
	 *
	 * @param int $product_id The product ID.
	 * @return string 'plugin' or 'theme'.
	 */
	private static function get_product_type(int $product_id): string {

		// Check product tags first
		$terms = get_the_terms($product_id, 'product_tag');

		if ( ! empty($terms) && ! is_wp_error($terms)) {
			foreach ($terms as $term) {
				if ($term->slug === 'theme' || $term->slug === 'themes') {
					return 'theme';
				}
			}
		}

		// Check product meta
		$type_meta = get_post_meta($product_id, '_product_type_software', true);

		if ($type_meta === 'theme') {
			return 'theme';
		}

		return 'plugin';
	}

	/**
	 * Check if a version string is a pre-release (alpha, beta, rc, etc.).
	 *
	 * @param string $version The version string.
	 * @return bool True if the version is a pre-release.
	 */
	public static function is_prerelease(string $version): bool {

		return (bool) preg_match('/-(?:alpha|beta|rc|dev|preview)\b/i', $version);
	}

	/**
	 * Get the latest version for a product, optionally filtering out pre-releases.
	 *
	 * @param int  $product_id          The product ID.
	 * @param bool $include_prerelease  Whether to include pre-release versions.
	 * @return array|null Latest version data or null if none found.
	 */
	public static function get_latest_version_by_product_id(int $product_id, bool $include_prerelease = false): ?array {

		$versions = self::get_all_versions_by_product_id($product_id);

		if (empty($versions)) {
			return null;
		}

		foreach ($versions as $version_data) {
			if ($include_prerelease || ! self::is_prerelease($version_data['version'])) {
				return $version_data;
			}
		}

		return null;
	}

	/**
	 * Clear version cache for a product.
	 *
	 * @param int $product_id The product ID.
	 * @return void
	 */
	public static function clear_cache(int $product_id): void {

		delete_transient('versions_' . $product_id);
	}
}
