<?php
/**
 * DRGF Admin Page.
 *
 * @package disable-remove-google-fonts
 */

/**
 * Create the admin pages.
 */
class DRGF_Admin {

	/**
	 * Start up
	 */
	public function __construct() {
		register_activation_hook( DRGF_PLUGIN_FILE, array( $this, 'activate' ) );

		add_action( 'admin_menu', array( $this, 'add_submenu' ), 10 );
		add_action( 'admin_init', array( $this, 'admin_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_drgf_check_fonts', array( $this, 'ajax_check_fonts' ) );
		add_action( 'wp_ajax_drgf_capture_current_page', array( $this, 'ajax_capture_current_page' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );
	}

	function activate() {
		add_option( 'drgf_do_activation_redirect', true );
	}

	/**
	 * Redirect to the Google Fonts Welcome page.
	 */
	function admin_redirect() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'drgf_do_activation_redirect', false ) ) {
			delete_option( 'drgf_do_activation_redirect' );
			if ( ! isset( $_GET['activate-multi'] ) && ! is_network_admin() ) {
				wp_safe_redirect( admin_url( 'themes.php?page=drgf' ) );
				exit;
			}
		}
	}

	/**
	 * Add options page
	 */
	public function add_submenu() {
		add_submenu_page(
			'themes.php',
			__( 'Google Fonts', 'disable-remove-google-fonts' ),
			__( 'Google Fonts', 'disable-remove-google-fonts' ),
			'manage_options',
			'drgf',
			array( $this, 'render_welcome_page' ),
			50
		);
		
		// Add results page.
		add_submenu_page(
			'themes.php',
			__( 'Google Fonts Check Results', 'disable-remove-google-fonts' ),
			__( 'Fonts Check Results', 'disable-remove-google-fonts' ),
			'manage_options',
			'drgf-results',
			array( $this, 'render_results_page' ),
			51
		);
	}

	/**
	 * Add options page
	 */
	public function enqueue() {
		// Only enqueue on admin pages or when admin bar is showing.
		if ( ! is_admin() && ! is_admin_bar_showing() ) {
			return;
		}

		// Ensure Dashicons are available for the admin bar icon on the frontend.
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style( 'drgf-admin', esc_url( DRGF_DIR_URL . 'admin/style.css' ), false, DRGF_VERSION );
		wp_enqueue_script( 'drgf-admin', esc_url( DRGF_DIR_URL . 'admin/scripts.js' ), ['jquery'], DRGF_VERSION, true );
		
		// Localize script for AJAX.
		wp_localize_script(
			'drgf-admin',
			'drgfCheck',
			array(
				'ajaxurl'              => admin_url( 'admin-ajax.php' ),
				'resultsPageUrl'       => admin_url( 'themes.php?page=drgf-results' ),
				'nonce'                => wp_create_nonce( 'drgf_check_fonts' ),
				'checkingText'         => __( 'Checking for Google Fonts...', 'disable-remove-google-fonts' ),
				'fontsFoundText'       => __( 'Google Fonts detected!', 'disable-remove-google-fonts' ),
				'noFontsText'          => __( 'No Google Fonts detected!', 'disable-remove-google-fonts' ),
				'foundReferencesText'  => __( 'Found %1$d reference(s) across %2$d stylesheet(s).', 'disable-remove-google-fonts' ),
				'referencesFoundText'  => __( 'References Found:', 'disable-remove-google-fonts' ),
				'checkedStylesheetsText' => __( 'Checked %d stylesheet(s) and found no Google Fonts references.', 'disable-remove-google-fonts' ),
				'errorText'            => __( 'Error:', 'disable-remove-google-fonts' ),
				'unknownErrorText'     => __( 'An unknown error occurred.', 'disable-remove-google-fonts' ),
				'timeoutErrorText'     => __( 'The check timed out. Please try again.', 'disable-remove-google-fonts' ),
				'adminPageError'       => __( 'This feature is only available on public-facing pages. Please visit a frontend page to check for Google Fonts.', 'disable-remove-google-fonts' ),
			)
		);
	}

	/**
	 * AJAX handler for capturing current page HTML.
	 */
	public function ajax_capture_current_page() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'drgf_check_fonts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'disable-remove-google-fonts' ) ) );
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'disable-remove-google-fonts' ) ) );
		}

		// Get the HTML from POST data.
		if ( ! isset( $_POST['html'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No HTML provided.', 'disable-remove-google-fonts' ) ) );
		}

		// Get raw HTML (we need to preserve all content including scripts and styles for analysis).
		$html = wp_unslash( $_POST['html'] );
		// Only sanitize the URL, not the HTML content (we'll analyze it as-is).
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		// Store captured HTML temporarily (only until next check, then it's cleared).
		// Note: We store the raw HTML without sanitization since we need it for analysis.
		set_transient( 'drgf_captured_html', array(
			'html'      => $html,
			'url'       => $url,
			'timestamp' => time(),
		), 300 ); // 5 minutes max (just as a safety, but we clear it immediately after use)

		wp_send_json_success( array( 'message' => __( 'Page HTML captured successfully.', 'disable-remove-google-fonts' ) ) );
	}

	/**
	 * AJAX handler for checking Google Fonts.
	 */
	public function ajax_check_fonts() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'drgf_check_fonts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'disable-remove-google-fonts' ) ) );
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'disable-remove-google-fonts' ) ) );
		}


		// Run the check.
		$result = drgf_check_google_fonts();

		// Store results.
		update_option( 'drgf_fonts_check_result', $result );
		update_option( 'drgf_fonts_check_time', current_time( 'mysql' ) );

		// Return JSON response.
		wp_send_json_success( $result );
	}

	/**
	 * Options page callback
	 */
	public function render_welcome_page() {
		update_option( 'dismissed-drgf-welcome', true );
		$site_url = site_url( '', 'https' );
		$url      = preg_replace( '(^https?://)', '', $site_url );
		?>
		<style>
		.notice {
			display: none;
		}
		</style>
			<div class="drgf-admin__wrap">
				<div class="drgf-admin__content">
					<div class="drgf-admin__content__header">
						<h1>Your Quickstart Guide</h1>
					</div>
					<div class="drgf-admin__content__inner">
						<p>Thank you for installing the <em>Remove Google Fonts</em> plugin!</p>
						<p><strong>✅ Now the plugin is active, it will begin working right away.</strong></p>
						
						<h3>How This Plugin Works</h3>
						<p>This plugin completely removes all references to Google Fonts from your website. That means that your website will no longer render  Google Fonts and will instead revert to a <a target="_blank" href="https://fontsplugin.com/web-safe-system-fonts/">fallback font</a>.</p>
						<p>However, some services load Google Fonts within an embedded iFrame. These include YouTube, Google Maps and ReCaptcha. It's not possible for this plugin to remove those services for the reasons <a target="_blank" href="https://fontsplugin.com/remove-disable-google-fonts/#youtube">outlined here</a>.</p>
						<h3>🔎 Check for Google Fonts</h3>
						<p>To test your website, visit any page and use the admin bar menu button to check for Google Fonts.</p>
						<img src="<?php echo esc_url( DRGF_DIR_URL . 'admin/check-google-fonts-button.jpg' ); ?>" alt="Admin Bar Menu">
						<p>We also have a free online checker tool that you can test your website with here: <a target="_blank" href="https://fontsplugin.com/google-fonts-checker/">Google Fonts Checker</a>.</p>
						<p>If there are any font requests still present, please <a target="_blank" href="https://wordpress.org/support/plugin/disable-remove-google-fonts/#new-post">create a support ticket</a> and our team will happily look into it for you.</p>
						
					<?php if ( function_exists( 'ogf_initiate' ) ) : ?>
						<h3>⭐️ Fonts Plugin Pro</h3>
						<p>Instead of removing the fonts completely, <a target="_blank" href="https://fontsplugin.com/drgf-upgrade">Fonts Plugin Pro</a> enables you to host the fonts from your <strong>own domain</strong> (<?php echo esc_html( $url ); ?>)  with the click of a button. Locally hosted fonts are more efficient, quicker to load and don't connect to any third-parties (GDPR & DSGVO-friendly).</p>
						<a class="drgf-admin__button button" href="https://fontsplugin.com/drgf-upgrade" target="_blank">Learn More</a>
					<?php else : ?>
						<h3>⭐️ Host Google Fonts Locally</h3>
						<p>Instead of removing the fonts completely, our <a href="https://fontsplugin.com/drgf-upgrade" target="_blank">Pro upgrade</a> enables you to host the fonts from your <strong>own domain</strong> (<?php echo esc_html( $url ); ?>)  with the click of a button. Locally hosted fonts are more efficient, quicker to load and don't connect to any third-parties (GDPR & DSGVO-friendly).</p>
						<a class="drgf-admin__button button" href="https://fontsplugin.com/drgf-upgrade" target="_blank">Get Started</a>
					<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
	}

	/**
	 * Results page callback
	 */
	public function render_results_page() {
		// Check if we have captured HTML that needs to be analyzed.
		$captured_html_data = get_transient( 'drgf_captured_html' );
		$auto_check = false;
		$header_check_time = '';
		
		// Auto-check if we have captured HTML (just captured, so it's fresh).
		if ( $captured_html_data && isset( $captured_html_data['html'] ) ) {
			$check_result = get_option( 'drgf_fonts_check_result', false );
			$check_time   = get_option( 'drgf_fonts_check_time', false );
			// Only auto-check if we don't have recent results (check was done before the capture).
			if ( ! $check_time || ( isset( $captured_html_data['timestamp'] ) && strtotime( $check_time ) < $captured_html_data['timestamp'] ) ) {
				$auto_check = true;
			}
		}
		
		// Get stored check results.
		$check_result = get_option( 'drgf_fonts_check_result', false );
		$check_time   = get_option( 'drgf_fonts_check_time', false );

		// For the header, only show the check time if we are not in an auto-check state
		// (i.e. the stored result is not older than the captured HTML we're about to analyze).
		if ( ! $auto_check && $check_time ) {
			$header_check_time = $check_time;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Google Fonts Check Results', 'disable-remove-google-fonts' ); ?></h1>

			<?php
			// Get the URL from check results or captured HTML data.
			$tested_url = '';
			// Prefer the most recent captured HTML URL if available, otherwise fall back to stored result.
			if ( $captured_html_data && isset( $captured_html_data['url'] ) ) {
				$tested_url = $captured_html_data['url'];
			} elseif ( $check_result && isset( $check_result['captured_url'] ) ) {
				$tested_url = $check_result['captured_url'];
			}
			?>
			
			<?php if ( ! empty( $tested_url ) || $header_check_time ) : ?>
				<p>
					<?php if ( ! empty( $tested_url ) ) : ?>
						<?php 
							printf(
								/* translators: %s: URL of tested page */
								esc_html__( 'Tested page: %s', 'disable-remove-google-fonts' ),
								'<strong>' . esc_html( $tested_url ) . '</strong>'
							);
						?>
					<?php endif; ?>
					<?php if ( $header_check_time ) : ?>
						<?php if ( ! empty( $tested_url ) ) : ?> | <?php endif; ?>
						<?php 
							printf(
								/* translators: %s: formatted date/time */
								esc_html__( 'Checked: %s', 'disable-remove-google-fonts' ),
								'<strong>' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $header_check_time ) ) ) . '</strong>'
							);
						?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			
			<div class="drgf-results-page">				
				<div id="drgf-check-results" class="drgf-check-results">
					<?php if ( $auto_check ) : ?>
						<div class="drgf-check-result">
							<p><?php esc_html_e( 'Analyzing captured page...', 'disable-remove-google-fonts' ); ?></p>
						</div>
					<?php elseif ( $check_result ) : ?>
						<?php
						$found_count = count( $check_result['references'] );
						$status_class = $check_result['found'] ? 'drgf-check-warning' : 'drgf-check-success';
						?>
						<div class="drgf-check-result <?php echo esc_attr( $status_class ); ?>">
							<?php if ( $check_result['found'] ) : ?>
								<h2><?php esc_html_e( 'Google Fonts detected!', 'disable-remove-google-fonts' ); ?></h2>
								<p><?php 
									printf(
										/* translators: %1$d: number of references, %2$d: number of stylesheets */
										esc_html__( 'Found %1$d reference(s) across %2$d stylesheet(s).', 'disable-remove-google-fonts' ),
										$found_count,
										$check_result['stylesheets_checked']
									);
								?></p>
								<?php if ( ! empty( $check_result['references'] ) ) : ?>
									<h3><?php esc_html_e( 'References Found:', 'disable-remove-google-fonts' ); ?></h3>
									<ul class="drgf-references-list">
										<?php foreach ( $check_result['references'] as $ref ) : ?>
											<li class="drgf-reference-item">
												<strong><?php echo esc_html( $ref['source'] ); ?></strong>
												<?php if ( ! empty( $ref['context'] ) ) : ?>
													<br><em><?php echo esc_html( $ref['context'] ); ?></em>
												<?php endif; ?>
												<?php if ( ! empty( $ref['url'] ) ) : ?>
													<br><code><?php echo esc_html( $ref['url'] ); ?></code>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							<?php else : ?>
								<h2><?php esc_html_e( 'No Google Fonts detected!', 'disable-remove-google-fonts' ); ?></h2>
								<p><?php 
									printf(
										/* translators: %d: number of stylesheets */
										esc_html__( 'Checked %d stylesheet(s) and found no Google Fonts references.', 'disable-remove-google-fonts' ),
										$check_result['stylesheets_checked']
									);
								?></p>
							<?php endif; ?>
							<?php if ( $check_time ) : ?>
								<p class="drgf-check-time"><em><?php 
									printf(
										/* translators: %s: formatted date/time */
										esc_html__( 'Last checked: %s', 'disable-remove-google-fonts' ),
										esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $check_time ) ) )
									);
								?></em></p>
							<?php endif; ?>
							<?php if ( ! empty( $check_result['error'] ) ) : ?>
								<p class="drgf-check-error"><strong><?php esc_html_e( 'Error:', 'disable-remove-google-fonts' ); ?></strong> <?php echo esc_html( $check_result['error'] ); ?></p>
							<?php endif; ?>
						</div>
					<?php elseif ( ! $auto_check && ! $check_result ) : ?>
						<div class="drgf-admin__wrap">
							<div class="drgf-admin__content">
								<div class="drgf-admin__content__inner">
									<h3>🔎 Check for Google Fonts</h3>
									<p><?php esc_html_e( 'To test your website, visit any page and use the admin bar menu button to check for Google Fonts.', 'disable-remove-google-fonts' ); ?></p>
									<img src="<?php echo esc_url( DRGF_DIR_URL . 'admin/check-google-fonts-button.jpg' ); ?>" alt="Admin Bar Menu">
									<p><?php esc_html_e( 'We also have a free online checker tool that you can test your website with here:', 'disable-remove-google-fonts' ); ?> <a target="_blank" href="https://fontsplugin.com/google-fonts-checker/"><?php esc_html_e( 'Google Fonts Checker', 'disable-remove-google-fonts' ); ?></a>.</p>
									<p><?php esc_html_e( 'If there are any font requests still present, please', 'disable-remove-google-fonts' ); ?> <a target="_blank" href="https://wordpress.org/support/plugin/disable-remove-google-fonts/#new-post"><?php esc_html_e( 'create a support ticket', 'disable-remove-google-fonts' ); ?></a> <?php esc_html_e( 'and our team will happily look into it for you.', 'disable-remove-google-fonts' ); ?></p>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php if ( $auto_check ) : ?>
		<script>
		jQuery( document ).ready( function() {
			// Auto-trigger check when page loads if we have fresh captured HTML.
			const $loading = jQuery( '#drgf-check-loading' );
			const $results = jQuery( '#drgf-check-results' );

			// Show loading state.
			$loading.show();
			$results.html( '<div class="drgf-check-result"><p>' + drgfCheck.checkingText + '</p></div>' );

			// Make AJAX request.
			jQuery.ajax(
				{
					url: drgfCheck.ajaxurl,
					type: 'POST',
					data: {
						action: 'drgf_check_fonts',
						nonce: drgfCheck.nonce,
					},
					timeout: 120000, // 120 seconds timeout.
				}
			)
			.done( function( response ) {
				if ( response.success && response.data ) {
					const result = response.data;
					let html = '';

					if ( result.found ) {
						const foundCount = result.references ? result.references.length : 0;
						html = '<div class="drgf-check-result drgf-check-warning">';
						html += '<h2>' + drgfCheck.fontsFoundText + '</h2>';
						html += '<p>' + drgfCheck.foundReferencesText.replace( '%1$d', foundCount ).replace( '%2$d', result.stylesheets_checked || 0 ) + '</p>';

						if ( result.references && result.references.length > 0 ) {
							html += '<h3>' + drgfCheck.referencesFoundText + '</h3>';
							html += '<ul class="drgf-references-list">';
							result.references.forEach( function( ref ) {
								html += '<li class="drgf-reference-item">';
								html += '<strong>' + ref.source + '</strong>';
								if ( ref.context ) {
									html += '<br><em>' + ref.context + '</em>';
								}
								if ( ref.url ) {
									html += '<br><code>' + ref.url + '</code>';
								}
								html += '</li>';
							} );
							html += '</ul>';
						}
						html += '</div>';
					} else {
						html = '<div class="drgf-check-result drgf-check-success">';
						html += '<h2>' + drgfCheck.noFontsText + '</h2>';
						html += '<p>' + drgfCheck.checkedStylesheetsText.replace( '%d', result.stylesheets_checked || 0 ) + '</p>';
						html += '</div>';
					}

					if ( result.error ) {
						html += '<p class="drgf-check-error"><strong>' + drgfCheck.errorText + '</strong> ' + result.error + '</p>';
					}

					$results.html( html );
				} else {
					const errorMsg = response.data && response.data.message ? response.data.message : drgfCheck.unknownErrorText;
					$results.html( '<div class="drgf-check-result drgf-check-error"><p><strong>' + drgfCheck.errorText + '</strong> ' + errorMsg + '</p></div>' );
				}
			} )
			.fail( function( jqXHR, textStatus ) {
				let errorMsg = drgfCheck.unknownErrorText;
				if ( textStatus === 'timeout' ) {
					errorMsg = drgfCheck.timeoutErrorText;
				} else if ( jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
					errorMsg = jqXHR.responseJSON.data.message;
				}
				$results.html( '<div class="drgf-check-result drgf-check-error"><p><strong>' + drgfCheck.errorText + '</strong> ' + errorMsg + '</p></div>' );
			} )
			.always( function() {
				$loading.hide();
			} );
		} );
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Add admin bar menu item.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		// Only show to users who can manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show on public-facing pages (not admin pages).
		if ( is_admin() ) {
			return;
		}

		$wp_admin_bar->add_menu(
			array(
				'id'    => 'drgf-check-fonts',
				// Add a magnifying glass (search) Dashicon next to the label.
				'title' => sprintf(
					'<span class="ab-icon dashicons dashicons-search" aria-hidden="true" style="margin-top: 2px; margin-right: 3px;"></span> %s',
					esc_html__( 'Check Google Fonts', 'disable-remove-google-fonts' )
				),
				'href'  => '#',
				'meta'  => array(
					'class' => 'drgf-check-now-btn',
				),
			)
		);
	}
}

if ( is_admin() || is_admin_bar_showing() ) {
	$drgf_admin = new DRGF_Admin();
}
