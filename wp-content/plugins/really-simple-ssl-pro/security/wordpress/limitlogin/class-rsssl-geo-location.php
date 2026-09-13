<?php
/**
 * Marcel Santing, Really Simple Plugins
 *
 * This PHP file contains the implementation of the [Class Name] class.
 *
 * @author Marcel Santing
 * @company Really Simple Plugins
 * @email marcel@really-simple-plugins.com
 * @package RSSSLPRO\Security\wordpress\limitlogin
 */

namespace RSSSL_PRO\Security\WordPress\Limitlogin;

if ( !defined('GEOIP_DETECT_VERSION') ) require rsssl_pro_path . '/assets/vendor/autoload.php';

use GeoIp2\Database\Reader;
use Exception;
use PharData;
use FilesystemIterator;
use RSSSL_PRO\Security\DynamicTables\Rsssl_Data_Table;
use RSSSL_PRO\Security\DynamicTables\Rsssl_Query_Builder;


/**
 * Rsssl_Geo_Location Class
 *
 * This class provides functionalities related to geolocation.
 * It utilizes the geoip2/geoip2 library for GeoIP functionalities.
 *
 * # geoip2/geoip2 library is provided by MaxMind.
 * # License: Apache-2.0
 * # Details & Documentation: [https://github.com/maxmind/GeoIP2-php](https://github.com/maxmind/GeoIP2-php)
 * # Author: Gregory J. Oschwald
 * Email: goschwald@maxmind.com
 * Homepage: [https://www.maxmind.com/](https://www.maxmind.com/)
 *
 * @since 7.0.1
 * @package RSSSLPRO\Security\wordpress\LimitLogin
 */
class Rsssl_Geo_Location {

	/**
	 * The name of the country table.
	 */
	const COUNTRY_TABLE = 'rsssl_country';

	/**
	 * The GeoIP database reader.
	 *
	 * @var Reader The GeoIP database reader.
	 */
	public $reader;

	/**
	 * The URL of the GeoIP database file.
	 *
	 * @var string The URL of the GeoIP database file.
	 */
	private $geo_ip_database_file_url = 'https://downloads.really-simple-security.com/maxmind/GeoLite2-Country.tar.gz';


	/**
	 * Rsssl_Geo_Location constructor.
	 */
	public function __construct() {
		$this->create_country_table();
		self::import_countries_from_csv();

		$this->init();
	}


	/**
	 * Initializes the Rsssl_Geo_Location class.
	 *
	 * @return void Initializes the Rsssl_Geo_Location class.
	 */
	public function init() {

		add_action( 'rsssl_geo_ip_database_file', array( $this, 'get_geo_ip_database_file' ) );
		add_action( 'rsssl_monthly_geo_ip_database_file', array( $this, 'validate_geo_ip_database' ) );

		if ( is_admin() && rsssl_user_can_manage() ) {
			// Schedule a single event to run in 1 hour if the database file does not exist.
			if ( ! $this->validate_geo_ip_database() && ! wp_next_scheduled( 'rsssl_geo_ip_database_file' ) ) {
				wp_schedule_single_event( time() + 3600, 'rsssl_geo_ip_database_file' );
			}

			// Schedule a monthly cron job to check if the database file is still valid.
			if ( ! wp_next_scheduled( 'rsssl_monthly_geo_ip_database_file' ) ) {
				wp_schedule_event( time(), 'monthly', 'rsssl_monthly_geo_ip_database_file' );
			}
		}

		$filename = get_option( 'rsssl_geo_ip_database_file' );
		if ( file_exists( $filename ) ) {
			try {
				$this->reader = new Reader( $filename );
			} catch ( Exception $e ) {
				$this->log_error( $e->getMessage() );
			}
		} else {
			$this->log_error( 'GeoIP database file does not exist.' );
		}
	}

	/**
	 * Create the country table if it doesn't exist.
	 *
	 * @since 7.0.1
	 */
	private function create_country_table() {
		//only load on front-end if it's a cron job
		if ( !is_admin() && !wp_doing_cron() ) {
			return;
		}

		if (!wp_doing_cron() && !rsssl_user_can_manage() ) {
			return;
		}

		if ( get_option( 'rsssl_country_db_version' ) !== rsssl_pro_version ) {
			// Create the table.
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table           = $wpdb->base_prefix . self::COUNTRY_TABLE;

			$sql = "CREATE TABLE $table (
			      id INT(11) NOT NULL AUTO_INCREMENT,
			      country_name TEXT NOT NULL,
			      iso2_code VARCHAR(2) NOT NULL,
			      iso3_code VARCHAR(3) NOT NULL,
			      region TEXT NOT NULL,
			      region_code VARCHAR(10),			    
			      PRIMARY KEY (id),
			      UNIQUE KEY iso2_code_key (iso2_code),
			      UNIQUE KEY iso3_code_key (iso3_code)
		        ) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_option( 'rsssl_country_db_version', rsssl_pro_version, false );
		}
	}

	/**
	 * Fetches the country code based on the provided IP address.
	 * Also, it checks if the Ip is in a Local IP range.
	 *
	 * @param  string  $ip  The IP address.
	 *
	 * @return string
	 */
	public static function get_county_by_ip( $ip ): string {
		// instancing the class.
		$rsssl_geo_location = new Rsssl_Geo_Location();

		// sanitizing the ip.
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );
		if ( null === $rsssl_geo_location->reader ) {
			//we download the database file. again.
			$rsssl_geo_location->get_geo_ip_database_file( true );

			return 'N/A';
		}

		try {
			$record = $rsssl_geo_location->reader->country( $ip ); // Using geoip2/geoip2 library for fetching country data.
			$code   = $record->country->isoCode;
		} catch ( Exception $e ) {
			$rsssl_geo_location->log_error( $e->getMessage() );
			$code = 'N/A';
		}
		return $code;
	}

	/**
	 * Creates a log entry in the error log.
	 *
	 * @param  string  $message  The message to log.
	 *
	 * @return void
	 */
	public static function log( string $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			error_log( $message );
		}
	}

	/**
	 * Checks if the provided IP address is in a local IP range.
	 *
	 * @param  string  $ip  The IP address.
	 *
	 * @return bool
	 */
	public function is_local_ip( string $ip ): bool {
		$ip              = filter_var( $ip, FILTER_VALIDATE_IP );
		$local_ip_ranges = $this->get_local_ip_ranges();

		foreach ( $local_ip_ranges as $local_ip_range ) {
			// first we check if the ip is has a cidr.
			if ( $this->is_ip_in_range( $ip, $local_ip_range ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * This list a range of ip's used by local development environments or are standard ip's used by the server.
	 *
	 * @return array
	 */
	private function get_local_ip_ranges(): array {
		return array(
			// IPv4 Addresses.
			'127.0.0.1/8',      // Localhost.
			'10.0.0.0/8',       // Class A Private IPs.
			'172.16.0.0/12',    // Class B Private IPs.
			'192.168.0.0/16',   // Class C Private IPs.
			'169.254.0.0/16',   // Link-local addresses.

			// IPv6 Addresses.
			'::1/128',          // Localhost for IPv6.
			'fe80::/10',        // Link-Local Addresses.
			'fc00::/7',         // Unique Local Addresses (including fd00::/8).
		);
	}


	/**
	 * Inserts a new country into the `rsssl_country` table.
	 *
	 * @param  string  $country_name  The name of the country.
	 * @param  string  $iso2_code  The ISO 2-letter code of the country.
	 * @param  string  $iso3_code  The ISO 3-letter code of the country.
	 * @param  string  $region  The region in which the country is located.
	 * @param  string  $region_code  The region code of the country.
	 *
	 * @return void
	 */
	public static function insert_country(
		string $country_name,
		string $iso2_code,
		string $iso3_code,
		string $region,
		string $region_code
	) {
		global $wpdb;
		$table_name = $wpdb->base_prefix . self::COUNTRY_TABLE;

		// Insert data into a custom table; no built-in function available.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->base_prefix . self::COUNTRY_TABLE,
			array(
				'country_name' => $wpdb->prepare( '%s', $country_name ),
				'iso2_code'    => $wpdb->prepare( '%s', $iso2_code ),
				'iso3_code'    => $wpdb->prepare( '%s', $iso3_code ),
				'region'       => $wpdb->prepare( '%s', $region ),
				'region_code'  => $wpdb->prepare( '%s', $region_code ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Checks if the GeoIP database file exists.
	 *
	 * @return bool
	 */
	public function validate_geo_ip_database(): bool {
		// We check if the database file exists we return true or false.
		if ( file_exists( get_option( 'rsssl_geo_ip_database_file' ) ) ) {
			return true;
		} else {
			// it does not exist so we try to download it.
			try {
				$this->get_geo_ip_database_file( true );

				return true;
			} catch ( Exception $e ) {
				$this->log_error( $e->getMessage() );

				return false;
			}
		}
	}

	/**
	 * Import countries from CSV file.
	 *
	 * This function imports countries from a CSV file into a database table. It first checks if the import version matches the current version of the plugin. If it does, it reads the CSV file and inserts the countries into the database table. It also logs any errors or success messages.
	 *
	 * @since 7.0.1
	 */
	public static function import_countries_from_csv(): void {
		//only load on front-end if it's a cron job
		if ( !is_admin() && !wp_doing_cron() ) {
			return;
		}

		if (!wp_doing_cron() && !rsssl_user_can_manage() ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->base_prefix . self::COUNTRY_TABLE;

		// first we check if the version of the plugin matches the version of the import.
		if ( get_option( 'rsssl_country_import_version' ) !== rsssl_pro_version ) {
			// now we check if the table exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$sql = $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name );

			if ( $wpdb->get_var( $sql ) !== $table_name ) {
				// The table does not exist, we try again later.
				return;
			}
			$csv_file_path = rsssl_pro_path . 'security/wordpress/limitlogin/countries.txt'; // Replace with the actual path to your CSV file.

			if ( file_exists( $csv_file_path ) ) {
				$csv_data = array_map( 'str_getcsv', file( $csv_file_path ) );

				if ( ! empty( $csv_data ) ) {
					// Remove the header row (Country Name, ISO2 Code, ISO3 Code, Region, Region Code).
					unset( $csv_data[0] );

					foreach ( $csv_data as $row ) {
						$country_name = trim( $row[0], "'" );
						$iso2_code    = trim( $row[1], "'" );
						$iso3_code    = trim( $row[2], "'" );
						$region       = trim( $row[3], "'" );
						$region_code  = trim( $row[4], "'" );

						// Perform the database insertion.
						try {
							// first we check if the country already exists.
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$country = $wpdb->get_row(
								$wpdb->prepare(
									"SELECT country_name FROM {$wpdb->base_prefix}rsssl_country WHERE iso2_code = %s",
									$iso2_code
								)
							);

							if ( empty( $country ) ) {
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery
								$wpdb->insert(
									$table_name,
									array(
										'country_name' => $country_name,
										'iso2_code'    => $iso2_code,
										'iso3_code'    => $iso3_code,
										'region'       => $region,
										'region_code'  => $region_code,
									),
									array( '%s', '%s', '%s', '%s', '%s' )
								);
							}
						} catch ( Exception $e ) {
							// Ignore duplicate entries or handle the error appropriately.
							die( esc_attr( $e->getMessage() ) );
						}
					}

					self::log( 'Countries imported successfully.' );
				} else {
					self::log( 'CSV file is empty.' );
				}
			} else {
				self::log( 'CSV file does not exist.' );
			}

			update_option( 'rsssl_country_import_version', rsssl_pro_version, false );
		}
	}

	/**
	 * Checks if the provided IP address is in the provided IP range.
	 *
	 * @param  string  $code  The Country iso2 code.
	 *
	 * @return string
	 */
	public static function get_country_by_iso2( string $code ): string {
		global $wpdb;
		$table_name = $wpdb->base_prefix . self::COUNTRY_TABLE;

		if ( 'N/A' === $code ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$country = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT country_name FROM {$wpdb->base_prefix}rsssl_country WHERE iso2_code = %s",
				$code
			)
		);

		// if the country is empty we check for region.
		if ( empty( $country ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$country = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT region, country_name FROM {$wpdb->base_prefix}rsssl_country WHERE region_code = %s",
					$code
				)
			);
			return $country->region ?? '';
		}

		return $country->country_name ?? '';
	}

	/**
	 * Get the Geo IP database file.
	 *
	 * @param  bool  $renew  Whether to renew the database file. Default true.
	 */
	public function get_geo_ip_database_file( bool $renew = true ) {
		if ( ! $renew ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload_dir       = $this->set_upload_dir( 'geo_ip' );
		$name             = 'GeoLite2-Country.tar.gz';
		$zip_file_name    = $upload_dir . $name;
		$tar_file_name    = str_replace( '.gz', '', $zip_file_name );
		$result_file_name = str_replace( '.tar.gz', '.mmdb', $name );
		$unzipped         = $upload_dir . $result_file_name;
		$dumpfile         = download_url( $this->geo_ip_database_file_url, $timeout = 250 );

		if ( is_wp_error( $dumpfile ) ) {
			$error_message = $dumpfile->get_error_message();
			$this->log_error( __( 'Error downloading file: ', 'really-simple-ssl' ) . $error_message );

			return;
		}

		update_option( 'rsssl_geo_ip_database_file', $unzipped );

		$this->remove_file( $unzipped );

		if ( ! file_exists( $zip_file_name ) ) {
			copy( $dumpfile, $zip_file_name );
		}

		$this->extract_tar_gz_file( $zip_file_name, $tar_file_name, $upload_dir );

		$this->copy_unzipped_file( $upload_dir, $result_file_name );

		$this->remove_file( $zip_file_name );
		$this->remove_file( $tar_file_name );
		$this->remove_file( $dumpfile );
	}

	/**
	 * Remove a file.
	 *
	 * @param  string  $file  The file to remove.
	 */
	private function remove_file( $file ) {
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Extract a tar.gz file.
	 *
	 * @param  string  $zip_file_name  The tar.gz file to extract.
	 * @param  string  $tar_file_name  The tar file name.
	 * @param  string  $upload_dir  The upload directory.
	 */
	private function extract_tar_gz_file( string $zip_file_name, string $tar_file_name, string $upload_dir ) {

		if (!class_exists('PharData') || !extension_loaded('phar')) {
			return;
		}
		try {
			$phar = new PharData( $zip_file_name );
			$this->remove_file( $tar_file_name );
			$phar->decompress();

			$phar = new PharData( $tar_file_name );
			$phar->extractTo( $upload_dir, null, true );
		} catch ( Exception $e ) {
			$this->log_error( __( 'Error extracting file', 'really-simple-ssl' ) );
		}
	}

	/**
	 * Copy the unzipped file.
	 *
	 * @param  string  $upload_dir  The upload directory.
	 * @param  string  $result_file_name  The result file name.
	 */
	private function copy_unzipped_file( $upload_dir, $result_file_name ) {
		foreach ( glob( $upload_dir . '*' ) as $file ) {
			if ( is_dir( $file ) ) {
				copy( trailingslashit( $file ) . $result_file_name, $upload_dir . $result_file_name );
				$this->remove_files_in_directory( $upload_dir, '*.txt' );
				$this->remove_directory_recursively( $file );
			}
		}
	}

	/**
	 * Remove files in a directory.
	 *
	 * @param  string  $directory  The directory.
	 * @param  string  $pattern  The pattern to match files.
	 */
	private function remove_files_in_directory( $directory, $pattern ) {
		foreach ( glob( $directory . $pattern ) as $file ) {
			$this->remove_file( $file );
		}
	}

	/**
	 * Recursively remove a directory and all of its contents.
	 *
	 * @param  string  $directory  The directory to remove.
	 */
	private function remove_directory_recursively( $directory ) {
		if ( ! file_exists( $directory ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $fileinfo ) {
			if ( $fileinfo->isDir() ) {
				// Use WordPress function to remove directory recursively.
				rmdir( $fileinfo->getRealPath() );
			} else {
				// Use WordPress function to delete files.
				wp_delete_file( $fileinfo->getRealPath() );
			}
		}
		// Use WordPress function to remove the top-level directory.
		rmdir( $directory );
	}

	/**
	 * Sets an upload path for the GeoIP database file.
	 *
	 * @param  string  $path  The path to set.
	 *
	 * @return string
	 */
	public function set_upload_dir( string $path ): string {
		$wp_upload_dir = wp_upload_dir();
		$upload_dir    = $wp_upload_dir['basedir'] . '/really-simple-ssl/';
		$upload_dir    = $upload_dir . $path;
		if ( ! file_exists( $upload_dir ) ) {
			mkdir( $upload_dir, 0777, true );
		}

		return trailingslashit( $upload_dir );
	}

	/**
	 * Retrieves countries from the database.
	 *
	 * This function handles the retrieval of countries based on the provided data.
	 * It creates a data table instance with the specified query builder and returns the results
	 * after validating and applying search, sorting, filtering, and pagination.
	 *
	 * @param  array|null  $data  Optional. An associative array containing the parameters for filtering, sorting, etc.
	 *                        - search: The search query.
	 *                        - sort: The sorting criteria.
	 *                        - filter: The filtering conditions.
	 *                        - pagination: The pagination parameters.
	 *
	 * @return array           The resulting event logs and additional information such as post data.
	 * @throws Exception      If any error occurs during the operation.
	 */
	public function get_countries( array $data = null ): array {
		require_once rsssl_pro_path . 'security/dynamic-tables/class-rsssl-data-table.php';
		require_once rsssl_pro_path . 'security/dynamic-tables/class-rsssl-query-builder.php';
		require_once rsssl_pro_path . 'security/dynamic-tables/class-rsssl-array-query-builder.php';
		try {
			if ( isset( $data['searchColumns'] ) ) {
				// we check if the search columns has a 'attempt_value' key if so we rename it to iso2_code.
				// we loop through the search columns and rename the attempt_value to iso2_code.
				foreach ( $data['searchColumns'] as $key => $value ) {
					if ( 'attempt_value' === $value ) {
						$data['searchColumns'][ $key ] = 'iso2_code';
					}
				}
			}
			global $wpdb;
			// manual ad a filter value to the $data.
			$data_table = new Rsssl_Data_Table( $data,
				new Rsssl_Query_Builder( $wpdb->base_prefix . 'rsssl_country' ) );
			$data_table->set_select_columns(
				array(
					'id',
					'country_name',
					'iso2_code',
					'region',
					"raw: 'country' as attempt_type",
					'raw: iso2_code as attempt_value',
					'iso3_code',
					"raw: '" . __( 'Trusted', 'really-simple-ssl' ) . "' as status",
				)
			);

			// We now get all the country codes that are in the Limit Login Attempts table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$registered_countries = $wpdb->get_results(
				"SELECT 
                            attempt_value,
                            status 
                        FROM {$wpdb->base_prefix}rsssl_login_attempts 
                               WHERE attempt_type = 'country'"
			);

			$result = $data_table
				->validate_search()
				->validate_sorting()
				->validate_pagination()
				->get_results();

			// we loop through the results and check if the country is in the registered countries.
			foreach ( $result['data'] as $key => $value ) {
				foreach ( $registered_countries as $registered_country ) {
					if ( $value->iso2_code === $registered_country->attempt_value ) {
						$result['data'][ $key ]->status = $registered_country->status;
					}
				}
			}

			$result['post'] = $data;

			return $result;
		} catch ( \Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Retrieves regions from the database.
	 *
	 * This function handles the retrieval of countries based on the provided data.
	 * It creates a data table instance with the specified query builder and returns the results
	 * after validating and applying search, sorting, filtering, and pagination.
	 *
	 * @param  array|null  $data  Optional. An associative array containing the parameters for filtering, sorting, etc.
	 *                        - search: The search query.
	 *                        - sort: The sorting criteria.
	 *                        - filter: The filtering conditions.
	 *                        - pagination: The pagination parameters.
	 *
	 * @return array           The resulting event logs and additional information such as post data.
	 * @throws Exception      If any error occurs during the operation.
	 */
	public function get_regions( array $data = null ): array {
		require_once rsssl_pro_path . 'security/dynamic-tables/class-rsssl-data-table.php';
		require_once rsssl_pro_path . 'security/dynamic-tables/class-rsssl-query-builder.php';
		require_once rsssl_pro_path . 'security/dynamic-tables/class-rsssl-array-query-builder.php';
		try {
			// Since we are manipulating columns we need to rename the search column.
			if ( isset( $data['searchColumns'] ) ) {
				// we check if the search columns has a 'attempt_value' key if so we rename it to iso2_code.
				// we loop through the search columns and rename the attempt_value to iso2_code.
				foreach ( $data['searchColumns'] as $key => $value ) {
					if ( 'attempt_value' === $value ) {
						$data['searchColumns'][ $key ] = 'region_code';
					}
				}
			}

			global $wpdb;

			// manual ad a filter value to the $data.
			$data_table = new Rsssl_Data_Table( $data,
				new Rsssl_Query_Builder( $wpdb->base_prefix . 'rsssl_country' ) );
			$data_table->set_select_columns(
				array(
					'id',
					'raw: MAX(id) as max_id',
					'raw: ' . __( "'All'", 'really-simple-ssl' ) . ' as country_name',
					'raw: MAX(region) as region',
					"raw: 'region' as attempt_type",
					'raw: region_code as attempt_value',
					'iso2_code',
					"raw: 'default' as status",
					'raw: count(*) as count',
				)
			);

			// Since we are manipulating columns we need to rename the search column.

			$data_table->group_by(
				array(
					'region_code',
					'region',
					'status',
					'attempt_type',
					'attempt_value',
				)
			);

			$result         = $data_table
				->validate_search()
				->validate_sorting()
				->validate_pagination()
				->get_results();
			$result['post'] = $data;

			return $result;
		} catch ( \Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * This log is specifically for the geo-location logs or any other lla logs.
	 *
	 * @param  string  $message  The message to log.
	 *
	 * @return void
	 */
	private function log_error( $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( defined('WP_DEBUG') && WP_DEBUG ) {
			error_log( $message );
		}
	}

	/**
	 * Checks if the provided IP address is in a local IP range.
	 *
	 * @param  string  $ip  The IP address.
	 * @param  string  $local_ip_range  The local IP range.
	 *
	 * @return bool
	 */
	private function is_ip_in_range( string $ip, string $local_ip_range ): bool {
		$ip             = filter_var( $ip, FILTER_VALIDATE_IP );
		$ip             = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$local_ip_range = explode( '/', $local_ip_range );

		$local_ip = $local_ip_range[0];
		$mask     = $local_ip_range[1];
		$mask     = pow( 2, 32 ) - pow( 2, ( 32 - $mask ) );
		$local_ip = ip2long( $local_ip );
		$ip       = ip2long( $ip );
		$local_ip = $local_ip & $mask;
		$ip       = $ip & $mask;

		return $local_ip === $ip;
	}
}
