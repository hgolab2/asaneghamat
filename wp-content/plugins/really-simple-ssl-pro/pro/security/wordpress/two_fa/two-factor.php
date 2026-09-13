<?php
/**
 * This package is based on the WordPress feature plugin https://wordpress.org/plugins/two-factor/
 *
 * Class for creating two-factor authorization.
 *
 * @since 7.0.6
 *
 */

if ( rsssl_admin_logged_in() ) {
	/**
	 * Include the settings class.
	 */
	require_once __DIR__ . '/class-two-factor-settings.php';
}

/**
 * Include the provider class.
 */
require_once __DIR__ . '/class-two-factor-provider.php';

/**
 * A compatability layer for some of the most-used plugins out there.
 */
require_once __DIR__ . '/class-two-factor-compat.php';

/**
 * Include the settings
 */
require_once __DIR__ . '/class-two-factor-settings.php';

$rsssl_two_factor_compat = new Rsssl_Two_Factor_Compat();

class Rsssl_Two_Factor {

	/**
	 * The user meta provider key.
	 *
	 * @type string
	 */
	const RSSSL_STATUS_USER_META_KEY = 'rsssl_two_fa_status';

	/**
	 * The user meta enabled providers key.
	 *
	 * @type string
	 */
	const RSSSL_ENABLED_PROVIDERS_USER_META_KEY = 'rsssl_two_fa_providers';

	/**
	 * The user meta nonce key.
	 *
	 * @type string
	 */
	const RSSSL_USER_META_NONCE_KEY = '_rsssl_two_factor_nonce';

	/**
	 * The user meta key to store the last failed timestamp.
	 *
	 * @type string
	 */
	const RSSSL_USER_RATE_LIMIT_KEY = '_rsssl_two_factor_last_login_failure';

	/**
	 * The user meta key to store the number of failed login attempts.
	 *
	 * @var string
	 */
	const RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY = '_rsssl_two_factor_failed_login_attempts';

	/**
	 * The user meta key to store whether or not the password was reset.
	 *
	 * @var string
	 */
	const RSSSL_USER_PASSWORD_WAS_RESET_KEY = '_rsssl_two_factor_password_was_reset';

	/**
	 * URL query paramater used for our custom actions.
	 *
	 * @var string
	 */
	const RSSSL_USER_SETTINGS_ACTION_QUERY_VAR = 'rsssl_two_factor_action';

	/**
	 * Nonce key for user settings.
	 *
	 * @var string
	 */
	const RSSSL_USER_SETTINGS_ACTION_NONCE_QUERY_ARG = '_rsssl_two_factor_action_nonce';

	/**
	 * Namespace for plugin rest api endpoints.
	 *
	 * @var string
	 */

	/**
	 * Keep track of all the password-based authentication sessions that
	 * need to invalidated before the second factor authentication.
	 *
	 * @var array
	 */
	private static $password_auth_tokens = array();

	/**
	 * Set up filters and actions.
	 *
	 * @param object $compat A compatibility layer for plugins.
	 *
	 * @since 0.1-dev
	 */
	public static function add_hooks( $compat ): void {

		if ( defined( 'RSSSL_DISABLE_2FA' ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'rsssl_get_providers' ) );
		add_action( 'wp_login', array( __CLASS__, 'rsssl_wp_login' ), 10, 2 );
		add_filter( 'wp_login_errors', array( __CLASS__, 'rsssl_maybe_show_reset_password_notice' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'rsssl_clear_password_reset_notice' ) );
		add_action( 'login_form_validate_2fa', array( __CLASS__, 'rsssl_login_form_validate_2fa' ) );

		/**
		 * Keep track of all the user sessions for which we need to invalidate the
		 * authentication cookies set during the initial password check.
		 *
		 * Is there a better way of doing this?
		 */
		add_action( 'set_auth_cookie', array( __CLASS__, 'rsssl_collect_auth_cookie_tokens' ) );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'rsssl_collect_auth_cookie_tokens' ) );

		if ( isset( $_GET['rsssl_one_time_login'] ) ) {
			add_action( 'init', array( __CLASS__, 'maybe_skip_auth' ) );
		}

		add_action( 'init', array( __CLASS__, 'rsssl_collect_auth_cookie_tokens' ) );

		// Run only after the core wp_authenticate_username_password() check.
		add_filter( 'authenticate', array( __CLASS__, 'rsssl_filter_authenticate' ) );

		// Run as late as possible to prevent other plugins from unintentionally bypassing.
		add_filter( 'authenticate', array( __CLASS__, 'rsssl_filter_authenticate_block_cookies' ), PHP_INT_MAX );
		add_action( 'admin_init', array( __CLASS__, 'rsssl_enable_dummy_method_for_debug' ) );
		add_filter( 'rsssl_two_factor_providers', array( __CLASS__, 'enable_dummy_method_for_debug' ) );

		$compat->init();
	}

	/**
	 * For each provider, include it and then instantiate it.
	 *
	 * @return array
	 * @since 0.1-dev
	 *
	 */
	public static function rsssl_get_providers() {
		$providers = array(
			'Rsssl_Two_Factor_Email' => __DIR__ . '/class-two-factor-email.php',
			//            'Two_Factor_Totp'         => __DIR__ . 'providers/class-two-factor-totp.php',
			//            'Two_Factor_FIDO_U2F'     => __DIR__ . 'providers/class-two-factor-fido-u2f.php',
			//            'Two_Factor_Backup_Codes' => __DIR__ . 'providers/class-two-factor-backup-codes.php',
			//            'Two_Factor_Dummy'        => __DIR__ . 'providers/class-two-factor-dummy.php',
		);

		/**
		 * Filter the supplied providers.
		 *
		 * This lets third-parties either remove providers (such as Email), or
		 * add their own providers (such as text message or Clef).
		 *
		 * @param array $providers A key-value array where the key is the class name, and
		 *                         the value is the path to the file containing the class.
		 */
		$providers = apply_filters( 'rsssl_two_factor_providers', $providers );

		/**
		 * For each filtered provider,
		 */
		foreach ( $providers as $class => $path ) {
			include_once $path;

			/**
			 * Confirm that it's been successfully included before instantiating.
			 */
			if ( class_exists( $class ) ) {
				try {
					$providers[ $class ] = call_user_func( array( $class, 'get_instance' ) );
				} catch ( Exception $e ) {
					unset( $providers[ $class ] );
				}
			}
		}

		return $providers;
	}

	/**
	 * @return void
	 *
	 * Allow 2FA bypass if status is open
	 */
	public static function maybe_skip_auth() {

		if ( isset( $_GET['rsssl_one_time_login'], $_GET['token'], $_GET['_wpnonce'] ) ) {

			$user_id = (int) Rsssl_Two_Factor_Settings::deobfuscate_user_id( sanitize_text_field( $_GET['rsssl_one_time_login'] ) );
			$user    = get_user_by( 'id', $user_id );

			if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'one_time_login_' . $user_id ) ) {
				wp_redirect( wp_login_url() . '?login_error=nonce_invalid' );
				exit;
			}

			// Retrieve the stored token from the transient
			$storedToken = get_transient( 'skip_two_fa_token_' . $user_id );

			// Check if the token is valid and not expired
			if ( $user && $storedToken && hash_equals( $storedToken, $_GET['token'] ) ) {

				// Delete the transient to invalidate the token
				delete_transient( 'skip_two_fa_token_' . $user_id );

				$provider = get_user_meta( $user->ID, 'rsssl_two_fa_status', true );

				// Only allow skipping for users which have 2FA value open
				if ( isset( $_GET['rsssl_two_fa_disable'] ) && 'open' === $provider ) {
					update_user_meta( $user_id, 'rsssl_two_fa_status', 'disabled' );
				}

				wp_set_auth_cookie( $user_id, false );
				wp_redirect( admin_url() );
			} else {
				// The token is invalid or expired
				// Redirect to the login page with an error message or handle it as needed
				wp_redirect( wp_login_url() . '?login_error=token_invalid' );
			}
			exit;
		}
	}

	/**
	 * Enable the dummy method only during debugging.
	 *
	 * @param array $methods List of enabled methods.
	 *
	 * @return array
	 */
	public static function enable_dummy_method_for_debug( $methods ) {
		if ( ! self::is_wp_debug() ) {
			unset( $methods['Two_Factor_Dummy'] );
		}

		return $methods;
	}

	/**
	 * Check if the debug mode is enabled.
	 *
	 * @return boolean
	 */
	protected static function is_wp_debug() {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Get the user settings page URL.
	 *
	 * Fetch this from the plugin core after we introduce proper dependency injection
	 * and get away from the singletons at the provider level (should be handled by core).
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return string
	 */
	protected static function get_user_settings_page_url( $user_id ) {
		$page = 'user-edit.php';

		if ( defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE ) {
			$page = 'profile.php';
		}

		return add_query_arg(
			array(
				'rsssl_user_id' => (int) $user_id,
			),
			self_admin_url( $page )
		);
	}

	/**
	 * Get the URL for resetting the secret token.
	 *
	 * @param integer $user_id User ID.
	 * @param string $action Custom two factor action key.
	 *
	 * @return string
	 */
	public static function get_user_update_action_url( $user_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					self::RSSSL_USER_SETTINGS_ACTION_QUERY_VAR => $action,
				),
				self::get_user_settings_page_url( $user_id )
			),
			sprintf( '%d-%s', $user_id, $action ),
			self::RSSSL_USER_SETTINGS_ACTION_NONCE_QUERY_ARG
		);
	}

	/**
	 * Check if a user action is valid.
	 *
	 * @param integer $user_id User ID.
	 * @param string $action User action ID.
	 *
	 * @return boolean
	 */
	public static function is_valid_user_action( $user_id, $action ) {
		$request_nonce = isset( $_REQUEST[ self::RSSSL_USER_SETTINGS_ACTION_NONCE_QUERY_ARG ] ) ? wp_unslash( $_REQUEST[ self::RSSSL_USER_SETTINGS_ACTION_NONCE_QUERY_ARG ] ) : '';

		if ( ! $user_id || ! $action || ! $request_nonce ) {
			return false;
		}

		return wp_verify_nonce(
			$request_nonce,
			sprintf( '%d-%s', $user_id, $action )
		);
	}

	/**
	 * Get the ID of the user being edited.
	 *
	 * @return integer
	 */
	public static function current_user_being_edited() {
		// Try to resolve the user ID from the request first.
		if ( ! empty( $_REQUEST['rsssl_user_id'] ) ) {
			$user_id = (int) $_REQUEST['rsssl_user_id'];

			if ( current_user_can( 'edit_user', $user_id ) ) {
				return $user_id;
			}
		}

		return get_current_user_id();
	}

	/**
	 * Trigger our custom update action if a valid
	 * action request is detected and passes the nonce check.
	 *
	 * @return void
	 */
	public static function rsssl_enable_dummy_method_for_debug() {
		$action  = isset( $_REQUEST[ self::RSSSL_USER_SETTINGS_ACTION_QUERY_VAR ] ) ? wp_unslash( $_REQUEST[ self::RSSSL_USER_SETTINGS_ACTION_QUERY_VAR ] ) : '';
		$user_id = self::current_user_being_edited();

		if ( self::is_valid_user_action( $user_id, $action ) ) {
			/**
			 * This action is triggered when a valid Two Factor settings
			 * action is detected and it passes the nonce validation.
			 *
			 * @param integer $user_id User ID.
			 * @param string $action Settings action.
			 */
			do_action( 'rsssl_two_factor_user_settings_action', $user_id, $action );
		}
	}

	/**
	 * Keep track of all the authentication cookies that need to be
	 * invalidated before the second factor authentication.
	 *
	 * @param string $cookie Cookie string.
	 *
	 * @return void
	 */
	public static function rsssl_collect_auth_cookie_tokens( $cookie ) {
		$parsed = wp_parse_auth_cookie( $cookie );

		if ( ! empty( $parsed['token'] ) ) {
			self::$password_auth_tokens[] = $parsed['token'];
		}
	}

	/**
	 * Fetch the WP_User object for a provided input.
	 *
	 * @param int|WP_User $user Optional. The WP_User or user ID. Defaults to current user.
	 *
	 * @return false|WP_User WP_User on success, false on failure.
	 * @since 0.8.0
	 *
	 */
	public static function fetch_user( $user = null ) {
		if ( null === $user ) {
			$user = wp_get_current_user();
		} elseif ( ! ( $user instanceof WP_User ) ) {
			$user = get_user_by( 'id', $user );
		}

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return $user;
	}

	/**
	 * This isn't currently set anywhere, but allows to add more providers in the future.
	 *
	 * @param $user
	 *
	 * @return array|mixed|string[]
	 */
	public static function get_user_enabled_providers( $user = null ) {
		$user = self::fetch_user( $user );
		if ( ! $user ) {
			return array();
		}
		$enabled_providers = get_user_meta( $user->ID, self::RSSSL_ENABLED_PROVIDERS_USER_META_KEY, true );
		if ( ! $enabled_providers ) {
			$enabled_providers = [ 'email' ];
		}

		return $enabled_providers;
	}

	public static function get_user_two_fa_status( $user = null ) {
		$status = get_user_meta( $user->ID, self::RSSSL_STATUS_USER_META_KEY, true );
		if ( ! $status ) {
			$status = 'disabled';
		}

		return $status;
	}

	/**
	 * Get all Two-Factor Auth providers that are enabled for the specified|current user.
	 *
	 * @param int|WP_User $user Optonal. User ID, or WP_User object of the the user. Defaults to current user.
	 *
	 * @return array
	 */
	public static function get_enabled_providers_for_user( $user = null ) {
		$user = self::fetch_user( $user );
		if ( ! $user ) {
			return array();
		}

		$providers         = self::rsssl_get_providers();
		$enabled_providers = self::get_user_enabled_providers( $user );
		$status            = self::get_user_two_fa_status( $user );
		// If enabled
		if ( in_array( 'email', $enabled_providers, true ) ) {
			if ( 'active' === $status || 'open' === $status ) {
				$enabled_providers = 'Rsssl_Two_Factor_Email';
			}
		}

		if ( empty( $enabled_providers ) ) {
			$enabled_providers = array();
		}

		// If $enabled_providers is not an array, convert it into one
		if ( ! is_array( $enabled_providers ) ) {
			// This will place the single item into an array
			$enabled_providers = array( $enabled_providers );
		}

		$enabled_providers = array_intersect( $enabled_providers, array_keys( $providers ) );

		/**
		 * Filter the enabled two-factor authentication providers for this user.
		 *
		 * @param array $enabled_providers The enabled providers.
		 * @param int $user_id The user ID.
		 */

		return apply_filters( 'rsssl_two_factor_enabled_providers_for_user', $enabled_providers, $user->ID );
	}

	/**
	 * Get all Two-Factor Auth providers that are both enabled and configured for the specified|current user.
	 *
	 * @param int|WP_User $user Optonal. User ID, or WP_User object of the the user. Defaults to current user.
	 *
	 * @return array
	 */
	public static function get_available_providers_for_user( $user = null ) {
		$user = self::fetch_user( $user );
		if ( ! $user ) {
			return array();
		}

		$providers            = self::rsssl_get_providers();
		$enabled_providers    = self::get_enabled_providers_for_user( $user );
		$configured_providers = array();

		foreach ( $providers as $classname => $provider ) {
			if ( in_array( $classname, $enabled_providers, true ) && $provider->is_available_for_user( $user ) ) {
				$configured_providers[ $classname ] = $provider;
			}
		}

		return $configured_providers;
	}

	/**
	 * Gets the Two-Factor Auth provider for the specified|current user.
	 *
	 * @param int|WP_User $user Optonal. User ID, or WP_User object of the the user. Defaults to current user.
	 *
	 * @return object|null
	 * @since 0.1-dev
	 *
	 */
	public static function get_primary_provider_for_user( $user = null ) {
		$user = self::fetch_user( $user );
		if ( ! $user ) {
			return null;
		}

		$providers           = self::rsssl_get_providers();
		$available_providers = self::get_available_providers_for_user( $user );

		// If there's only one available provider, force that to be the primary.
		if ( empty( $available_providers ) ) {
			return null;
		} elseif ( 1 === count( $available_providers ) ) {
			$provider = key( $available_providers );
		} else {
			$provider = self::get_user_enabled_providers( $user );

			// If the provider specified isn't enabled, just grab the first one that is.
			if ( ! isset( $available_providers[ $provider ] ) ) {
				$provider = key( $available_providers );
			}
		}

		/**
		 * Filter the two-factor authentication provider used for this user.
		 *
		 * @param string $provider The provider currently being used.
		 * @param int $user_id The user ID.
		 */
		$provider = apply_filters( 'rsssl_two_factor_primary_provider_for_user', $provider, $user->ID );

		if ( isset( $providers[ $provider ] ) ) {
			return $providers[ $provider ];
		}

		return null;
	}

	/**
	 * Quick boolean check for whether a given user is using two-step.
	 *
	 * @param int|WP_User $user Optonal. User ID, or WP_User object of the the user. Defaults to current user.
	 *
	 * @return bool
	 * @since 0.1-dev
	 *
	 */
	public static function is_user_using_two_factor( $user = null ): bool {
		$provider = self::get_primary_provider_for_user( $user );
		$user     = self::fetch_user( $user );

		$enabled_providers_meta = self::get_user_enabled_providers( $user );
		// Initialize as empty arrays if they are empty
		$two_fa_forced_roles   = rsssl_get_option( 'two_fa_forced_roles' );
		$two_fa_optional_roles = rsssl_get_option( 'two_fa_optional_roles' );
		if ( empty( $two_fa_forced_roles ) ) {
			$two_fa_forced_roles = [];
		}
		if ( empty( $two_fa_optional_roles ) ) {
			$two_fa_optional_roles = [];
		}

		if ( 'active' === $enabled_providers_meta ) {
			return true;
		}

		if ( 'open' === $enabled_providers_meta ) {
			return true;
		}

		foreach ( $user->roles as $role ) {
			// If not forced, and not optional, or disabled, or provider not enabled
			if ( ! in_array( $role, $two_fa_forced_roles, true )
			     && ! in_array( $role, $two_fa_optional_roles, true )
			) {
				// Skip 2FA
				return false;
			}
		}

		return ! empty( $provider );
	}


	/**
	 * Handle the browser-based login.
	 *
	 * @param string $user_login Username.
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @since 0.1-dev
	 *
	 */
	public static function rsssl_wp_login( $user_login, $user ) {

		if ( ! self::is_user_using_two_factor( $user->ID ) ) {
			return;
		}

		$provider = self::get_user_enabled_providers( $user );

		// Initialize as empty array if it is empty
		$two_fa_optional_roles = rsssl_get_option( 'two_fa_optional_roles' );
		if ( empty( $two_fa_optional_roles ) ) {
			$two_fa_optional_roles = [];
		}

		// Disable login for users which have a required 2FA method but don't have it activated
		foreach ( $user->roles as $role ) {
			if ( 'open' !== $provider && ! in_array( $role, $two_fa_optional_roles, true ) ) {
				// Only invalidate and clear cookies if the user isn't allowed to skip
				self::destroy_current_session_for_user( $user );
				wp_clear_auth_cookie();
			}
		}

		self::show_two_factor_login( $user );
		exit;
	}


	/**
	 * Destroy the known password-based authentication sessions for the current user.
	 *
	 * Is there a better way of finding the current session token without
	 * having access to the authentication cookies which are just being set
	 * on the first password-based authentication request.
	 *
	 * @param \WP_User $user User object.
	 *
	 * @return void
	 */
	public static function destroy_current_session_for_user( $user ) {
		$session_manager = WP_Session_Tokens::get_instance( $user->ID );

		foreach ( self::$password_auth_tokens as $auth_token ) {
			$session_manager->destroy( $auth_token );
		}
	}

	/**
	 * Prevent login through XML-RPC and REST API for users with at least one
	 * two-factor method enabled.
	 *
	 * @param WP_User|WP_Error $user Valid WP_User only if the previous filters
	 *                                have verified and confirmed the
	 *                                authentication credentials.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function rsssl_filter_authenticate( $user ) {
		if ( $user instanceof WP_User && self::is_api_request() && self::is_user_using_two_factor( $user->ID ) && ! self::is_user_api_login_enabled( $user->ID ) ) {
			return new WP_Error(
				'invalid_application_credentials',
				__( 'API login for user disabled.', 'really-simple-ssl' )
			);
		}

		return $user;
	}

	/**
	 * Prevent login cookies being set on login for Two Factor users.
	 *
	 * This makes it so that Core never sends the auth cookies. `login_form_validate_2fa()` will send them manually once the 2nd factor has been verified.
	 *
	 * @param WP_User|WP_Error $user Valid WP_User only if the previous filters
	 *                                have verified and confirmed the
	 *                                authentication credentials.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function rsssl_filter_authenticate_block_cookies( $user ) {
		/*
		 * NOTE: The `login_init` action is checked for here to ensure we're within the regular login flow,
		 * rather than through an unsupported 3rd-party login process which this plugin doesn't support.
		 */
		if ( $user instanceof WP_User && self::is_user_using_two_factor( $user->ID ) && did_action( 'login_init' ) ) {
			add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		}

		return $user;
	}

	/**
	 * If the current user can login via API requests such as XML-RPC and REST.
	 *
	 * @param integer $user_id User ID.
	 *
	 * @return boolean
	 */
	public static function is_user_api_login_enabled( $user_id ) {
		return (bool) apply_filters( 'rsssl_two_factor_user_api_login_enable', false, $user_id );
	}

	/**
	 * Is the current request an XML-RPC or REST request.
	 *
	 * @return boolean
	 */
	public static function is_api_request() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return false;
	}

	/**
	 * Display the login form.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 *
	 * @since 0.1-dev
	 *
	 */
	public static function show_two_factor_login( $user ) {

		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		$login_nonce = self::create_login_nonce( $user->ID );
		if ( ! $login_nonce ) {
			wp_die( esc_html__( 'Failed to create a login nonce.', 'really-simple-ssl' ) );
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : admin_url();

		self::login_html( $user, $login_nonce['rsssl_key'], $redirect_to );
	}

	/**
	 * Displays a message informing the user that their account has had failed login attempts.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function maybe_show_last_login_failure_notice( $user ) {
		$last_failed_two_factor_login = (int) get_user_meta( $user->ID, self::RSSSL_USER_RATE_LIMIT_KEY, true );
		$failed_login_count           = (int) get_user_meta( $user->ID, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY, true );

		if ( $last_failed_two_factor_login ) {
			echo '<div id="login_notice" class="message"><strong>';
			printf(
			/*  translators: 1: number of failed login attempts, 2: time since last failed login */
				_n(
					'Warning: Your account has attempted to login without providing a valid two factor token. The last failed login occurred %2$s ago. If this wasn\'t you, you should reset your password.',
					'Warning: %1$s login attempts have been detected on your account with the correct password but with an incorrect two-factor token. The last failed login occurred %2$s ago. If this wasn\'t you, you should reset your password.',
					$failed_login_count,
					'really-simple-ssl'
				),
				number_format_i18n( $failed_login_count ),
				human_time_diff( $last_failed_two_factor_login, time() )
			);
			echo '</strong></div>';
		}
	}

	/**
	 * Show the password reset notice if the user's password was reset.
	 *
	 * They were also sent an email notification in `send_password_reset_email()`, but email sent from a typical
	 * web server is not reliable enough to trust completely.
	 *
	 * @param WP_Error $errors
	 */
	public static function rsssl_maybe_show_reset_password_notice( $errors ) {
		if ( 'incorrect_password' !== $errors->get_error_code() ) {
			return $errors;
		}

		if ( ! isset( $_POST['log'] ) ) {
			return $errors;
		}

		$user_name      = sanitize_user( wp_unslash( $_POST['log'] ) );
		$attempted_user = get_user_by( 'login', $user_name );
		if ( ! $attempted_user && str_contains( $user_name, '@' ) ) {
			$attempted_user = get_user_by( 'email', $user_name );
		}

		if ( ! $attempted_user ) {
			return $errors;
		}

		$password_was_reset = get_user_meta( $attempted_user->ID, self::RSSSL_USER_PASSWORD_WAS_RESET_KEY, true );

		if ( ! $password_was_reset ) {
			return $errors;
		}

		$errors->remove( 'incorrect_password' );
		$errors->add(
			'rsssl_two_factor_password_reset',
			sprintf(
			/* translators: %s: URL to reset password */
				__( 'Your password was reset because of too many failed Two Factor attempts. You will need to <a href="%s">create a new password</a> to regain access. Please check your email for more information.', 'really-simple-ssl' ),
				esc_url( add_query_arg( 'action', 'lostpassword', rsssl_wp_login_url() ) )
			)
		);

		return $errors;
	}

	/**
	 * Clear the password reset notice after the user resets their password.
	 *
	 * @param WP_User $user
	 */
	public static function rsssl_clear_password_reset_notice( $user ) {
		delete_user_meta( $user->ID, self::RSSSL_USER_PASSWORD_WAS_RESET_KEY );
	}

	/**
	 * Generates the html form for the second step of the authentication process.
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 * @param string $login_nonce A string nonce stored in usermeta.
	 * @param string $redirect_to The URL to which the user would like to be redirected.
	 * @param string $error_msg Optional. Login error message.
	 * @param string|object $provider An override to the provider.
	 *
	 * @since 0.1-dev
	 *
	 */
	public static function login_html( $user, $login_nonce, $redirect_to, $error_msg = '', $provider = null ) {
		if ( empty( $provider ) ) {
			$provider = self::get_primary_provider_for_user( $user->ID );
		} elseif ( is_string( $provider ) && method_exists( $provider, 'get_instance' ) ) {
			$provider = call_user_func( array( $provider, 'get_instance' ) );
		}

		$provider_class = get_class( $provider );

		$available_providers = self::get_available_providers_for_user( $user );
		$backup_providers    = array_diff_key( $available_providers, array( $provider_class => null ) );
		$interim_login       = isset( $_REQUEST['interim-login'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rememberme          = (int) self::rememberme();

		if ( ! function_exists( 'login_header' ) ) {
			// We really should migrate login_header() out of `wp-login.php` so it can be called from an includes file.
			include_once __DIR__ . '/function-login-header.php';
		}

		login_header();

		if ( ! empty( $error_msg ) ) {
			echo '<div id="login_error" class="notice notice-error"><strong>Error: </strong>' . esc_html( $error_msg ) . '<br /></div>';
		} else {
			self::maybe_show_last_login_failure_notice( $user );
		}
		?>

        <form name="rsssl_validate_2fa_form" id="loginform"
              action="<?php echo esc_url( self::login_url( array( 'action' => 'validate_2fa' ), 'login_post' ) ); ?>"
              method="post" autocomplete="off">
            <input type="hidden" name="provider" id="provider" value="<?php echo esc_attr( $provider_class ); ?>"/>
            <input type="hidden" name="rsssl-wp-auth-id" id="rsssl-wp-auth-id"
                   value="<?php echo esc_attr( $user->ID ); ?>"/>
            <input type="hidden" name="rsssl-wp-auth-nonce" id="rsssl-wp-auth-nonce"
                   value="<?php echo esc_attr( $login_nonce ); ?>"/>
			<?php if ( $interim_login ) { ?>
                <input type="hidden" name="interim-login" value="1"/>
			<?php } else { ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>"/>
			<?php } ?>
            <input type="hidden" name="rememberme" id="rememberme" value="<?php echo esc_attr( $rememberme ); ?>"/>

			<?php $provider->authentication_page( $user ); ?>
        </form>

		<?php
		if ( 1 === count( $backup_providers ) ) :
			$backup_classname = key( $backup_providers );
			$backup_provider = $backup_providers[ $backup_classname ];
			$login_url       = self::login_url(
				array(
					'action'              => 'rsssl_validate_2fa',
					'rsssl-provider'      => $backup_classname,
					'rsssl-wp-auth-id'    => $user->ID,
					'rsssl-wp-auth-nonce' => $login_nonce,
					'redirect_to'         => $redirect_to,
					'rememberme'          => $rememberme,
				)
			);
			?>
            <div class="backup-methods-wrap">
                <p class="backup-methods">
                    <a href="<?php echo esc_url( $login_url ); ?>">
						<?php
						echo esc_html(
							sprintf(
							// translators: %s: Two-factor method name.
								__( 'Or, use your backup method: %s &rarr;', 'really-simple-ssl' ),
								$backup_provider->get_label()
							)
						);
						?>
                    </a>
                </p>
            </div>
		<?php elseif ( 1 < count( $backup_providers ) ) : ?>
            <div class="backup-methods-wrap">
                <p class="backup-methods">
                    <a href="javascript:;"
                       onclick="document.querySelector('ul.backup-methods').style.display = 'block';">
						<?php esc_html_e( 'Or, use a backup method…', 'really-simple-ssl' ); ?>
                    </a>
                </p>
                <ul class="backup-methods">
					<?php
					foreach ( $backup_providers as $backup_classname => $backup_provider ) :
						$login_url = self::login_url(
							array(
								'action'              => 'rsssl_validate_2fa',
								'rsssl-provider'      => $backup_classname,
								'rsssl-wp-auth-id'    => $user->ID,
								'rsssl-wp-auth-nonce' => $login_nonce,
								'redirect_to'         => $redirect_to,
								'rememberme'          => $rememberme,
							)
						);
						?>
                        <li>
                            <a href="<?php echo esc_url( $login_url ); ?>">
								<?php echo esc_html( $backup_provider->get_label() ); ?>
                            </a>
                        </li>
					<?php endforeach; ?>
                </ul>
            </div>
		<?php endif; ?>
        <style>
            /* @todo: migrate to an external stylesheet. */
            .backup-methods-wrap {
                margin-top: 16px;
                padding: 0 24px;
            }

            .backup-methods-wrap a {
                color: #999;
                text-decoration: none;
            }

            ul.backup-methods {
                display: none;
                padding-left: 1.5em;
            }

            /* Prevent Jetpack from hiding our controls, see https://github.com/Automattic/jetpack/issues/3747 */
            .jetpack-sso-form-display #loginform > p,
            .jetpack-sso-form-display #loginform > div {
                display: block;
            }

            #login form p.two-factor-prompt {
                margin-bottom: 1em;
            }

            .input.rsssl-authcode {
                letter-spacing: .3em;
            }

            .input.rsssl-authcode::placeholder {
                opacity: 0.5;
            }
        </style>
        <script>
            (function () {
                // Enforce numeric-only input for numeric inputmode elements.
                const form = document.querySelector('#loginform'),
                    inputEl = document.querySelector('input.rsssl-authcode[inputmode="numeric"]'),
                    expectedLength = inputEl?.dataset.digits || 0;

                if (inputEl) {
                    let spaceInserted = false;
                    inputEl.addEventListener(
                        'input',
                        function () {
                            let value = this.value.replace(/[^0-9 ]/g, '').trimStart();

                            if (!spaceInserted && expectedLength && value.length === Math.floor(expectedLength / 2)) {
                                value += ' ';
                                spaceInserted = true;
                            } else if (spaceInserted && !this.value) {
                                spaceInserted = false;
                            }

                            this.value = value;

                            // Auto-submit if it's the expected length.
                            if (expectedLength && value.replace(/ /g, '').length == expectedLength) {
                                if (undefined !== form.requestSubmit) {
                                    form.requestSubmit();
                                    form.submit.disabled = "disabled";
                                }
                            }
                        }
                    );
                }
            })();
        </script>
		<?php
		if ( ! function_exists( 'login_footer' ) ) {
			include_once __DIR__ . '/function-login-footer.php';
		}

		login_footer();
		?>
		<?php
	}

	/**
	 * Generate the two-factor login form URL.
	 *
	 * @param array $params List of query argument pairs to add to the URL.
	 * @param string $scheme URL scheme context.
	 *
	 * @return string
	 */
	public static function login_url( $params = array(), $scheme = 'login' ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$params = urlencode_deep( $params );

		return add_query_arg( $params, site_url( 'wp-login.php', $scheme ) );
	}

	/**
	 * Get the hash of a nonce for storage and comparison.
	 *
	 * @param array $nonce Nonce array to be hashed. ⚠️ This must contain user ID and expiration,
	 *                     to guarantee the nonce only works for the intended user during the
	 *                     intended time window.
	 *
	 * @return string|false
	 */
	protected static function hash_login_nonce( $nonce ) {
		$message = wp_json_encode( $nonce );

		if ( ! $message ) {
			return false;
		}

		return wp_hash( $message, 'nonce' );
	}

	/**
	 * Create the login nonce.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array|false
	 * @since 0.1-dev
	 *
	 */
	public static function create_login_nonce( $user_id ) {
		$login_nonce = array(
			'rsssl_user_id'    => $user_id,
			'rsssl_expiration' => time() + ( 15 * MINUTE_IN_SECONDS ),
		);

		try {
			$login_nonce['rsssl_key'] = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $ex ) {
			$login_nonce['rsssl_key'] = wp_hash( $user_id . wp_rand() . microtime(), 'nonce' );
		}

		// Store the nonce hashed to avoid leaking it via database access.
		$hashed_key = self::hash_login_nonce( $login_nonce );

		if ( $hashed_key ) {
			$login_nonce_stored = array(
				'rsssl_expiration' => $login_nonce['rsssl_expiration'],
				'rsssl_key'        => $hashed_key,
			);

			if ( update_user_meta( $user_id, self::RSSSL_USER_META_NONCE_KEY, $login_nonce_stored ) ) {
				return $login_nonce;
			}
		}

		return false;
	}

	/**
	 * Delete the login nonce.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 * @since 0.1-dev
	 *
	 */
	public static function delete_login_nonce( $user_id ) {
		return delete_user_meta( $user_id, self::RSSSL_USER_META_NONCE_KEY );
	}

	/**
	 * Verify the login nonce.
	 *
	 * @param int $user_id User ID.
	 * @param string $nonce Login nonce.
	 *
	 * @return bool
	 * @since 0.1-dev
	 *
	 */
	public static function verify_login_nonce( $user_id, $nonce ) {
		$login_nonce = get_user_meta( $user_id, self::RSSSL_USER_META_NONCE_KEY, true );

		if ( ! $login_nonce || empty( $login_nonce['rsssl_key'] ) || empty( $login_nonce['rsssl_expiration'] ) ) {
			return false;
		}

		$unverified_nonce = array(
			'rsssl_user_id'    => $user_id,
			'rsssl_expiration' => $login_nonce['rsssl_expiration'],
			'rsssl_key'        => $nonce,
		);

		$unverified_hash = self::hash_login_nonce( $unverified_nonce );
		$hashes_match    = $unverified_hash && hash_equals( $login_nonce['rsssl_key'], $unverified_hash );

		if ( $hashes_match && time() < $login_nonce['rsssl_expiration'] ) {
			return true;
		}

		// Require a fresh nonce if verification fails.
		self::delete_login_nonce( $user_id );

		return false;
	}

	/**
	 * Determine the minimum wait between two factor attempts for a user.
	 *
	 * This implements an increasing backoff, requiring an attacker to wait longer
	 * each time to attempt to brute-force the login.
	 *
	 * @param WP_User $user The user being operated upon.
	 *
	 * @return int Time delay in seconds between login attempts.
	 */
	public static function get_user_time_delay( $user ) {
		/**
		 * Filter the minimum time duration between two factor attempts.
		 *
		 * @param int $rate_limit The number of seconds between two factor attempts.
		 */
		$rate_limit = apply_filters( 'rsssl_two_factor_rate_limit', 1 );

		$user_failed_logins = get_user_meta( $user->ID, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY, true );
		if ( $user_failed_logins ) {
			$rate_limit = pow( 2, $user_failed_logins ) * $rate_limit;

			/**
			 * Filter the maximum time duration a user may be locked out from retrying two factor authentications.
			 *
			 * @param int $max_rate_limit The maximum number of seconds a user might be locked out for. Default 15 minutes.
			 */
			$max_rate_limit = apply_filters( 'rsssl_two_factor_max_rate_limit', 15 * MINUTE_IN_SECONDS );

			$rate_limit = min( $max_rate_limit, $rate_limit );
		}

		/**
		 * Filters the per-user time duration between two factor login attempts.
		 *
		 * @param int $rate_limit The number of seconds between two factor attempts.
		 * @param WP_User $user The user attempting to login.
		 */
		return apply_filters( 'rsssl_two_factor_user_rate_limit', $rate_limit, $user );
	}

	/**
	 * Determine if a time delay between user two factor login attempts should be triggered.
	 *
	 * @param WP_User $user The User.
	 *
	 * @return bool True if rate limit is okay, false if not.
	 * @since 0.8.0
	 *
	 */
	public static function is_user_rate_limited( $user ) {
		$rate_limit  = self::get_user_time_delay( $user );
		$last_failed = get_user_meta( $user->ID, self::RSSSL_USER_RATE_LIMIT_KEY, true );

		$rate_limited = false;
		if ( $last_failed && $last_failed + $rate_limit > time() ) {
			$rate_limited = true;
		}

		/**
		 * Filter whether this login attempt is rate limited or not.
		 *
		 * This allows for dedicated plugins to rate limit two factor login attempts
		 * based on their own rules.
		 *
		 * @param bool $rate_limited Whether the user login is rate limited.
		 * @param WP_User $user The user attempting to login.
		 */
		return apply_filters( 'rsssl_two_factor_is_user_rate_limited', $rate_limited, $user );
	}

	/**
	 * Login form validation.
	 *
	 * @since 0.1-dev
	 */
	public static function rsssl_login_form_validate_2fa() {
		$wp_auth_id = ! empty( $_REQUEST['rsssl-wp-auth-id'] ) ? absint( $_REQUEST['rsssl-wp-auth-id'] ) : 0;
		$nonce      = ! empty( $_REQUEST['rsssl-wp-auth-nonce'] ) ? wp_unslash( $_REQUEST['rsssl-wp-auth-nonce'] ) : '';
		$provider   = ! empty( $_REQUEST['rsssl-provider'] ) ? wp_unslash( $_REQUEST['rsssl-provider'] ) : false;

		$is_post_request = ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ) );

		if ( ! $wp_auth_id || ! $nonce ) {
			return;
		}

		$user = get_userdata( $wp_auth_id );
		if ( ! $user ) {
			return;
		}

		if ( $provider ) {
			$providers = self::get_available_providers_for_user( $user );
			if ( isset( $providers[ $provider ] ) ) {
				$provider = $providers[ $provider ];
			} else {
				wp_die( esc_html__( 'Cheatin&#8217; uh?', 'really-simple-ssl' ), 403 );
			}
		} else {
			$provider = self::get_primary_provider_for_user( $user->ID );
		}

		if ( $provider->user_token_has_expired( $user->ID ) ) {
			self::login_html( $user, '', '', esc_html__( 'Your verification code expired, click “Resend Code” to receive a new verification code.', 'really-simple-ssl' ), $provider );
			exit;
		}

		if ( true !== self::verify_login_nonce( $user->ID, $nonce ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Allow the provider to re-send codes, etc.
		if ( true === $provider->pre_process_authentication( $user ) ) {
			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'really-simple-ssl' ) );
			}

			self::login_html( $user, $login_nonce['rsssl_key'], $_REQUEST['redirect_to'], '', $provider );
			exit;
		}

		// If the form hasn't been submitted, just display the auth form.
		if ( ! $is_post_request ) {
			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'really-simple-ssl' ) );
			}

			self::login_html( $user, $login_nonce['rsssl_key'], $_REQUEST['redirect_to'], '', $provider );
			exit;
		}

		// Rate limit two factor authentication attempts.
		if ( true === self::is_user_rate_limited( $user ) ) {
			$time_delay = self::get_user_time_delay( $user );
			$last_login = get_user_meta( $user->ID, self::RSSSL_USER_RATE_LIMIT_KEY, true );

			$error = new WP_Error(
				'rsssl_two_factor_too_fast',
				sprintf(
				/* translators: %s: time delay between login attempts */
					__( 'Too many invalid verification codes, you can try again in %s. This limit protects your account against automated attacks.', 'really-simple-ssl' ),
					human_time_diff( $last_login + $time_delay )
				)
			);

			do_action( 'rsssl_wp_login_failed', $user->user_login, $error );//phpcs:ignore

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'really-simple-ssl' ) );
			}

			self::login_html( $user, $login_nonce['rsssl_key'], $_REQUEST['redirect_to'], esc_html( $error->get_error_message() ), $provider );
			exit;
		}

		// Ask the provider to verify the second factor.
		if ( true !== $provider->validate_authentication( $user ) ) {
			do_action( 'rsssl_wp_login_failed', $user->user_login, new WP_Error( 'rsssl_two_factor_invalid', __( 'Invalid verification code.', 'really-simple-ssl' ) ) );//phpcs:ignore

			// Store the last time a failed login occurred.
			update_user_meta( $user->ID, self::RSSSL_USER_RATE_LIMIT_KEY, time() );

			// Store the number of failed login attempts.
			update_user_meta( $user->ID, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY, 1 + (int) get_user_meta( $user->ID, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );

			if ( self::should_reset_password( $user->ID ) ) {
				self::reset_compromised_password( $user );
				self::send_password_reset_emails( $user );
				self::show_password_reset_error();
				exit;
			}

			$login_nonce = self::create_login_nonce( $user->ID );
			if ( ! $login_nonce ) {
				wp_die( esc_html__( 'Failed to create a login nonce.', 'really-simple-ssl' ) );
			}

			if ( $provider->user_token_has_expired( $user->ID ) ) {
				self::login_html( $user, $login_nonce['rsssl_key'], $_REQUEST['redirect_to'], esc_html__( 'Your verification code expired, click “Resend Code” to receive a new verification code.', 'really-simple-ssl' ), $provider );
				exit;
			} else {
				self::login_html( $user, $login_nonce['rsssl_key'], $_REQUEST['redirect_to'], esc_html__( 'Invalid verification code.', 'really-simple-ssl' ), $provider );
				exit;
			}
		}

		self::delete_login_nonce( $user->ID );
		delete_user_meta( $user->ID, self::RSSSL_USER_RATE_LIMIT_KEY );
		delete_user_meta( $user->ID, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY );

		$rememberme = false;
		if ( isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'] ) {
			$rememberme = true;
		}

		/*
		 * NOTE: This filter removal is not normally required, this is included for protection against
		 * a plugin/two factor provider which runs the `authenticate` filter during it's validation.
		 * Such a plugin would cause self::rsssl_filter_authenticate_block_cookies() to run and add this filter.
		 */
		remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		wp_set_auth_cookie( $user->ID, $rememberme );

		do_action( 'rsssl_two_factor_user_authenticated', $user );

		// Must be global because that's how login_header() uses it.
		global $interim_login;
		$interim_login = isset( $_REQUEST['interim-login'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.Security.NonceVerification.Recommended

		if ( $interim_login ) {
			$customize_login = isset( $_REQUEST['customize-login'] );
			if ( $customize_login ) {
				wp_enqueue_script( 'customize-base' );
			}
			$message       = '<p class="message">' . __( 'You have logged in successfully.', 'really-simple-ssl' ) . '</p>';
			$interim_login = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			login_header( '', $message );
			?>
            </div>
			<?php
			/** This action is documented in wp-login.php */
			do_action( 'login_footer' );//phpcs:ignore
			?>
			<?php if ( $customize_login ) : ?>
                <script type="text/javascript">setTimeout(function () {
                        new wp.customize.Messenger({
                            url: '<?php echo esc_url( wp_customize_url() ); ?>',
                            channel: 'login'
                        }).send('login')
                    }, 1000);</script>
			<?php endif; ?>
            </body></html>
			<?php
			exit;
		}
		$redirect_to = apply_filters( 'login_redirect', $_REQUEST['redirect_to'], $_REQUEST['redirect_to'], $user );//phpcs:ignore
		wp_safe_redirect( $redirect_to );

		exit;
	}

	/**
	 * Determine if the user's password should be reset.
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public static function should_reset_password( $user_id ) {
		$failed_attempts = (int) get_user_meta( $user_id, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY, true );

		/**
		 * Filters the maximum number of failed attempts on a 2nd factor before the user's
		 * password will be reset. After a reasonable number of attempts, it's safe to assume
		 * that the password has been compromised and an attacker is trying to brute force the 2nd
		 * factor.
		 *
		 * ⚠️ `get_user_time_delay()` mitigates brute force attempts, but many 2nd factors --
		 * like TOTP and backup codes -- are very weak on their own, so it's not safe to give
		 * attackers unlimited attempts. Setting this to a very large number is strongly
		 * discouraged.
		 *
		 * @param int $limit The number of attempts before the password is reset.
		 */
		$failed_attempt_limit = apply_filters( 'rsssl_two_factor_failed_attempt_limit', 30 );

		return $failed_attempts >= $failed_attempt_limit;
	}

	/**
	 * Reset a compromised password.
	 *
	 * If we know that the the password is compromised, we have the responsibility to reset it and inform the
	 * user. `get_user_time_delay()` mitigates brute force attempts, but this acts as an extra layer of defense
	 * which guarantees that attackers can't brute force it (unless they compromise the new password).
	 *
	 * @param WP_User $user The user who failed to login
	 */
	public static function reset_compromised_password( $user ) {
		// Unhook because `wp_password_change_notification()` wouldn't notify the site admin when
		// their password is compromised.
		remove_action( 'after_password_reset', 'wp_password_change_notification' );
		reset_password( $user, wp_generate_password( 25 ) );
		update_user_meta( $user->ID, self::RSSSL_USER_PASSWORD_WAS_RESET_KEY, true );
		add_action( 'after_password_reset', 'wp_password_change_notification' );

		self::delete_login_nonce( $user->ID );
		delete_user_meta( $user->ID, self::RSSSL_USER_RATE_LIMIT_KEY );
		delete_user_meta( $user->ID, self::RSSSL_USER_FAILED_LOGIN_ATTEMPTS_KEY );
	}

	/**
	 * Notify the user and admin that a password was reset for being compromised.
	 *
	 * @param WP_User $user The user whose password should be reset
	 */
	public static function send_password_reset_emails( $user ) {
		self::notify_user_password_reset( $user );

		/**
		 * Filters whether or not to email the site admin when a user's password has been
		 * compromised and reset.
		 *
		 * @param bool $reset `true` to notify the admin, `false` to not notify them.
		 */
		$notify_admin = apply_filters( 'rsssl_two_factor_notify_admin_user_password_reset', true );
		$admin_email  = get_option( 'admin_email' );

		if ( $notify_admin && $admin_email !== $user->user_email ) {
			self::notify_admin_user_password_reset( $user );
		}
	}

	/**
	 * Notify the user that their password has been compromised and reset.
	 *
	 * @param WP_User $user The user to notify
	 *
	 * @return void
	 */
	public static function notify_user_password_reset( $user ) {
		$message = sprintf(
		/* translators: %1$s: user login, %2$s: site url, %3$s: password best practices link, %4$s: lost password url */
			__(
				'Hello %1$s, an unusually high number of failed login attempts have been detected on your account at %2$s.

These attempts successfully entered your password, and were only blocked because they failed to enter your second authentication factor. Despite not being able to access your account, this behavior indicates that the attackers have compromised your password. The most common reasons for this are that your password was easy to guess, or was reused on another site which has been compromised.

To protect your account, your password has been reset, and you will need to create a new one. For advice on setting a strong password, please read %3$s

To pick a new password, please visit %4$s

This is an automated notification. If you would like to speak to a site administrator, please contact them directly.',
				'really-simple-ssl'
			),
			esc_html( $user->user_login ),
			home_url(),
			'https://wordpress.org/documentation/article/password-best-practices/',
			esc_url( add_query_arg( 'action', 'lostpassword', rsssl_wp_login_url() ) )
		);
		$message = str_replace( "\t", '', $message );
		if ( ! class_exists( 'rsssl_mailer' ) ) {
			require_once rsssl_path . 'mailer/class-mail.php';
		}
		$mailer          = new rsssl_mailer();
		$mailer->subject = __( 'Your password was compromised and has been reset', 'really-simple-ssl' );
		$mailer->branded = false;
		/* translators: %s: site url */
		$mailer->sent_by_text      = sprintf( __( 'Notification by %s', 'really-simple-ssl' ), site_url() );
		$mailer->template_filename = apply_filters( 'rsssl_email_template', rsssl_path . '/mailer/templates/email-unbranded.html' );
		$mailer->to                = $user->user_email;
		$mailer->title             = __( 'Hi', 'really-simple-ssl' ) . ' ' . $user->display_name . ',';
		$mailer->message           = $message;
		$mailer->send_mail();
	}

	/**
	 * Notify the admin that a user's password was compromised and reset.
	 *
	 * @param WP_User $user The user whose password was reset.
	 *
	 * @return void
	 */
	public static function notify_admin_user_password_reset( $user ) {
		$subject = sprintf(
		/* translators: %s: user login */
			__( 'Compromised password for %s has been reset', 'really-simple-ssl' ),
			esc_html( $user->user_login )
		);

		$message = sprintf(
		/* translators: %1$s: user login, %2$d: user id, %3$s: hooks documentation url */
			__(
				'Hello, this is a notice from your website to inform you that an unusually high number of failed login attempts have been detected on the %1$s account (ID %2$d).

Those attempts successfully entered the user\'s password, and were only blocked because they entered invalid second authentication factors.

To protect their account, the password has automatically been reset, and they have been notified that they will need to create a new one.

If you do not wish to receive these notifications, you can disable them with the `two_factor_notify_admin_user_password_reset` filter. See %3$s for more information.

Thank you',
				'really-simple-ssl'
			),
			esc_html( $user->user_login ),
			$user->ID,
			'https://developer.wordpress.org/plugins/hooks/'
		);
		$message = str_replace( "\t", '', $message );
		if ( ! class_exists( 'rsssl_mailer' ) ) {
			require_once rsssl_path . 'mailer/class-mail.php';
		}
		$mailer          = new rsssl_mailer();
		$mailer->subject = $subject;
		$mailer->branded = false;
		/* translators: %s: site url */
		$mailer->sent_by_text      = sprintf( __( 'Notification by %s', 'really-simple-ssl' ), site_url() );
		$mailer->template_filename = apply_filters( 'rsssl_email_template', rsssl_path . '/mailer/templates/email-unbranded.html' );
		$mailer->to                = $user->user_email;
		$mailer->title             = __( 'Compromised password reset', 'really-simple-ssl' );
		$mailer->message           = $message;
		$mailer->send_mail();
	}

	/**
	 * Show the password reset error when on the login screen.
	 */
	public static function show_password_reset_error() {
		$error = new WP_Error(
			'too_many_attempts',
			sprintf(
				'<p>%s</p>
				<p style="margin-top: 1em;">%s</p>',
				__( 'There have been too many failed two-factor authentication attempts, which often indicates that the password has been compromised. The password has been reset in order to protect the account.', 'really-simple-ssl' ),
				__( 'If you are the owner of this account, please check your email for instructions on regaining access.', 'really-simple-ssl' )
			)
		);

		login_header( __( 'Password Reset', 'really-simple-ssl' ), '', $error );
		login_footer();
	}

	/**
	 * Should the login session persist between sessions.
	 *
	 * @return boolean
	 */
	public static function rememberme() {
		$rememberme = false;

		if ( ! empty( $_REQUEST['rememberme'] ) ) {
			$rememberme = true;
		}

		return (bool) apply_filters( 'rsssl_two_factor_rememberme', $rememberme );
	}
}

new Rsssl_Two_Factor();
Rsssl_Two_Factor::add_hooks( $rsssl_two_factor_compat );