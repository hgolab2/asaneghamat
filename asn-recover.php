<?php
/**
 * One-off recovery + diagnostics script – upload to the site root, open it
 * once in the browser with ?key=asn-2026, then it deletes itself.
 *
 * 0. Deletes stray files from Hub's rules/ folder (Hub includes EVERY file
 *    there; a PHP-generated error_log or a "#backup.php" breaks the site).
 * 1. Turns Hub "Optimized files" OFF unless ?keep=1 is passed, purges caches.
 * 2. Prints the last PHP fatal errors from the host error logs.
 * 3. Parse-checks every Hub optimisation rule file.
 */
if ( ! isset( $_GET['key'] ) || 'asn-2026' !== $_GET['key'] ) {
	http_response_code( 403 );
	exit( 'forbidden' );
}

define( 'WP_USE_THEMES', false );
require __DIR__ . '/wp-load.php';

header( 'Content-Type: text/plain; charset=utf-8' );
@ini_set( 'display_errors', 1 );
error_reporting( E_ALL );

echo "PHP ", PHP_VERSION, " | memory_limit ", ini_get( 'memory_limit' ), " | WP ", get_bloginfo( 'version' ), "\n";
echo "mu-plugin version: ", defined( 'ASN_PERF_VERSION' ) ? ASN_PERF_VERSION : 'NOT LOADED', " | applied: ", var_export( get_option( 'asn_perf_version' ), true ), "\n\n";

/* ---- 0. stray files in Hub rules/ ------------------------------------- */
$rules_dir = WP_PLUGIN_DIR . '/hub-elementor-addons/elementor/optimization/widget-assets/rules';
echo "===== Hub rules/ folder =====\n";
$strays = 0;
foreach ( (array) scandir( $rules_dir ) as $name ) {
	if ( '.' === $name || '..' === $name || is_dir( $rules_dir . '/' . $name ) ) {
		continue;
	}
	if ( ! preg_match( '/^[a-z0-9-]+\.php$/', $name ) ) {
		$strays++;
		$size = filesize( $rules_dir . '/' . $name );
		$ok   = @unlink( $rules_dir . '/' . $name );
		echo "stray file ", $name, " (", $size, " bytes) -> ", $ok ? 'DELETED' : 'COULD NOT DELETE', "\n";
	}
}
echo $strays ? '' : "clean\n";
echo "\n";

/* ---- 1. revert (skipped with ?keep=1) --------------------------------- */
$keep  = isset( $_GET['keep'] ) && '1' === $_GET['keep'];
$theme = get_option( 'liquid_one_opt' );
if ( is_array( $theme ) ) {
	echo "Hub before: optimized_files=", $theme['enable_optimized_files'] ?? '-', " combine_js=", $theme['combine_js'] ?? '-', "\n";
	if ( ! $keep ) {
		$theme['enable_optimized_files'] = 'off';
	}
	$theme['combine_js'] = 'off';
	update_option( 'liquid_one_opt', $theme );
	echo "Hub now: optimized_files=", $theme['enable_optimized_files'], " combine_js=off", $keep ? ' (kept: ?keep=1)' : '', "\n";
}
delete_option( 'liquid_assets_cache' );
foreach ( (array) glob( wp_upload_dir()['basedir'] . '/liquid-styles/liquid-merged-*' ) as $f ) {
	@unlink( $f );
}
echo "elementor e_font_icon_svg: ", var_export( get_option( 'elementor_experiment-e_font_icon_svg' ), true ), "\n";

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

/* ---- 2. last fatal errors --------------------------------------------- */
echo "\n===== last fatal errors =====\n";
foreach ( array( ABSPATH . 'error_log', ABSPATH . 'wp-admin/error_log', WP_CONTENT_DIR . '/error_log', WP_CONTENT_DIR . '/debug.log', WP_CONTENT_DIR . '/plugins/error_log', WP_CONTENT_DIR . '/themes/error_log' ) as $log ) {
	if ( ! is_readable( $log ) ) {
		continue;
	}
	$size = filesize( $log );
	$fh   = fopen( $log, 'r' );
	fseek( $fh, max( 0, $size - 400000 ) );
	$chunk = stream_get_contents( $fh );
	fclose( $fh );
	$lines = array_values( array_filter( explode( "\n", $chunk ), function ( $l ) {
		return preg_match( '/PHP (Fatal|Parse)|Uncaught|Allowed memory/i', $l );
	} ) );
	echo "-- ", $log, " (", count( $lines ), " matches, showing last 8)\n";
	foreach ( array_slice( $lines, -8 ) as $l ) {
		echo "   ", mb_substr( trim( $l ), 0, 600 ), "\n";
	}
}

/* ---- 3. parse-check Hub rule files ------------------------------------ */
echo "\n===== parse check: hub-elementor-addons/elementor/optimization =====\n";
$dir = WP_PLUGIN_DIR . '/hub-elementor-addons/elementor/optimization';
$bad = 0;
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $it as $f ) {
		if ( 'php' !== strtolower( $f->getExtension() ) ) {
			continue;
		}
		$src = file_get_contents( $f->getPathname() );
		$err = '';
		try {
			// Compiles the file without running it: a syntax error throws ParseError
			// before the leading `return true` executes.
			@eval( 'return true; ?>' . $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		} catch ( \ParseError $e ) {
			$err = $e->getMessage() . ' on line ' . $e->getLine();
		} catch ( \Throwable $e ) {
			$err = '';
		}
		if ( false !== strpos( $src, 'array(,' ) ) {
			$err = ( $err ? $err . '; ' : '' ) . 'contains "array(,"';
		}
		if ( $err ) {
			$bad++;
			echo "BAD  ", str_replace( WP_PLUGIN_DIR, '', $f->getPathname() ), " -> ", $err, "\n";
		}
	}
} else {
	echo "directory not found: $dir\n";
}
echo $bad ? '' : "all rule files parse OK\n";

@unlink( __FILE__ );
echo "\nDone. This script deleted itself. Open https://asaneghamat.com/ now.\n";
