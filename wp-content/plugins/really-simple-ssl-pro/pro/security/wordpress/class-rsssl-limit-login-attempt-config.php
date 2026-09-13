<?php

require_once rsssl_path . 'pro/security/wordpress/limitlogin/class-rsssl-login-attempt.php';

use REALLY_SIMPLE_SSL\Security\WordPress\Limitlogin\Rsssl_Login_Attempt;

/**
 * Class Rsssl_Limit_Login_Attempt_Config
 *
 * This class provides configuration options for the Rsssl_Limit_Login_Attempt plugin.
 * Also this is a placeholder until the new LLA update is released.
 *
 */
class Rsssl_Limit_Login_Attempt_Config {
	public function __construct() {
		add_action( 'rsssl_after_save_field', array( $this, 'save_field_handler' ), 10, 4 );
	}

	/**
	 * Handles the saving of a field.
	 *
	 * @param  string $field_id  The ID of the field.
	 * @param  mixed  $field_value  The new value of the field.
	 * @param  mixed  $prev_value  The previous value of the field.
	 * @param  string $field_type  The type of the field.
	 *
	 * @return void
	 */
	public function save_field_handler( string $field_id, $field_value, $prev_value, string $field_type ): void {
		// Add your condition based on field_id, field_value, etc.
		if ( 'enable_limited_login_attempts' === $field_id &&
		     true === (bool) $field_value && ! Rsssl_Login_Attempt::check_if_table_exists() ) {
			     Rsssl_Login_Attempt::create_login_attempts_table(true);
		     }
	}

}

new Rsssl_Limit_Login_Attempt_Config();