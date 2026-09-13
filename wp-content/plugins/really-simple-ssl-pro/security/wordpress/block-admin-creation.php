<?php defined( 'ABSPATH' ) or die();
class rsssl_block_admin_creation {
	function __construct($is_upgrade=false) {
		add_action( "rsssl_after_save_field", array($this, 'setup_admin_list'), 100, 4 );
		add_action( "init", array($this, 'check_logged_in_users_init') );
		add_action( 'xmlrpc_call', array($this, 'check_logged_in_users_xml') , 1000, 3);
		add_action( "profile_update", array($this, 'verify_user_update'), 10, 3 );
		add_action( 'user_register', array($this, 'verify_user_create'), 10, 2);
		add_action( 'delete_user', array($this, 'cleanup_on_user_delete'),10, 3 );
		add_action( 'shutdown', array($this, 'maybe_force_admin_registration'), 20 );

		add_filter( 'rsssl_fields', array($this,'register_confirm_field') );
	}

	public function register_confirm_field($fields){
		$fields = array_merge( $fields, [
				[
					'id'       => 'block_admin_creation_confirm',
					'menu_id'  => 'advanced_hardening',
					'group_id' => 'advanced_hardening',
					'type'     => 'hidden',
					'label'    => '',
					'disabled' => false,
					'default'  => false,
				]
			]
		);
		return $fields;
	}

	/**
	 * When a new user is added in the wordpress profile page, add it to our own admin user list
	 */

	public function verify_user_create($user_id, $userdata): void {
		if ( !current_user_can('create_users') ){
			return;
		}

		//check referrer
		if ( !$this->is_create_user_request() ) {
			return;
		}

		if (!isset($userdata['role'])){
			return;
		}

		if ( $userdata['role']!=='administrator' ){
			return;
		}
		//new role is administrator. Add to list of admin users
		$this->register_admin($user_id);
	}

	/**
	 * Check if this request is coming from the create user page
	 *
	 * @return bool
	 */
	private function is_create_user_request(): bool {
		$nonce_checked = isset($_POST['_wpnonce_create-user'] ) && wp_verify_nonce($_POST['_wpnonce_create-user'], 'create-user' );
		return  ( $nonce_checked && isset( $_POST['_wp_http_referer'] ) && strpos( $_POST['_wp_http_referer'], 'wp-admin/user-new.php' ) !== false );
	}

	/**
	 * Check if this request is coming from the update user page
	 * Only leave the UserId out if this is not a security check
	 *
	 * @return bool
	 */
	private function is_update_user_request($user_id): bool {
		//verify nonce
		$nonce_check = $user_id ? isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'update-user_' . $user_id ) : true;
		return  $nonce_check && isset( $_POST['_wp_http_referer'] ) && strpos( $_POST['_wp_http_referer'], 'wp-admin/user-edit.php' ) !== false;
	}

	/**
	 * Cleanup on user delete
	 * @param $id
	 * @param $reassign
	 * @param $user
	 *
	 * @return void
	 */
	public function cleanup_on_user_delete($id, $reassign, $user): void {
		if ( !current_user_can('delete_users') ){
			return;
		}
		$this->deregister_admin($id);
	}

	/**
	 * On user update, check if the user role is set to admin and add it to our own admin user list
	 */
	public function verify_user_update(int $user_id, WP_User $old_user_data, array $userdata): void {
		if ( !current_user_can('edit_users') ){
			return;
		}

		if ( !isset($userdata['role']) ){
			return;
		}

		if ($userdata['role']!=='administrator'){
			$this->deregister_admin($user_id);
			return;
		}

		if ($old_user_data->role==='administrator'){
			return;
		}

		//check referrer
		if (!$this->is_update_user_request($user_id)){
			return;
		}

		//new role is administrator, old role is not administrator. Add to list of admin users
		$this->register_admin($user_id);
	}

	/**
	 * Create random key for encryption purposes and save in an option
	 * @return string
	 */
	private function get_key(): string {
		$key = get_option('rsssl_registered_admin_key', false );
		if (!$key) {
			$key = wp_generate_password( 64, false );
			update_option( 'rsssl_registered_admin_key', $key, false );
		}
		return $key;
	}

	/**
	 * Register an admin user within Really Simple SSL
	 *
	 * @param int $user_id
	 *
	 * @return void
	 */
	private function register_admin(int $user_id): void {
		if (!current_user_can('create_users') && !current_user_can('edit_users')) {
			return;
		}
		$admin_users = $this->get_registered_admins();
		if ( ! in_array( $user_id, $admin_users, true ) ){
			$admin_users[] = $user_id;
			update_option('rsssl_registered_admin_users', $this->encrypt($admin_users), false);
		}
	}

	/**
	 * Remove a user from the registered admin list
	 *
	 * @param int $user_id
	 *
	 * @return void
	 */
	private function deregister_admin( int $user_id): void {
		if (!current_user_can('delete_users') ) {
			return;
		}
		$admin_users = $this->get_registered_admins();
		if ( in_array( $user_id, $admin_users, true ) ){
			//remove $user_id from $admin_users
			$key = array_search( $user_id, $admin_users, true );
			unset($admin_users[$key]);
			update_option('rsssl_registered_admin_users', $this->encrypt($admin_users), false);
		}
	}

	/**
	 * Get list of registered admins
	 * @return array
	 */
	private function get_registered_admins(): array {
		$admin_users = get_option('rsssl_registered_admin_users', []);
		return $this->decrypt($admin_users);
	}

	/**
	 * Encrypt data
	 *
	 * @param array $data
	 *
	 * @return string
	 */
	private function encrypt( array $data): string {
		$string = serialize($data);
		$key = $this->get_key();
		$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
		$encrypted = openssl_encrypt($string, 'aes-256-cbc', $key, 0, $iv);
		return base64_encode($encrypted . '::' . $iv);
	}

	/**
	 * Decrypt data
	 * @param string|array $data
	 *
	 * @return array
	 */
	private function decrypt($data): array {
		if (is_array($data)) {
			return $data;
		}
		$key = $this->get_key();
		list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
		$data =  openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
		$data = unserialize($data);
		if (!is_array($data)){
			return [];
		}
		return $data;
	}

	/**
	 * @param $method
	 * @param $args
	 * @param $obj
	 *
	 * @return void
	 */
	public function check_logged_in_users_xml($method, $args, $obj ): void {
		$this->check_logged_in_users();
	}

	/**
	 * @return void
	 */
	public function check_logged_in_users_init(): void {
		$this->check_logged_in_users();
	}
	/**
	 * Cron to check if all admin users are available in the our own admin user list
	 * If not, change role to subscriber
	 */
	public function check_logged_in_users(): void {
		//don't check if not logged in
		if ( !is_user_logged_in() ) {
			return;
		}

		//check if user is administrator
		$user = wp_get_current_user();
		if ( $user && !in_array( 'administrator', $user->roles, true ) ) {
			return;
		}

		if (defined('RSSSL_SKIP_ADMIN_CHECK') && RSSSL_SKIP_ADMIN_CHECK) {
			return;
		}

		//Don't drop admins if RSSSL_FORCE_ADMIN_REGISTRATION is set to true
		if (defined('RSSSL_FORCE_ADMIN_REGISTRATION') && RSSSL_FORCE_ADMIN_REGISTRATION) {
			return;
		}

		//only run if function has completed registration
		if ( !rsssl_get_option('block_admin_creation_confirm') ) {
			return;
		}

		if ( $this->is_create_user_request() || $this->is_update_user_request(false)) {
			return;
		}

		//get list of admin users from wordpress
		$admin_users = get_users(['role'=>'administrator', 'fields'=>'ID']);
		$rsssl_admin_users = $this->get_registered_admins();
		$not_registered = [];

		//get list of users not existing in the rsssl_admin_users  list
		foreach ($admin_users as $admin_user) {
			//cannot use strict here.
			if (! in_array( $admin_user, $rsssl_admin_users ) ) {
				$not_registered[] = $admin_user;
			}
		}

		if ( count($not_registered)===0 ) {
			return;
		}

		//do lowest last
		usort($not_registered, static function($a, $b) {
			return $b - $a;
		});

		//set admins to subscriber if not registered.
		foreach ( $not_registered as $user_id ) {
			if ( count($admin_users)===1 ){
				//if there is only one admin user left, don't change role
				return;
			}
			//remove user_id from $admin_users array
			$admin_users = array_diff($admin_users, [$user_id]);

			//change role to subscriber
			$user = new WP_User($user_id);
			$user->set_role('subscriber');
			
			//mailer class is not loaded when XMLRPC is called, as this is not admin and not cron.
			if ( !class_exists('rsssl_mailer' )){
				require_once( rsssl_path . 'mailer/class-mail.php');
			}

			$block = [
				'title' => __('Manual approval required', 'really-simple-ssl-pro'),
				'message' => __('Because of your settings in Really Simple SSL, this user has been set to subscriber until you change the role manually.','really-simple-ssl-pro'),
				'url' => add_query_arg(array('user_id'=> $user_id), admin_url('user-edit.php')),
			];

			if ( class_exists('rsssl_mailer')) {
				$mailer          = new rsssl_mailer();
				$mailer->subject = __( 'Suspicious admin account detected', 'really-simple-ssl-pro' );
				$mailer->title = __( 'Suspicious admin account detected', 'really-simple-ssl-pro' );
				$mailer->message = __( 'A user account with administrator privileges was created outside the WordPress dashboard.', 'really-simple-ssl-pro' );
				$mailer->warning_blocks[] = $block;
				$mailer->send_mail();
			}
		}
	}

	/**
	 * If the corresponding setting has been changed, clear the test cache and re-run it.
	 *
	 * @param string $field_id
	 * @param mixed  $field_value
	 * @param mixed  $prev_value
	 * @param string $field_type
	 *
	 * @return void
	 */
	public function setup_admin_list( string $field_id, $field_value, $prev_value, $field_type ): void {
		if ( !rsssl_user_can_manage() ) {
			return;
		}

		if ( $field_id === 'block_admin_creation'  ) {
			if ( $field_value ) {
				update_option('rsssl_admin_registration_started', true, false);
				//add current admin users to our own list
				$remaining_admins = $this->register_admins();
				if ( $remaining_admins ===0 ) {
					$this->toggle_setting(true);
				}
			} else {
				$this->toggle_setting(false);
				delete_option('rsssl_registered_admin_users' );
				delete_option('rsssl_admin_registration_started');
			}
		}
	}

	public function maybe_force_admin_registration(): void {
		if ( defined( 'RSSSL_FORCE_ADMIN_REGISTRATION' ) && RSSSL_FORCE_ADMIN_REGISTRATION ) {
			$remaining_admins = $this->register_admins();
			if ( $remaining_admins === 0 ) {
				$this->toggle_setting(true);
			}
		}
	}

	/**
	 * Register all admin users and return count of not converted admins
	 * @return int
	 */
	private function register_admins(): int {
		$force_registration_acive = defined( 'RSSSL_FORCE_ADMIN_REGISTRATION' ) && RSSSL_FORCE_ADMIN_REGISTRATION;

		if ( !$force_registration_acive && !rsssl_user_can_manage() ) {
			return 0;
		}

		$admin_users = get_users(['role'=>'administrator', 'fields'=>'ID']);
		$count = count($admin_users);
		foreach ($admin_users as $admin_user) {
			$count--;
			$this->register_admin($admin_user);
		}
		return $count;
	}

	/**
	 * Enable the setting to block admin creation
	 *
	 * @param bool $enable
	 *
	 * @return void
	 */
	private function toggle_setting( bool $enable): void {

		//default disable it, we can't use rsssl_update_option is this would cause a loop
		if (is_multisite() && rsssl_is_networkwide_active()) {
			$options = get_site_option('rsssl_options', []);
		} else {
			$options = get_option('rsssl_options', []);
		}
		if (!is_array($options)) $options = [];
		$options['block_admin_creation_confirm'] = (bool) $enable;
		if ( is_multisite() && rsssl_is_networkwide_active() ) {
			update_site_option( 'rsssl_options', $options );
		} else {
			update_option( 'rsssl_options', $options );
		}
	}
}


if ( !defined('RSSSL_DISABLE_BLOCK_ADMIN_CREATION') ){
	new rsssl_block_admin_creation();
}