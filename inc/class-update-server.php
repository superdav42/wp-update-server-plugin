<?php

namespace WP_Update_Server_Plugin;

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
			$download_url = $this->getDownloadUrlForUser( $user_id, $package->slug );
			if ($download_url) {
				return self::addQueryArg(['XDEBUG_SESSION' => 'XDEBUG_ECLIPSE'], $download_url);
			}
		}
		$query = [
			'update_action'  => 'download',
			'update_slug'    => $package->slug,
		];

		return self::addQueryArg($query, $this->serverUrl);
	}

	protected function getDownloadUrlForUser($user_id, $slug) {

		// Find downloadable product by SKU ($slug)
		$args = [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'meta_key'       => '_sku',
			'meta_value'     => $slug,
			'meta_compare'   => '='
		];
		$product_query = new \WP_Query($args);

		if (empty($product_query->posts)) {
			return null; // No product found with this slug
		}

		$product_id = $product_query->posts[0]->ID;
		$product = wc_get_product($product_id);

		if (!$product || !$product->is_downloadable()) {
			return null; // Product not downloadable or invalid
		}

		// Check if the current user has valid purchase history for this product
		$customer_orders = wc_get_orders(array(
			'limit' => -1,
			'customer_id' => $user_id,
			'status' => array('wc-completed'), // Only completed orders
		));

		foreach ($customer_orders as $order) {
			foreach ($order->get_items() as $item) {
				$item_product = $item->get_product();
				if ($item_product && $item_product->get_sku() === $slug) {
					$downloads = $item->get_item_downloads();
					$download  = reset($downloads);
					if ($download) {
						return $download['download_url'];
					}
				}
			}
		}
		return null;
	}
	protected function findPackage($slug) {
		$product_id = wc_get_product_id_by_sku($slug);
		$product    = wc_get_product($product_id);

		$latest_version = null;
		if ($product && $product->exists() && $product->is_downloadable()) {
			$files = $product->get_downloads();
			foreach($files as $file_id => $file) {

				$file_info = \WC_Download_Handler::parse_file_path($product->get_file_download_path($file_id));
				$filepath = $file_info['file_path'];

				if ( $file_info['remote_file'] ) {
					$tmp = wp_tempnam($file['name']);
					file_put_contents($tmp, file_get_contents($filepath));
					$filepath = $tmp;
				}

				if ($file->get_enabled() && is_file($filepath) && is_readable($filepath)) {
					/** @var \Wpup_Package $package */
					$package = call_user_func($this->packageFileLoader, $filepath, $slug, $this->cache);
				}

				if (empty($latest_version) || version_compare($package->getMetadata()['version'], $latest_version->getMetadata()['version'], '>' )) {
					$latest_version = $package;
				}
			}
		}
		return $latest_version;
	}

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
