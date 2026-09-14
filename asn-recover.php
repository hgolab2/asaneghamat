<?php
/**
 * One-off recovery script – upload to the site root, open it once in the
 * browser with ?key=asn-2026, then it deletes itself.
 *
 * Reverts the Hub "Optimized files" switch (whose split-CSS rule file has a
 * shipped syntax error unless the patched widget-options.php is deployed)
 * and purges caches, so the front end renders again.
 */
if ( ! isset( $_GET['key'] ) || 'asn-2026' !== $_GET['key'] ) {
	http_response_code( 403 );
	exit( 'forbidden' );
}

define( 'WP_USE_THEMES', false );
define( 'SHORTINIT', false );
require __DIR__ . '/wp-load.php';

header( 'Content-Type: text/plain; charset=utf-8' );

$rules = WP_PLUGIN_DIR . '/hub-elementor-addons/elementor/optimization/widget-assets/rules/widget-options.php';
$src   = file_exists( $rules ) ? file_get_contents( $rules ) : '';
echo "widget-options.php on server: ", ( '' === $src ? 'MISSING' : ( false !== strpos( $src, 'array(,' ) ? 'UNPATCHED (has array(,)' : 'patched OK' ) ), "\n";

$theme = get_option( 'liquid_one_opt' );
if ( is_array( $theme ) ) {
	$theme['enable_optimized_files'] = 'off';
	$theme['combine_js']             = 'off';
	update_option( 'liquid_one_opt', $theme );
	echo "Hub Optimized files: OFF\n";
}
delete_option( 'liquid_assets_cache' );
foreach ( (array) glob( wp_upload_dir()['basedir'] . '/liquid-styles/liquid-merged-*' ) as $f ) {
	@unlink( $f );
}
// Let the mu-plugin's maintenance run again once the rule file is fixed.
delete_option( 'asn_perf_version' );

if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
	echo "Elementor CSS cache cleared\n";
}
if ( function_exists( 'rocket_clean_domain' ) ) {
	rocket_clean_domain();
	echo "WP Rocket page cache cleared\n";
}
if ( function_exists( 'rocket_clean_minify' ) ) {
	rocket_clean_minify();
	echo "WP Rocket minify cache cleared\n";
}

@unlink( __FILE__ );
echo "\nDone. This script deleted itself. Open https://asaneghamat.com/ now.\n";
