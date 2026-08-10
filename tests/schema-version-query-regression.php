<?php
/**
 * Regression checks for version-gated custom table discovery.
 *
 * @package WP_Update_Server_Plugin
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$schema_versions = [];

function add_action(string $hook, callable $callback): void {
	unset($hook, $callback);
}

function get_option(string $option, $default = false) {
	global $schema_versions;

	return $schema_versions[$option] ?? $default;
}

function update_option(string $option, $value, bool $autoload = true): bool {
	global $schema_versions;

	unset($autoload);
	$schema_versions[$option] = $value;

	return true;
}

require_once dirname(__DIR__) . '/inc/class-telemetry-table.php';
require_once dirname(__DIR__) . '/inc/class-passive-installs-table.php';
require_once dirname(__DIR__) . '/inc/class-site-discovery-table.php';
require_once dirname(__DIR__) . '/inc/class-composer-token-table.php';
require_once dirname(__DIR__) . '/inc/class-paypal-merchants-table.php';
require_once dirname(__DIR__) . '/inc/class-stripe-analytics-table.php';

$table_managers = [
	[new \WP_Update_Server_Plugin\Telemetry_Table(), 'maybe_create_table'],
	[new \WP_Update_Server_Plugin\Passive_Installs_Table(), 'maybe_create_table'],
	[new \WP_Update_Server_Plugin\Site_Discovery_Table(), 'maybe_create_table'],
	[new \WP_Update_Server_Plugin\Composer_Token_Table(), 'maybe_create_table'],
	[new \WP_Update_Server_Plugin\PayPal_Merchants_Table(), 'maybe_create_tables'],
	[new \WP_Update_Server_Plugin\Stripe_Analytics_Table(), 'maybe_create_tables'],
];

$manager_classes = [
	\WP_Update_Server_Plugin\Telemetry_Table::class,
	\WP_Update_Server_Plugin\Passive_Installs_Table::class,
	\WP_Update_Server_Plugin\Site_Discovery_Table::class,
	\WP_Update_Server_Plugin\Composer_Token_Table::class,
	\WP_Update_Server_Plugin\PayPal_Merchants_Table::class,
	\WP_Update_Server_Plugin\Stripe_Analytics_Table::class,
];

foreach ($manager_classes as $manager_class) {
	$schema_versions[$manager_class::SCHEMA_VERSION_OPTION] = $manager_class::SCHEMA_VERSION;
}

$wpdb = new class() {
	public function __call(string $name, array $arguments) {
		throw new RuntimeException("Current schemas must not call wpdb::{$name}().");
	}
};

foreach ($table_managers as $callback) {
	$callback();
}

fwrite(STDOUT, "Schema version query regression checks passed.\n");
