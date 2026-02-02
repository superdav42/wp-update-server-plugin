<?php
/**
 * Changelog Manager
 *
 * Manages changelog functionality for WooCommerce products.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changelog Manager Class.
 */
class Changelog_Manager {

	/**
	 * Meta key for storing full changelog.
	 *
	 * @var string
	 */
	const META_KEY = '_wu_changelog';

	/**
	 * Meta key for storing latest version's changelog.
	 *
	 * Used for email notifications to show only the current release's changes.
	 *
	 * @var string
	 */
	const LATEST_META_KEY = '_wu_latest_changelog';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_downloads', [ $this, 'add_changelog_field' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_changelog_field' ] );
	}

	/**
	 * Add changelog textarea to the product Downloads tab.
	 *
	 * @return void
	 */
	public function add_changelog_field(): void {
		global $post;

		echo '<div class="options_group">';

		woocommerce_wp_textarea_input(
			[
				'id'          => self::META_KEY,
				'label'       => __( 'Changelog', 'wp-update-server-plugin' ),
				'description' => __( 'Enter the changelog for this release. This will be included in new release notification emails.', 'wp-update-server-plugin' ),
				'desc_tip'    => true,
				'placeholder' => __( "## What's New\n\n- Feature 1\n- Bug fix 1\n- Improvement 1", 'wp-update-server-plugin' ),
				'value'       => get_post_meta( $post->ID, self::META_KEY, true ),
				'style'       => 'min-height: 150px;',
			]
		);

		echo '</div>';
	}

	/**
	 * Save the changelog field.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function save_changelog_field( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification.
		if ( isset( $_POST[ self::META_KEY ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$changelog = sanitize_textarea_field( wp_unslash( $_POST[ self::META_KEY ] ) );
			update_post_meta( $post_id, self::META_KEY, $changelog );
		}
	}

	/**
	 * Get the full changelog for a product.
	 *
	 * @param int $product_id The product ID.
	 * @return string The changelog text.
	 */
	public static function get_changelog( int $product_id ): string {
		return (string) get_post_meta( $product_id, self::META_KEY, true );
	}

	/**
	 * Get the latest version's changelog for a product.
	 *
	 * This contains only the changes for the most recent version,
	 * suitable for email notifications.
	 *
	 * @param int $product_id The product ID.
	 * @return string The latest version's changelog text.
	 */
	public static function get_latest_changelog( int $product_id ): string {
		return (string) get_post_meta( $product_id, self::LATEST_META_KEY, true );
	}

	/**
	 * Get a changelog excerpt suitable for email notifications.
	 *
	 * Prefers the latest version's changelog if available, otherwise
	 * falls back to a truncated version of the full changelog.
	 *
	 * @param int $product_id The product ID.
	 * @param int $max_length Maximum length of the excerpt (only used for fallback).
	 * @return string The changelog excerpt.
	 */
	public static function get_changelog_excerpt( int $product_id, int $max_length = 500 ): string {
		// First try to get the latest version's changelog (preferred for emails)
		$latest_changelog = self::get_latest_changelog( $product_id );

		if ( ! empty( $latest_changelog ) ) {
			return $latest_changelog;
		}

		// Fallback to truncating the full changelog
		$changelog = self::get_changelog( $product_id );

		if ( empty( $changelog ) ) {
			return '';
		}

		if ( strlen( $changelog ) <= $max_length ) {
			return $changelog;
		}

		// Find a good break point (newline or space).
		$excerpt = substr( $changelog, 0, $max_length );
		$last_newline = strrpos( $excerpt, "\n" );
		$last_space = strrpos( $excerpt, ' ' );

		// Prefer breaking at newline, then space.
		if ( $last_newline !== false && $last_newline > $max_length * 0.7 ) {
			$excerpt = substr( $excerpt, 0, $last_newline );
		} elseif ( $last_space !== false ) {
			$excerpt = substr( $excerpt, 0, $last_space );
		}

		return trim( $excerpt ) . '...';
	}
}
