<?php
/**
 * Regression checks for Downloads_Page WooCommerce availability guards.
 *
 * @package WP_Update_Server_Plugin
 */

declare(strict_types=1);

namespace WP_Update_Server_Plugin {
	require_once dirname(__DIR__) . '/inc/class-downloads-page.php';
}

namespace {
	/**
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	function assert_true(bool $condition, string $message): void {

		if ( ! $condition) {
			throw new RuntimeException($message);
		}
	}

	assert_true( ! function_exists('is_account_page'), 'WooCommerce is_account_page() should be unavailable in this regression test');

	$reflection = new ReflectionClass(\WP_Update_Server_Plugin\Downloads_Page::class);
	$downloads  = $reflection->newInstanceWithoutConstructor();

	$downloads->enqueue_assets();

	fwrite(STDOUT, "Downloads_Page WooCommerce guard regression checks passed.\n");
}
