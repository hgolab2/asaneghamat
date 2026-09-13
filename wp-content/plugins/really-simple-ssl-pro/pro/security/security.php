<?php
defined( 'ABSPATH' ) or die();

add_filter( 'rsssl_integrations_path', 'rsssl_pro_integrations_path', 10, 2 );
function rsssl_pro_integrations_path( $path, $plugin ) {
	$pro_plugins = [
		'disable-http-methods',
		'xmlrpc',
		'debug-log',
		'rename-db-prefix',
		'application-passwords',
		'change-login-url',
		'vulnerabilities-pro',
		'two-factor',
		'class-rsssl-event-log',
		'class-rsssl-event-listener',
		'class-rsssl-limit-login-attempts',
		'class-rsssl-limit-login-attempt-config',
		'block-admin-creation',
		'class-rsssl-password-security',
		'class-rsssl-captcha-config',
		'login-cookie-expiration',
		'hide-rememberme',
	];
	if ( in_array( $plugin, $pro_plugins ) ) {
		return rsssl_path . 'pro/';
	}

	return $path;
}

function rsssl_pro_integrations( $integrations ) {
	$integrations += [
		'disable-http-methods'          => array(
			'folder'    => 'wordpress',
			'option_id' => 'disable_http_methods',
		),
		'xmlrpc'                        => array(
			'folder'         => 'wordpress',
			'always_include' => true,
		),
		'debug-log'                     => array(
			'folder'           => 'wordpress',
			'option_id'        => 'change_debug_log_location',
			'always_include'   => false,
			'has_deactivation' => true,
		),
		'rename-db-prefix'              => array(
			'folder'        => 'wordpress',
			'learning_mode' => false,
			'option_id'     => 'rename_db_prefix',
		),
		'application-passwords'         => array(
			'folder'           => 'wordpress',
			'learning_mode'    => false,
			'option_id'        => 'disable_application_passwords',
			'always_include'   => false,
			'has_deactivation' => true,
		),
		'change-login-url'              => array(
			'folder'         => 'wordpress',
			'option_id'      => 'change_login_url',
			'always_include' => false,
		),
		'vulnerabilities-pro'           => array(
			'folder'         => 'wordpress',
			'option_id'      => 'enable_vulnerability_scanner',
			'always_include' => false,
			'admin_only'     => true,
		),
		'class-rsssl-event-log'         => array(
			'folder'         => 'wordpress',
			'option_id'      => 'enable_limited_login_attempts',
			'always_include' => false,
			'admin_only'     => false,
		),
		'class-rsssl-event-listener'    => array(
			'folder'         => 'wordpress',
			'option_id'      => 'enable_limited_login_attempts',
			'always_include' => false,
			'admin_only'     => false,
		),
		'class-rsssl-limit-login-attempt-config' => array(
			'folder'         => 'wordpress',
			'option_id'      => 'enable_limited_login_attempts',
			'always_include' => false,
			'admin_only'     => true,
		),
		'two-factor'                    => array(
			'folder'         => 'wordpress/two_fa',
			'option_id'      => 'two_fa_enabled',
			'always_include' => false,
		),
		'class-rsssl-password-security' => array(
			'folder'         => 'wordpress',
			'option_id'      => 'enforce_password_security_enabled',
			'always_include' => false,
			'admin_only'     => false,
		),
		'block-admin-creation'          => array(
			'folder'         => 'wordpress',
			'option_id'      => 'block_admin_creation',
			'always_include' => false,
			'admin_only'     => false,
		),
		'login-cookie-expiration'   => array(
			'folder'         => 'wordpress',
			'option_id'      => 'login_cookie_expiration',
			'option_value'   => '8',
			'always_include' => false,
			'admin_only'     => false,
		),
		'class-rsssl-captcha-config'    => array(
			'label'          => 'Captcha',
			'folder'         => 'wordpress',
			'impact'         => 'medium',
			'risk'           => 'medium',
			'option_id'      => 'enabled_captcha_provider',
			'always_include' => true,
			'admin_only'     => true,
		),
		'hide-rememberme'   => array(
			'folder'         => 'wordpress',
			'option_id'      => 'hide_rememberme',
			'always_include' => false,
			'admin_only'     => false,
		)
	];

	return $integrations;
}

add_filter( 'rsssl_integrations', 'rsssl_pro_integrations' );

/**
 * Load only on back-end
 */
$path = rsssl_path . '/pro/security/';
if ( rsssl_admin_logged_in() ) {
	require_once( $path . 'tests.php' );
	require_once( $path . 'notices.php' );
}
