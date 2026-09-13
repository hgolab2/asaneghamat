<?php
/**
 * Marcel Santing, Really Simple Plugins
 *
 * This PHP file contains the implementation of the Rsssl_Event_Listener class.
 *
 * @author Marcel Santing
 * @company Really Simple Plugins
 * @email marcel@really-simple-plugins.com
 * @package RSSSL_PRO\Security\WordPress
 */

namespace RSSSL_PRO\Security\WordPress;

require_once rsssl_pro_path . 'security/wordpress/eventlog/class-rsssl-event-type.php';
require_once rsssl_pro_path . 'security/wordpress/limitlogin/class-rsssl-login-attempt.php';
require_once rsssl_pro_path . 'security/wordpress/class-rsssl-limit-login-attempts.php';

use RSSSL_PRO\Security\WordPress\Eventlog\Rsssl_Event_Type;
use Exception;
use RSSSL_PRO\Security\WordPress\Rsssl_Limit_Login_Attempts;
use RSSSL_PRO\Security\WordPress\Limitlogin\Rsssl_Login_Attempt;
use RuntimeException;
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
	 * @package RSSSL_PRO\Security\WordPress
	 */
	class Rsssl_Event_Listener {

		/**
		 * Event code for successful login.
		 */
		const LOGIN_SUCCESS        = '1000'; // code for successful login.
		const LOGIN_FAILED         = '1001'; // code for failed login.
		const LOGIN_SUCCESS_REST   = '1002'; // code for successful login via rest api.
		const LOGIN_FAILED_REST    = '1003'; // code for failed login via rest api.
		const LOGIN_SUCCESS_XMLRPC = '1004'; // code for successful login via xmlrpc.
		const LOGIN_FAILED_XMLRPC  = '1005'; // code for failed login via xmlrpc.

		/**
		 * Endpoints
		 */
		const LOGIN_ENDPOINT = 'wp-login';

		/**
		 * Xmlrpc endpoint.
		 */
		const XMLRPC_ENDPOINT = 'xmlrpc';

		/**
		 * Rest endpoint.
		 */
		const REST_ENDPOINT = 'rest';

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
				$this->sanitized_ip   = filter_var( $limit_login_attempts->get_ip_address()[0], FILTER_VALIDATE_IP );
				$this->hook_init();
			}
		}

		/**
		 * Hook initialization method.
		 * Attaches the appropriate actions to the WordPress hooks.
		 *
		 * @throws Exception If an error occurs during processing.
		 */
		public function hook_init() {
			add_action( 'login_form', array( $this, 'inject_nonce_to_login_form' ) );
			if ( ! is_user_logged_in() ) {
				// we hook into the login page to display a message if the user is blocked.
				add_filter( 'login_message', array( $this, 'user_was_blocked' ) );

				if (isset($_POST['log'])) {
					$this->listen_to_post_request();
				}
				/*
				 * We hook into the login and login failed events.
				 */
				add_filter( 'wp_login', array( $this, 'listen_to_successful_login_attempt' ), 10, 2 );
				add_filter( 'wp_login_failed', array( $this, 'listen_to_failed_login_attempt' ), 10, 1 );

			}
		}


		/**
		 * Listen to login attempt and redirect user to login page if login is blocked.
		 *
		 * @return void|WP_User
		 * @throws Exception If an error occurs during the redirect.
		 */
		public function listen_to_post_request() {
			if ( isset( $_POST['log'] ) ) {
				// Check if nonce is set and verify it.
				if ( ! isset( $_POST['rsssl_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rsssl_login_nonce'] ) ), 'rsssl_login_action' ) ) {
					// Nonce verification failed.
					$login_url = remove_query_arg('_wpnonce');
					wp_safe_redirect( $login_url );
					exit;
				}

				$login_attempt = new Rsssl_Login_Attempt( $_POST['log'], $this->sanitized_ip );
				if ( $login_attempt->is_login_blocked() ) {
					try {
						$nonce     = wp_create_nonce( 'rsssl_block_message' );
						$login_url = wp_login_url() . '?' . $login_attempt->block_state . '=true&_wpnonce=' . $nonce;
						wp_safe_redirect( $login_url );
						exit;
					} catch ( Exception $e ) {
						throw new RuntimeException( 'Error redirecting user to login page: ' . esc_html( $e->getMessage() ) );
					}
				}
			}
		}

		/**
		 * Display a message to the user if the user is blocked.
		 *
		 * @return string|null The message to display.
		 */
		public function display_blocked_user_message() {
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
					//we redirect to the login page. We will remove the wp_nonce from the url.
					$login_url = remove_query_arg('_wpnonce');
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
			$login_attempt->end_failed_login_attempt();
			// We are happy and log a successfully login.
			$event = Rsssl_Event_Type::login( sanitize_user( $login_username ), self::LOGIN_SUCCESS );
			( new Rsssl_Event_Log() )->log_event( $event );

			return $logged_in_user;
		}

		/**
		 * Listens to a failed login attempt and logs the event.
		 *
		 * @param  string $username  The username used for login.
		 *
		 * @throws Exception If an error occurs during processing.
		 */
		public function listen_to_failed_login_attempt( string $username ) {
			// now we start the failed login attempt.
			$login_attempt = new Rsssl_Login_Attempt( $username, $this->sanitized_ip );
			$event         = Rsssl_Event_Type::login( $username, self::LOGIN_FAILED );
			( new Rsssl_Event_Log() )->log_event( $event );
			// if the user or ip is allowed we do not log the failed login attempt.
			if ( $login_attempt->is_login_allowed() ) {
				return;
			}
			// if the user alreadu is locked out we do not log the failed login attempt.
			if ( $login_attempt->is_login_blocked() ) {
				return;
			}
			$login_attempt->start_failed_login_attempt( self::LOGIN_ENDPOINT );
		}

		/**
		 * Inject a nonce field into the WordPress login form.
		 */
		public function inject_nonce_to_login_form() {
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
add_action( 'init', '\RSSSL_PRO\Security\WordPress\rsssl_initialize_event_listener' );
