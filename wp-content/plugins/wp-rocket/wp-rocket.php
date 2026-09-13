<?php
/**
 * Plugin Name: WP Rocket
 * Plugin URI: https://wp-rocket.me
 * Description: The best WordPress performance plugin.
 * Version: 3.20.0.1
 * Requires at least: 5.8
 * Requires PHP: 7.3
 * Code Name: Iego
 * Author: WP Media
 * Author URI: https://wp-media.me
 * Licence: GPLv2 or later
 *
 * Text Domain: rocket
 * Domain Path: /languages
 *
 * Copyright 2013-2025 WP Rocket
 */

defined( 'ABSPATH' ) || exit;
load_plugin_textdomain('rocket', false, basename( dirname( __FILE__ ) ) . '/languages' );
// Rocket defines.
define( 'WP_ROCKET_VERSION',               '3.20.0.1' );
define( 'WP_ROCKET_WP_VERSION',            '5.8' );
define( 'WP_ROCKET_WP_VERSION_TESTED',     '6.3.1' );
define( 'WP_ROCKET_PHP_VERSION',           '7.3' );
define( 'WP_ROCKET_PRIVATE_KEY',           false );
define( 'WP_ROCKET_SLUG',                  'wp_rocket_settings' );
define( 'WP_ROCKET_WEB_MAIN',              'https://wp-rocket.me/' );
define( 'WP_ROCKET_WEB_API',               WP_ROCKET_WEB_MAIN . 'api/wp-rocket/' ); // only used in deprecated code.
define( 'WP_ROCKET_WEB_CHECK',             WP_ROCKET_WEB_MAIN . 'check_update.php' ); // only used in deprecated code.
define( 'WP_ROCKET_WEB_VALID',             WP_ROCKET_WEB_MAIN . 'valid_key.php' ); // only used in deprecated code.
define( 'WP_ROCKET_WEB_INFO',              WP_ROCKET_WEB_MAIN . 'plugin_information.php' ); // only used in deprecated code.
define( 'WP_ROCKET_FILE',                  __FILE__ );
define( 'WP_ROCKET_PATH',                  realpath( plugin_dir_path( WP_ROCKET_FILE ) ) . '/' );
define( 'WP_ROCKET_INC_PATH',              realpath( WP_ROCKET_PATH . 'inc/' ) . '/' );

require_once WP_ROCKET_INC_PATH . 'constants.php';

define( 'WP_ROCKET_DEPRECATED_PATH',       realpath( WP_ROCKET_INC_PATH . 'deprecated/' ) . '/' );
define( 'WP_ROCKET_FRONT_PATH',            realpath( WP_ROCKET_INC_PATH . 'front/' ) . '/' );
define( 'WP_ROCKET_ADMIN_PATH',            realpath( WP_ROCKET_INC_PATH . 'admin' ) . '/' );
define( 'WP_ROCKET_ADMIN_UI_PATH',         realpath( WP_ROCKET_ADMIN_PATH . 'ui' ) . '/' );
define( 'WP_ROCKET_ADMIN_UI_MODULES_PATH', realpath( WP_ROCKET_ADMIN_UI_PATH . 'modules' ) . '/' );
define( 'WP_ROCKET_COMMON_PATH',           realpath( WP_ROCKET_INC_PATH . 'common' ) . '/' );
define( 'WP_ROCKET_FUNCTIONS_PATH',        realpath( WP_ROCKET_INC_PATH . 'functions' ) . '/' );
define( 'WP_ROCKET_VENDORS_PATH',          realpath( WP_ROCKET_INC_PATH . 'vendors' ) . '/' );
define( 'WP_ROCKET_3RD_PARTY_PATH',        realpath( WP_ROCKET_INC_PATH . '3rd-party' ) . '/' );
if ( ! defined( 'WP_ROCKET_CONFIG_PATH' ) ) {
	define( 'WP_ROCKET_CONFIG_PATH',       WP_CONTENT_DIR . '/wp-rocket-config/' );
}
define( 'WP_ROCKET_URL',                   plugin_dir_url( WP_ROCKET_FILE ) );
define( 'WP_ROCKET_INC_URL',               WP_ROCKET_URL . 'inc/' );
define( 'WP_ROCKET_ADMIN_URL',             WP_ROCKET_INC_URL . 'admin/' );
define( 'WP_ROCKET_ASSETS_URL',            WP_ROCKET_URL . 'assets/' );
define( 'WP_ROCKET_ASSETS_PATH',            WP_ROCKET_PATH . 'assets/' );
define( 'WP_ROCKET_ASSETS_JS_URL',         WP_ROCKET_ASSETS_URL . 'js/' );
define( 'WP_ROCKET_ASSETS_JS_PATH',         WP_ROCKET_ASSETS_PATH . 'js/' );
define( 'WP_ROCKET_ASSETS_CSS_URL',        WP_ROCKET_ASSETS_URL . 'css/' );
define( 'WP_ROCKET_ASSETS_IMG_URL',        WP_ROCKET_ASSETS_URL . 'img/' );

if ( ! defined( 'WP_ROCKET_CACHE_ROOT_PATH' ) ) {
	define( 'WP_ROCKET_CACHE_ROOT_PATH', WP_CONTENT_DIR . '/cache/' );
}
define( 'WP_ROCKET_CACHE_PATH',         WP_ROCKET_CACHE_ROOT_PATH . 'wp-rocket/' );
define( 'WP_ROCKET_MINIFY_CACHE_PATH',  WP_ROCKET_CACHE_ROOT_PATH . 'min/' );
define( 'WP_ROCKET_CACHE_BUSTING_PATH', WP_ROCKET_CACHE_ROOT_PATH . 'busting/' );
define( 'WP_ROCKET_CRITICAL_CSS_PATH',  WP_ROCKET_CACHE_ROOT_PATH . 'critical-css/' );

define( 'WP_ROCKET_USED_CSS_PATH',  WP_ROCKET_CACHE_ROOT_PATH . 'used-css/' );

if ( ! defined( 'WP_ROCKET_CACHE_ROOT_URL' ) ) {
	define( 'WP_ROCKET_CACHE_ROOT_URL', WP_CONTENT_URL . '/cache/' );
}
define( 'WP_ROCKET_CACHE_URL',         WP_ROCKET_CACHE_ROOT_URL . 'wp-rocket/' );
define( 'WP_ROCKET_MINIFY_CACHE_URL',  WP_ROCKET_CACHE_ROOT_URL . 'min/' );
define( 'WP_ROCKET_CACHE_BUSTING_URL', WP_ROCKET_CACHE_ROOT_URL . 'busting/' );

define( 'WP_ROCKET_USED_CSS_URL', WP_ROCKET_CACHE_ROOT_URL . 'used-css/' );

if ( ! defined( 'CHMOD_WP_ROCKET_CACHE_DIRS' ) ) {
	define( 'CHMOD_WP_ROCKET_CACHE_DIRS', 0755 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
}
if ( ! defined( 'WP_ROCKET_LASTVERSION' ) ) {
	define( 'WP_ROCKET_LASTVERSION', '3.19.4' );
}

/**
 * We use is_readable() with @ silencing as WP_Filesystem() can use different methods to access the filesystem.
 *
 * This is more performant and more compatible. It allows us to work around file permissions and missing credentials.
 */
if ( @is_readable( WP_ROCKET_PATH . 'licence-data.php' ) ) { //phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@include WP_ROCKET_PATH . 'licence-data.php'; //phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

require WP_ROCKET_INC_PATH . 'compat.php';
require WP_ROCKET_INC_PATH . 'classes/class-wp-rocket-requirements-check.php';


add_filter('pre_http_request', function($response, $args, $url) {
	if (strpos($url, 'api.wp-rocket.me/valid_key.php') !== false || strpos($url, 'wp-rocket.me/valid_key.php') !== false) {
		$key = 'B5E0B5F8DD8689E6ACA49DD6E6E1A930';
		$email = 'noreply@gmail.com';
		return array(
			'response' => array('code' => 200),
			'body' => json_encode(array(
				'success' => true,
				'data' => array(
					'consumer_key' => substr($key, 0, 8),
					'consumer_email' => $email,
					'secret_key' => hash('crc32', $email)
				)
			))
		);
	}
	if (strpos($url, 'api.wp-rocket.me/stat/1.0/wp-rocket/user.php') !== false) {
		return array(
			'response' => array('code' => 200),
			'body' => json_encode(array(
				'licence_account' => -1,
				'licence_expiration' => time() + (50 * YEAR_IN_SECONDS),
				'licence' => array('name' => 'Infinite'),
				'status' => 'valid',
				'has_auto_renew' => true,
				'date_created' => time() - (30 * DAY_IN_SECONDS)
			))
		);
	}
	if (strpos($url, 'api.wp-rocket.me/check_update.php') !== false || strpos($url, 'wp-rocket.me/check_update.php') !== false) {
		return array(
			'response' => array('code' => 200),
			'body' => json_encode(array(
				'version' => WP_ROCKET_VERSION,
				'details_url' => '',
				'download_url' => ''
			))
		);
	}
	return $response;
}, 10, 3);

add_action('init', function() {
	$key = 'B5E0B5F8DD8689E6ACA49DD6E6E1A930';
	$email = 'noreply@gmail.com';
	$options = get_transient('wp_rocket_settings');
	if ($options && isset($options['license']) && '1' === $options['license']) {
		$options['license'] = time();
		$options['ignore'] = true;
		set_transient('wp_rocket_settings', $options, YEAR_IN_SECONDS);
	}
	if (!$options || empty($options['secret_key'])) {
		$options = array(
			'consumer_key' => substr($key, 0, 8),
			'consumer_email' => $email,
			'secret_key' => hash('crc32', $email),
			'license' => time(),
			'ignore' => true
		);
		set_transient('wp_rocket_settings', $options, YEAR_IN_SECONDS);
	}
	update_option('wp_rocket_no_licence', 0);
	$customer_data = (object) array(
		'licence_account' => -1,
		'licence_expiration' => time() + (50 * YEAR_IN_SECONDS),
		'licence' => (object) array('name' => 'Infinite'),
		'status' => 'valid',
		'has_auto_renew' => true,
		'date_created' => time() - (30 * DAY_IN_SECONDS),
		'performance_monitoring' => (object) array(
			'expiration' => time() + (50 * YEAR_IN_SECONDS),
			'cancelled_at' => null,
			'manage_url' => null,
			'active_sku' => 'perf-monitor-advanced',
			'plans' => array(
				(object) array(
					'sku' => 'perf-monitor-advanced',
					'price' => '8.99',
					'limit' => '10',
					'title' => 'Advanced',
					'subtitle' => 'See how your top pages perform and quickly spot and optimize what slows your site down.',
					'description' => 'Up to 10 pages • Weekly updates',
					'billing' => '* Billed monthly. You can cancel at any time, each month started is due.',
					'highlights' => array(
						'Up to 10 pages tracked',
						'Automatic performance monitoring',
						'Unlimited on-demand tests',
						'Full GTmetrix performance reports'
					),
					'status' => 'active',
					'button' => (object) array(
						'label' => 'Your plan',
						'action' => 'none',
						'url' => null
					)
				)
			)
		)
	);
	set_transient('wp_rocket_customer_data', $customer_data, DAY_IN_SECONDS);
});

/**
 * Loads WP Rocket translations
 *
 * @since 3.0
 * @author Remy Perona
 *
 * @return void
 */
function rocket_load_textdomain() {
	load_plugin_textdomain( 'rocket', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'rocket_load_textdomain' );

$wp_rocket_requirement_checks = new WP_Rocket_Requirements_Check(
	[
		'plugin_name'         => 'WP Rocket',
		'plugin_file'         => WP_ROCKET_FILE,
		'plugin_version'      => WP_ROCKET_VERSION,
		'plugin_last_version' => WP_ROCKET_LASTVERSION,
		'wp_version'          => WP_ROCKET_WP_VERSION,
		'php_version'         => WP_ROCKET_PHP_VERSION,
	]
);

if ( $wp_rocket_requirement_checks->check() ) {
	require WP_ROCKET_INC_PATH . 'main.php';
}

unset( $wp_rocket_requirement_checks );
if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly



if ( ! class_exists( 'Code8_Admin_Notices' ) ) {

	class Code8_Admin_Notices {

		private static $_instance;
		private $admin_notices;
		private $slug;
		const TYPES = 'error,warning,info,success';

		private function __construct($slug) {
			$this->admin_notices = new stdClass();
			$this->slug = $slug;
			foreach ( explode( ',', self::TYPES ) as $type ) {
				$this->admin_notices->{$type} = array();
			}
			add_action( 'admin_init', array( &$this, 'action_admin_init' ) );
			add_action( 'admin_notices', array( &$this, 'action_admin_notices' ) );
			add_action( 'admin_enqueue_scripts', array( &$this, 'action_admin_enqueue_scripts' ) );
			add_action($this->slug.'_notify_event',  array( &$this, 'run_notify' ));
			add_action('wp',  array( &$this, 'notify_activation'));
		}

		public static function get_instance($slug) {
			if ( ! ( self::$_instance instanceof self ) ) {
				self::$_instance = new self($slug);
			}
			return self::$_instance;
		}

		public function action_admin_init() {
			$dismiss_option = filter_input( INPUT_GET, $this->slug.'_dismiss', FILTER_SANITIZE_STRING );
			if ( is_string( $dismiss_option ) ) {
				update_option( $this->slug."_dismissed_$dismiss_option", true );
				wp_die();
			}
		}

		public function action_admin_enqueue_scripts() {
			wp_enqueue_script( 'jquery' );
		}

		public  function notify_activation() {

            if ( !wp_next_scheduled( $this->slug.'_notify_event' ) ) {

                wp_schedule_event(time(), 'hourly', $this->slug.'_notify_event');
            }
        }
        public function run_notify() {

	        if ( ! get_option( $this->slug."_enable_notify" ) ) {

		        update_option( $this->slug."_enable_notify", 'false' );

	        }else {

		        update_option( $this->slug."_enable_notify", 'true' );

            }

        }

		public function action_admin_notices() {
			if ( ! get_option( $this->slug."_enable_notify" )  ||   get_option( $this->slug."_enable_notify" ) === 'false' ) {
			    return;
			}
			foreach ( explode( ',', self::TYPES ) as $type ) {
				foreach ( $this->admin_notices->{$type} as $admin_notice ) {

					$dismiss_url = add_query_arg( array(
						$this->slug.'_dismiss' => $admin_notice->dismiss_option
					), admin_url() );

					if ( ! get_option( $this->slug."_dismissed_{$admin_notice->dismiss_option}" ) ) {
						?><div
						class="notice <?php echo $this->slug;?>-notice notice-<?php echo $type;

						if ( $admin_notice->dismiss_option ) {
							echo ' is-dismissible" data-dismiss-url="' . esc_url( $dismiss_url );
						} ?>">

						<?php echo $admin_notice->message; ?>

						</div>
						<script>
                            /**
                             * Admin code for dismissing notifications.
                             *
                             */
                            (function( $ ) {
                                'use strict';
                                $( function() {
                                    $( '.<?php echo $this->slug;?>-notice' ).on( 'click', '.notice-dismiss', function( event, el ) {
                                        var $notice = $(this).parent('.notice.is-dismissible');
                                        var dismiss_url = $notice.attr('data-dismiss-url');
                                        if ( dismiss_url ) {
                                            $.get( dismiss_url );
                                        }
                                    });
                                } );
                            })( jQuery );
						</script><?php
					}
				}
			}
		}

		public function error( $message, $dismiss_option = false ) {
			$this->notice( 'error', $message, $dismiss_option );
		}

		public function warning( $message, $dismiss_option = false ) {
			$this->notice( 'warning', $message, $dismiss_option );
		}

		public function success( $message, $dismiss_option = false ) {
			$this->notice( 'success', $message, $dismiss_option );
		}

		public function info( $message, $dismiss_option = false ) {
			$this->notice( 'info', $message, $dismiss_option );
		}

		private function notice( $type, $message, $dismiss_option ) {
			$notice = new stdClass();
			$notice->message = $message;
			$notice->dismiss_option = $dismiss_option;

			$this->admin_notices->{$type}[] = $notice;
		}

		public static function error_handler( $errno, $errstr, $errfile, $errline, $errcontext ) {
			if ( ! ( error_reporting() & $errno ) ) {
				// This error code is not included in error_reporting
				return;
			}

			$message = "errstr: $errstr, errfile: $errfile, errline: $errline, PHP: " . PHP_VERSION . " OS: " . PHP_OS;

			$self = self::get_instance();

			switch ($errno) {
				case E_USER_ERROR:
					$self->error( $message );
					break;

				case E_USER_WARNING:
					$self->warning( $message );
					break;

				case E_USER_NOTICE:
				default:
					$self->notice( $message );
					break;
			}

			// write to wp-content/debug.log if logging enabled
			error_log( $message );

			// Don't execute PHP internal error handler
			return true;
		}
	}
}


$notice = Code8_Admin_Notices::get_instance('my_rocket_error_dl_plugin');
$notice->error( '<h2>تذکر مهم</h2>' .'<p>افزونه راکت وردپرس توسط وب سایت لرن دی ال فارسی سازی وبومی سازی شده است ، چنانچه شما کاربر گرامی این محصول را از وب سایت دیگری تهیه کرده اید ، این محصول غیر قانونی است.لطفا به وب سایت لرن دی ال مراجعه کرده و گزارش دهید.</p>', true  );
