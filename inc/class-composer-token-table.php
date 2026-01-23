<?php
/**
 * Composer Token Database Table
 *
 * Handles the custom database table for storing Composer authentication tokens.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Composer_Token_Table {

	/**
	 * Table name (without prefix)
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wu_composer_tokens';

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action('admin_init', [$this, 'maybe_create_table']);
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {

		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create the table if it doesn't exist.
	 *
	 * @return void
	 */
	public function maybe_create_table(): void {

		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Check if table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ($table_exists === $table_name) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			token_hash varchar(64) NOT NULL,
			token_prefix varchar(12) NOT NULL,
			name varchar(255) NOT NULL DEFAULT 'Default',
			last_used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta($sql);
	}

	/**
	 * Insert a new token record.
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $token_hash The SHA-256 hash of the token.
	 * @param string $prefix     The token prefix for display (e.g., "wu_tk_xxxx").
	 * @param string $name       The token name.
	 * @return int|false The inserted ID or false on failure.
	 */
	public static function insert(int $user_id, string $token_hash, string $prefix, string $name = 'Default') {

		global $wpdb;

		$result = $wpdb->insert(
			self::get_table_name(),
			[
				'user_id'    => $user_id,
				'token_hash' => $token_hash,
				'token_prefix' => $prefix,
				'name'       => $name,
				'created_at' => current_time('mysql'),
			],
			['%d', '%s', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get a token record by its hash.
	 *
	 * @param string $token_hash The SHA-256 hash of the token.
	 * @return array|null The token record or null if not found/revoked.
	 */
	public static function get_by_hash(string $token_hash): ?array {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE token_hash = %s AND revoked_at IS NULL",
				$token_hash
			),
			ARRAY_A
		);

		return $result ?: null;
	}

	/**
	 * Get all tokens for a user.
	 *
	 * @param int  $user_id      The user ID.
	 * @param bool $include_revoked Whether to include revoked tokens.
	 * @return array Array of token records.
	 */
	public static function get_by_user(int $user_id, bool $include_revoked = false): array {

		global $wpdb;

		$table_name = self::get_table_name();

		$where_revoked = $include_revoked ? '' : ' AND revoked_at IS NULL';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, token_prefix, name, last_used_at, created_at, revoked_at
				FROM {$table_name}
				WHERE user_id = %d{$where_revoked}
				ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		);

		return $results ?: [];
	}

	/**
	 * Revoke a token.
	 *
	 * @param int $token_id The token ID.
	 * @param int $user_id  The user ID (for security verification).
	 * @return bool True on success, false on failure.
	 */
	public static function revoke(int $token_id, int $user_id): bool {

		global $wpdb;

		$result = $wpdb->update(
			self::get_table_name(),
			['revoked_at' => current_time('mysql')],
			[
				'id'      => $token_id,
				'user_id' => $user_id,
			],
			['%s'],
			['%d', '%d']
		);

		return $result !== false;
	}

	/**
	 * Update the last used timestamp for a token.
	 *
	 * @param int $token_id The token ID.
	 * @return void
	 */
	public static function update_last_used(int $token_id): void {

		global $wpdb;

		$wpdb->update(
			self::get_table_name(),
			['last_used_at' => current_time('mysql')],
			['id' => $token_id],
			['%s'],
			['%d']
		);
	}

	/**
	 * Count active tokens for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return int The number of active tokens.
	 */
	public static function count_user_tokens(int $user_id): int {

		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND revoked_at IS NULL",
				$user_id
			)
		);
	}
}
