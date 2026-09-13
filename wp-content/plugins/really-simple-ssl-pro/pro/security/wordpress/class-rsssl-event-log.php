<?php
/**
 * File: rsssl-event-log.php
 *
 * This file contains the definition of the rsssl_event_log class, which is responsible for event logging.
 *
 * @package REALLY_SIMPLE_SSL\Security\WordPress
 * @author Marcel Santing
 */

namespace REALLY_SIMPLE_SSL\Security\WordPress;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}
require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-data-table.php';
require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-query-builder.php';
require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-array-query-builder.php';

use DateTime;
use DateTimeZone;
use Exception;
use REALLY_SIMPLE_SSL\Security\WordPress\Limitlogin\Rsssl_Geo_Location;
use REALLY_SIMPLE_SSL\Security\DynamicTables\Rsssl_Data_Table;
use REALLY_SIMPLE_SSL\Security\DynamicTables\Rsssl_Query_Builder;
use RuntimeException;
use stdClass;
use wpdb;

if ( ! class_exists( 'Rsssl_Event_Log' ) ) {

	/**
	 * Class rsssl_event_log
	 * This class is used to log events to the database.
	 * and adds the appropriate actions to the bespoke events. Like limit login attempts.
	 *
	 * @package REALLY_SIMPLE_SSL\Security\WordPress
	 * @author Marcel Santing
	 */
	class Rsssl_Event_Log {
		public const TABLE = 'rsssl_event_logs';

		/**
		 * Rsssl_Event_Log constructor.
		 */
		public function __construct() {
			require_once rsssl_path . 'pro/security/wordpress/limitlogin/class-rsssl-geo-location.php';
			// we only run this once if the plugin was activated.
			add_action( 'plugins_loaded', array($this, 'create_event_log_table'), 20 );
			new Rsssl_Geo_Location();
		}

		/**
		 * Create the event log table.
		 *
		 * @return void
		 */
		public static function create_event_log_table(): void {
			// Only load on front-end if it's a cron job.
			if ( ! is_admin() && ! wp_doing_cron() ) {
				return;
			}

			if ( ! wp_doing_cron() && ! rsssl_user_can_manage() ) {
				return;
			}

			if ( get_option( 'rsssl_event_log_db_version' ) !== rsssl_version ) {
				// Create the table.
				global $wpdb;
				$charset_collate = $wpdb->get_charset_collate();
				$table           = $wpdb->base_prefix . self::TABLE;

				$sql = "CREATE TABLE $table (
		            id mediumint(9) NOT NULL AUTO_INCREMENT,
		            timestamp bigint NOT NULL,
		            event_id int(5) NOT NULL,
		            event_type TEXT NOT NULL,
		            event_name TEXT NOT NULL,
		            event_data TEXT NULL,
		            severity TEXT NOT NULL,
		            username TEXT NULL,
		            source_ip TEXT NULL,
		            description TEXT NULL,
		            PRIMARY KEY  (id)
		        ) $charset_collate;";

				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $sql );
				update_option( 'rsssl_event_log_db_version', rsssl_version, false );
			}
		}

		/**
		 * Log an event into the database.
		 *
		 * @param  array $data  An associative array containing the details of the event. Expected keys:
		 *                           - username: The username associated with the event.
		 *                           - timestamp: The event timestamp.
		 *                           - event_id: The ID of the event.
		 *                           - event_type: The type of event.
		 *                           - event_data: Additional data related to the event.
		 *                           - severity: The severity level of the event.
		 *                           - source_ip: The IP address from which the event originated.
		 *                           - description: A brief description of the event.
		 *
		 * @return void
		 * @throws Exception If the insertion fails.
		 */
		public static function log_event( array $data ): void {
			global $wpdb;
			// if the event id = 1010 or 1020 we add a small-time increase to the timestamp.
			if ( '1010' === $data['event_id'] || '1020' === $data['event_id'] ) {
				++ $data['timestamp'];
			}
			// Sanitize and validate data.
			$data['username']    = sanitize_text_field( $data['username'] );
			$data['timestamp']   = (int) $data['timestamp'];
			$data['event_id']    = (int) $data['event_id'];
			$data['event_type']  = sanitize_text_field( $data['event_type'] );
			$data['severity']    = sanitize_text_field( $data['severity'] );
			$data['source_ip']   = isset( $data['source_ip'] ) ? sanitize_text_field( $data['source_ip'] ) : null;
			$data['description'] = isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : null;

			// If no event exists or no timeframe provided, create a new event.
			// phpcs:ignore WordPress.DB
			$result = $wpdb->insert(
				$wpdb->base_prefix . self::TABLE,
				$data
			);

			if ( false === $result ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'Really Simple SSL: Failed to insert event into the database' );
				}
			}
		}

		/**
		 * Cleans up all events older than thirty days.
		 *
		 * @global wpdb $wpdb
		 * @return void
		 */
		public static function clean_up_event_log(): void {
			global $wpdb;

			$query = $wpdb->prepare(
				"DELETE FROM {$wpdb->base_prefix}rsssl_event_logs WHERE timestamp < (UNIX_TIMESTAMP() - %d)",
				30 * 24 * 60 * 60
			);

			// Execute the query.
			// phpcs:ignore WordPress.DB
			$wpdb->query( $query );
		}

		/**
		 * Retrieves event logs from the database.
		 *
		 * This function handles the retrieval of event logs based on the provided data.
		 * It creates a data table instance with the specified query builder and returns the results
		 * after validating and applying search, sorting, filtering, and pagination.
		 *
		 * @param  array|null $data  Optional. An associative array containing the parameters for filtering, sorting, etc.
		 *                        - search: The search query.
		 *                        - sort: The sorting criteria.
		 *                        - filter: The filtering conditions.
		 *                        - pagination: The pagination parameters.
		 *
		 * @return array           The resulting event logs and additional information such as post data.
		 * @throws Exception      If any error occurs during the operation.
		 */
		public function get_events( array $data = null ): array {
			global $wpdb;
			try {
				$timezone = $this->get_wordpress_timezone()->getName();

				// Manipulate the ['sortColumn']['column'] to sort by timestamp instead of datetime.
				if ( isset( $data['sortColumn']['column'] ) && 'datetime' === $data['sortColumn']['column'] ) {
					$data['sortColumn']['column'] = 'timestamp';
				}

				$data_table = new Rsssl_Data_Table( $data, new Rsssl_Query_Builder( $wpdb->base_prefix . self::TABLE ) );
				$data_table->set_select_columns(
					array(
						'id',
						'username',
						'description',
						'event_type',
						'source_ip',
						'timestamp',
						'event_name',
						'severity',
						"raw:JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.iso2_code')) as iso2_code",
						"raw:JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.country_name')) as country_name",
						"raw:DATE_FORMAT(FROM_UNIXTIME(timestamp), '%%H:%%i, %%M %%e') as datetime",
					)
				);

				$result = $data_table
					->validate_search()
					->validate_sorting(
						array(
							'column'    => 'timestamp',
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
		 * Retrieves event logs from the database.
		 *
		 * @throws Exception If an error occurs during the operation.
		 */
		public function get_wordpress_timezone(): DateTimeZone {
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
		 * Converts the datetime to the timezone of the user.
		 *
		 * @throws Exception If an error occurs during the operation.
		 *
		 * @param string $datetime The datetime to convert.
		 * @param string $timezone The timezone to convert to.
		 */
		private function convert_timezone( string $datetime, string $timezone ): string {
			$date = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );
			$date->setTimezone( new DateTimeZone( $timezone ) );
			return $date->format( 'H:i, M j' );
		}

		/**
		 * Retrieves event logs from the database.
		 *
		 * @param string $get_ip_address The IP address to search for.
		 * @param string $code           The event code to search for.
		 *
		 * @return array|object|stdClass[]|null
		 */
		public function get_event_log( string $get_ip_address, string $code ) {
			global $wpdb;
			$ip_address = $get_ip_address;
			$ip_address = filter_var( $ip_address, FILTER_VALIDATE_IP );
			$ip_address = sanitize_text_field( $ip_address );
			$ip_address = $wpdb->esc_like( $ip_address );

			// phpcs:ignore WordPress.DB
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->base_prefix}rsssl_event_logs WHERE source_ip = %s AND event_id = %s",
					$ip_address,
					$code
				)
			);
		}
	}

	new Rsssl_Event_Log();
}

if ( rsssl_get_option( 'enable_limited_login_attempts' ) ) {
	if ( ! function_exists( 'REALLY_SIMPLE_SSL\Security\WordPress\rsssl_event_log_api' ) ) {
		/**
		 * This function is used to log events to the database.
		 *
		 * @param array  $response The response to be returned.
		 * @param string $action   The action to be performed.
		 * @param array  $data     The data to be used for the event log.
		 *
		 * @return array
		 * @throws Exception If an error occurs during the operation.
		 */
		function rsssl_event_log_api( array $response, string $action, array $data ): array {
			if ( ! rsssl_admin_logged_in() ) {
				return $response;
			}
			if ( 'event_log' === $action ) {
				// creating a random string based on time.
				$response = ( new rsssl_event_log() )->get_events( $data );
			}
			return $response;
		}

		// Add the rsssl_event_log_api function as a filter callback.
		add_filter( 'rsssl_do_action', 'REALLY_SIMPLE_SSL\Security\wordpress\rsssl_event_log_api', 10, 3 );
	}
}

// Add the cleanup function to the hook.
add_action(
	'REALLY_SIMPLE_SSL_every_day_hook',
	array( 'REALLY_SIMPLE_SSL\Security\wordpress\rsssl_event_log', 'clean_up_event_log' )
);
