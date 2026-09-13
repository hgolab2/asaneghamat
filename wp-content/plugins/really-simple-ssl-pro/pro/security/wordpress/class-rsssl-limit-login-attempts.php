<?php
/**
 * This file contains the implementation of the Rsssl_Limit_Login_Attempts class.
 * This class is used to check if a login attempt should be blocked or allowed.
 * It also contains the logic to block and unblock users and IP addresses.
 * It also contains the logic to add and remove users and IP addresses to the allowlist and blocklist.
 *
 * @package REALLY_SIMPLE_SSL\Security\WordPress
 * @company Really Simple Plugins
 * @website https://really-simple-plugins.com
 */

namespace REALLY_SIMPLE_SSL\Security\WordPress;

use DateTimeZone;
use REALLY_SIMPLE_SSL\Security\WordPress\Eventlog\Rsssl_Event_Type;
use Exception;
use REALLY_SIMPLE_SSL\Security\WordPress\LimitLogin\Rsssl_Geo_Location;
use REALLY_SIMPLE_SSL\Security\WordPress\Rsssl_Event_Log;
use REALLY_SIMPLE_SSL\Security\DynamicTables\Rsssl_Data_Table;
use REALLY_SIMPLE_SSL\Security\DynamicTables\Rsssl_Query_Builder;
use REALLY_SIMPLE_SSL\Security\DynamicTables\Rsssl_Array_Query_Builder;
use stdClass;
use const FILTER_FLAG_IPV4;
use const FILTER_VALIDATE_IP;
require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-data-table.php';
require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-query-builder.php';
require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-array-query-builder.php';

if ( ! class_exists( 'Rsssl_Limit_Login_Attempts' ) ) {
	/**
	 * Class Rsssl_Limit_Login_Attempts
	 *
	 * This class is used to check if a login attempt should be blocked or allowed.
	 *
	 * @package REALLY_SIMPLE_SSL\Security\WordPress
	 * @company Really Simple Plugins
	 * @website https://really-simple-plugins.com
	 *
	 * @author Marcel Santing
	 */
	class Rsssl_Limit_Login_Attempts {

		const CACHE_EXPIRATION          = 3600;
		const EVENT_CODE_USER_BLOCKED   = '1012';
		const EVENT_CODE_USER_UNBLOCKED = '1013';
		const EVENT_CODE_IP_BLOCKED     = '1022';
		const EVENT_CODE_IP_UNBLOCKED   = '1023';

		const EVENT_CODE_IP_ADDED_TO_ALLOWLIST       = '1024';
		const EVENT_CODE_IP_REMOVED_FROM_ALLOWLIST   = '1025';
		const EVENT_CODE_USER_ADDED_TO_ALLOWLIST     = '1014';
		const EVENT_CODE_USER_REMOVED_FROM_ALLOWLIST = '1015';
		const EVENT_CODE_IP_UNLOCKED                 = '1021';
		const EVENT_CODE_USER_LOCKED                 = '1010';
		const EVENT_CODE_USER_UNLOCKED               = '1011';
		const EVENT_CODE_IP_LOCKED                   = '1020';
		const EVENT_CODE_IP_UNLOCKED_BY_ADMIN        = '1021';
		const EVENT_CODE_COUNTRY_BLOCKED             = '1026';
		const EVENT_CODE_COUNTRY_UNBLOCKED           = '1027';


		/**
		 *
		 * Process the request. Get the IP address(es) and check if they are present in the allowlist / blocklist.
		 *
		 * @return string
		 */
		public function check_request(): string {
			$ips = $this->get_ip_address();

			return $this->check_ip_address( $ips );
		}

		/**
		 * Check if the request is for a user and if so, check if the user is present in the allowlist / blocklist.
		 *
		 * @param  string $username The username to check.
		 *
		 * @return string
		 */
		public function check_request_for_user( string $username ): string {
			$usernames = array( $username );
			return $this->check_against_users( $usernames );
		}

		/**
		 * Check if the request is for a country and if so, check if the country is present in the allowlist / blocklist.
		 *
		 * @return string
		 */
		public function check_request_for_country(): string {
			$country = Rsssl_Geo_Location::get_county_by_ip( $this->get_ip_address()[0] );

			return $this->check_against_countries( array( $country ) );
		}

		/**
		 * Retrieves a list of unique, validated IP addresses from various headers.
		 *
		 * This function attempts to retrieve the client's IP address from a variety of HTTP headers,
		 * including 'X-Forwarded-For', 'X-Forwarded', 'Forwarded-For', and 'Forwarded'. The function
		 * prefers rightmost IPs in these headers as they are less likely to be spoofed. It also checks
		 * if each IP is valid and not in a private or reserved range. Duplicate IP addresses are removed
		 * from the returned array.
		 *
		 * Note: While this function strives to obtain accurate IP addresses, the nature of HTTP headers
		 * means that it cannot guarantee the authenticity of the IP addresses.
		 *
		 * @return array An array of unique, validated IP addresses. If no valid IP addresses are found,
		 *               an empty array is returned.
		 */
		public function get_ip_address(): array {
			// Initialize an array to hold all discovered IP addresses.
			$ip_addresses = array();
			// Initialize a variable to hold the rightmost IP address.
			$rightmost_ip = null;

			// Define an array of headers to check for possible client IP addresses.
			$headers_to_check = array(
				'HTTP_CF_CONNECTING_IP',
				'HTTP_TRUE_CLIENT_IP',
				'HTTP_X_CLUSTER_CLIENT_IP',
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED',
				'HTTP_X_REAL_IP',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED',
				'HTTP_X_FORWARDED_FOR',
				'REMOTE_ADDR'
			);

			// Loop through each header.
			foreach ( $headers_to_check as $header ) {
				// If the header exists in the $_SERVER array.
				if ( isset( $_SERVER[ $header ] ) ) {
					// Remove all spaces from the header value and explode it by comma.
					// to get a list of IP addresses.
					$ips = explode( ',', str_replace( ' ', '', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) );

					// Reverse the array to process rightmost IP first, which is less likely to be spoofed.
					$ips = array_reverse( $ips );

					// Loop through each IP address in the list.
					foreach ( $ips as $ip ) {
						$ip = trim( $ip );

						// If the IP address is valid and does not belong to a private or reserved range.
						if ( filter_var(
							$ip,
							FILTER_VALIDATE_IP
						) ) {// , FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
							// Add the IP address to the array
							$ip_addresses[] = $ip;

							// If we haven't stored a rightmost IP yet, store this one.
							if ( null === $rightmost_ip ) {
								$rightmost_ip = $ip;
							}
						}
					}
				}
			}

			// If we found a rightmost IP address.
			if ( null !== $rightmost_ip ) {
				// Get all keys in the IP addresses array that match the rightmost IP.
				$rightmost_ip_keys = array_keys( $ip_addresses, $rightmost_ip, true );

				// Loop through each key.
				foreach ( $rightmost_ip_keys as $key ) {
					// If this is not the first instance of the rightmost IP.
					if ( $key > 0 ) {
						// Remove this instance from the array.
						unset( $ip_addresses[ $key ] );
					}
				}
			}

			return array_values( array_unique( $ip_addresses ) );
		}

		/**
		 * Processes an IP or range and calls the appropriate function.
		 *
		 * This function determines whether the provided input is an IP address or an IP range,
		 * and then calls the appropriate function accordingly.
		 *
		 * @param array $ip_addresses The IP addresses to check.
		 *
		 * @return string Returns a status representing the check result: 'allowed' for allowlist hit, 'blocked' for blocklist hit, 'not found' for no hits.
		 */
		public function check_ip_address( array $ip_addresses ): string {
			$found_blocked_ip = false;
			foreach ( $ip_addresses as $ip ) {
				// Remove any white space around the input.
				$item = trim( $ip );
				// Validate the input to determine whether it's an IP or a range.
				if ( filter_var( $item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
					// It's a valid IP address.
					$status = $this->check_against_ips( array( $item ) );
					// If not found in regular IP's, check against ranges.
					if ( 'not_found' === $status ) {
						$status = $this->get_ip_range_status( array( $item ) );
					}

					if ( 'allowed' === $status ) {
						return 'allowed';
					}

					if ( 'blocked' === $status ) {
						$found_blocked_ip = true;
					}
				}
			}

			if ( $found_blocked_ip ) {
				return 'blocked';
			}

			return 'not_found';
		}

		/**
		 * Checks if a given IP address is within a specified IP range.
		 *
		 * This function supports both IPv4 and IPv6 addresses, and can handle ranges in
		 * both standard notation (e.g. "192.0.2.0") and CIDR notation (e.g. "192.0.2.0/24").
		 *
		 * In CIDR notation, the function uses a bitmask to check if the IP address falls within
		 * the range. For IPv4 addresses, it uses the `ip2long()` function to convert the IP
		 * address and subnet to their integer representations, and then uses the bitmask to
		 * compare them. For IPv6 addresses, it uses the `inet_pton()` function to convert the IP
		 * address and subnet to their binary representations, and uses a similar bitmask approach.
		 *
		 * If the range is not in CIDR notation, it simply checks if the IP equals the range.
		 *
		 * @param  string $ip  The IP address to check.
		 * @param  string $range  The range to check the IP address against.
		 *
		 * @return bool True if the IP address is within the range, false otherwise.
		 */
		public function ip_in_range( string $ip, string $range ): bool {
			// Check if the IP address is properly formatted.
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
				return false;
			}
			// Check if the range is in CIDR notation.
			if ( strpos( $range, '/' ) !== false ) {
				// The range is in CIDR notation, so we split it into the subnet and the bit count.
				[ $subnet, $bits ] = explode( '/', $range );

				if ( ! is_numeric( $bits ) || $bits < 0 || $bits > 128 ) {
					return false;
				}

				// Check if the subnet is a valid IPv4 address.
				if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					// Convert the IP address and subnet to their integer representations.
					$ip     = ip2long( $ip );
					$subnet = ip2long( $subnet );

					// Create a mask based on the number of bits.
					$mask = - 1 << ( 32 - $bits );

					// Apply the mask to the subnet.
					$subnet &= $mask;

					// Compare the masked IP address and subnet.
					return ( $ip & $mask ) === $subnet;
				}

				// Check if the subnet is a valid IPv6 address.
				if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
					// Convert the IP address and subnet to their binary representations.
					$ip     = inet_pton( $ip );
					$subnet = inet_pton( $subnet );
					// Divide the number of bits by 8 to find the number of full bytes.
					$full_bytes = floor( $bits / 8 );
					// Find the number of remaining bits after the full bytes.
					$partial_byte = $bits % 8;
					// Initialize the mask.
					$mask = '';
					// Add the full bytes to the mask, each byte being "\xff" (255 in binary).
					$mask .= str_repeat( "\xff", $full_bytes );
					// If there are any remaining bits...
					if ( 0 !== $partial_byte ) {
						// Add a byte to the mask with the correct number of 1 bits.
						// First, create a string with the correct number of 1s.
						// Then, pad the string to 8 bits with 0s.
						// Convert the binary string to a decimal number.
						// Convert the decimal number to a character and add it to the mask.
						$mask .= chr( bindec( str_pad( str_repeat( '1', $partial_byte ), 8, '0' ) ) );
					}

					// Fill in the rest of the mask with "\x00" (0 in binary).
					// The total length of the mask should be 16 bytes, so subtract the number of bytes already added.
					// If we added a partial byte, we need to subtract 1 more from the number of bytes to add.
					$mask .= str_repeat( "\x00", 16 - $full_bytes - ( 0 !== $partial_byte ? 1 : 0 ) );

					// Compare the masked IP address and subnet.
					return ( $ip & $mask ) === $subnet;
				}

				// The subnet was not a valid IP address.
				return false;
			}

			if ( ! filter_var( $range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
				// The range was not in CIDR notation and was not a valid IP address.
				return false;
			}

			// The range is not in CIDR notation, so we simply check if the IP equals the range.
			return $ip === $range;
		}

		/**
		 * Checks a list of IP addresses against allowlist and blocklist.
		 *
		 * This function fetches explicit IP addresses from the database tables and checks if the supplied IPs are in the allowlist or blocklist.
		 * If an IP is found in the allowlist or blocklist, it is stored in the corresponding database table and a status is returned.
		 *
		 * @param  array $ip_addresses  The list of IP addresses to check.
		 *
		 * @return string|null Status representing the check result: 'allowed' for allowlist hit, 'blocked' for blocklist hit, 'not found' for no hits.
		 */
		public function check_against_ips( array $ip_addresses ): string {

			global $wpdb;

			$cache_key_allowlist = 'rsssl_allowlist_ips';
			$cache_key_blocklist = 'rsssl_blocklist_ips';

			// Try to get the lists from cache.
			$allowlist_ips = wp_cache_get( $cache_key_allowlist );
			$blocklist_ips = wp_cache_get( $cache_key_blocklist );

			// If not cached, fetch from the database and then cache.
			if ( false === $allowlist_ips ) {
				// phpcs:ignore WordPress.DB
				$allowlist_ips = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE status = 'allowed' AND attempt_type = 'source_ip' AND  attempt_value NOT LIKE '%/%'" );

				wp_cache_set( $cache_key_allowlist, $allowlist_ips, null, self::CACHE_EXPIRATION );
			}

			if ( false === $blocklist_ips ) {
				// phpcs:ignore WordPress.DB
				$blocklist_ips = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE status = 'blocked' AND attempt_type = 'source_ip' AND  attempt_value NOT LIKE '%/%'" );

				wp_cache_set( $cache_key_blocklist, $blocklist_ips, null, self::CACHE_EXPIRATION );
			}

			// Check the IP addresses.
			foreach ( $ip_addresses as $ip ) {
				if ( in_array( $ip, $allowlist_ips, true ) ) {
					return 'allowed';
				}
				if ( in_array( $ip, $blocklist_ips, true ) ) {
					return 'blocked';
				}
			}

			return 'not_found';
		}

		/**
		 * Checks a list of usernames against allowlist and blocklist.
		 *
		 * @param  array $usernames The list of usernames to check.
		 *
		 * @return string
		 */
		public function check_against_users( array $usernames ): string {

			global $wpdb;

			$cache_key_allowlist = 'rsssl_allowlist_users';
			$cache_key_blocklist = 'rsssl_blocklist_users';

			// Try to get the lists from cache.
			$allowlist_users = wp_cache_get( $cache_key_allowlist );
			$blocklist_users = wp_cache_get( $cache_key_blocklist );

			// If not cached, fetch from the database and then cache.
			if ( false === $allowlist_users ) {
				// phpcs:ignore WordPress.DB
				$allowlist_users = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE status = 'allowed' AND attempt_type = 'username' " );
				wp_cache_set( $cache_key_allowlist, $allowlist_users, null, self::CACHE_EXPIRATION );
			}

			if ( false === $blocklist_users ) {
				// phpcs:ignore WordPress.DB
				$blocklist_users = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE status = 'blocked' AND attempt_type = 'username' " );
				wp_cache_set( $cache_key_blocklist, $blocklist_users, null, self::CACHE_EXPIRATION );
			}

			// Check the users.
			foreach ( $usernames as $username ) {
				if ( in_array( $username, $allowlist_users, true ) ) {
					return 'allowed';
				}
				if ( in_array( $username, $blocklist_users, true ) ) {
					return 'blocked';
				}
			}

			return 'not_found';
		}

		/**
		 * Checks a list of countries against allowlist and blocklist.
		 *
		 * @param  array $countries The list of countries to check.
		 *
		 * @return string
		 */
		public function check_against_countries( array $countries ): string {
			global $wpdb;

			$cache_key_allowlist = 'rsssl_allowlist_countries';
			$cache_key_blocklist = 'rsssl_blocklist_countries';

			// Try to get the lists from cache.
			$allowlist_countries = wp_cache_get( $cache_key_allowlist );
			$blocklist_countries = wp_cache_get( $cache_key_blocklist );

			// If not cached, fetch from the database and then cache.
			if ( false === $allowlist_countries ) {
				// phpcs:ignore WordPress.DB
				$allowlist_countries = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE status = 'allowed' AND attempt_type = 'country' " );
				wp_cache_set( $cache_key_allowlist, $allowlist_countries, null, self::CACHE_EXPIRATION );
			}

			if ( false === $blocklist_countries ) {
				// phpcs:ignore WordPress.DB
				$blocklist_countries = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE status = 'blocked' AND attempt_type = 'country' " );
				wp_cache_set( $cache_key_blocklist, $blocklist_countries, null, self::CACHE_EXPIRATION );
			}

			// Check the countries.
			foreach ( $countries as $country ) {
				if ( in_array( $country, $allowlist_countries, true ) ) {
					return 'allowed';
				}
				if ( in_array( $country, $blocklist_countries, true ) ) {
					return 'blocked';
				}
			}

			return 'not_found';
		}

		/**
		 * Checks a list of IP addresses against allowlist and blocklist ranges.
		 *
		 * This function fetches IP ranges from the database tables and checks if the supplied IPs are within the allowlist or blocklist ranges.
		 * If an IP is found in the allowlist or blocklist range, it is stored in the corresponding database table and a status is returned.
		 *
		 * @param  array $ip_addresses  The list of IP addresses to check.
		 *
		 * @return string|null Status representing the check result: 'allowed' for allowlist hit, 'blocked' for blocklist hit, 'not found' for no hits.
		 */
		public function get_ip_range_status( array $ip_addresses ): string {

			global $wpdb;

			$cache_key_allowlist_ranges = 'rsssl_allowlist_ranges';
			$cache_key_blocklist_ranges = 'rsssl_blocklist_ranges';

			// Try to get the lists from cache.
			$allowlist_ranges = wp_cache_get( $cache_key_allowlist_ranges );
			$blocklist_ranges = wp_cache_get( $cache_key_blocklist_ranges );

			// If not cached, fetch from the database and then cache.
			if ( false === $allowlist_ranges ) {
				// phpcs:ignore WordPress.DB
				$allowlist_ranges = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_type = 'source_ip' AND status = 'allowed' AND attempt_value LIKE '%/%'" );
				wp_cache_set( $cache_key_allowlist_ranges, $allowlist_ranges, null, self::CACHE_EXPIRATION );
			}

			if ( false === $blocklist_ranges ) {
				// phpcs:ignore WordPress.DB
				$blocklist_ranges = $wpdb->get_col( 'SELECT attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_type = 'source_ip' AND status = 'blocked' AND attempt_value LIKE '%/%'" );
				wp_cache_set( $cache_key_blocklist_ranges, $blocklist_ranges, null, self::CACHE_EXPIRATION );
			}

			// Check the IP addresses.
			foreach ( $ip_addresses as $ip ) {
				foreach ( $allowlist_ranges as $range ) {
					if ( $this->ip_in_range( $ip, $range ) ) {
						return 'allowed';
					}
				}
				foreach ( $blocklist_ranges as $range ) {
					if ( $this->ip_in_range( $ip, $range ) ) {
						return 'blocked';
					}
				}
			}

			return 'not_found';
		}

		/**
		 * Adds an IP address to the allowlist.
		 *
		 * @param  string $ip  The IP address to add.
		 */
		public function add_to_allowlist( string $ip ): void {

			if ( ! rsssl_user_can_manage() ) {
				return;
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB
			$wpdb->insert(
				$wpdb->base_prefix . 'rsssl_allowlist',
				array(
					'ip_or_range' => filter_var(
						$ip,
						FILTER_VALIDATE_IP,
						FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
					),
				),
				array( '%s ' )
			);

			$this->invalidate_cache( 'rsssl_allowlist', $ip );
		}

		/**
		 * Adds an IP address to the blocklist.
		 *
		 * @param  string $ip  The IP address to add.
		 */
		public function add_to_blocklist( string $ip ): void {

			if ( ! rsssl_user_can_manage() ) {
				return;
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB
			$wpdb->insert(
				$wpdb->base_prefix . 'rsssl_blocklist',
				array(
					'ip_or_range' => filter_var(
						$ip,
						FILTER_VALIDATE_IP,
						FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
					),
				),
				array( '%s ' )
			);

			// Invalidate the blocklist cache.
			$this->invalidate_cache( 'rsssl_blocklist', $ip );
		}

		/**
		 * Removes an IP address from the allowlist.
		 *
		 * @param  string $ip  The IP address to remove.
		 */
		public function remove_from_allowlist( string $ip ): void {

			if ( ! rsssl_user_can_manage() ) {
				return;
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB
			$wpdb->delete(
				$wpdb->base_prefix . 'rsssl_allowlist',
				array(
					'ip_or_range' => filter_var(
						$ip,
						FILTER_VALIDATE_IP,
						FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
					),
				),
				array( '%s ' )
			);

			// Invalidate the allowlist cache.
			$this->invalidate_cache( 'rsssl_allowlist', $ip );
		}

		/**
		 * Removes an IP address from the blocklist.
		 *
		 * @param  string $ip  The IP address to remove.
		 */
		public function remove_from_blocklist( string $ip ): void {

			if ( ! rsssl_user_can_manage() ) {
				return;
			}

			global $wpdb;

			// phpcs:ignore WordPress.DB
			$wpdb->delete(
				$wpdb->base_prefix . 'rsssl_blocklist',
				array(
					'ip_or_range' => filter_var(
						$ip,
						FILTER_VALIDATE_IP,
						FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
					),
				),
				array( '%s ' )
			);

			// Invalidate the blocklist cache.
			$this->invalidate_cache( 'rsssl_blocklist', $ip );
		}

		/**
		 * Invalidates the cache for the specified table and IP address.
		 *
		 * This function clears the cache for the allowlist or blocklist based on the provided table and IP address.
		 * If the IP address is a range, it clears the cache for the corresponding range cache key. Otherwise, it clears
		 * the cache for the corresponding IP cache key.
		 *
		 * @param  string $table  The table name ('rsssl_allowlist' or 'rsssl_blocklist').
		 * @param  string $ip  The IP address or range.
		 *
		 * @return void
		 */
		public function invalidate_cache( string $table, string $ip ): void {

			if ( 'rsssl_allowlist' === $table ) {
				// Check if range or IP.
				if ( strpos( $ip, '/' ) !== false ) {
					wp_cache_delete( 'rsssl_allowlist_ranges' );
				} else {
					wp_cache_delete( 'rsssl_allowlist_ips' );
				}
			}

			if ( 'rsssl_blocklist' === $table ) {
				if ( strpos( $ip, '/' ) !== false ) {
					wp_cache_delete( 'rsssl_blocklist_ranges' );
				} else {
					wp_cache_delete( 'rsssl_blocklist_ips' );
				}
			}
		}

		/**
		 * Converts an IPv6 address to binary.
		 *
		 * @param  string $ip The IP address to check.
		 *
		 * @return string
		 */
		public static function ip_v6_2binary( string $ip ): string {
			$ip  = inet_pton( $ip );
			$bin = '';
			for ( $bit = strlen( $ip ) - 1; $bit >= 0; $bit-- ) {
				$bin = sprintf( '%08b', ord( $ip[ $bit ] ) ) . $bin;
			}

			return $bin;
		}

		/**
		 * Calculates the CIDR notation for a given IP range.
		 *
		 * @param  string $ip1 The first IP address in the range.
		 * @param  string $ip2 The last IP address in the range.
		 *
		 * @return array|string[]
		 */
		public static function calculate_cidr_from_range( string $ip1, string $ip2 ): array {
			// Determine if ip is v4 or v6.
			$is_ip_v6 = strpos( $ip1, ':' ) !== false;
			$ip1      = trim( $ip1 );
			$ip2      = trim( $ip2 );
			$cidr     = 0;

			if ( $is_ip_v6 ) {
				// Ensure two IPv6 addresses were provided.
				$start_binary = inet_pton( $ip1 );
				$start_bin    = '';
				$bits         = 15;
				while ( $bits >= 0 ) {
					$bin       = sprintf( '%08b', ( ord( $start_binary[ $bits ] ) ) );
					$start_bin = $bin . $start_bin;
					--$bits;
				}

				// Ensure two IPv6 addresses were provided.
				$end_binary = inet_pton( $ip2 );
				$end_bin    = '';
				$bits       = 15;
				while ( $bits >= 0 ) {
					$bin     = sprintf( '%08b', ( ord( $end_binary[ $bits ] ) ) );
					$end_bin = $bin . $end_bin;
					--$bits;
				}

				// Convert IP addresses to binary.
				$cidr_array = array();
				$mask       = 127;
				$diff_first = false;
				while ( $mask > 0 ) {
					if ( $start_bin[ $mask ] !== $end_bin[ $mask ] ) {
						while ( $start_bin[ $mask ] !== $end_bin[ $mask ] ) {
							if ( $diff_first ) {
								$ip_bin = str_pad( substr( $start_bin, 0, $mask ) . '1', 128, '0', STR_PAD_RIGHT );
								$ip     = '';
								$offset = 0;
								while ( $offset <= 7 ) {
									$bin_part = substr( $ip_bin, ( $offset * 16 ), 16 );
									$ip      .= dechex( bindec( $bin_part ) );
									if ( 7 !== $offset ) {
										$ip .= ':';
									}
									++$offset;
								}
								$cidr_array[] = inet_ntop( inet_pton( $ip ) ) . '/' . ( $mask + 1 );
							}
							--$mask;
						}
						// Ensure two IPv6 addresses were provided.
						if ( false === $diff_first ) {
							$diff_first = true;

							$ip_bin = str_pad( substr( $start_bin, 0, $mask ) . '1', 128, '0', STR_PAD_RIGHT );
							$ip     = '';
							$offset = 0;
							while ( $offset <= 7 ) {
								$bin_part = substr( $ip_bin, ( $offset * 16 ), 16 );
								$ip      .= dechex( bindec( $bin_part ) );
								if ( 7 !== $offset ) {
									$ip .= ':';
								}
								++$offset;
							}
							$cidr_array[] = inet_ntop( inet_pton( $ip ) ) . '/' . ( $mask + 1 );
						}
					} else {
						--$mask;
					}
				}

				return $cidr_array;
			} else {
				// Ensure two IPv4 addresses were provided.
				array_map(
					function ( $arg ) {
						if ( ! filter_var( $arg, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
							throw new \InvalidArgumentException( ' is not a valid IPv4 address' );
						}
					},
					func_get_args()
				);

				// Array for CIDRs to return.
				$cidrs = array();

				// Convert the IPs to unsigned integers so we can do math with them.
				$start  = (int) sprintf( '%u', ip2long( $ip1 ) );
				$finish = (int) sprintf( '%u', ip2long( $ip2 ) );

				if ( $start === $finish ) {
					return array( $ip1 . '/32' );
				}

				// Calculate the log base 2 for the quantity of IP addresses in the range.
				$log2 = log( ( $finish - $start ) + 1, 2 );

				// Determine the closest enclosing CIDR suffix that isn't too big.
				$cidr_suffix = 32 - floor( $log2 );

				// Add the corresponding CIDR to the return array.
				$cidrs[] = long2ip( $start ) . '/' . $cidr_suffix;

				// See if we have any IPs left over.
				$num_ips_remaining = ( ( $finish - $start ) + 1 ) - pow( 2, 32 - $cidr_suffix );

				// If so, recurse.
				if ( $num_ips_remaining > 0 ) {
					$next_starting_ip = long2ip( (int) ( $start + pow( 2, 32 - $cidr_suffix ) ) );
					$cidrs            = array_merge(
						$cidrs,
						self::calculate_cidr_from_range( $next_starting_ip, $ip2 )
					);
				}

				return $cidrs;

			}
		}

		/**
		 * Calculates the number of IPs in a CIDR.
		 *
		 * @param string $cidr The CIDR to calculate the number of IPs for.
		 *
		 * @return string
		 */
		public static function calculate_number_of_ips_form_cidr( $cidr ) {
			// first we determine v4 or v6.
			if ( strpos( $cidr, ':' ) !== false ) {
				// v6.
				$cidr = explode( '/', $cidr );
				$cidr = $cidr[1];
				$ips  = pow( 2, ( 128 - $cidr ) );

				return self::format_number( $ips );
			}
			// we calculate the number of ips in a cidr.
			$cidr = explode( '/', $cidr );
			$cidr = $cidr[1];
			$ips  = pow( 2, ( 32 - $cidr ) );

			return self::format_number( $ips );
		}

		/**
		 * Formats a number to a human readable format.
		 *
		 * @param  int $number The number to format.
		 *
		 * @return string
		 */
		public static function format_number( int $number ): string {
			$suffixes       = array(
				'',
				'k (Thousand)',
				'M (Million)',
				'B (Billion)',
				'T (Trillion)',
				'P (Quadrillion)',
				'E (Quintillion)',
				'Z (Sextillion)',
				'Y (Septillion)',
			);
			$i              = 0;
			$suffixes_count = count( $suffixes ); // Get the count once outside the loop.
			while ( abs( $number ) >= 1000 && $i < $suffixes_count - 1 ) {
				$number /= 1000;
				++$i;
			}

			return round( $number, 2 ) . $suffixes[ $i ];
		}

		/**
		 * Fetches a list of IP addresses in the allowlist.
		 *
		 * This method retrieves a list of IP addresses from the "rsssl_login_attempts" table
		 * based on certain conditions and filters set in the method.
		 *
		 * If the LoginAttempt is not activated, it will fetch dummy data. It uses the
		 * `Rsssl_Data_Table` and `Rsssl_Query_Builder` objects to structure and execute the SQL query.
		 *
		 * @param  array|null $data  An optional array of data that might contain filters,
		 *                       search criteria, pagination, or sorting instructions for
		 *                       the Rsssl_Data_Table.
		 *
		 * @return array The resulting list of IP addresses in the allowlist and any associated data.
		 *               The result structure is not only limited to the IP addresses but also
		 *               contains metadata and other relevant information. It also appends the $data
		 *               parameter to the 'post' key of the result for tracing purposes.
		 *
		 * @throws Exception Throws an exception if there's an error while fetching the data.
		 *
		 * @uses Rsssl_Data_Table To structure the SQL query.
		 * @uses Rsssl_Query_Builder To build the actual SQL query.
		 *
		 * @example
		 * $exampleClass = new ClassName();
		 * $ipAllowList = $exampleClass->get_list();
		 * foreach ($ipAllowList as $ip) {
		 *     echo $ip['source_ip'];
		 * }
		 *
		 * @since 7.0.0
		 * @author Marcel Santing
		 */
		public function get_list( array $data = null ): array {
			global $wpdb;
			try {
				$timezone = $this->get_wordpress_timezone()->getName();

				// Manipulate the ['sortColumn']['column'] to sort by timestamp instead of datetime.
				if ( isset( $data['sortColumn']['column'] ) && 'datetime' === $data['sortColumn']['column'] ) {
					$data['sortColumn']['column'] = 'last_failed';
				}

				// manual ad a filter value to the $data.
				$data_table = new Rsssl_Data_Table(
					$data,
					new Rsssl_Query_Builder( $wpdb->base_prefix . 'rsssl_login_attempts' )
				);
				$data_table->set_select_columns(
					array(
						'id',
						'attempt_value',
						'attempt_type',
						'status',
						'last_failed',
						"raw:DATE_FORMAT(FROM_UNIXTIME(last_failed), '%%H:%%i, %%M %%e') as datetime",
					)
				);
				// we already add a where selection in the query builder.
				$data_table->set_where(
					array(
						'attempt_type',
						'=',
						'source_ip',
					)
				);

				$result = $data_table
					->validate_search()
					->validate_sorting(
						array(
							'column'    => 'last_failed',
							'direction' => 'desc',
						)
					)
					->validate_filter()
					->validate_pagination()
					->get_results();

				// Now we remove all sorting.
				if ( isset( $data['sortColumn']['column'] ) && 'timestamp' === $data['sortColumn']['column'] ) {
					$data['sortColumn']['column'] = 'datetime';
				}

				$result['post'] = $data;

				// We loop through the results and convert the datetime to the timezone of the user.
				foreach ( $result['data'] as $key => $value ) {
					// Ensure $timezone is valid before attempting conversion.
					if ( is_string( $value->datetime ) ) {
						$result['data'][ $key ]->datetime = $this->convert_timezone( $value->datetime, $timezone );
					}
				}

				return $result;

			} catch ( Exception $e ) {
				wp_die( esc_html( $e->getMessage() ) );
			}
		}

		/**
		 * Fetches a list of usernames in the allowlist.
		 *
		 * @param  array|null $data  An optional array of data that might contain filters.
		 * @throws Exception When the timezone is not valid.
		 */
		public function get_user_list( array $data ) {
			global $wpdb;
			try {
				$timezone = $this->get_wordpress_timezone()->getName();

				// Manipulate the ['sortColumn']['column'] to sort by timestamp instead of datetime.
				if ( isset( $data['sortColumn']['column'] ) && 'datetime' === $data['sortColumn']['column'] ) {
					$data['sortColumn']['column'] = 'last_failed';
				}
				// manual ad a filter value to the $data.
				$data_table = new Rsssl_Data_Table(
					$data,
					new Rsssl_Query_Builder( $wpdb->base_prefix . 'rsssl_login_attempts' )
				);
				$data_table->set_select_columns(
					array(
						'id',
						'attempt_value',
						'attempt_type',
						'status',
						'last_failed',
						"raw:DATE_FORMAT(FROM_UNIXTIME(last_failed), '%%H:%%i, %%M %%e') as datetime",
					)
				);
				// we already add a where selection in the query builder.
				$data_table->set_where(
					array(
						'attempt_type',
						'=',
						'username',
					)
				);

				// We only get the users where the status in not null
				$data_table->set_where(
					array(
						'status',
						'!=',
						'null',
					)
				);

				$result = $data_table
					->validate_search()
					->validate_sorting(
						array(
							'column'    => 'last_failed',
							'direction' => 'desc',
						)
					)
					->validate_filter()
					->validate_pagination()
					->get_results();

				if ( isset( $data['sortColumn']['column'] ) && 'last_failed' === $data['sortColumn']['column'] ) {
					$data['sortColumn']['column'] = 'datetime';
				}

				$result['post'] = $data;

				// We loop through the results and convert the datetime to the timezone of the user.
				foreach ( $result['data'] as $key => $value ) {
					// Ensure $timezone is valid before attempting conversion.
					if ( is_string( $value->datetime ) ) {
						$result['data'][ $key ]->datetime = $this->convert_timezone( $value->datetime, $timezone );
					}
				}

				return $result;

			} catch ( Exception $e ) {
				wp_die( esc_html( $e->getMessage() ) );
			}
		}


		/**
		 * Fetches a list of countries in the allowlist.
		 *
		 * @param  array|null $data  An optional array of data that might contain filters.
		 *
		 * @throws Exception When the timezone is not valid.
		 */
		public function get_country_list( ?array $data ) {
			// since countries has a status all option we need to call a different function.
			if ( isset( $data['filterValue'] ) && 'countries' === $data['filterValue'] ) {
				return ( new Rsssl_Geo_Location() )->get_countries( $data );
			} elseif ( isset( $data['filterValue'] ) && 'regions' === $data['filterValue'] ) {
				return ( new Rsssl_Geo_Location() )->get_regions( $data );
			}

			global $wpdb;

			try {
				$timezone = get_option( 'timezone_string' );
				if ( ! $timezone ) {
					// Als er geen tijdzone is ingesteld, gebruik dan UTC.
					$timezone = 'UTC';
				}
				// manual ad a filter value to the $data.
				$data_table = new Rsssl_Data_Table(
					$data,
					new Rsssl_Query_Builder( $wpdb->base_prefix . 'rsssl_login_attempts' )
				);
				$data_table->set_select_columns(
					array(
						'attempt_value',
						'raw: ' . $wpdb->base_prefix . 'rsssl_login_attempts.id as id',
						'attempt_type',
						'raw: c.country_name as country_name',
						'raw: c.region as region',
						'raw: ' . $wpdb->base_prefix . 'rsssl_login_attempts.status as status',
						'last_failed',
						"raw:DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(last_failed), 'UTC', '{$timezone}'), '%%H:%%i, %%M %%e') as datetime",
					)
				);

				$data_table->join( $wpdb->base_prefix . 'rsssl_country' )
				           ->on( $wpdb->base_prefix . 'rsssl_login_attempts.attempt_value', '=', 'c.iso2_code' )
				           ->as( 'c' );

				// we already add a where selection in the query builder.

				$result2         = $data_table
					->validate_search()
					->validate_sorting(
						array(
							'column'    => 'datetime',
							'direction' => 'desc',
						)
					)
					->validate_filter()
					->validate_pagination()
					->get_results();
				$result2['post'] = $data;

				return $result2;

			} catch ( Exception $e ) {
				wp_die( esc_html( $e->getMessage() ) );
			}
		}

		/**
		 * Updates a row in the database.
		 *
		 * @param array  $data The data to update.
		 * @param string $type The type of data to update.
		 *
		 * @throws Exception When an error occurs while updating the data.
		 */
		public function update_row( $data, $type = 'ip' ): array {
			global $wpdb;
			// phpcs:ignore WordPress.DB
			$result = $wpdb->update(
				$wpdb->base_prefix . 'rsssl_login_attempts',
				array(
					'status' => $data['status'],
				),
				array(
					'id' => $data['id'],
				),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return array( 'error', $wpdb->last_error, $wpdb->last_query );
			}

			// now we fetch the record.
			// phpcs:ignore WordPress.DB
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE id = %d",
				$data['id']
			);

			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_row( $query, ARRAY_A );
			// no errors so we add an event log based on the status.
			$this->log_event_data( $result );

			if ( 'ip' === $type ) {
				// we need to update the cache.
				$this->invalidate_cache( 'rsssl_allowlist', $result['attempt_value'] );
				$this->invalidate_cache( 'rsssl_blocklist', $result['attempt_value'] );
			}

			if ( 'username' === $type ) {
				// we need to update the cache.
				wp_cache_delete( 'rsssl_allowlist_users' );
				wp_cache_delete( 'rsssl_blocklist_users' );
			}

			return array( 'success', $data );
		}

		/**
		 * Deletes a row from the database.
		 *
		 * @param  array $data  The data to delete.
		 *
		 * @return array
		 * @throws Exception When an error occurs while deleting the data.
		 */
		public function add_to_ip_list( array $data ): array {
			global $wpdb;

			// if the ipAddress key is a sting but containS a comma, we convert it to an array.
			if ( is_string( $data['ipAddress'] ) && str_contains( $data['ipAddress'], ',' ) ) {
				$data['ipAddress'] = explode( ',', $data['ipAddress'] );
			}
			// fist we check if the ip data is a collection of multiple ip's.
			if ( is_array( $data['ipAddress'] ) ) {
				foreach ( $data['ipAddress'] as $ip_address ) {
					$data['ipAddress'] = trim( $ip_address );
					// first we check if the ip is with mask notation.
					if ( str_contains( $data['ipAddress'], '/' ) ) {
						// then we validate the cidr if so.
						if ( ! $this->is_valid_cidr( $data['ipAddress'] ) ) {
							continue;
						}
					} elseif ( ! filter_var(
						$data['ipAddress'],
						FILTER_VALIDATE_IP,
						FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6
					) ) { // we have a single ip so we check if it is a valid ip or valid ipv6.

						continue;
					}

					$this->add_ip_to_list( $data );
				}

				return array( 'success', $data );
			} else {
				// we have a single ip.
				return $this->add_ip_to_list( $data );
			}
		}

		/**
		 * Add's an IP address to the allowlist or blocklist.
		 *
		 * @param array $data The data to add.
		 *
		 * @return array
		 * @throws Exception When an error occurs while adding the data.
		 */
		private function add_ip_to_list( $data ) {
			global $wpdb;
			// first we check if the ip is already in the list and if so, we change the status.
			// Check if the ip already exists using a prepared statement.
			$sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'source_ip'",
				$data['ipAddress']
			);

			// phpcs:ignore WordPress.DB
			$exists = $wpdb->get_var( $sql );

			if ( $exists ) {
				// ok so the ip already exists, we need to update the status.
				// phpcs:ignore WordPress.DB
				$result = $wpdb->update(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'status' => $data['status'],
					),
					array(
						'attempt_value' => $data['ipAddress'],
						'attempt_type'  => 'source_ip',
					),
					array( '%s' ),
					array( '%s', '%s' )
				);

				return array( 'success', $data, $wpdb->last_query );
			}

			// phpcs:ignore WordPress.DB
			$result = $wpdb->insert(
				$wpdb->base_prefix . 'rsssl_login_attempts',
				array(
					'attempt_value' => $data['ipAddress'],
					'attempt_type'  => 'source_ip',
					'status'        => $data['status'],
					'last_failed'   => time(),
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);

			if ( false === $result ) {
				return array( 'error', $wpdb->last_error, $wpdb->last_query );
			}

			// we fetch the datarecord.
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'source_ip'",
				$data['ipAddress']
			);

			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_row( $query, ARRAY_A );

			$this->log_event_data( $result );

			// we need to update the cache.
			$this->invalidate_cache( 'rsssl_allowlist', $result['attempt_value'] );
			$this->invalidate_cache( 'rsssl_blocklist', $result['attempt_value'] );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Checks if a CIDR is valid.
		 *
		 * @param  string $cidr The CIDR to check.
		 *
		 * @return bool
		 */
		private function is_valid_cidr( string $cidr ): bool {
			$parts = explode( '/', $cidr );
			if ( 2 !== count( $parts ) ) {
				return false;
			}
			$ip      = $parts[0];
			$netmask = (int) $parts[1];

			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				// Validate IPv4 CIDR.
				if ( 0 > $netmask || 32 < $netmask ) {
					return false;
				}

				return true;
			} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				// Validate IPv6 CIDR.
				if ( 0 > $netmask || 128 < $netmask ) {
					return false;
				}

				return true;
			}

			// If not IPv4 or IPv6, return false.
			return false;
		}

		/**
		 * Adds a Country to the allowlist or blocklist.
		 *
		 * @param  array $data The data to add.
		 *
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function add_country_to_list( array $data ): array {
			global $wpdb;
			// first we check if the country is already in the list.
			// Check if the country already exists using a prepared statement.
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
				$data['country']
			);
			//phpcs:ignore WordPress.DB
			$exists = $wpdb->get_var( $sql );

			if ( $exists ) {
				return array( 'error', 'Country already exists in the list.' );
			}

			// phpcs:ignore WordPress.DB
			$result = $wpdb->insert(
				$wpdb->base_prefix . 'rsssl_login_attempts',
				array(
					'attempt_value' => $data['country'],
					'attempt_type'  => 'country',
					'status'        => $data['status'],
					'last_failed'   => time(),
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);

			if ( false === $result ) {
				return array( 'error', $wpdb->last_error, $wpdb->last_query );
			}

			// we fetch the datarecord.
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
				$data['country']
			);

			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_row( $query, ARRAY_A );
			$this->log_event_data( $result );

			// we need to update the cache.
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Removes a country from the allowlist or blocklist.
		 *
		 * @param  array $data The data to remove.
		 *
		 * @throws Exception When an error occurs while removing the data.
		 */
		public function remove_country_from_list( array $data ): array {
			global $wpdb;
			// we fetch the data-record.
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
				$data['country']
			);
			// phpcs:ignore WordPress.DB
			$record = $wpdb->get_row( $query, ARRAY_A );

			// phpcs:ignore WordPress.DB
			$result = $wpdb->delete(
				$wpdb->base_prefix . 'rsssl_login_attempts',
				array(
					'attempt_value' => $data['country'],
					'attempt_type'  => 'country',
				),
				array( '%s', '%s' )
			);

			if ( false === $result ) {
				return array( 'error', $wpdb->last_error, $wpdb->last_query );
			}

			// we fetch the datarecord.
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
				$data['country']
			);
			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_row( $query, ARRAY_A );
			$this->log_event_data( $record, true );

			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Adds a region to the allowlist or blocklist.
		 *
		 * @param  array $data The data to add.
		 *
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function add_region_to_list( array $data ): array {
			global $wpdb;

			// based on the region we need to get the countries associated with it.
			$query = $wpdb->prepare(
				"SELECT iso2_code FROM {$wpdb->base_prefix}rsssl_country WHERE region_code = %s",
				$data['region']
			);

			// phpcs:ignore WordPress.DB
			$countries = $wpdb->get_results( $query );

			// now we add the countries to the list.
			foreach ( $countries as $key => $country ) {
				// we add a status property.
				$country->status        = $data['status'];
				$country->attempt_type  = 'country';
				$country->attempt_value = $country->iso2_code;

				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
					$country->iso2_code
				);

				// phpcs:ignore WordPress.DB
				$exists = $wpdb->get_var( $sql );

				if ( $exists ) {
					unset( $countries[ $key ] );
					continue;
				}

				// phpcs:ignore WordPress.DB
				$result = $wpdb->insert(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'attempt_value' => $country->iso2_code,
						'attempt_type'  => 'country',
						'status'        => $data['status'],
						'last_failed'   => time(),
					),
					array( '%s', '%s', '%s', '%s', '%d' )
				);

				if ( false === $result ) {
					return array( 'error', $wpdb->last_error, $wpdb->last_query );
				}
			}

			$this->log_event_data( $countries );
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data, $wpdb->last_query );
		}


		/**
		 * Adds a User to the the list.
		 *
		 * @param array $data The data to add.
		 *
		 * @return array
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function add_user_to_list( $data ): array {
			global $wpdb;

			// first we check if the ip is already in the list and if so, we change the status.
			// Check if the ip already exists using a prepared statement.
			$sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'username'",
				$data['user']
			);

			// phpcs:ignore WordPress.DB
			$exists = $wpdb->get_var( $sql );

			if ( $exists ) {
				// ok so the ip already exists, we need to update the status.
				// phpcs:ignore WordPress.DB
				$result = $wpdb->update(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'status' => $data['status'],
					),
					array(
						'attempt_value' => $data['user'],
						'attempt_type'  => 'username',
					),
					array( '%s' ),
					array( '%s', '%s' )
				);

				return array( 'success', $data );
			}

			// phpcs:ignore WordPress.DB
			$result = $wpdb->insert(
				$wpdb->base_prefix . 'rsssl_login_attempts',
				array(
					'attempt_value' => $data['user'],
					'attempt_type'  => 'username',
					'status'        => $data['status'],
					'last_failed'   => time(),
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);

			if ( false === $result ) {
				return array( 'error', $wpdb->last_error, $wpdb->last_query );
			}

			// we now fetch the record.
			$query = $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'username'",
				$data['user']
			);

			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_row( $query, ARRAY_A );

			$this->log_event_data( $result );

			wp_cache_delete( 'rsssl_allowlist_users' );
			wp_cache_delete( 'rsssl_blocklist_users' );

			return array( 'success', $data );
		}

		/**
		 * Updates multiple rows in the database.
		 *
		 * @param  array $data The data to update.
		 *
		 * @return array
		 * @throws Exception When an error occurs while updating the data.
		 */
		public function update_multi_rows( array $data ): array {
			global $wpdb;

			// Prepare placeholders and values for the IN clause.
			$placeholders = implode( ',', array_fill( 0, count( $data['ids'] ), '%d' ) );
			$query_values = array_merge( array( $data['status'] ), $data['ids'] );

			// Update the rows.
			//phpcs:ignore WordPress.DB
			$update_query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB
				"UPDATE {$wpdb->base_prefix}rsssl_login_attempts SET status = %s WHERE id  (" . $placeholders . ')',
				$query_values
			);

			// phpcs:ignore WordPress.DB
			$wpdb->query( $update_query );

			// Fetch the updated records.
			$select_query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB
				"SELECT * FROM {$wpdb->base_prefix}rsssl_login_attempts WHERE id IN (" . $placeholders . ')',
				$data['ids']
			);

			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_results( $select_query, ARRAY_A );

			// Add an event log based on the status.
			$this->log_event_data( $result );

			// Update the cache.
			foreach ( $result as $row ) {
				$this->invalidate_cache( 'rsssl_allowlist', $row['attempt_value'] );
				$this->invalidate_cache( 'rsssl_blocklist', $row['attempt_value'] );
			}

			wp_cache_delete( 'rsssl_allowlist_users' );
			wp_cache_delete( 'rsssl_blocklist_users' );
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data );
		}

		/**
		 * Deletes a row from the database.
		 *
		 * @param  array $data  The data to delete.
		 *
		 * @throws Exception When an error occurs while deleting the data.
		 */
		public function delete_entry( $data ): array {
			global $wpdb;

			// Safely prepare the SQL statement for fetching the record.
			$prepared_query = $wpdb->prepare(
				'SELECT id, status, attempt_type, attempt_value FROM ' . $wpdb->base_prefix . 'rsssl_login_attempts WHERE id = %d',
				$data['id']
			);

			// phpcs:ignore WordPress.DB
			$result = $wpdb->get_row( $prepared_query, ARRAY_A );

			// Check if record exists.
			if ( ! $result ) {
				return array( 'error', 'Record not found.' );
			}

			// Safely delete the record.
			// phpcs:ignore WordPress.DB
			$wpdb->delete( $wpdb->base_prefix . 'rsssl_login_attempts', array( 'id' => $data['id'] ), array( '%d' ) );

			// Log the event based on record status.
			$this->log_event_data( $result, true );

			wp_cache_delete( 'rsssl_allowlist_ranges' );
			wp_cache_delete( 'rsssl_allowlist_ips' );
			wp_cache_delete( 'rsssl_blocklist_ranges' );
			wp_cache_delete( 'rsssl_blocklist_ips' );
			wp_cache_delete( 'rsssl_allowlist_users' );
			wp_cache_delete( 'rsssl_blocklist_users' );
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data );
		}

		/**
		 * Deletes multiple rows from the database.
		 *
		 * @param  array $data  The data to delete.
		 *
		 * @throws Exception When an error occurs while deleting the data.
		 */
		public function delete_multi_entries( array $data ): array {
			global $wpdb;

			// Safely prepare the SQL statement for deletion.
			$ids = implode( ',', array_map( 'intval', $data['ids'] ) ); // Ensure ids are integers.
			// phpcs:ignore WordPress.DB
			$prepared_query = $wpdb->prepare( 'SELECT id, status, attempt_type, attempt_value FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE id IN ($ids)" );
			// phpcs:ignore WordPress.DB
			$deleted_records = $wpdb->get_results( $prepared_query );

			// Now safely delete the records.
			// phpcs:ignore WordPress.DB
			$delete_query = $wpdb->prepare( 'DELETE FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE id IN ($ids)" );
			// phpcs:ignore WordPress.DB
			$deleted_count = $wpdb->query( $delete_query );

			// Check if records were actually deleted.
			if ( false === $deleted_count ) {
				return array( 'error', 'Failed to delete records.' );
			}

			// Log events.
			$this->log_event_data( $deleted_records, true );

			wp_cache_delete( 'rsssl_allowlist_ranges' );
			wp_cache_delete( 'rsssl_allowlist_ips' );
			wp_cache_delete( 'rsssl_blocklist_ranges' );
			wp_cache_delete( 'rsssl_blocklist_ips' );
			wp_cache_delete( 'rsssl_allowlist_users' );
			wp_cache_delete( 'rsssl_blocklist_users' );
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data );
		}

		/**
		 * Logs events for the given records.
		 *
		 * @param array $data    The data to log.
		 * @param bool  $deleted Whether the data was deleted or not.
		 *
		 * @return void
		 * @throws Exception When an error occurs while logging the data.
		 */
		private function log_event_data( $data, $deleted = false ) {
			// If data is not an array, convert it to an array.
			$original = $data;
			if ( ! is_array( $data ) || ( is_object( $original ) && $data === (array) $original ) ) {
				$data = array( $data );
			}

			if ( ! isset( $data[0] ) && isset( $data['id'] ) ) {
				$data = array( $data );
			}

			foreach ( $data as $record ) {
				if ( is_array( $record ) ) {
					$status        = $record['status'] ?? null;
					$attempt_type  = $record['attempt_type'] ?? null;
					$attempt_value = $record['attempt_value'] ?? null;
				} elseif ( is_object( $record ) ) {
					$status        = $record->status ?? null;
					$attempt_type  = $record->attempt_type ?? null;
					$attempt_value = $record->attempt_value ?? null;
				} else {
					// If it's neither an array nor an object, skip this iteration.
					continue;
				}

				switch ( array( $status, $attempt_type ) ) {
					case array( 'blocked', 'source_ip' ):
						$event_type = self::EVENT_CODE_IP_BLOCKED;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_IP_UNBLOCKED;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, $attempt_value );
						break;
					case array( 'allowed', 'source_ip' ):
						$event_type = self::EVENT_CODE_IP_ADDED_TO_ALLOWLIST;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_IP_REMOVED_FROM_ALLOWLIST;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, $attempt_value );
						break;
					case array( 'blocked', 'username' ):
						$event_type = self::EVENT_CODE_USER_BLOCKED;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_USER_UNBLOCKED;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, '', $attempt_value );
						break;
					case array( 'allowed', 'username' ):
						$event_type = self::EVENT_CODE_USER_ADDED_TO_ALLOWLIST;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_USER_REMOVED_FROM_ALLOWLIST;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, '', $attempt_value );
						break;
					case array( 'locked', 'username' ):
						$event_type = self::EVENT_CODE_USER_LOCKED;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_USER_UNLOCKED;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, '', $attempt_value );
						break;
					case array( 'locked', 'source_ip' ):
						$event_type = self::EVENT_CODE_IP_LOCKED;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_IP_UNLOCKED;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, $attempt_value );
						break;
					case array( 'blocked', 'country' ):
						$event_type = self::EVENT_CODE_COUNTRY_BLOCKED;
						if ( $deleted ) {
							$event_type = self::EVENT_CODE_COUNTRY_UNBLOCKED;
						}
						$event_type = Rsssl_Event_Type::add_to_block( $event_type, '', '', $attempt_value );
						break;
					default:
						// No event to log for this record.
						continue 2;
				}

				Rsssl_Event_Log::log_event( $event_type );
			}
		}

		/**
		 * Removes countries based on the regions.
		 *
		 * @param array $data The data to add.
		 *
		 * @return array
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function remove_region_from_list( $data ) {
			global $wpdb;
			// based on the region we need to get the countries associated with it.
			// phpcs:ignore WordPress.DB
			$countries = $wpdb->get_results( 'SELECT iso2_code FROM ' . $wpdb->base_prefix . "rsssl_country WHERE region_code = '{$data['region']}'" );

			// now we add the countries to the list.
			foreach ( $countries as $key => $country ) {
				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
					$country->iso2_code
				);

				// we add a status property.
				$country->status        = $data['status'];
				$country->attempt_type  = 'country';
				$country->attempt_value = $country->iso2_code;

				// phpcs:ignore WordPress.DB
				$exists = $wpdb->get_var( $sql );

				if ( ! $exists ) {
					// we unset the country from the list.
					unset( $countries[ $key ] );
					continue;
				}

				// phpcs:ignore WordPress.DB
				$result = $wpdb->delete(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'attempt_value' => $country->iso2_code,
						'attempt_type'  => 'country',
					),
					array( '%s', '%s' )
				);

				if ( false === $result ) {
					return array( 'error', $wpdb->last_error, $wpdb->last_query );
				}
			}

			$this->log_event_data( $countries, true );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Adds Countries to the allowlist or blocklist.
		 *
		 * @param array $data The data to add.
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function add_countries_to_list( $data ) {
			global $wpdb;

			$countries = array();
			// now we add the countries to the list.
			foreach ( $data['countries'] as $country ) {

				// we create an object.
				$country_added                = new StdClass();
				$country_added->iso2_code     = $country;
				$country_added->status        = 'blocked';
				$country_added->attempt_type  = 'country';
				$country_added->attempt_value = $country;

				$countries[] = $country_added;

				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
					$country
				);

				// phpcs:ignore WordPress.DB
				$exists = $wpdb->get_var( $sql );

				if ( $exists ) {
					continue;
				}

				// phpcs:ignore WordPress.DB
				$result = $wpdb->insert(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'attempt_value' => $country,
						'attempt_type'  => 'country',
						'status'        => $data['status'],
						'last_failed'   => time(),
					),
					array( '%s', '%s', '%s', '%s', '%d' )
				);

				if ( false === $result ) {
					return array( 'error', $wpdb->last_error, $wpdb->last_query );
				}
			}

			$this->log_event_data( $countries );

			// we need to update the cache.
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Removes Countries from the allowlist or blocklist.
		 *
		 * @param  array $data The data to add.
		 *
		 * @return array
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function remove_countries_from_list( array $data ): array {
			global $wpdb;

			$countries = array();
			// now we add the countries to the list.
			foreach ( $data['countries'] as $country ) {

				// we create an object.
				$country_added                = new StdClass();
				$country_added->iso2_code     = $country;
				$country_added->status        = 'blocked';
				$country_added->attempt_type  = 'country';
				$country_added->attempt_value = $country;

				$countries[] = $country_added;

				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
					$country
				);

				// phpcs:ignore WordPress.DB
				$exists = $wpdb->get_var( $sql );

				if ( ! $exists ) {
					continue;
				}

				// phpcs:ignore WordPress.DB
				$result = $wpdb->delete(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'attempt_value' => $country,
						'attempt_type'  => 'country',
					),
					array( '%s', '%s' )
				);

				if ( false === $result ) {
					return array( 'error', $wpdb->last_error, $wpdb->last_query );
				}
			}

			$this->log_event_data( $countries, true );
			// we clear the cache.
			wp_cache_delete( 'rsssl_allowlist_countries' );
			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Adds regions to the allowlist or blocklist.
		 *
		 * @param  array $data The data to add.
		 *
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function add_regions_to_list( array $data ): array {
			global $wpdb;
			$countries = array();

			// first we get the regions.
			$region_count = count( $data['regions'] );

			$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB
				'SELECT region_code FROM ' . $wpdb->base_prefix . 'rsssl_country WHERE id IN (' . implode( ',', array_fill( 0, $region_count, '%s' ) ) . ')',
				...$data['regions']
			);
			// phpcs:ignore WordPress.DB
			$regions = $wpdb->get_results( $query );

			// based on all the regions we need to get the countries associated with it.
			foreach ( $regions as $region ) {
				// first we get the countries.
				$query = $wpdb->prepare(
					'SELECT iso2_code FROM ' . $wpdb->base_prefix . 'rsssl_country WHERE region_code = %s',
					$region->region_code
				);

				$countries = array_merge(
					$countries,
					// phpcs:ignore WordPress.DB
					$wpdb->get_results( $query )
				);
			}

			// now we add the countries to the list.
			foreach ( $countries as $key => $country ) {
				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
					$country->iso2_code
				);

				// we add a status property.
				// phpcs:ignore WordPress.DB
				$exists = $wpdb->get_var( $sql );

				if ( $exists ) {
					unset( $countries[ $key ] );
					continue;
				}

				// phpcs:ignore WordPress.DB
				$result = $wpdb->insert(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'attempt_value' => $country->iso2_code,
						'attempt_type'  => 'country',
						'status'        => 'blocked',
						'last_failed'   => time(),
					),
					array( '%s', '%s', '%s', '%s', '%d' )
				);

				if ( false === $result ) {
					return array( 'error', $wpdb->last_error, $wpdb->last_query );
				}

				$country->status        = 'blocked';
				$country->attempt_type  = 'country';
				$country->attempt_value = $country->iso2_code;
			}
			$this->log_event_data( $countries );
			// we clear the cache.
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Removes regions from the allowlist or blocklist.
		 *
		 * @param  array $data The data to add.
		 *
		 * @return array
		 * @throws Exception When an error occurs while adding the data.
		 */
		public function remove_regions_from_list( array $data ): array {
			global $wpdb;
			$countries    = array();
			$placeholders = implode( ',', array_fill( 0, count( $data['regions'] ), '%s' ) );

			$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB
				'SELECT region_code FROM ' . $wpdb->base_prefix . 'rsssl_country WHERE id IN (' . $placeholders . ')',
				$data['regions']
			);

			// phpcs:ignore WordPress.DB
			$regions = $wpdb->get_results( $query );

			// based on all the regions we need to get the countries associated with it.
			foreach ( $regions as $region ) {
				$query = $wpdb->prepare(
					'SELECT iso2_code FROM ' . $wpdb->base_prefix . 'rsssl_country WHERE region_code = %s',
					$region->region_code
				);

				$countries = array_merge(
					$countries,
					// phpcs:ignore WordPress.DB
					$wpdb->get_results( $query )
				);
			}

			// now we add the countries to the list.
			foreach ( $countries as $country ) {
				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . "rsssl_login_attempts WHERE attempt_value = %s AND attempt_type = 'country'",
					$country->iso2_code
				);

				// phpcs:ignore WordPress.DB
				$exists = $wpdb->get_var( $sql );

				if ( $exists ) {
					continue;
				}

				// now we remove the countries.
				// phpcs:ignore WordPress.DB
				$result = $wpdb->delete(
					$wpdb->base_prefix . 'rsssl_login_attempts',
					array(
						'attempt_value' => $country->iso2_code,
						'attempt_type'  => 'country',
					),
					array( '%s', '%s' )
				);

				$country->status        = 'blocked';
				$country->attempt_type  = 'country';
				$country->attempt_value = $country->iso2_code;

				if ( false === $result ) {
					return array( 'error', $wpdb->last_error, $wpdb->last_query );
				}
			}

			$this->log_event_data( $countries, true );
			// we clear the cache.
			wp_cache_delete( 'rsssl_allowlist_countries' );

			return array( 'success', $data, $wpdb->last_query );
		}

		/**
		 * Fetches the timezone of the WordPress installation.
		 * If no timezone is set, the timezone of the server is used.
		 *
		 * @return DateTimeZone The timezone of the WordPress installation.
		 * @throws Exception If the timezone is invalid.
		 */
		public function get_wordpress_timezone() {
			$timezone_string = get_option( 'timezone_string' );

			if ( ! empty( $timezone_string ) ) {
				return new DateTimeZone( $timezone_string );
			}

			$offset  = get_option( 'gmt_offset' );
			$hours   = (int) $offset;
			$minutes = ( $offset - $hours ) * 60;

			$offset_string = sprintf( '%+03d:%02d', $hours, $minutes );

			return new DateTimeZone( $offset_string );
		}

		/**
		 * Converts a datetime string to the timezone of the WordPress installation.
		 *
		 * @param string $datetime The datetime string to convert.
		 * @param string $timezone The timezone to convert to.
		 *
		 * @return string The converted datetime string.
		 * @throws Exception If the timezone is invalid.
		 */
		private function convert_timezone( string $datetime, string $timezone ): string {
			$date = new \DateTime( $datetime, new \DateTimeZone( 'UTC' ) );
			$date->setTimezone( new \DateTimeZone( $timezone ) );

			return $date->format( 'H:i, M j' );
		}
	}


	if ( ! function_exists( 'REALLY_SIMPLE_SSL\Security\WordPress\rsssl_ip_list_api' ) && rsssl_get_option( 'enable_limited_login_attempts' ) ) {
		/**
		 * This function is used to handle the api calls for the ip list.
		 *
		 * @param array  $response The response array.
		 * @param string $action The action to perform.
		 * @param array  $data The data to use.
		 *
		 * @return array|null
		 * @throws Exception When an error occurs while updating the data.
		 */
		function rsssl_ip_list_api( array $response, string $action, $data ): ?array {
			// if the option is not enabled, we return the response.
			if ( ! rsssl_admin_logged_in() ) {
				return $response;
			}
			switch ( $action ) {
				case 'ip_list':
					// creating a random string based on time.
					$response = ( new Rsssl_Limit_Login_Attempts() )->get_list( $data );
					break;
				case 'user_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->get_user_list( $data );
					break;
				case 'ip_update_row':
					$response = ( new Rsssl_Limit_Login_Attempts() )->update_row( $data );
					break;
				case 'user_update_multi_row':
				case 'update_multi_row':
				case 'ip_update_multi_row':
					$response = ( new Rsssl_Limit_Login_Attempts() )->update_multi_rows( $data );
					break;
				case 'ip_add_ip_address':
					$response = ( new Rsssl_Limit_Login_Attempts() )->add_to_ip_list( $data );
					break;
				case 'user_add_user':
					$response = ( new Rsssl_Limit_Login_Attempts() )->add_user_to_list( $data );
					break;
				case 'user_update_row':
					$response = ( new Rsssl_Limit_Login_Attempts() )->update_row( $data, 'username' );
					break;
				case 'get_mask_from_range':
					$cidr     = Rsssl_Limit_Login_Attempts::calculate_cidr_from_range(
						$data['lowest'],
						$data['highest']
					);
					$response = array( 'cidr' => $cidr );
					break;
				case 'add_country_to_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->add_country_to_list( $data );
					break;
				case 'add_countries_to_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->add_countries_to_list( $data );
					break;
				case 'remove_countries_from_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->remove_countries_from_list( $data );
					break;
				case 'remove_country_from_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->remove_country_from_list( $data );
					break;
				case 'add_region_to_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->add_region_to_list( $data );
					break;
				case 'remove_region_from_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->remove_region_from_list( $data );
					break;
				case 'remove_regions_from_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->remove_regions_from_list( $data );
					break;
				case 'add_regions_to_list':
					$response = ( new Rsssl_Limit_Login_Attempts() )->add_regions_to_list( $data );
					break;
				case 'delete_entry':
					$response = ( new Rsssl_Limit_Login_Attempts() )->delete_entry( $data );
					break;
				case 'delete_multi_entries':
					$response = ( new Rsssl_Limit_Login_Attempts() )->delete_multi_entries( $data );
					break;
				case 'country_list':
					// creating a random string based on time.
					$response = ( new Rsssl_Limit_Login_Attempts() )->get_country_list( $data );
					break;
				default:
					break;
			}

			return $response;
		}

		// Add the rsssl_ip_list_api function as a filter callback.
		add_filter( 'rsssl_do_action', 'REALLY_SIMPLE_SSL\Security\WordPress\rsssl_ip_list_api', 10, 3 );
	}
}
