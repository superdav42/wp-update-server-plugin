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
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $tables = [];
	public bool $token_column_exists = false;
	public bool $fail_alter = false;
	public int $database_calls = 0;

	public function get_charset_collate(): string {
		return '';
	}

	public function prepare(string $query, string $value): string {
		unset($query);

		return $value;
	}

	public function get_var(string $table) {
		++$this->database_calls;
		$this->last_error = '';

		return isset($this->tables[$table]) ? $table : null;
	}

	public function get_results(string $query): array {
		++$this->database_calls;
		$this->last_error = '';

		return $this->token_column_exists ? [(object) ['Field' => 'token_value']] : [];
	}

	public function query(string $query) {
		++$this->database_calls;

		if ($this->fail_alter) {
			$this->last_error = 'ALTER TABLE failed';
			return false;
		}

		$this->last_error         = '';
		$this->token_column_exists = true;

		return 1;
	}
};

foreach ($table_managers as $callback) {
	$callback();
}

assert_same(0, $wpdb->database_calls, 'current schemas must not perform database discovery');

unset($schema_versions[\WP_Update_Server_Plugin\Stripe_Analytics_Table::SCHEMA_VERSION_OPTION]);
$wpdb->tables[\WP_Update_Server_Plugin\Stripe_Analytics_Table::get_analytics_table()] = true;
$wpdb->tables[\WP_Update_Server_Plugin\Stripe_Analytics_Table::get_accounts_table()]  = true;
(new \WP_Update_Server_Plugin\Stripe_Analytics_Table())->maybe_create_tables();
assert_same(
	\WP_Update_Server_Plugin\Stripe_Analytics_Table::SCHEMA_VERSION,
	$schema_versions[\WP_Update_Server_Plugin\Stripe_Analytics_Table::SCHEMA_VERSION_OPTION] ?? '',
	'existing Stripe tables must persist the current schema version'
);

unset($schema_versions[\WP_Update_Server_Plugin\Composer_Token_Table::SCHEMA_VERSION_OPTION]);
$wpdb->tables[\WP_Update_Server_Plugin\Composer_Token_Table::get_table_name()] = true;
$wpdb->token_column_exists = false;
$wpdb->fail_alter          = true;
(new \WP_Update_Server_Plugin\Composer_Token_Table())->maybe_create_table();
assert_same(
	'',
	$schema_versions[\WP_Update_Server_Plugin\Composer_Token_Table::SCHEMA_VERSION_OPTION] ?? '',
	'a failed schema migration must leave the schema version unchanged'
);

fwrite(STDOUT, "Schema version query regression checks passed.\n");

function assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(
			sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true))
		);
	}
}
