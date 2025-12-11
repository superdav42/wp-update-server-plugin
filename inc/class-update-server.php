<?php
/**
 * Update server class.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Update server class.
 */
class Update_Server extends \Wpup_UpdateServer {

	/**
	 * Use our prefixed query vars.
	 *
	 * @param \Wpup_Package $package the package.
	 *
	 * @return string
	 */
	protected function generateDownloadUrl(\Wpup_Package $package) {
		$user_id = apply_filters('determine_current_user', null);
		if ($user_id) {
			$download_url = $this->getDownloadUrlForUser($user_id, $package->slug);
			if ($download_url) {
				return $download_url;
			}
		}
		$query = [
			'update_action' => 'download',
			'update_slug'   => $package->slug,
		];

		return self::addQueryArg($query, $this->serverUrl);
	}

	/**
	 * Retrieves the download URL for a specific user and product specified by its SKU.
	 *
	 * @param int $user_id The ID of the user for whom the download URL is being retrieved.
	 * @param string $slug The SKU identifying the product for which the download URL is needed.
	 *
	 * @return string|null The download URL if available, or null if no valid downloads are found.
	 */
	protected function getDownloadUrlForUser($user_id, $slug) {
		/** @var \WC_Product_Data_Store_Interface $product_data_store */
		$product_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'product');
		$product_id         = $product_data_store->get_product_id_by_sku($slug);

		/** @var \WC_Customer_Download_Data_Store $downloads_data_store */
		$downloads_data_store = wc_get_container()->get(LegacyProxy::class)->get_instance_of(\WC_Data_Store::class, 'customer-download');

		$permissions = $downloads_data_store->get_downloads(
			array(
				'product_id' => $product_id,
				'user_id'    => $user_id,
			)
		);

		if ( ! empty($permissions) ) {
			foreach ( $permissions as $permission ) {
				/** @var \WC_Customer_Download $permission */
				return Store_Api::get_download_url($permission);
			}
		}

		// Check if the current user has valid purchase history for this product.
		$customer_orders = wc_get_orders(
			array(
				'limit'       => -1,
				'customer_id' => $user_id,
				'status'      => array('wc-completed'), // Only completed orders
			)
		);

		foreach ($customer_orders as $order) {
			foreach ($order->get_items() as $item) {
				$item_product = $item->get_product();
				if ($item_product && $item_product->get_sku() === $slug) {
					$downloads = $item->get_item_downloads();
					uasort(
						$downloads,
						function ($a, $b) {
							return version_compare(substr(strrchr($a['name'], '-'), 1), substr(strrchr($b['name'], '-'), 1));
						}
					);
					$download = end($downloads);
					if ($download) {
						return $download['download_url'];
					}
				}
			}
		}
		return null;
	}

	/**
	 * Finds the most recent package associated with a given product slug.
	 *
	 * @param string $slug The product slug to search for the package.
	 *
	 * @return \Wpup_Package|null The latest package if found, otherwise null.
	 */
	protected function findPackage($slug) {
		$product_id = wc_get_product_id_by_sku($slug);
		$product    = wc_get_product($product_id);

		$latest_version = null;
		if ($product && $product->exists() && $product->is_downloadable()) {
			$files = $product->get_downloads();
			foreach ($files as $file_id => $file) {
				$file_info = \WC_Download_Handler::parse_file_path($product->get_file_download_path($file_id));
				$filepath  = $file_info['file_path'];

				if ( $file_info['remote_file'] ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					$tmp = wp_tempnam($file['name']);
					file_put_contents($tmp, file_get_contents($filepath));
					$filepath = $tmp;
				}

				if ($file->get_enabled() && is_file($filepath) && is_readable($filepath)) {
					/** @var \Wpup_Package $package */
					$package = call_user_func($this->packageFileLoader, $filepath, $slug, $this->cache);
				}

				if (empty($latest_version) || version_compare($package->getMetadata()['version'], $latest_version->getMetadata()['version'], '>')) {
					$latest_version = $package;
				}
			}
		}
		return $latest_version;
	}

	/**
	 * Handles the download action for a package request.
	 *
	 * @param \Wpup_Request $request The request object containing package details.
	 *
	 * @return void
	 */
	protected function actionDownload(\Wpup_Request $request) {
		$package = $request->package;
		$user_id = apply_filters('determine_current_user', null);
		if ($user_id) {
			$download_url = $this->getDownloadUrlForUser($user_id, $package->slug);
			if ($download_url) {
				wp_safe_redirect($download_url);
				exit();
			}
		}

		$this->exitWithError('You do not have a valid order for this package.', 403);
	}
}
