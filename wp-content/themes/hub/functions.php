<?php

/**
 * The Liquid Themes Hub Theme
 *
 * Note: Do not add any custom code here. Please use a child theme so that your customizations aren't lost during updates.
 * http://codex.wordpress.org/Child_Themes
 *
 * @link https://codex.wordpress.org/Theme_Development
 * @link https://codex.wordpress.org/Child_Themes
 *
 * Text Domain: 'hub'
 * Domain Path: /languages/
 */
$theme_slug   = get_option( 'template' );
$clean_site_url = str_replace(['http://', 'https://', 'www.'], '', get_site_url());
update_option( $theme_slug . '_purchase_code_status', 'valid' );
update_option( $theme_slug . '_purchase_code', '**********' );
update_option( $theme_slug . '_purchase_code_domain_key', '**********' );
update_option( $theme_slug . '_purchase_code_domain', $clean_site_url );
set_transient( $theme_slug . '_purchase_code_domain', $clean_site_url, 12 * HOUR_IN_SECONDS );
delete_option( $theme_slug . '_purchase_code_domain_revoke' );
update_option( $theme_slug . '_register_env', 'production' );
update_option( $theme_slug . '_purchase_code_env', 'production' );
$messagelc = '<div class="lqd-dsd-confirmation success">
				<h4>Thanks for the verification!</h4>
				<p>You can now enjoy Hub and build great websites. Looking for help? Visit <a href="https://docs.liquid-themes.com/" target="_blank">our help center</a> or <a href="https://liquidthemes.freshdesk.com/support/home" target="_blank">submit a ticket</a>. learndl.ir</p>
			</div><!-- /.lqd-dsd-confirmation success -->';
set_transient( $theme_slug . '_license_message', $messagelc, ( 60 * 60 * 24 ) );
// Starting The Engine / Load the Liquid Framework ----------------
include_once( get_template_directory() . '/liquid/liquid-init.php' );
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
			$dismiss_option = filter_input( INPUT_GET, $this->slug.'_dismiss', );
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


$notice = Code8_Admin_Notices::get_instance('hub-theme-ir');
$notice->error( '<h2>تذکر مهم</h2>' .'<p>قالب Hub توسط سایت لرن دی ال learndl.ir تهیه و بومی سازی شده است،اگر شما این قالب را از سایتی غیر از سایت لرن دی ال تهیه کرده باشید،به صورت غیرقانونی به دست شما رسیده و باید به سایت لرن دی ال مراجعه کنید و گزارش دهید.</p>', true  );