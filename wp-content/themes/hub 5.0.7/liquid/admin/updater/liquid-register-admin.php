<?php
/**
 * Liquid Themes Theme Framework
 * The Liquid_Register initiate the theme engine
 */

if ( ! defined( 'ABSPATH' ) ){
	exit; // Exit if accessed directly
}

#[AllowDynamicProperties]
class Liquid_Register {
	/**
	 * Variables required for the theme updater
	 *
	 * @since 1.0.0
	 * @type string
	 */

	protected $remote_api_url = null;
	protected $theme_slug = null;
	protected $version = null;
	protected $renew_url = null;
	protected $strings = null;
	protected $site_url = null;
	protected $frontend_message = null;
	protected $selector_class = null;

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 */
	function __construct( $config = array(), $strings = array() ) {
		$config = wp_parse_args( $config, array(
			'remote_api_url' => 'https://api.liquid-themes.com/hub',
			'theme_slug'     => 'hub',
			'version'        => '',
			'author'         => 'Liquid Themes',
			'renew_url'      => ''
		) );

		// Set config arguments
		$this->remote_api_url = $config['remote_api_url'];
		$this->theme_slug     = sanitize_key( $config['theme_slug'] );
		$this->version        = $config['version'];
		$this->author         = $config['author'];
		$this->renew_url      = $config['renew_url'];
		$this->site_url       = liquid_helper()->get_clean_site_url();
		$this->selector_class = uniqid('lqd-');

		// Populate version fallback
		if ( '' == $config['version'] ) {
			$theme = wp_get_theme( $this->theme_slug );
			$this->version = $theme->get( 'Version' );
		}

		// Strings passed in from the updater config
		$this->strings = $strings;

		add_action( 'after_setup_theme', array( $this, 'init_hooks' ) );
		add_action( 'admin_init', array( $this, 'updater' ) );
		add_action( 'admin_init', array( $this, 'register_option' ) );
		add_filter( 'http_request_args', array( $this, 'disable_wporg_request' ), 5, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_message_style' ), 90 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );

		$this->check_license();
		$this->check_revoke();
	}

	/**
	 * Creates the updater class.
	 *
	 * since 1.0.0
	 */
	function updater() {
		/* If there is no valid license key status, don't allow updates. */
		if ( get_option( $this->theme_slug . '_purchase_code_status', false ) != 'valid' ) {
			return;
		}

		if ( !class_exists( 'Liquid_Updater' ) ) {
			// Load our custom theme updater
			include( get_template_directory() . '/liquid/admin/updater/liquid-updater-class.php' );
		}

		new Liquid_Updater(
			array(
				'remote_api_url' => $this->remote_api_url,
				'version' 		 => $this->version,
				'purchase_code'  => trim( get_option( $this->theme_slug . '_purchase_code' ) ),
			),
			$this->strings
		);
	}

	/**
	 * [init_hooks description]
	 * @method init_hooks
	 * @return [type]     [description]
	 */
	public function init_hooks() {
		if ( !$this->check_wpml() ) {
			$this->check_domain();
		}

        if ( 'valid' != get_option( $this->theme_slug . '_purchase_code_status', false ) ) {
            if ( ( ! isset( $_GET['page'] ) || 'liquid' != $_GET['page'] ) ) {
                add_action( 'admin_notices', array( $this, 'admin_error' ) );
            }
        }

		if ( get_option( $this->theme_slug . '_purchase_code_domain_revoke' ) ) {
			add_action( 'admin_notices', function() {
				echo '<div class="error"><p>' . get_option( $this->theme_slug . '_purchase_code_domain_revoke' ) . '</p></div>';
			} );
		}

		add_action( 'wp_footer', function() {
			if ( $this->frontend_message ) {
				if ( get_option( $this->theme_slug . '_purchase_code_status', false ) != 'valid' ) {
					$this->selector_class .= ' err';
				}
				printf( '<div class="%1$s">%2$s</div>', esc_attr( $this->selector_class ), $this->frontend_message );
			}
		} );
	}

	function admin_error() {
		echo '<div class="error"><p>' . sprintf( wp_kses( __( 'The %s theme needs to be registered. %sRegister Now%s', 'hub' ), 'a' ), 'Hub', '<a href="' . admin_url( 'admin.php?page=liquid') . '">' , '</a>' ) . '</p></div>';
	}

	function messages() {
		set_transient( $this->theme_slug . '_license_message', $this->check_license(), ( 60 * 60 * 24 ) );
		$message = get_transient( $this->theme_slug . '_license_message' );
		echo wp_kses_post( $message );
		return;
		$license = trim( get_option( $this->theme_slug . '_purchase_code' ) );
		$status = get_option( $this->theme_slug . '_purchase_code_status', false );
		$env = get_option( $this->theme_slug . '_register_env', false );
		$domain_key = get_option( $this->theme_slug . '_purchase_code_domain_key' );

		if ( isset($_GET['page']) && ($_GET['page'] == 'liquid') ){
			if ( $status === false || empty( $status ) || $status != 'valid' ) {
				$tag = 'h4';
				$message = '<'.$tag.'>Activate Hub</'.$tag.'><p>Go to <a href="https://portal.liquid-themes.com/">portal.liquid-themes.com</a> and create your Liquid account before activating your theme</p>';
				delete_transient( $this->theme_slug . '_license_message' );
				set_transient( $this->theme_slug . '_license_message', $message, ( 60 * 60 * 24 ) );
				echo $message;
				return;
			} elseif ( $status === 'valid' && !	empty( $domain_key ) ){
				$message = '<div class="lqd-dsd-confirmation success">
							<h4>
								Thanks for the verification!
							</h4>
							<p>You can now enjoy Hub and build great websites. Looking for help? Visit <a href="https://docs.liquid-themes.com/" target="_blank">our help center</a> or <a href="https://liquidthemes.freshdesk.com/support/home" target="_blank">submit a ticket</a>.</p>
							<div class="notice notice-info" style="margin-top: 15px; padding-top: 10px; padding-bottom: 10px;">
								<p>You can use the <b style="color: #000;">Revoke License</b> button to deactivate your license. This step is required when switching between <strong>development mode</strong> (for testing) and <strong>production mode</strong> (for live websites).</p>
								<p>&#x26a0;&#xfe0f; <b style="color: #000;">Important:</b> If you need to migrate your site to another domain, you <strong>must revoke the license</strong> using this button first.</p>
								<p><b style="color: #000;">Note:</b> Revoking your license will temporarily make the site inaccessible to visitors. However, you can reactivate it at any time.</p>
							</div>
						</div><!-- /.lqd-dsd-confirmation success -->';
				delete_transient( $this->theme_slug . '_license_message' );
				set_transient( $this->theme_slug . '_license_message', $message, ( 60 * 60 * 24 ) );
				echo $message;
				return;
			} elseif( $status === 'valid' && empty( $domain_key ) ) {
				$message = '<div class="lqd-dsd-confirmation success">
							<h4 style="color:#000000;">
								Action Required!
							</h4>
							<div class="notice notice-info" style="margin-top: 15px; padding-top: 10px; padding-bottom: 10px;">
								<p>Your site has no record on Liquid Portal. Please complete the synchronization process. Go to <a href="https://portal.liquid-themes.com/">portal.liquid-themes.com</a> and create your Liquid account before sync the license.</p>
							</div>
						</div><!-- /.lqd-dsd-confirmation success -->';
				delete_transient( $this->theme_slug . '_license_message' );
				set_transient( $this->theme_slug . '_license_message', $message, ( 60 * 60 * 24 ) );
				echo $message;
			}
		}
	}

	/**
	 * Outputs the markup used on the theme license page.
	 *
	 * since 1.0.0
	 */
	function form() {
		global $wp;
		$url = add_query_arg( $_GET, $wp->request );
		$strings = $this->strings;
		$license = trim( get_option( $this->theme_slug . '_purchase_code' ) );
		$domain_key = trim( get_option( $this->theme_slug . '_purchase_code_domain_key' ) ?? '' );
		$email = get_option( $this->theme_slug . '_register_email', false );
		$env = get_option( $this->theme_slug . '_register_env', false );
		$status = get_option( $this->theme_slug . '_purchase_code_status', false );

		if (
			get_option( $this->theme_slug . '_purchase_code_status', false ) != 'valid' ||
			( isset($_GET['liquid_license_status']) && $_GET['liquid_license_status'] != 'valid' ) ||
			( get_option( $this->theme_slug . '_purchase_code_status', false ) == 'valid' && ( empty( $domain_key ) || empty( $license ) ) )
		):
		?>
		<form action="https://portal.liquid-themes.com/license/activate" method="GET" target="_blank" class="lqd-dsd-register-form">
			<?php settings_fields( $this->theme_slug . '-license' ); ?>
			<input type="hidden" name="envato_item_id" value="31569152" />
			<input type="hidden" name="theme" value="<?php echo esc_attr($this->theme_slug) ?>" />
			<input type="hidden" name="domain" value="<?php echo site_url(); ?>" />
			<input type="hidden" name="return_url" value="<?php echo admin_url( 'admin.php' . $url ); ?>" />
			<div class="lqd-dsd-register-radio">
				<p>Choose license type: </p>
				<div>
					<input type="radio" id="development" name="register_env" value="development" <?php echo esc_attr(($env === 'development' || empty($env)) ? 'checked' : ''); ?>>
					<label for="development">Development</label>
					<p style="font-weight: 400; font-size: 13px; margin-top: 1em;">This is your staging area, used for building, testing, or making improvements to your site before deploying changes to the production environment.</p>
				</div>
				<div>
					<input type="radio" id="production" name="register_env" value="production" <?php echo esc_attr($env === 'production' ? 'checked' : ''); ?>>
					<label for="production">Production</label>
					<p style="font-weight: 400; font-size: 13px; margin-top: 1em;">This is your live domain, where the web project is fully operational and accessible to end-users.</p>
				</div>
			</div>
			<strong style="color: #000; margin-bottom:8px; display:block;">Important Note:</strong>
			<p>If you activate your site in the development environment, a warning message will appear at the bottom of your site, indicating that it is a development site. This warning will automatically disappear once you activate your site in the production environment.</p>
			<button type="submit">
				<?php esc_html_e( 'Connect to Liquid Portal', 'hub' ) ?>
			</button>
		</form>
		<?php
		else:
		?>
		<div class="lqd-dsd-box-foot">
			<a href="https://portal.liquid-themes.com/" target="_blank"><?php esc_html_e( 'Manage Licenses in Liquid Portal', 'hub' ); ?></a>
			<form action="https://portal.liquid-themes.com/license/revoke" method="GET" target="_blank" class="lqd-dsd-register-form" style="margin-top:12px;">
			<?php settings_fields( $this->theme_slug . '-license' ); ?>
				<input type="hidden" name="envato_item_id" value="31569152" />
				<input type="hidden" name="theme" value="<?php echo esc_attr($this->theme_slug) ?>" />
				<input type="hidden" name="domain" value="<?php echo site_url(); ?>" />
				<input type="hidden" name="domain_key" value="<?php echo esc_attr( $domain_key ); ?>" />
				<input type="hidden" name="license_code" value="<?php echo esc_attr( $license ); ?>" />
				<input type="hidden" name="return_url" value="<?php echo admin_url( 'admin.php' . $url ); ?>" />
				<button type="submit" style="background-color: #dc2626;">
					<?php esc_html_e( 'Revoke License', 'hub' ) ?>
				</button>
			</form>
		</div>
		<?php
		endif;
	}

	/**
	 * Registers the option used to store the license key in the options table.
	 *
	 * since 1.0.0
	 */
	function register_option() {
		register_setting(
			$this->theme_slug . '-license',
			$this->theme_slug . '_purchase_code',
			array( $this, 'sanitize_license' )
		);
		register_setting(
			$this->theme_slug . '-license',
			$this->theme_slug . '_register_email'
		);
		register_setting(
			$this->theme_slug . '-license',
			$this->theme_slug . '_register_env'
		);
	}

	/**
	 * Sanitizes the license key.
	 *
	 * since 1.0.0
	 *
	 * @param string $new License key that was submitted.
	 * @return string $new Sanitized license key.
	 */
	function sanitize_license( $new ) {
		$old = get_option( 'hub_purchase_code' );

		if ( $old && $old != $new ) {
			// New license has been entered, so must reactivate
			delete_option( $this->theme_slug . '_purchase_code_status' );
			delete_transient( $this->theme_slug . '_license_message' );
		}

		return $new;
	}

	/**
	 * Makes a call to the API.
	 *
	 * @since 1.0.0
	 *
	 * @param array $api_params to be used for wp_remote_get.
	 * @return array $response decoded JSON response.
	 */
	function get_api_response( $api_params ) {
		// Call the custom API.
		$response = wp_remote_get(
			add_query_arg( $api_params, $this->remote_api_url ),
			array( 'timeout' => 15, 'sslverify' => false )
		);

		// Make sure the response came back okay.
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response;
	}

	/**
     * Makes a call to the API.
     *
     * @since 1.5
     *
     * @param array $api_params to be used for wp_remote_get.
     * @return array $response decoded JSON response.
     */
	function ld_api_response( $api_params ) {
		$api_response = wp_remote_post('https://portal.liquid-themes.com/api/license/activate', $api_params);

		$status = [];
		$response = json_decode( wp_remote_retrieve_body( $api_response ), true );
        // Make sure the response came back okay.
		if ( is_wp_error( $api_response ) || wp_remote_retrieve_response_code( $api_response ) != 200 ) {
			$status['success'] = 'false';
			$status['valid'] = 'false';
			if ( isset( $response['error'][0] ) ) {
				$status['message'] = $response['error'][0];
			} else {
				$status['message'] = esc_html( 'Something went wrong. Please try again later.');
			}
			return $status;

		}

		$status['success'] = 'true';
		$status['valid'] = 'valid';

        return $status;
     }

	/**
	 * Checks if license is valid and gets expire date.
	 *
	 * @since 1.0.0
	 *
	 * @return string $message License status message.
	 */
	function check_license() {
		//update_option( $this->theme_slug . '_purchase_code_status', $license_data->license );
			$message = '<div class="lqd-dsd-confirmation success">
			<h4>Thanks for the verification!</h4>
			<p>You can now enjoy Hub and build great websites. Looking for help? Visit <a href="https://docs.liquid-themes.com/" target="_blank">our help center</a> or <a href="https://liquidthemes.freshdesk.com/support/home" target="_blank">submit a ticket</a>.</p>
			</div><!-- /.lqd-dsd-confirmation success -->';
			return $message;
		if (
			! isset( $_GET['liquid_license_status'] ) &&
			! isset( $_GET['liquid_license_key'] ) &&
			! isset( $_GET['liquid_license_domain_key'] ) &&
			! isset( $_GET['liquid_license_env'] )
		) {
			return;
		}

		global $wp;
		$url = add_query_arg( $_GET, $wp->request );

		if ( $_GET['liquid_license_status'] === 'valid' ) {
			update_option( $this->theme_slug . '_purchase_code_status', $_GET['liquid_license_status'] );
			update_option( $this->theme_slug . '_purchase_code', $_GET['liquid_license_key'] );
			update_option( $this->theme_slug . '_purchase_code_domain_key', $_GET['liquid_license_domain_key'] );
			update_option( $this->theme_slug . '_purchase_code_domain', $this->site_url );
			update_option( $this->theme_slug . '_purchase_code_env', $_GET['liquid_license_env'] );
			delete_option( $this->theme_slug . '_purchase_code_domain_revoke' );
			delete_option( $this->theme_slug . '_purchase_code_domain_migrated' );

			$message = '<div class="lqd-dsd-confirmation success">
							<h4>
								Thanks for the verification!
								<svg xmlns="http://www.w3.org/2000/svg" width="21" height="22" viewBox="0 0 21 22">
									<path fill="currentColor" fill-rule="evenodd" d="M398.4,76.475 L407.775,67.1 L406.3,65.575 L398.4,73.5 L394.7,69.775 L393.225,71.25 L398.4,76.475 Z M400.5,60.85 C402.40001,60.85 404.158325,61.3249952 405.775,62.275 C407.341675,63.1750045 408.574995,64.4083255 409.475,65.975 C410.425005,67.5916747 410.9,69.3499905 410.9,71.25 C410.9,73.1500095 410.425005,74.9083252 409.475,76.525 C408.574995,78.0916745 407.341675,79.3249955 405.775,80.225 C404.158325,81.1750047 402.40001,81.65 400.5,81.65 C398.59999,81.65 396.841675,81.1750047 395.225,80.225 C393.658325,79.3083287 392.425005,78.0666745 391.525,76.5 C390.574995,74.8833252 390.1,73.1333427 390.1,71.25 C390.1,69.3666573 390.574995,67.6166747 391.525,66 C392.441671,64.4333255 393.683326,63.1916712 395.25,62.275 C396.866675,61.3249952 398.616657,60.85 400.5,60.85 Z" transform="translate(-390 -60)"/>
								</svg>
							</h4>
							<p>You can now enjoy Hub and build great websites. Looking for help? Visit <a href="https://docs.liquid-themes.com/" target="_blank">our help center</a> or <a href="https://liquidthemes.freshdesk.com/support/home" target="_blank">submit a ticket</a>.</p>
						</div><!-- /.lqd-dsd-confirmation success -->';
		} else {
			if ( isset($_GET['liquid_license_message']) && ! empty( $_GET['liquid_license_message'] ) ) {
				$message_text = $_GET['liquid_license_message'];
			} else {
				$message_text = 'Something went wrong! Contact to support.';
			}

			$message = '<div class="lqd-dsd-confirmation fail">
							<h4>
								Activation is invalid.
								<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 15 15">
									<polygon fill="currentColor" fill-rule="evenodd" points="274.775 64.45 268.975 70.25 274.775 76.05 273.3 77.525 267.5 71.725 261.7 77.525 260.225 76.05 266.025 70.25 260.225 64.45 261.7 62.975 267.5 68.775 273.3 62.975" transform="translate(-260 -63)"/>
								</svg>
							</h4>
							<p>' . $message_text . '</p>
							<p> Looking for help? Visit <a href="https://docs.liquid-themes.com/" target="_blank">our help center</a> or <a href="https://liquidthemes.freshdesk.com/support/home" target="_blank">submit a ticket</a>.</p>
						</div><!-- /.lqd-dsd-confirmation fail -->';
		}

		set_transient( $this->theme_slug . '_license_message', $message, ( 60 * 60 * 24 ) ); // message

		if ( false !== strpos( $url, '?page=liquid-setup&step=license') ) {
			wp_redirect(admin_url('admin.php?page=liquid-setup&step=license'));
		} else {
			wp_redirect(admin_url('admin.php?page=liquid'));
		}
	}

	/**
	 * Checks domain expire date.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function check_domain() {
		if ( !defined( 'WP_HOME' ) || !defined( 'WP_SITEURL' ) ) {
			if ( !get_option( $this->theme_slug . '_purchase_code_domain' ) ){
				update_option( $this->theme_slug . '_purchase_code_domain', $this->site_url );
			} else {
				if ( 
					$this->site_url != get_option( $this->theme_slug . '_purchase_code_domain' ) &&
					strpos( $this->site_url, get_option( $this->theme_slug . '_purchase_code_domain' ) ) === false
				){
					delete_option( $this->theme_slug . '_purchase_code_status' );
					delete_option( $this->theme_slug . '_purchase_code'  );
					delete_option( $this->theme_slug . '_purchase_code_domain_key' );
					delete_option( $this->theme_slug . '_purchase_code_domain' );
					$message = '<h3>Action Required: Reactivate Your Hub Theme License</h3>
						<p>We’ve detected that your site is now on a new domain, but your Hub Theme license is still registered to the old one. To restore full functionality, transfer your license to the current domain.</p>
						<p><strong>Steps to Reactivate Your License:</strong></p>
						<ol>
						<li><strong>Go to the previous domain</strong> where the theme was originally activated. <i>(If you no longer have access to the old domain, contact us for assistance.)</i></li>
						<li><strong>Open the license activation page</strong> on the old domain and click <strong>"Revoke License."</strong></li>
						<li><strong>Return to this site</strong> (your current domain) and activate the theme again.</li>
						</ol>
						<p><strong>If You Require a New License:</strong></p>
						<ol style="list-style-type:disc;">
						<li>Purchase a new license and follow the instructions in <strong><a href="https://docs.liquid-themes.com/article/566-liquid-portal-my-license-is-not-showing-in-my-account" target="_blank">this guide</a></strong> to add it to your account and activate the theme using it.</li>
						</ol>
						<p>Completing these steps will restore full site functionality and make it accessible to visitors. If you need help at any point, please <strong><a href="https://liquidthemes.freshdesk.com/support/tickets/new" target="_blank">contact our support team.</a></strong></p>';
					update_option( $this->theme_slug . '_purchase_code_domain_revoke', $message );
					update_option( $this->theme_slug . '_purchase_code_domain_migrated', $this->site_url );
				}
			}
		}
	}

	/**
	 * Checks WPML plugin's negotitation type.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function check_wpml(){
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ){
            global $sitepress;
			$language_negotiation_type = (int) $sitepress->get_setting( 'language_negotiation_type' );
			return $language_negotiation_type == 2;
        }

		return false;
	}

	/**
	 * Checks revoke.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function check_revoke() {
		if ( isset( $_GET['liquid_license_revoke'] ) && ! empty( $_GET['liquid_license_revoke'] ) ){
			$message = call_user_func( 'sanitize_text_field', wp_unslash( $_GET['liquid_license_revoke'] ) );
			delete_option( $this->theme_slug . '_purchase_code_status' );
			delete_option( $this->theme_slug . '_purchase_code'  );
			delete_option( $this->theme_slug . '_purchase_code_domain_key' );
			delete_option( $this->theme_slug . '_purchase_code_domain' );
			update_option( $this->theme_slug . '_purchase_code_domain_revoke', $message );
		}
	}

	/**
	 * Disable requests to wp.org repository for this theme.
	 *
	 * @since 1.0.0
	 */
	function disable_wporg_request( $r, $url ) {
		// If it's not a theme update request, bail.
		if ( 0 !== strpos( $url, 'https://api.wordpress.org/themes/update-check/1.1/' ) ) {
 			return $r;
 		}

 		// Decode the JSON response
 		$themes = json_decode( $r['body']['themes'] );

 		// Remove the active parent and child themes from the check
 		$parent = get_option( 'template' );
 		$child = get_option( 'stylesheet' );
 		unset( $themes->themes->$parent );
 		unset( $themes->themes->$child );

 		// Encode the updated JSON response
 		$r['body']['themes'] = json_encode( $themes );

 		return $r;
	}

	/**
	 * Prepare the messages
	 *
	 * @since 1.0.0
	 *
	 * @return string $message
	 */
	function frontend_message_style() {
		$style = ".{$this->selector_class}{
			position: fixed;
			left: 0;
			bottom: 0;
			right: 0;
			margin: 0;
			padding: 1em 1.41575em;
			background-color: #3d9cd2;
			color: #fff;
			z-index: 9998;
			display: flex;
			justify-content: center;
			align-items: center;
			gap: 8px;
			a{color:#fff}
		}";
		$style .= ".{$this->selector_class}.err{
			background-color: #d63939;
		}";
		wp_add_inline_style( 'liquid-base', $style );

		if ( get_option( $this->theme_slug . '_purchase_code_env' ) ) {
			if ( get_option( $this->theme_slug . '_purchase_code_env' ) == 'development' ) {
				$this->frontend_message = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22ZM12 20C16.4183 20 20 16.4183 20 12C20 7.58172 16.4183 4 12 4C7.58172 4 4 7.58172 4 12C4 16.4183 7.58172 20 12 20ZM11 7H13V9H11V7ZM11 11H13V17H11V11Z"></path></svg><span>This site is registered on <a href="https://portal.liquid-themes.com/" target="_blank">portal.liquid-themes.com</a> as a <strong>development site.</strong> Switch to <a href="' . admin_url("admin.php?page=liquid") . '" target="_blank"><strong>production mode</strong></a> to remove this warning.</span><svg onclick="this.parentElement.remove()" style="margin-left: auto;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22ZM12 20C16.4183 20 20 16.4183 20 12C20 7.58172 16.4183 4 12 4C7.58172 4 4 7.58172 4 12C4 16.4183 7.58172 20 12 20ZM12 10.5858L14.8284 7.75736L16.2426 9.17157L13.4142 12L16.2426 14.8284L14.8284 16.2426L12 13.4142L9.17157 16.2426L7.75736 14.8284L10.5858 12L7.75736 9.17157L9.17157 7.75736L12 10.5858Z"></path></svg>';
			}
		}

		if ( get_option( $this->theme_slug . '_purchase_code_status', false ) != 'valid' ) {
			$this->frontend_message = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22ZM12 20C16.4183 20 20 16.4183 20 12C20 7.58172 16.4183 4 12 4C7.58172 4 4 7.58172 4 12C4 16.4183 7.58172 20 12 20ZM11 15H13V17H11V15ZM11 7H13V13H11V7Z"></path></svg> This theme is disabled due to a revoked license. The site is currently inaccessible to visitors. Please activate your license to restore access and functionality. <a style="font-weight:bold" href="https://liquidthemes.freshdesk.com/support/tickets/new">Contact Support</a>';
		}
	}

	function template_redirect() {
		if ( get_option( $this->theme_slug . '_purchase_code_status', false ) != 'valid' ) {
			if ( ! current_user_can('manage_options') ) {
				wp_die(
					'<h1>The site is currently unavailable. Please check back soon!</h1><p>If you are the site administrator, please log in and review your dashboard for details.</p><p><a href="'. wp_login_url() .'">Log in to WordPress</a></p>',
					'Site Unavailable',
					array('response' => 503)
				);
			}
		}
}

}

new Liquid_Register;