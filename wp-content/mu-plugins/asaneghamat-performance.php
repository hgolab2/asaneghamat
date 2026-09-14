<?php
/**
 * Plugin Name: Asan Eghamat – Performance & Accessibility
 * Description: PageSpeed/Lighthouse optimizations for asaneghamat.com (fonts, LCP image, head cleanup, accessibility fixes). No WordPress core files are modified.
 * Version:     1.1.2
 * Author:      Asan Eghamat
 */

defined( 'ABSPATH' ) || exit;

define( 'ASN_PERF_VERSION', '1.1.2' );

final class ASN_Performance {

	/** @var string Child theme URL (no trailing slash). */
	private $child_uri;

	public function __construct() {
		$this->child_uri = untrailingslashit( get_stylesheet_directory_uri() );

		// ---- Fonts ---------------------------------------------------------
		add_filter( 'elementor_pro/custom_fonts/font_display', array( $this, 'font_display_swap' ) );
		add_filter( 'pre_option_elementor_font_display', array( $this, 'font_display_swap' ) );
		add_action( 'wp_head', array( $this, 'preload_assets' ), 2 );

		// ---- Head / asset cleanup -------------------------------------------
		add_action( 'init', array( $this, 'disable_emojis' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_unused_assets' ), 100 );
		add_action( 'wp', array( $this, 'remove_global_styles' ) );
		add_action( 'init', array( $this, 'cleanup_head_links' ) );

		// ---- WP Rocket integration -------------------------------------------
		add_filter( 'rocket_lazyload_excluded_src', array( $this, 'rocket_lazyload_exclusions' ) );
		add_filter( 'rocket_lazyload_excluded_attributes', array( $this, 'rocket_lazyload_excluded_attributes' ) );

		// ---- Accessibility / HTML post-processing ------------------------------
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );

		// ---- One-time cache flush after deploy ---------------------------------
		add_action( 'admin_init', array( $this, 'maybe_flush_caches' ) );
	}

	/* ====================================================================== */
	/* Fonts                                                                   */
	/* ====================================================================== */

	public function font_display_swap() {
		return 'swap';
	}

	/**
	 * Preload the two real font files and (on the front page only) the LCP
	 * background image so the browser discovers them before parsing CSS.
	 *
	 * The "webcity" custom font (Elementor Pro > Custom Fonts, post 6495) is
	 * registered with five weights; in the database they now all point at the
	 * same two files (Regular for 400/500, Bold for 600-800) so only two
	 * requests happen instead of four (see dedupe_webcity_font()).
	 */
	public function preload_assets() {
		if ( is_admin() || $this->is_elementor_editor() ) {
			return;
		}

		$fonts = trailingslashit( wp_upload_dir()['baseurl'] ) . '2023/05/';
		echo '<link rel="preload" href="' . esc_url( $fonts . 'IRANSansX-Regular.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
		echo '<link rel="preload" href="' . esc_url( $fonts . 'IRANSansX-Bold.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";

		if ( is_front_page() ) {
			$img = $this->child_uri . '/assets/img/';
			echo '<link rel="preload" as="image" href="' . esc_url( $img . 'home-bg-mobile.webp' ) . '" media="(max-width: 767px)" fetchpriority="high">' . "\n";
			echo '<link rel="preload" as="image" href="' . esc_url( $img . 'home-bg-desktop.webp' ) . '" media="(min-width: 768px)" fetchpriority="high">' . "\n";
		}
	}

	/* ====================================================================== */
	/* Head / asset cleanup                                                    */
	/* ====================================================================== */

	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter( 'tiny_mce_plugins', function ( $plugins ) {
			return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
		} );
	}

	public function dequeue_unused_assets() {
		if ( is_admin() || $this->is_elementor_editor() ) {
			return;
		}

		// Redux admin utility CSS that leaks onto the front end.
		wp_dequeue_style( 'redux-extendify-styles' );
		wp_deregister_style( 'redux-extendify-styles' );

		// Font Awesome 4 (77 KB font): only wp-bottom-menu uses it, and the child
		// theme renders those five icons as CSS-masked SVGs. If a page turns out
		// to use other FA4 icons, process_html() re-injects the stylesheet.
		wp_dequeue_style( 'font-awesome' );

		if ( is_front_page() ) {
			wp_dequeue_style( 'classic-theme-styles' );
			wp_dequeue_style( 'wp-block-library' );
		}
	}

	/**
	 * Gutenberg global styles (9 KB inline, block presets) are not used by the
	 * Elementor-built front page. Core enqueues them both in the head and,
	 * for classic themes, again at wp_footer (then hoists them into the head),
	 * so both hooks have to go.
	 */
	public function remove_global_styles() {
		if ( is_admin() || ! is_front_page() || $this->is_elementor_editor() ) {
			return;
		}
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
	}

	public function cleanup_head_links() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}

	/* ====================================================================== */
	/* WP Rocket                                                               */
	/* ====================================================================== */

	/** Never lazy-load the LCP background or the logo. */
	public function rocket_lazyload_exclusions( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'hub-child/assets/img/home-bg-';
		$excluded[] = 'hub-child/assets/img/logo-';
		return $excluded;
	}

	/**
	 * An image WordPress (or Rocket's own above-the-fold beacon) marked as
	 * fetchpriority="high" is the LCP candidate; lazy-loading it defeats the
	 * purpose and adds a layout shift.
	 */
	public function rocket_lazyload_excluded_attributes( $excluded ) {
		$excluded   = is_array( $excluded ) ? $excluded : array();
		$excluded[] = 'fetchpriority="high"';
		return $excluded;
	}

	/* ====================================================================== */
	/* HTML post-processing (accessibility)                                    */
	/* ====================================================================== */

	public function start_buffer() {
		if ( is_admin() || is_feed() || is_robots() || is_embed() || $this->is_elementor_editor() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * @param string $html Full page HTML.
	 * @return string
	 */
	public function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		foreach ( array( 'fix_overlay_links', 'fix_icon_only_links', 'swap_logo', 'restore_fa4_if_needed' ) as $step ) {
			$result = $this->$step( $html );
			// preg_* returns null on a PCRE error; never ship a blank page.
			if ( is_string( $result ) && '' !== $result ) {
				$html = $result;
			}
		}

		return $html;
	}

	/**
	 * Hub blog cards render an empty overlay <a> that covers the card; give it
	 * the post title as accessible name and fix the theme's `tab-index` typo.
	 */
	private function fix_overlay_links( $html ) {
		return preg_replace_callback(
			'#<article\b[^>]*>.*?</article>#s',
			function ( $m ) {
				$article = $m[0];

				if ( false === strpos( $article, 'lqd-lp-overlay-link' ) ) {
					return $article;
				}

				$title = '';
				if ( preg_match( '#class="[^"]*entry-title[^"]*"[^>]*>\s*<a\b[^>]*>(.*?)</a>#s', $article, $t ) ) {
					$title = trim( wp_strip_all_tags( $t[1] ) );
				}
				$label = '' !== $title ? $title : 'مشاهده مطلب';

				return preg_replace_callback(
					'#<a\b([^>]*\bclass="[^"]*lqd-lp-overlay-link[^"]*"[^>]*)>#',
					function ( $a ) use ( $label ) {
						$attrs = $a[1];
						$attrs = preg_replace( '#\s+tab-index="[^"]*"#', ' tabindex="-1"', $attrs );
						if ( false === stripos( $attrs, 'aria-label' ) ) {
							$attrs .= ' aria-label="' . esc_attr( $label ) . '"';
						}
						return '<a' . $attrs . '>';
					},
					$article
				);
			},
			$html
		);
	}

	/**
	 * Any <a> whose content has no text, no <img alt>, no <svg><title> and no
	 * aria-label gets a generic Persian label. Covers lightbox overlays
	 * (`.lqd-overlay.fresco`) and icon-only buttons (`.btn-icon-block`).
	 */
	private function fix_icon_only_links( $html ) {
		return preg_replace_callback(
			'#<a\b([^>]*)>((?:(?!</a>).)*)</a>#s',
			function ( $m ) {
				$attrs = $m[1];
				$inner = $m[2];

				if ( preg_match( '#\baria-(label|labelledby)=#i', $attrs ) ) {
					return $m[0];
				}
				if ( preg_match( '#<img\b[^>]*\balt="[^"]+"#i', $inner ) ) {
					return $m[0];
				}
				if ( preg_match( '#<title\b[^>]*>\s*\S#i', $inner ) ) {
					return $m[0];
				}

				$text = trim( preg_replace( '#(&nbsp;|\s|\x{200c})+#u', '', wp_strip_all_tags( $inner ) ) );
				if ( '' !== $text ) {
					return $m[0];
				}

				if ( preg_match( '#\btitle="([^"]+)"#i', $attrs, $t ) ) {
					$label = $t[1];
				} elseif ( false !== strpos( $inner, 'ios-play' ) || false !== strpos( $inner, 'fa-play' ) ) {
					$label = 'پخش ویدیو';
				} elseif ( false !== strpos( $attrs, 'fresco' ) || false !== strpos( $attrs, 'lqd-fi-overlay-link' ) ) {
					$label = 'بزرگنمایی تصویر';
				} elseif ( false !== strpos( $attrs, 'lqd-overlay' ) ) {
					$label = 'مشاهده';
				} else {
					$label = 'مشاهده';
				}

				return '<a' . $attrs . ' aria-label="' . esc_attr( $label ) . '">' . $inner . '</a>';
			},
			$html
		);
	}

	/**
	 * The header logo is a 759×767 image displayed at ~75px. Serve a 220px
	 * version shipped with the child theme instead.
	 */
	private function swap_logo( $html ) {
		$small = $this->child_uri . '/assets/img/logo-220.webp';

		return preg_replace_callback(
			'#<img\b[^>]*\bclass="[^"]*\blogo-default\b[^"]*"[^>]*>#',
			function ( $m ) use ( $small ) {
				$tag = $m[0];
				if ( false === strpos( $tag, '/uploads/2021/07/asaneghamat.webp' ) ) {
					return $tag;
				}
				$tag = preg_replace( '#https?://[^"\']+/uploads/2021/07/asaneghamat\.webp#', $small, $tag );
				// Always emit intrinsic dimensions (Lighthouse "unsized-images"),
				// whether or not WP Rocket managed to add them.
				$tag = preg_replace( '#\s(width|height)="\d+"#', '', $tag );
				$tag = preg_replace( "#viewBox='0%200%20\d+%20\d+'#", "viewBox='0%200%20220%20222'", $tag );
				return preg_replace( '#^<img\b#', '<img width="220" height="222"', $tag );
			},
			$html
		);
	}

	/**
	 * FA4 is dequeued globally; if this page uses `fa fa-*` icons anywhere
	 * other than the bottom menu, add the stylesheet back.
	 */
	private function restore_fa4_if_needed( $html ) {
		if ( ! defined( 'ELEMENTOR_URL' ) ) {
			return $html;
		}
		// Ignore the bottom-menu icons (handled by the child theme CSS).
		$probe = preg_replace(
			array( '#<i class="wp-bottom-menu-item-icons[^"]*"[^>]*>#', '#<form[^>]*wp-bottom-menu-search-form.*?</form>#s' ),
			'',
			$html
		);
		if ( null === $probe ) {
			$probe = $html;
		}
		if ( ! preg_match( '#class="(?:[^"]*\s)?fa(?:\s[^"]*)?"#', $probe ) ) {
			return $html;
		}
		$href = ELEMENTOR_URL . 'assets/lib/font-awesome/css/font-awesome.min.css?ver=4.7.0';
		$link = '<link rel="stylesheet" id="font-awesome-css" href="' . esc_url( $href ) . '" media="all">' . "\n";
		return preg_replace( '#</head>#', $link . '</head>', $html, 1 );
	}

	/* ====================================================================== */
	/* Maintenance                                                             */
	/* ====================================================================== */

	/**
	 * Runs once per ASN_PERF_VERSION, on the first wp-admin visit after this
	 * file is deployed: applies the database-side settings that belong to this
	 * optimisation (so no manual table sync is needed), then purges caches.
	 */
	public function maybe_flush_caches() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'asn_perf_version' ) === ASN_PERF_VERSION ) {
			return;
		}

		$this->apply_settings();
		$this->dedupe_webcity_font();
		$this->regenerate_custom_font_faces();

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}

		update_option( 'asn_perf_version', ASN_PERF_VERSION, false );
	}

	/**
	 * Settings normally changed through the admin UI:
	 *
	 * - Hub Theme Options > Performance > "Optimized files" ON: per-page merged
	 *   CSS (uploads/liquid-styles/liquid-merged-styles-{ID}.css, ~130 KB)
	 *   replaces the 1 MB theme-elementor.min.css. "Combine JS" stays OFF – its
	 *   merged bundle drops gsap/particles/FontFaceObserver on some pages.
	 * - Elementor > Settings > Features > "Inline Font Icons" active: Font
	 *   Awesome icons render as inline SVG, so the FA5 CSS + 78 KB font go away.
	 */
	private function apply_settings() {
		$theme = get_option( 'liquid_one_opt' );
		if ( is_array( $theme ) ) {
			// Only switch the merged CSS on when the patched rule file is deployed;
			// the shipped copy has a parse error that takes the whole site down.
			$theme['enable_optimized_files'] = $this->hub_rules_file_ok() ? 'on' : 'off';
			$theme['combine_js']             = 'off';
			update_option( 'liquid_one_opt', $theme );
		}
		// Merged files are (re)built on the next front-end request of each page.
		delete_option( 'liquid_assets_cache' );
		foreach ( (array) glob( wp_upload_dir()['basedir'] . '/liquid-styles/liquid-merged-*' ) as $file ) {
			@unlink( $file );
		}

		update_option( 'elementor_experiment-e_font_icon_svg', 'active' );
	}

	/**
	 * hub-elementor-addons 5.0.8 ships `'lqdsep-btn-icon-base' => array(,` in
	 * its split-CSS rule file – a parse error that is only hit when Hub's
	 * "Optimized files" is on. The repo carries a patched copy; make sure it is
	 * the one on the server before relying on it.
	 */
	private function hub_rules_file_ok() {
		$dir  = WP_PLUGIN_DIR . '/hub-elementor-addons/elementor/optimization/widget-assets/rules';
		$main = $dir . '/widget-options.php';
		if ( ! is_readable( $main ) || false === strpos( (string) file_get_contents( $main ), 'lqdsep-btn-icon-base' ) ) {
			return false;
		}
		// Hub scandir()s this folder and includes *every* .php file – so a
		// leftover backup such as "#widget-options.php" breaks the site too.
		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file );
			if ( false !== strpos( $src, 'array(,' ) ) {
				return false;
			}
			try {
				// Compile only; a syntax error throws before `return true` runs.
				@eval( 'return true; ?>' . $src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			} catch ( \ParseError $e ) {
				return false;
			} catch ( \Throwable $e ) {
				// Runtime error means it compiled – fine.
				continue;
			}
		}
		return true;
	}

	/**
	 * The "webcity" custom font is uploaded as five weights pointing at five
	 * .woff files, of which only two are unique (Regular = 400/500, Bold =
	 * 600/700/800, byte-identical copies). Point every weight at the two
	 * canonical files and add the woff2 versions (already in uploads), so the
	 * browser downloads two woff2 files instead of four woff files.
	 */
	private function dedupe_webcity_font() {
		global $wpdb;

		$font_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'elementor_font' AND post_title = %s LIMIT 1", 'webcity' ) );
		if ( ! $font_id ) {
			return;
		}
		$files = get_post_meta( $font_id, 'elementor_font_files', true );
		if ( ! is_array( $files ) ) {
			return;
		}

		$base = trailingslashit( wp_upload_dir()['baseurl'] ) . '2023/05/';
		$ids  = array();
		foreach ( array( 'IRANSansX-Regular.woff2', 'IRANSansX-Regular.woff', 'IRANSansX-Bold.woff2', 'IRANSansX-Bold.woff' ) as $name ) {
			$ids[ $name ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1", '%/2023/05/' . $name ) );
		}

		foreach ( $files as &$row ) {
			if ( empty( $row['font_weight'] ) ) {
				continue;
			}
			$face         = (int) $row['font_weight'] >= 600 ? 'IRANSansX-Bold' : 'IRANSansX-Regular';
			$row['woff2'] = array( 'id' => $ids[ $face . '.woff2' ], 'url' => $base . $face . '.woff2' );
			$row['woff']  = array( 'id' => $ids[ $face . '.woff' ], 'url' => $base . $face . '.woff' );
		}
		unset( $row );

		update_post_meta( $font_id, 'elementor_font_files', $files );
	}

	/**
	 * Elementor Pro caches the generated @font-face CSS in post meta at save
	 * time, so the font_display filter above only takes effect after a
	 * regeneration. Rebuild it for every custom font.
	 */
	private function regenerate_custom_font_faces() {
		$class = '\ElementorPro\Modules\AssetsManager\AssetTypes\Fonts\Custom_Fonts';
		if ( ! class_exists( $class ) ) {
			return;
		}
		$fonts = get_posts( array( 'post_type' => 'elementor_font', 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
		if ( empty( $fonts ) ) {
			return;
		}
		$custom_fonts = new $class();
		foreach ( $fonts as $font_id ) {
			$css = $custom_fonts->generate_font_face( $font_id );
			if ( $css ) {
				update_post_meta( $font_id, 'elementor_font_face', $css );
			}
		}
	}

	/* ====================================================================== */
	/* Helpers                                                                 */
	/* ====================================================================== */

	private function is_elementor_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$el = \Elementor\Plugin::$instance;
		return ( isset( $el->editor ) && $el->editor->is_edit_mode() )
			|| ( isset( $el->preview ) && $el->preview->is_preview_mode() );
	}
}

new ASN_Performance();
