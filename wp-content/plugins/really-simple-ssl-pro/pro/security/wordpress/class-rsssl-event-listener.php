<?php
/**
 * Marcel Santing, Really Simple Plugins
 *
 * This PHP file contains the implementation of the Rsssl_Event_Listener class.
 *
 * @author Marcel Santing
 * @company Really Simple Plugins
 * @email marcel@really-simple-plugins.com
 * @package REALLY_SIMPLE_SSL\Security\WordPress
 */

namespace REALLY_SIMPLE_SSL\Security\WordPress;

require_once rsssl_path . 'pro/security/wordpress/eventlog/class-rsssl-event-type.php';
require_once rsssl_path . 'pro/security/wordpress/limitlogin/class-rsssl-login-attempt.php';
require_once rsssl_path . 'pro/security/wordpress/class-rsssl-limit-login-attempts.php';
require_once rsssl_path . 'pro/security/wordpress/captcha/class-rsssl-captcha.php';

use REALLY_SIMPLE_SSL\Security\WordPress\Captcha\Rsssl_Captcha;
use REALLY_SIMPLE_SSL\Security\WordPress\Eventlog\Rsssl_Event_Type;
use Exception;
use REALLY_SIMPLE_SSL\Security\WordPress\Limitlogin\Rsssl_Login_Attempt;
use RuntimeException;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'rsssl_event_listener' ) ) {
	/**
	 * Class rsssl_event_listener
	 *
	 * This class is used to listen to events and log them to the database
	 * and adds the appropriate actions to the bespoke events. Like limit login attempts
	 *
	 * @package REALLY_SIMPLE_SSL\Security\WordPress
	 */
	class Rsssl_Event_Listener {

		/**
		 * Event code for successful login.
		 */
		public const LOGIN_SUCCESS        = '1000'; // code for successful login.
		public const LOGIN_FAILED         = '1001'; // code for failed login.

		/**
		 * Endpoints
		 */
		public const LOGIN_ENDPOINT = 'wp-login';


		/**
		 * The sanitized ip address.
		 *
		 * @var mixed The sanitized ip address.
		 */
		public $sanitized_ip;

		/**
		 * Constructor for the rsssl_event_listener class.
		 * Initializes the hooks for login events.
		 *
		 * @throws Exception If an error occurs during processing.
		 */
		public function __construct() {
			if ( ! is_user_logged_in() ) {
				// get the sanitized ip.
				$limit_login_attempts = new Rsssl_Limit_Login_Attempts();
				$ip_addresses = $limit_login_attempts->get_ip_address();
				$this->sanitized_ip = isset( $ip_addresses[0] ) ? filter_var( $ip_addresses[0], FILTER_VALIDATE_IP ) : null;
				$this->hook_init();
			}
		}

		/**
		 * Hook initialization method.
		 * Attaches the appropriate actions to the WordPress hooks.
		 *
		 * @throws Exception If an error occurs during processing.
		 */
		public function hook_init(): void {
			add_action( 'login_form', array( $this, 'inject_nonce_to_login_form' ) );
			if ( ! is_user_logged_in() ) {
				// we hook into the login page to display a message if the user is blocked.
				add_filter( 'login_message', array( $this, 'user_was_blocked' ) );
				//phpcs:ignore
				if ( isset( $_POST['log'] ) ) {
					$this->listen_to_post_request();
				}

				/*
				 * We hook into the login and login failed events.
				 */
				add_filter( 'wp_login', array( $this, 'listen_to_successful_login_attempt' ), 10, 2 );
				add_filter( 'wp_login_failed', array( $this, 'listen_to_failed_login_attempt' ), 10, 1 );
				add_filter( 'authenticate', array( $this, 'validate_captcha' ), 30, 3 );
			}
		}

		/**
		 * Injects the captcha to the WordPress login form.
		 *
		 * @return void
		 */
		public function inject_captcha_to_login_form(): void {
			// We only inject the captcha if it is enabled.
			if ( rsssl_get_option( 'captcha_fully_enabled' ) && rsssl_get_option( 'limit_login_attempts_captcha' ) ) {
				echo esc_html( Rsssl_Captcha::render() );
			}
		}

		/**
		 * Validate the captcha for limited login attempts.
		 *
		 * @param  mixed  $user  The user object.
		 * @param  string  $username  The username provided by the user.
		 * @param  string  $password  The password provided by the user.
		 *
		 * @return WP_Error|WP_User The WP_Error object if captcha validation fails, otherwise the user object.
		 */
		public function validate_captcha( $user, string $username, string $password ) {
			// Only proceed with CAPTCHA validation if it is enabled and necessary.
			if ( rsssl_get_option( 'enable_limited_login_attempts' ) &&
				get_transient( 'rsssl_failed_login_attempt_' . $this->create_unique_id() ) &&
				rsssl_get_option( 'limit_login_attempts_captcha' )
			) {
				// Initialize the CAPTCHA object.
				$captcha = new Rsssl_Captcha();

				// Retrieve the CAPTCHA response sent by the user.
				$captcha_response = $captcha->captcha_provider->get_response_value();

				// Validate the CAPTCHA response.
				if ( ! $captcha->captcha_provider->validate( $captcha_response ) ) {
					// If CAPTCHA validation fails, return a WP_Error object.
					return new WP_Error( 'captcha_failed', __( '<strong>Error</strong>: Captcha validation failed.', 'really-simple-ssl' ) );
				}
			}
			return $user;
		}


		/**
		 * Listen to the POST request and perform necessary actions.
		 *
		 * @return void
		 *
		 * @throws Exception If an error occurs during processing.
		 */
		public function listen_to_post_request(): void {
			if ( isset( $_POST['log'] ) ) {
				// Check if nonce is set and verify it.
				if ( ! isset( $_POST['rsssl_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rsssl_login_nonce'] ) ), 'rsssl_login_action' ) ) {
					// Nonce verification failed.
					$login_url = remove_query_arg( '_wpnonce' );
					wp_safe_redirect( $login_url );
					exit;
				}
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$login_attempt = new Rsssl_Login_Attempt( wp_unslash( $_POST['log'] ), $this->sanitized_ip );
				if ( $login_attempt->is_login_blocked() ) {
					try {
						$nonce     = wp_create_nonce( 'rsssl_block_message' );
						$login_url = wp_login_url() . '?' . $login_attempt->block_state . '=true&_wpnonce=' . $nonce;
						wp_safe_redirect( $login_url );
						exit;
					} catch ( Exception $e ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'Really Simple SSL: Error redirecting user to login page: ' . esc_html( $e->getMessage() ) );
						}
					}
				}
			}
		}

		/**
		 * Display a message to the user if the user is blocked.
		 *
		 * @return string|null The message to display.
		 */
		public function display_blocked_user_message(): ?string {
			// Check if the 'blocked' query argument is set.
			return __( 'Your access has been denied, please contact the webmaster for support', 'really-simple-ssl' );
		}

		/**
		 * Display a message to the user if the user is blocked.
		 *
		 * @param string $message The message to display.
		 *
		 * @return string The message to display.
		 */
		public function user_was_blocked( string $message ): string {

			// Verify nonce for 'blocked' or 'locked_out' state.
			if ( ( isset( $_GET['blocked'] ) && 'true' === $_GET['blocked'] ) ||
				( isset( $_GET['locked_out'] ) && 'true' === $_GET['locked_out'] ) ) {
				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rsssl_block_message' ) ) {
					// Nonce verification failed.
					// we redirect to the login page. We will remove the wp_nonce from the url.
					$login_url = remove_query_arg( '_wpnonce' );
					wp_safe_redirect( $login_url );
					exit;
				}
			}

			// Display appropriate block message.
			if ( isset( $_GET['blocked'] ) && 'true' === $_GET['blocked'] ) {
				$message .= '<div id="login_error" class="notice notice-error">' . __(
					'Your access has been denied, please contact the webmaster for support',
					'really-simple-ssl'
				) . '.</div>';
			}

			if ( isset( $_GET['locked_out'] ) && 'true' === $_GET['locked_out'] ) {
				$message .= '<div id="login_error" class="notice notice-error">' . __(
					'Your access has been denied, too many login attempts',
					'really-simple-ssl'
				) . '.</div>';
			}

			return $message;
		}


		/**
		 * Listens to a successful login attempt and logs the event.
		 *
		 * @param  string  $login_username The username used for login.
		 * @param  WP_User $logged_in_user The logged-in user.
		 *
		 * @return WP_User
		 * @throws Exception If an error occurs during processing.
		 */
		public function listen_to_successful_login_attempt( string $login_username, WP_User $logged_in_user ): WP_User {
			// now we end the failed login attempt.
			$login_attempt = new Rsssl_Login_Attempt( sanitize_user( $login_username ), $this->sanitized_ip );
			$login_attempt->end_failed_login_attempt( self::LOGIN_ENDPOINT );

			// We are happy and log a successful login.
			$event = Rsssl_Event_Type::login( sanitize_user( $login_username ), self::LOGIN_SUCCESS );
//			( new Rsssl_Event_Log() )->log_event( $event );
			Rsssl_Event_Log::log_event( $event);
			// if the user or ip is allowed we do not log the failed login attempt.
			delete_transient( 'rsssl_failed_login_attempt_' . $this->create_unique_id() );
			$this->delete_cookie();

			return $logged_in_user;
		}

		/**
		 * Listens to a failed login attempt and logs the event.
		 *
		 * @param  string $username  The username used for login.
		 *
		 * @throws Exception If an error occurs during processing.
		 */
		public function listen_to_failed_login_attempt( string $username ): void {
			add_action( 'login_form', array( $this, 'inject_captcha_to_login_form' ) );
			set_transient( 'rsssl_failed_login_attempt_' .$this->create_unique_id(), true, 60 * 10 ); // Expires in 10 minutes.

			if ( Rsssl_Login_Attempt::check_if_table_exists()) {
				// now we start the failed login attempt.
				$login_attempt = new Rsssl_Login_Attempt( $username, $this->sanitized_ip );
				$event         = Rsssl_Event_Type::login( $username, self::LOGIN_FAILED );
//				( new Rsssl_Event_Log() )->log_event( $event );
				Rsssl_Event_Log::log_event( $event);
				// if the user or ip is allowed we do not log the failed login attempt.
				if ( $login_attempt->is_login_allowed() ) {
					return;
				}
				// if the user already is locked out we do not log the failed login attempt.
				if ( $login_attempt->is_login_blocked() ) {
					return;
				}
				$login_attempt->start_failed_login_attempt( self::LOGIN_ENDPOINT );
			}

		}

		/**
		 * Create a unique ID and set it as a cookie if the ID does not already exist.
		 *
		 * @return string The hashed unique ID.
		 */
		private function create_unique_id(): string {
			$cookie_name = 'rsssl_captcha_uid';

			if (!isset($_COOKIE[$cookie_name])) {
				// Generate a unique ID using uniqid() function with more entropy for better uniqueness
				$unique_id = uniqid('', true);

				$expiry_time = time()+60*10;  // Expires in 10 minutes.
				setcookie($cookie_name, $unique_id, [
					'expires' => $expiry_time,
					'httponly' => true, // Made it HTTP only for better security
					'samesite' => 'Strict',
				]);
				if(!isset($_COOKIE[$cookie_name])){ // Check if cookie was set
					// If not, start session and store the unique_id there
					session_start();
					$_SESSION[$cookie_name] = $unique_id;
					return hash('md5', $_SESSION[$cookie_name]);
				}
				// Normal operation with cookies - hashing and set transient
				return hash('md5', $unique_id);
			}

			return hash('md5', $_COOKIE[$cookie_name]); // Hashing for safety.
		}

		/**
		 * Delete a cookie.
		 *
		 * This method deletes a cookie by unsetting it and setting its expiration time to a pastime.
		 *
		 * @return void
		 */
		public function delete_cookie(): void {
			$cookie_name = 'rsssl_captcha_uid';
			if (isset($_COOKIE[$cookie_name])) {
				unset($_COOKIE[$cookie_name]);
				setcookie($cookie_name, '', time() - 3600, '/');
			}

			if (session_status() === PHP_SESSION_NONE) {
				session_start();
			}

			// Check if the session variable exists
			if (isset($_SESSION[$cookie_name])) {
				// Remove the session variable
				unset($_SESSION[$cookie_name]);
			}
		}

		/**
		 * Inject a nonce field into the WordPress login form.
		 */
		public function inject_nonce_to_login_form(): void {
			wp_nonce_field( 'rsssl_login_action', 'rsssl_login_nonce' );
		}
	}
}

/**
 * Initializes the rsssl_event_listener class.
 *
 * @return void
 */
function rsssl_initialize_event_listener() {
	new Rsssl_Event_Listener();
}

// This is where you're adding it to the 'init' action.
add_action( 'init', '\REALLY_SIMPLE_SSL\Security\WordPress\rsssl_initialize_event_listener' );
