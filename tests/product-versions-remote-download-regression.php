<?php
/**
 * Regression checks for bounded Product_Versions remote archive inspection.
 *
 * @package WP_Update_Server_Plugin
 */

declare(strict_types=1);

namespace {
	const HOUR_IN_SECONDS = 3600;
	const DAY_IN_SECONDS  = 86400;

	define('ABSPATH', sys_get_temp_dir() . '/wu-product-versions-test-' . getmypid() . '/');

	if ( ! is_dir(ABSPATH . 'wp-admin/includes')) {
		mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
	}

	file_put_contents(ABSPATH . 'wp-admin/includes/file.php', "<?php\n");

	$GLOBALS['wu_http_calls']             = [];
	$GLOBALS['wu_http_mode']              = 'success';
	$GLOBALS['wu_test_object_cache']      = [];
	$GLOBALS['wu_test_object_cache_ttls'] = [];
	$GLOBALS['wu_test_package_metadata']  = ['version' => '3.2.1'];
	$GLOBALS['wu_test_products']          = [];
	$GLOBALS['wu_test_transients']        = [];
	$GLOBALS['wu_test_transient_ttls']    = [];
	$GLOBALS['wu_using_ext_object_cache'] = true;
	$GLOBALS['wu_last_temp_file_created'] = null;

	class WP_Error {
	}

	class WC_Product {

		/**
		 * @var int
		 */
		private $id;

		/**
		 * @var string
		 */
		private $download_path;

		/**
		 * @var array<string,WC_Product_Download>
		 */
		private $downloads;

		/**
		 * @param int    $id            Product ID.
		 * @param string $download_path Download path.
		 * @param array  $downloads     Product downloads.
		 */
		public function __construct(int $id, string $download_path, array $downloads = []) {

			$this->id            = $id;
			$this->download_path = $download_path;
			$this->downloads     = $downloads;
		}

		/**
		 * @param string $file_id Download file ID.
		 * @return string Download path.
		 */
		public function get_file_download_path(string $file_id): string {

			return $this->download_path;
		}

		/**
		 * @return int Product ID.
		 */
		public function get_id(): int {

			return $this->id;
		}

		/**
		 * @return bool Whether the product exists.
		 */
		public function exists(): bool {

			return true;
		}

		/**
		 * @return bool Whether the product is downloadable.
		 */
		public function is_downloadable(): bool {

			return true;
		}

		/**
		 * @return array<string,WC_Product_Download> Product downloads.
		 */
		public function get_downloads(): array {

			return $this->downloads;
		}
	}

	class WC_Product_Download {

		/**
		 * @var string
		 */
		private $name;

		/**
		 * @param string $name Download name.
		 */
		public function __construct(string $name) {

			$this->name = $name;
		}

		/**
		 * @return string Download name.
		 */
		public function get_name(): string {

			return $this->name;
		}

		/**
		 * @return bool Whether the download is enabled.
		 */
		public function get_enabled(): bool {

			return true;
		}
	}

	/**
	 * @param int $product_id Product ID.
	 * @return WC_Product|null Product stub.
	 */
	function wc_get_product(int $product_id): ?WC_Product {

		return $GLOBALS['wu_test_products'][$product_id] ?? null;
	}

	class WC_Download_Handler {

		/**
		 * @param string $path Download path.
		 * @return array{file_path:string,remote_file:bool} Parsed file info.
		 */
		public static function parse_file_path(string $path): array {

			return [
				'file_path'   => $path,
				'remote_file' => 0 === strpos($path, 'https://'),
			];
		}
	}

	class Wpup_Package {

		/**
		 * @param string $filepath Archive path.
		 * @return self Package instance.
		 */
		public static function fromArchive(string $filepath): self {

			if ( ! is_file($filepath)) {
				throw new RuntimeException('Archive missing.');
			}

			return new self();
		}

		/**
		 * @return array<string,string> Package metadata.
		 */
		public function getMetadata(): array {

			return $GLOBALS['wu_test_package_metadata'];
		}
	}

	/**
	 * @param string $filename Temp filename seed.
	 * @return string Temporary file path.
	 */
	function wp_tempnam(string $filename): string {

		$temp_file                             = tempnam(sys_get_temp_dir(), 'wu-remote-');
		$GLOBALS['wu_last_temp_file_created'] = $temp_file;

		return $temp_file;
	}

	/**
	 * @param string $filepath File path.
	 * @return void
	 */
	function wp_delete_file(string $filepath): void {

		if (file_exists($filepath)) {
			unlink($filepath);
		}
	}

	/**
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request arguments.
	 * @return array<string,mixed>|WP_Error Response or error.
	 */
	function wp_safe_remote_get(string $url, array $args) {

		$GLOBALS['wu_http_calls'][] = [
			'url'  => $url,
			'args' => $args,
		];

		if ('timeout' === $GLOBALS['wu_http_mode']) {
			return new WP_Error();
		}

		$body = 'archive';
		$code = 200;

		if ('http_500' === $GLOBALS['wu_http_mode']) {
			$body = 'error';
			$code = 500;
		}

		file_put_contents($args['filename'], $body);

		$content_length = strlen($body);

		if ('oversized' === $GLOBALS['wu_http_mode']) {
			$content_length = \WP_Update_Server_Plugin\Product_Versions::REMOTE_ARCHIVE_MAX_BYTES + 1;
		}

		return [
			'response' => [
				'code' => $code,
			],
			'headers'  => [
				'content-length' => (string) $content_length,
			],
		];
	}

	/**
	 * @param mixed $thing Possible error.
	 * @return bool Whether the value is a WP_Error.
	 */
	function is_wp_error($thing): bool {

		return $thing instanceof WP_Error;
	}

	/**
	 * @param array<string,mixed> $response HTTP response.
	 * @return int Response code.
	 */
	function wp_remote_retrieve_response_code(array $response): int {

		return (int) ($response['response']['code'] ?? 0);
	}

	/**
	 * @param array<string,mixed> $response HTTP response.
	 * @param string              $header   Header name.
	 * @return string|null Header value.
	 */
	function wp_remote_retrieve_header(array $response, string $header): ?string {

		return $response['headers'][$header] ?? null;
	}

	/**
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param bool   $force Whether to bypass local cache.
	 * @param bool   $found Whether the key was found.
	 * @return mixed Cached value, or false when missing.
	 */
	function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null) {

		$found = array_key_exists($key, $GLOBALS['wu_test_object_cache'][$group] ?? []);

		return $found ? $GLOBALS['wu_test_object_cache'][$group][$key] : false;
	}

	/**
	 * @param string $key        Cache key.
	 * @param mixed  $value      Cached value.
	 * @param string $group      Cache group.
	 * @param int    $expiration Expiration seconds.
	 * @return bool Whether the value was stored.
	 */
	function wp_cache_set(string $key, $value, string $group = '', int $expiration = 0): bool {

		$GLOBALS['wu_test_object_cache'][$group][$key]      = $value;
		$GLOBALS['wu_test_object_cache_ttls'][$group][$key] = $expiration;

		return true;
	}

	/**
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return bool Whether the value was deleted.
	 */
	function wp_cache_delete(string $key, string $group = ''): bool {

		$found = array_key_exists($key, $GLOBALS['wu_test_object_cache'][$group] ?? []);
		unset($GLOBALS['wu_test_object_cache'][$group][$key]);

		return $found;
	}

	/**
	 * @return bool Whether a persistent object-cache backend is active.
	 */
	function wp_using_ext_object_cache(): bool {

		return $GLOBALS['wu_using_ext_object_cache'];
	}

	/**
	 * @param string $key Transient key.
	 * @return mixed Transient value, or false when missing.
	 */
	function get_transient(string $key) {

		return $GLOBALS['wu_test_transients'][$key] ?? false;
	}

	/**
	 * @param string $key        Transient key.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration seconds.
	 * @return bool Whether the transient was stored.
	 */
	function set_transient(string $key, $value, int $expiration): bool {

		$GLOBALS['wu_test_transients'][$key]     = $value;
		$GLOBALS['wu_test_transient_ttls'][$key] = $expiration;

		return true;
	}

	/**
	 * @param string $key Transient key.
	 * @return bool Whether the transient was deleted.
	 */
	function delete_transient(string $key): bool {

		$found = array_key_exists($key, $GLOBALS['wu_test_transients']);
		unset($GLOBALS['wu_test_transients'][$key]);

		return $found;
	}
}

namespace WP_Update_Server_Plugin {
	require_once dirname(__DIR__) . '/inc/class-product-versions.php';
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

	/**
	 * @param string $mode        HTTP mock mode.
	 * @param bool   $clear_cache Whether to clear the object cache.
	 * @return void
	 */
	function reset_remote_test_state(string $mode, bool $clear_cache = false): void {

		$GLOBALS['wu_http_calls']             = [];
		$GLOBALS['wu_http_mode']              = $mode;
		$GLOBALS['wu_last_temp_file_created'] = null;

		if ($clear_cache) {
			$GLOBALS['wu_test_object_cache']      = [];
			$GLOBALS['wu_test_object_cache_ttls'] = [];
			$GLOBALS['wu_test_transients']        = [];
			$GLOBALS['wu_test_transient_ttls']    = [];
		}
	}

	/**
	 * @param WC_Product          $product Product stub.
	 * @param WC_Product_Download $file    Download file stub.
	 * @return array<string,string>|null Version info.
	 */
	function invoke_extract_version(WC_Product $product, WC_Product_Download $file): ?array {

		$method = new ReflectionMethod(\WP_Update_Server_Plugin\Product_Versions::class, 'extract_version_from_file');
		$method->setAccessible(true);

		return $method->invoke(null, $product, 'download-1', $file);
	}

	$source_file = dirname(__DIR__) . '/inc/class-product-versions.php';
	$source      = file_get_contents($source_file);

	assert_true(false !== $source, "Unable to read {$source_file}");

	$required_patterns = [
		'/wp_safe_remote_get\s*\(/'                                                     => 'remote archives use the WordPress safe HTTP API',
		'/\'timeout\'\s*=>\s*self::REMOTE_ARCHIVE_TIMEOUT/'                            => 'remote archive requests have an explicit timeout',
		'/\'redirection\'\s*=>\s*self::REMOTE_ARCHIVE_REDIRECTION_LIMIT/'               => 'remote archive requests have an explicit redirect limit',
		'/\'stream\'\s*=>\s*true/'                                                      => 'remote archive responses stream to disk',
		'/\'filename\'\s*=>\s*\$tmp/'                                                   => 'remote archive responses target the temporary file',
		'/\'limit_response_size\'\s*=>\s*self::REMOTE_ARCHIVE_MAX_BYTES/'               => 'remote archive responses have a maximum byte limit',
		'/wp_remote_retrieve_response_code\s*\(\s*\$response\s*\)/'                    => 'remote archive responses validate HTTP status',
		'/wp_remote_retrieve_header\s*\(\s*\$response\s*,\s*\'content-length\'\s*\)/' => 'remote archive responses inspect content length',
		'/wp_cache_set\s*\(/'                                                           => 'remote metadata uses the WordPress object cache',
		'/self::CACHE_GROUP/'                                                            => 'version metadata uses a dedicated cache group',
		'/hash\s*\(\s*\'sha256\'\s*,\s*\$identity\s*\)/'                               => 'remote version cache keys hash URL-bearing identity data',
	];

	$forbidden_patterns = [
		'/file_put_contents\s*\(\s*\$tmp\s*,\s*file_get_contents\s*\(\s*\$filepath\s*\)\s*\)/' => 'unbounded remote archive copy via file_get_contents()',
		'/file_get_contents\s*\(\s*\$filepath\s*\)/'                                           => 'direct remote archive reads via file_get_contents()',
	];

	foreach ($required_patterns as $pattern => $description) {
		assert_true(1 === preg_match($pattern, $source), "Missing: {$description}");
	}

	foreach ($forbidden_patterns as $pattern => $description) {
		assert_true(1 !== preg_match($pattern, $source), "Forbidden: {$description}");
	}

	$remote_url = 'https://github.com/Ultimate-Multisite/ultimate-update-server-plugin/issues/32';
	$product    = new WC_Product(123, $remote_url);
	$file       = new WC_Product_Download('Private Archive.zip');
	$versioned_file = new WC_Product_Download('Ultimate Multisite: WooCommerce Integration v3.2.1');

	reset_remote_test_state('success', true);
	assert_true(['version' => '3.2.1'] === invoke_extract_version($product, $versioned_file), 'versioned display names expose package metadata');
	assert_true(0 === count($GLOBALS['wu_http_calls']), 'versioned display names avoid remote archive inspection');

	reset_remote_test_state('timeout', true);
	assert_true(null === invoke_extract_version($product, $file), 'timeout errors fail closed');
	assert_true(1 === count($GLOBALS['wu_http_calls']), 'timeout path attempts one bounded HTTP request');

	$timeout_args = $GLOBALS['wu_http_calls'][0]['args'];
	assert_true(15 === $timeout_args['timeout'], 'HTTP timeout is 15 seconds');
	assert_true(3 === $timeout_args['redirection'], 'HTTP redirect limit is 3');
	assert_true(true === $timeout_args['stream'], 'HTTP response streams to disk');
	assert_true(52428800 === $timeout_args['limit_response_size'], 'HTTP response size is capped');
	assert_true(is_string($timeout_args['filename']) && '' !== $timeout_args['filename'], 'HTTP response has a temp filename');
	assert_true( ! file_exists($timeout_args['filename']), 'timeout path removes temporary files');

	reset_remote_test_state('http_500', true);
	assert_true(null === invoke_extract_version($product, $file), 'HTTP non-2xx responses fail closed');
	assert_true( ! file_exists($GLOBALS['wu_last_temp_file_created']), 'HTTP non-2xx path removes temporary files');

	reset_remote_test_state('oversized', true);
	assert_true(null === invoke_extract_version($product, $file), 'oversized responses fail closed');
	assert_true( ! file_exists($GLOBALS['wu_last_temp_file_created']), 'oversized path removes temporary files');

	reset_remote_test_state('success', true);
	$result = invoke_extract_version($product, $file);
	assert_true(['version' => '3.2.1'] === $result, 'successful remote inspection extracts package metadata');
	assert_true( ! file_exists($GLOBALS['wu_last_temp_file_created']), 'successful path removes temporary files');
	$cache_group = \WP_Update_Server_Plugin\Product_Versions::CACHE_GROUP;
	assert_true(1 === count($GLOBALS['wu_test_object_cache'][$cache_group]), 'successful remote metadata is cached');

	$cache_key = (string) array_key_first($GLOBALS['wu_test_object_cache'][$cache_group]);
	assert_true(false === strpos($cache_key, $remote_url), 'cache key does not expose the raw remote URL');
	assert_true(86400 === $GLOBALS['wu_test_object_cache_ttls'][$cache_group][$cache_key], 'remote metadata cache TTL is one day');

	reset_remote_test_state('success');
	$result = invoke_extract_version($product, $file);
	assert_true(['version' => '3.2.1'] === $result, 'cached remote inspection returns cached metadata');
	assert_true(0 === count($GLOBALS['wu_http_calls']), 'cached remote inspection does not download again');

	reset_remote_test_state('timeout', true);
	assert_true(null === invoke_extract_version($product, $file), 'failed remote inspection returns null');
	assert_true(1 === count($GLOBALS['wu_http_calls']), 'failed inspection attempts one bounded HTTP request');
	assert_true(3600 === $GLOBALS['wu_test_object_cache_ttls'][$cache_group][$cache_key], 'failed inspection is cached for one hour');

	reset_remote_test_state('success');
	assert_true(null === invoke_extract_version($product, $file), 'negative cache hit returns null');
	assert_true(0 === count($GLOBALS['wu_http_calls']), 'negative cache hit does not download again');

	$product = new WC_Product(123, $remote_url, ['download-1' => $file]);
	$GLOBALS['wu_test_products'][123] = $product;
	reset_remote_test_state('success', true);
	$versions = \WP_Update_Server_Plugin\Product_Versions::get_all_versions_by_product_id(123);
	assert_true(1 === count($GLOBALS['wu_http_calls']), 'uncached product versions inspect the remote archive once');
	assert_true('3.2.1' === $versions[0]['version'], 'product version inspection returns archive metadata');
	assert_true(3600 === $GLOBALS['wu_test_object_cache_ttls'][$cache_group]['versions_123'], 'product version list is cached for one hour');

	reset_remote_test_state('success');
	$versions = \WP_Update_Server_Plugin\Product_Versions::get_all_versions_by_product_id(123);
	assert_true('3.2.1' === $versions[0]['version'], 'cached product version list is returned');
	assert_true(0 === count($GLOBALS['wu_http_calls']), 'cached product version list avoids remote inspection');

	\WP_Update_Server_Plugin\Product_Versions::clear_cache(123);
	assert_true( ! isset($GLOBALS['wu_test_object_cache'][$cache_group]['versions_123']), 'product cache invalidation deletes the grouped object-cache entry');
	assert_true(empty($GLOBALS['wu_test_object_cache'][$cache_group]), 'product cache invalidation deletes remote metadata entries');

	$GLOBALS['wu_using_ext_object_cache'] = false;
	reset_remote_test_state('success', true);
	$versions = \WP_Update_Server_Plugin\Product_Versions::get_all_versions_by_product_id(123);
	$transient_key = $cache_group . '_versions_123';
	assert_true(1 === count($GLOBALS['wu_http_calls']), 'transient fallback inspects an uncached remote archive once');
	assert_true('3.2.1' === $versions[0]['version'], 'transient fallback returns archive metadata');
	assert_true(3600 === $GLOBALS['wu_test_transient_ttls'][$transient_key], 'product version list uses a one-hour transient fallback');

	reset_remote_test_state('success');
	\WP_Update_Server_Plugin\Product_Versions::get_all_versions_by_product_id(123);
	assert_true(0 === count($GLOBALS['wu_http_calls']), 'transient fallback avoids remote inspection across requests');

	\WP_Update_Server_Plugin\Product_Versions::clear_cache(123);
	assert_true( ! isset($GLOBALS['wu_test_transients'][$transient_key]), 'product cache invalidation deletes the transient fallback');
	assert_true(empty($GLOBALS['wu_test_transients']), 'product cache invalidation deletes transient remote metadata entries');

	fwrite(STDOUT, "Product_Versions remote archive regression checks passed.\n");
}
