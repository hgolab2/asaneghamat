<?php
class Rsssl_Two_Factor_Settings {
	/**
	 * @var Rsssl_Two_Factor_Settings
	 */
	private static $_this;

	public function __construct() {
		if ( isset( self::$_this ) ) {
			wp_die();
		}

		self::$_this = $this;
		add_filter( 'rsssl_do_action', array( $this, 'two_fa_table' ), 10, 3 );
		add_filter( 'rsssl_after_save_field', array( $this, 'maybe_reset_user_role' ), 20, 4 );
		add_filter( 'rsssl_after_save_field', array( $this, 'maybe_disable_two_fa_enabled' ), 21, 4 );
		add_action( 'set_user_role', array( $this, 'on_user_role_change' ), 10, 3 );
		add_action( 'user_register', array( $this, 'add_meta_to_new_user' ) );
		add_action( 'updated_user_meta', array( $this, 'update_user_meta' ), 10, 4 );

		$this->set_initial_user_meta();

	}

	/**
	 * @return void
	 *
	 * Set initial 2FA usermeta on activation
	 */
	public function set_initial_user_meta() {

		if ( !is_admin() || !rsssl_user_can_manage() ) {
			return;
		}

		if ( ! get_option('rsssl_initial_usermeta_set' ) ) {

			rsssl_update_option('two_fa_optional_roles', [ 'editor', 'author', 'contributor', 'administrator' ] );
			rsssl_update_option('two_fa_forced_roles', [] );

			$users = get_users();

			foreach ( $users as $user ) {
				self::update_two_fa_method_based_on_role( $user->ID, $user->roles );
			}
		}

		update_option('rsssl_initial_usermeta_set', true);
	}

	public static function update_user_meta( $meta_id, $object_id, $meta_key, $_meta_value ){
		if ( 'rsssl_two_fa_status' === $meta_key && 'disabled' !== $_meta_value ){
			delete_user_meta($object_id, 'rsssl_two_fa_disabled_by_user');
		}
	}
	/**
	 * @param $user_id
	 *
	 * @return void
	 *
	 * Set 2FA method for new users
	 */

	public static function add_meta_to_new_user( $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		self::update_two_fa_method_based_on_role( $user_id, $user->roles );
	}

	/**
	 * @param $user_id
	 * @param $role
	 * @param $old_roles
	 *
	 * @return void
	 *
	 * Set 2FA method on user role change
	 */
	public static function on_user_role_change( $user_id, $role, $old_roles ) {
		//get user roles
		$user = get_userdata( $user_id );
		$roles = [$role];
		if ( $user ) {
			$roles =array_unique(array_merge($user->roles, $roles));
		}
		self::update_two_fa_method_based_on_role( $user_id,$roles );
	}

	/**
	 * @param string $field_id
	 * @param $new_value
	 * @param $prev_value
	 * @param $type
	 *
	 * @return void
	 *
	 * Disable Two Factor when Enable Login Protection is disabled
	 */
	public static function maybe_disable_two_fa_enabled( string $field_id, $new_value, $prev_value, $type ): void {

		if ( ! rsssl_user_can_manage() ) {
			return;
		}

		if ( 'login_protection_enabled' === $field_id && $new_value === 0 ) {
			rsssl_update_option( 'two_fa_enabled', false );
		}

	}
	
	/**
	 * Check if roles array contains optional roles
	 * @param $roles
	 *
	 * @return bool
	 */
	protected static function has_optional_role($roles): bool {
		$two_fa_optional_roles = rsssl_get_option( 'two_fa_optional_roles' );

		if ( ! is_array( $two_fa_optional_roles ) ) {
			$two_fa_optional_roles = [];
		}
		foreach ( $roles as $role ) {
			if ( in_array( $role, $two_fa_optional_roles, true ) ) {
				return true;
			}
		}
		return false;
	}
	/**
	 * Check if roles array contains forced roles
	 * @param $roles
	 *
	 * @return bool
	 */
	protected static function has_forced_role($roles): bool {

		$two_fa_forced_roles = rsssl_get_option( 'two_fa_forced_roles' );

		if (!is_array($two_fa_forced_roles)) {
			$two_fa_forced_roles = [];
		}

		foreach ( $roles as $role ) {
			if ( in_array( $role, $two_fa_forced_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $user_id
	 * @param $roles
	 *
	 * @return void
	 *
	 * Update 2FA method based on role
	 */
	protected static function update_two_fa_method_based_on_role( $user_id, $roles ): void {
		// first, check if user has a forced role. If so, set active and exit.
		if ( self::has_forced_role( $roles ) ) {
			update_user_meta( $user_id, 'rsssl_two_fa_status', 'active' );
			return;
		}

		// then, check if user has an optional role. If so, set open and exit.
		if ( self::has_optional_role( $roles ) ) {
			update_user_meta( $user_id, 'rsssl_two_fa_status', 'open' );
			return;
		}

		// finally, remove the meta if there are no optional and no forced roles.
		delete_user_meta( $user_id, 'rsssl_two_fa_status' );
	}

	/**
	 * If a user role is removed, we need to reset this role for all users
	 *
	 * @param string $field_id
	 * @param $new_value
	 * @param $prev_value
	 * @param $type
	 *
	 * @return void
	 */
	public static function maybe_reset_user_role( string $field_id, $new_value, $prev_value, $type ) {


		if ( ! rsssl_user_can_manage() ) {
			return;
		}

		if ( 'login_protection_enabled' === $field_id && ! $new_value ) {
			rsssl_update_option( 'two_fa_enabled', false );
		}

		if ( 'two_fa_forced_roles' !== $field_id && 'two_fa_optional_roles' !== $field_id && 'two_fa_enabled' !== $field_id ) {
			return;
		}

		if ( $new_value === $prev_value ) {
			return;
		}

		//initialize all in case just enabled.
		if ('two_fa_enabled' === $field_id) {
			self::update_roles_status( 'two_fa_optional_roles', rsssl_get_option('two_fa_optional_roles'), true );
			self::update_roles_status( 'two_fa_forced_roles', rsssl_get_option('two_fa_forced_roles'), true );
		} else {
			self::update_roles_status( $field_id, $new_value );
		}

	}

	/**
	 * Update the status for all users in a role, based on the role type, optional or forced.
	 *
	 * @param string $type
	 * @param array $update_roles
	 * @param bool $initialize
	 *
	 * @return void
	 */
	public static function update_roles_status( $type, $update_roles, bool $initialize = false ): void {
		$other_roles = ( 'two_fa_forced_roles' === $type ) ? rsssl_get_option( 'two_fa_optional_roles' ) : rsssl_get_option( 'two_fa_forced_roles' );
		//for all roles not in the list, remove them from the database.
		$roles = rsssl_get_roles();
		if ( !is_array($update_roles) ) {
			$update_roles = [];
		}

		if ( !is_array($other_roles) ) {
			$other_roles = [];
		}

		foreach ( $roles as $role ) {
			if ( !$initialize ) {
				if ( ! in_array( $role, $update_roles, true ) && ! in_array( $role, $other_roles, true ) ) {
					self::set_tfa_status_for_users( $role, 'disabled' );
				}
			}

			if ( 'two_fa_forced_roles' === $type && in_array( $role, $update_roles, true ) ) {
				//if just added, and roles are forced, set to active.
				self::set_tfa_status_for_users( $role, 'active' );
			}
			if ( 'two_fa_optional_roles' === $type && in_array( $role, $update_roles, true ) && ! in_array( $role, $other_roles, true ) ) {
				//if just added, and roles are forced, set to active.
				self::set_tfa_status_for_users( $role, 'open' );
			}
		}
	}

	/**
	 * @param $status
	 *
	 * @return string
	 */
	public static function sanitize_status( $status ): string {
		return in_array( $status, [ 'open', 'active', 'disabled' ], true ) ? $status : 'open';
	}

	/**
	 * @param string $role
	 * @param string $status
	 *
	 * @return void
	 */
	public static function set_tfa_status_for_users( string $role, string $status ): void {
		$status = self::sanitize_status( $status );
		if ( ! wp_roles()->is_role( $role ) ) {
			return;
		}
		$args = 			array(
			'role'   => $role,
			'fields' => array( 'ID' ), // Only get necessary fields
		);

		if ($status !== 'disabled' ) {
			$args['meta_key'] = 'rsssl_two_fa_disabled_by_user';
			$args['meta_compare'] = 'NOT EXISTS';
		}

		$users = get_users( $args );
		foreach ( $users as $user ) {
			if ( is_object($user)) {
				$user = $user->ID;
			}
			update_user_meta( $user, 'rsssl_two_fa_status', $status );
		}
	}

	/**
	 * @return array
	 *
	 * Return data for the Two FA table
	 */
	public function two_fa_table( array $response, string $action, $data ): array {

		if ( ! rsssl_user_can_manage() ) {
			return $response;
		}

		if ( 'two_fa_table' === $action ) {

			$page          = isset( $data['currentPage'] ) ? (int) $data['currentPage'] : 1;
			$page_size     = isset( $data['currentRowsPerPage'] ) ? (int) $data['currentRowsPerPage'] : 5;
			$search_term   = isset( $data['search'] ) ? sanitize_text_field( $data['search'] ) : '';
			$filter_value  = isset( $data['filterValue'] ) ? sanitize_text_field( $data['filterValue'] ) : 'active';
			$filter_column = isset( $data['filterColumn'] ) ? sanitize_text_field( $data['filterColumn'] ) : 'rsssl_two_fa_status';
			$sort_column   = isset( $data['sortColumn'] ) ? sanitize_text_field( $data['sortColumn'] ) : 'user';
			$sort_direction = isset( $data['sortDirection'] ) ? sanitize_text_field( $data['sortDirection'] ) : 'DESC';

			$args = array(
				'fields'  => array( 'ID', 'display_name' ), // Only get necessary fields
			);

			//if $filter_column== rsssl_two_fa_status, change query
			if ( 'rsssl_two_fa_status' === $filter_column ) {
				$args['meta_key']     = 'rsssl_two_fa_status';
				$args['meta_compare'] = '=';
				$args['meta_value']   = self::sanitize_status( $filter_value );
			}

			$args['orderby'] = $sort_column === 'user' ? 'display_name' : $sort_column;
			$args['order'] = $sort_direction;

			if ( '' !== $search_term ) {
				$args['search'] = '*' . $search_term . '*';
			}

			$total_data = get_users( $args );

			//now limit to one page only
			$args['number'] = $page_size;
			$args['offset'] = $page - 1;

			$users = get_users( $args );

			$formatted_data = array();
			foreach ( $users as $user ) {
				// Fetch user meta data
				$two_fa_status = get_user_meta( $user->ID, 'rsssl_two_fa_status', true );

				// Set rsssl_two_fa_status, default to 'open'
				$two_fa_status = $two_fa_status ?: 'open'; //phpcs:ignore

				// Create a WP_User instance to access roles
				$wp_user   = new WP_User( $user->ID );
				$user_role = ! empty( $wp_user->roles ) ? $wp_user->roles[0] : __( 'No role', 'really-simple-ssl' );

				// Format user data
				$formatted_data[] = array(
					'id'                  => $user->ID,
					'user'                => ucfirst( $user->display_name ),
					'rsssl_two_fa_status' => $two_fa_status,
					'user_role'           => ucfirst( $user_role ),
					'status_for_user'     => ucfirst( $two_fa_status ),
				);
			}

			$formatted_data = array_values( $formatted_data );
			$data = [
				'request_success' => true,
				'data'            => $formatted_data,
				'totalRecords'    => count( $total_data ),
			];

			return $data;
		}
		return $response;
	}


	public static function rsssl_one_time_login_url( $user_id, $disable_two_fa=false ) {

		$token = bin2hex ( openssl_random_pseudo_bytes(16) ) ; // 16 bytes * 8 bits/byte = 128 bits
		set_transient('skip_two_fa_token_' . $user_id, $token, 2 * MINUTE_IN_SECONDS );

		$obfuscated_user_id = self::obfuscate_user_id( $user_id );

		$nonce = wp_create_nonce( 'one_time_login_' . $user_id );

		$args = [
			'rsssl_one_time_login' => $obfuscated_user_id,
			'token' => $token,
			'_wpnonce' => $nonce,
		];

		if ( $disable_two_fa ) {
			$args['rsssl_two_fa_disable'] = true;
		}

		// Return the URL with the added query arguments
		return add_query_arg( $args, admin_url() );

	}

	/**
	 * @param $user_id
	 *
	 * @return string
	 *
	 * Obfuscate the user ID for use in URL
	 */
	public static function obfuscate_user_id( $user_id ): string {
		// Convert the user ID to a string with some noise
		$obfuscated = 'user-' . $user_id . '-id';
		// Encode the string using base64
		return base64_encode( $obfuscated );
	}

	/**
	 * @param $data
	 *
	 * @return string|null
	 *
	 * Deobfuscate the user ID for use in URL
	 */
	public static function deobfuscate_user_id( $data ): ?string {
		// Decode from base64
		$decoded = base64_decode( $data );
		// Remove the noise to get the user ID
		if ( preg_match('/user-(\d+)-id/', $decoded, $matches ) ) {
			return $matches[1];
		}

		return null;
	}
}

new Rsssl_Two_Factor_Settings();