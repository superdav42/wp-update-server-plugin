<?php

namespace WP_Update_Server_Plugin;

class Store_Api {

	/**
	 * Initialize the store API functionality.
	 */
	public function __construct() {
		add_action('rest_api_init', array($this, 'register_product_icon_data'));
		add_filter('woocommerce_rest_prepare_product_object', array($this, 'add_product_icon_to_api'), 10, 3);
	}

	/**
	 * Register product icon data with WooCommerce Store API.
	 */
	public function register_product_icon_data() {
		if (function_exists('woocommerce_store_api_register_endpoint_data')) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema::IDENTIFIER,
					'namespace'       => 'wp-update-server-plugin',
					'data_callback'   => array($this, 'get_product_icon_data'),
					'schema_callback' => array($this, 'get_product_icon_schema'),
					'schema_type'     => ARRAY_A,
				)
			);
		}
	}

	/**
	 * Get product icon data for Store API.
	 *
	 * @param \WC_Product $product The product data.
	 * @return array
	 */
	public function get_product_icon_data($product): array {
		$product_id = $product->get_id();

		if (! $product_id) {
			return array();
		}
		$icon_full_url = Product_Icon::get_product_icon($product_id, 'full');

		$user_id = apply_filters('determine_current_user', null);

		$download_url = '';
		if ($user_id) {
			// Check if the current user has valid purchase history for this product
			$customer_orders = wc_get_orders(array(
				'limit'       => - 1,
				'customer_id' => $user_id,
				'status'      => array('wc-completed'), // Only completed orders
			));

			foreach ($customer_orders as $order) {
				foreach ($order->get_items() as $item) {
					$item_product = $item->get_product();
					if ($item_product && $item_product->get_sku() === $product->get_sku()) {
						$downloads = $item->get_item_downloads();
						$download  = reset($downloads);
						if ($download) {
							$download_url = $download['download_url'];
						}
					}
				}
			}
		}

		return array(
			'author' => [
				'display_name' => 'David Stone',
			],
			'download_url' => $download_url,
			'icon' => $icon_full_url,
			'beta' => $this->is_beta_product_by_id($product_id),
			'legacy' => $this->is_legacy_product_by_id($product_id),
			'tested_up_to' => get_post_meta($product_id, '_tested_up_to', true),
			'requires' => get_post_meta($product_id, '_requires_wp', true),
			'active_installs' => get_post_meta($product_id, '_active_installs', true),
		);
	}

	/**
	 * Get schema for product icon data.
	 *
	 * @return array
	 */
	public function get_product_icon_schema() {
		return array(
			'icon' => array(
				'description' => __('Full size icon URL.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'format'      => 'uri',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'beta' => array(
				'description' => __('Whether the product is marked as beta.', 'wp-update-server-plugin'),
				'type'        => 'boolean',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'legacy' => array(
				'description' => __('Whether the product is marked as legacy.', 'wp-update-server-plugin'),
				'type'        => 'boolean',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'tested_up_to' => array(
				'description' => __('WordPress version the product has been tested up to.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'requires' => array(
				'description' => __('Minimum WordPress version required.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
			'active_installs' => array(
				'description' => __('Number of active installations.', 'wp-update-server-plugin'),
				'type'        => 'string',
				'context'     => array('view'),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Add product icon data to WooCommerce REST API responses.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WC_Product       $product The product object.
	 * @param \WP_REST_Request  $request The request object.
	 * @return \WP_REST_Response
	 */
	public function add_product_icon_to_api($response, $product, $request) {
		$icon_url = Product_Icon::get_product_icon($product->get_id(), 'thumbnail');
		$icon_full_url = Product_Icon::get_product_icon($product->get_id(), 'full');

		$response->data['icon'] = $icon_url ? array(
			'thumbnail' => $icon_url,
			'full' => $icon_full_url,
			'id' => Product_Icon::get_product_icon_id($product->get_id()),
		) : null;

		// Add additional metadata to REST API
		$response->data['author'] = [
			'display_name' => 'David Stone',
		];
		$response->data['beta'] = $this->is_beta_product_by_id($product->get_id());
		$response->data['legacy'] = $this->is_legacy_product_by_id($product->get_id());
		$response->data['tested_up_to'] = get_post_meta($product->get_id(), '_tested_up_to', true);
		$response->data['requires'] = get_post_meta($product->get_id(), '_requires_wp', true);
		$response->data['active_installs'] = get_post_meta($product->get_id(), '_active_installs', true);

		return $response;
	}

	/**
	 * Check if product is marked as beta by ID.
	 *
	 * @param int $product_id The product ID.
	 * @return bool
	 */
	private function is_beta_product_by_id($product_id) {
		$beta_meta = get_post_meta($product_id, '_is_beta', true);
		return $beta_meta === 'yes' || $beta_meta === '1';
	}

	/**
	 * Check if product is marked as legacy by ID.
	 *
	 * @param int $product_id The product ID.
	 * @return bool
	 */
	private function is_legacy_product_by_id($product_id) {
		$legacy_meta = get_post_meta($product_id, '_is_legacy', true);
		return 'yes' === $legacy_meta  || '1' === $legacy_meta;
	}
}