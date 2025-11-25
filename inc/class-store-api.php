<?php

namespace WP_Update_Server_Plugin;

class Store_Api {

	/**
	 * Initialize the store API functionality.
	 */
	public function __construct() {
		add_action('rest_api_init', array($this, 'register_product_icon_data'));
		add_filter('woocommerce_rest_prepare_product_object', array($this, 'add_product_icon_to_api'), 10, 3);
		add_filter('woocommerce_product_data_store_cpt_get_products_query', array($this, 'modify_subscription_query'), 10, 3);
		add_action('pre_get_posts', array($this, 'modify_store_api_subscription_query'), 10);
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

	/**
	 * Modify product query to include simple products with subscriptions when type=subscription is requested.
	 *
	 * @param array $wp_query_args WP_Query arguments.
	 * @param array $query_vars    Query variables from wc_get_products().
	 * @param mixed $data_store    The data store instance.
	 * @return array Modified WP_Query arguments.
	 */
	public function modify_subscription_query($wp_query_args, $query_vars, $data_store) {
		// Check if type=subscription is requested
		if (isset($query_vars['type']) && $query_vars['type'] === 'subscription') {
			// Change the tax_query to look for 'simple' product type instead of 'subscription'
			// since we now use simple products that are converted to subscriptions via
			// WooCommerce All Products for Subscriptions
			if (isset($wp_query_args['tax_query'])) {
				foreach ($wp_query_args['tax_query'] as $key => $tax_query) {
					if (isset($tax_query['taxonomy']) && $tax_query['taxonomy'] === 'product_type'
					    && isset($tax_query['terms']) && $tax_query['terms'] === 'subscription') {
						$wp_query_args['tax_query'][$key]['terms'] = 'simple';
					}
				}
			}

			// Add meta query to filter only products that have subscription schemes
			// and exclude products where subscriptions are disabled
			if (!isset($wp_query_args['meta_query'])) {
				$wp_query_args['meta_query'] = array();
			}

			// The WooCommerce All Products for Subscriptions plugin uses:
			// - _wcsatt_schemes: Contains the subscription schemes data
			// - _wcsatt_schemes_status: Can be 'inherit', 'override', or 'disable'
			$wp_query_args['meta_query'][] = array(
				'relation' => 'AND',
				array(
					'key'     => '_wcsatt_schemes',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_wcsatt_schemes_status',
					'value'   => 'disable',
					'compare' => '!=',
				),
			);
		}

		return $wp_query_args;
	}

	/**
	 * Modify Store API queries to include simple products with subscriptions when type=subscription is requested.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 */
	public function modify_store_api_subscription_query($query) {
		// Only modify product queries from the Store API
		if (!defined('REST_REQUEST') || !REST_REQUEST) {
			return;
		}

		// Check if this is a product query
		$post_type = $query->get('post_type');
		if ($post_type !== 'product') {
			return;
		}

		// Check if there's a tax_query filtering by product_type
		$tax_query = $query->get('tax_query');
		if (empty($tax_query) || !is_array($tax_query)) {
			return;
		}

		// Look for subscription type query and modify it
		$modified = false;
		foreach ($tax_query as $key => $tax_query_item) {
			if (isset($tax_query_item['taxonomy']) && $tax_query_item['taxonomy'] === 'product_type'
			    && isset($tax_query_item['terms']) && $tax_query_item['terms'] === 'subscription') {
				// Change subscription to simple
				$tax_query[$key]['terms'] = 'simple';
				$modified = true;
				break;
			}
		}

		// Only modify the query if we found a subscription type filter
		if (!$modified) {
			return;
		}

		// Update the tax_query
		$query->set('tax_query', $tax_query);

		// Add meta_query to exclude products where subscriptions are explicitly disabled
		$meta_query = $query->get('meta_query');
		if (empty($meta_query)) {
			$meta_query = array();
		}

		// The WooCommerce All Products for Subscriptions plugin uses:
		// - _wcsatt_schemes_status: Can be 'inherit', 'override', or 'disable'
		// - When not set or set to 'inherit', the product uses global subscription plans
		// - Only exclude products where status is explicitly set to 'disable'
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_wcsatt_schemes_status',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_wcsatt_schemes_status',
				'value'   => 'disable',
				'compare' => '!=',
			),
		);

		$query->set('meta_query', $meta_query);
	}
}
