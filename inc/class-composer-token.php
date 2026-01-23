<?php
/**
 * Composer Token Handler
 *
 * Handles token generation, validation, and management for Composer authentication.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

class Composer_Token {

	/**
	 * Token prefix for identification.
	 *
	 * @var string
	 */
	const TOKEN_PREFIX = 'wu_tk_';

	/**
	 * Token length (excluding prefix).
	 *
	 * @var int
	 */
	const TOKEN_LENGTH = 32;

	/**
	 * Rate limit: max validation attempts per IP per minute.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 10;

	/**
	 * Generate a new token for a user.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $name    The token name.
	 * @return string|false The raw token (shown only once) or false on failure.
	 */
	public static function generate(int $user_id, string $name = 'Default') {

		// Generate a cryptographically secure random token
		$random_part = self::generate_random_token();
		$raw_token   = self::TOKEN_PREFIX . $random_part;

		// Create the hash for storage
		$token_hash = self::hash_token($raw_token);

		// Create the display prefix (first 4 chars of random part)
		$display_prefix = self::TOKEN_PREFIX . substr($random_part, 0, 4);

		// Store in database
		$result = Composer_Token_Table::insert($user_id, $token_hash, $display_prefix, $name);

		if ($result === false) {
			return false;
		}

		// Return the raw token - this is the only time it's available
		return $raw_token;
	}

	/**
	 * Validate a token and return the associated user ID.
	 *
	 * @param string $raw_token The raw token to validate.
	 * @return int|null The user ID or null if invalid/rate limited.
	 */
	public static function validate(string $raw_token): ?int {

		// Check rate limiting first
		if ( ! self::check_rate_limit()) {
			return null;
		}

		// Validate token format
		if ( ! self::is_valid_format($raw_token)) {
			return null;
		}

		// Hash the token for lookup
		$token_hash = self::hash_token($raw_token);

		// Look up the token
		$token_record = Composer_Token_Table::get_by_hash($token_hash);

		if ($token_record === null) {
			return null;
		}

		// Update last used timestamp
		Composer_Token_Table::update_last_used((int) $token_record['id']);

		return (int) $token_record['user_id'];
	}

	/**
	 * Revoke a token.
	 *
	 * @param int $token_id The token ID.
	 * @param int $user_id  The user ID (for security verification).
	 * @return bool True on success, false on failure.
	 */
	public static function revoke(int $token_id, int $user_id): bool {

		return Composer_Token_Table::revoke($token_id, $user_id);
	}

	/**
	 * Get all tokens for a user (without sensitive data).
	 *
	 * @param int $user_id The user ID.
	 * @return array Array of token records.
	 */
	public static function get_user_tokens(int $user_id): array {

		return Composer_Token_Table::get_by_user($user_id);
	}

	/**
	 * Hash a token using SHA-256.
	 *
	 * @param string $raw_token The raw token.
	 * @return string The SHA-256 hash.
	 */
	private static function hash_token(string $raw_token): string {

		return hash('sha256', $raw_token);
	}

	/**
	 * Generate a cryptographically secure random token.
	 *
	 * @return string A 32-character alphanumeric string.
	 */
	private static function generate_random_token(): string {

		$bytes = random_bytes(self::TOKEN_LENGTH);

		// Convert to alphanumeric (base62-ish)
		$chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$result = '';

		for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
			$result .= $chars[ord($bytes[$i]) % strlen($chars)];
		}

		return $result;
	}

	/**
	 * Check if a token has valid format.
	 *
	 * @param string $raw_token The raw token.
	 * @return bool True if valid format.
	 */
	private static function is_valid_format(string $raw_token): bool {

		// Must start with prefix
		if (strpos($raw_token, self::TOKEN_PREFIX) !== 0) {
			return false;
		}

		// Must be correct length
		$expected_length = strlen(self::TOKEN_PREFIX) + self::TOKEN_LENGTH;

		if (strlen($raw_token) !== $expected_length) {
			return false;
		}

		// Random part must be alphanumeric
		$random_part = substr($raw_token, strlen(self::TOKEN_PREFIX));

		return ctype_alnum($random_part);
	}

	/**
	 * Check and update rate limiting.
	 *
	 * @return bool True if within rate limit, false if exceeded.
	 */
	private static function check_rate_limit(): bool {

		$ip = self::get_client_ip();

		if (empty($ip)) {
			return true; // Can't rate limit without IP
		}

		$transient_key = 'wu_ct_rate_' . md5($ip);
		$attempts      = (int) get_transient($transient_key);

		if ($attempts >= self::RATE_LIMIT) {
			return false;
		}

		set_transient($transient_key, $attempts + 1, MINUTE_IN_SECONDS);

		return true;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string The client IP or empty string.
	 */
	private static function get_client_ip(): string {

		$headers = [
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ($headers as $header) {
			if ( ! empty($_SERVER[$header])) {
				$ip = sanitize_text_field(wp_unslash($_SERVER[$header]));

				// Handle comma-separated list (X-Forwarded-For)
				if (strpos($ip, ',') !== false) {
					$ip = trim(explode(',', $ip)[0]);
				}

				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}

		return '';
	}
}
