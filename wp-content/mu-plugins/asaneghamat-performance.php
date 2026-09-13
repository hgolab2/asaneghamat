<?php
/**
 * Plugin Name: Asan Eghamat – Performance & Accessibility
 * Description: PageSpeed/Lighthouse optimizations for asaneghamat.com (fonts, LCP image, head cleanup, accessibility fixes). No WordPress core files are modified.
 * Version:     1.0.0
 * Author:      Asan Eghamat
 */

defined( 'ABSPATH' ) || exit;

define( 'ASN_PERF_VERSION', '1.0.0' );

final class ASN_Performance {

	/** @var string Child theme URL (no trailing slash). */
	private $child_uri;

	public function __construct() {
		$this->child_uri = untrailingslashit( get_stylesheet_directory_uri() );

		// ---- Fonts ---------------------------------------------------------
		add_filter( 'elementor_pro/custom_fonts/font_display', array( $this, 'font_display_swap' ) );
		add_filter( 'pre_option_elementor_font_display', array( $this, 'font_display_swap' ) );
		add_action( 'wp_head', array( $this, 'preload_assets' ), 2 );
		add_action( 'wp_head', array( $this, 'font_face_override' ), 999 );

		// ---- Head / asset cleanup -------------------------------------------
		add_action( 'init', array( $this, 'disable_emojis' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_unused_assets' ), 100 );
		add_action( 'init', array( $this, 'cleanup_head_links' ) );

		// ---- WP Rocket integration -------------------------------------------
		add_filter( 'rocket_lazyload_excluded_src', array( $this, 'rocket_lazyload_exclusions' ) );

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
	 */
	public function preload_assets() {
		if ( is_admin() || $this->is_elementor_editor() ) {
			return;
		}

		$fonts = $this->child_uri . '/assets/fonts/';
		echo '<link rel="preload" href="' . esc_url( $fonts . 'IRANSansX-Regular.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
		echo '<link rel="preload" href="' . esc_url( $fonts . 'IRANSansX-Bold.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";

		if ( is_front_page() ) {
			$img = $this->child_uri . '/assets/img/';
			echo '<link rel="preload" as="image" href="' . esc_url( $img . 'home-bg-mobile.webp' ) . '" media="(max-width: 767px)" fetchpriority="high">' . "\n";
			echo '<link rel="preload" as="image" href="' . esc_url( $img . 'home-bg-desktop.webp' ) . '" media="(min-width: 768px)" fetchpriority="high">' . "\n";
		}
	}

	/**
	 * Elementor Pro registers the "webcity" family with five weights that
	 * point at five .woff files, but only two of them are unique (Regular and
	 * Bold, byte-identical copies). Redeclaring the family here – after
	 * Elementor's CSS – makes the browser download two woff2 files instead of
	 * four woff files, and adds font-display: swap.
	 */
	public function font_face_override() {
		if ( is_admin() || $this->is_elementor_editor() ) {
			return;
		}

		$fonts   = $this->child_uri . '/assets/fonts/';
		$regular = esc_url( $fonts . 'IRANSansX-Regular.woff2' );
		$bold    = esc_url( $fonts . 'IRANSansX-Bold.woff2' );

		$css  = '';
		foreach ( array( 400 => $regular, 500 => $regular, 600 => $bold, 700 => $bold, 800 => $bold ) as $weight => $url ) {
			$css .= "@font-face{font-family:'webcity';font-style:normal;font-weight:{$weight};font-display:swap;src:url('{$url}') format('woff2')}";
		}

		echo '<style id="asn-fonts">' . $css . '</style>' . "\n";
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

		// Gutenberg global styles / classic-theme styles are not used by the
		// Elementor-built front page.
		if ( is_front_page() ) {
			wp_dequeue_style( 'global-styles' );
			wp_dequeue_style( 'classic-theme-styles' );
			wp_dequeue_style( 'wp-block-library' );
		}
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

		foreach ( array( 'fix_overlay_links', 'fix_icon_only_links', 'swap_logo' ) as $step ) {
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
				$tag = str_replace( 'https://asaneghamat.com/wp-content/uploads/2021/07/asaneghamat.webp', $small, $tag );
				$tag = preg_replace( '#\bwidth="759"#', 'width="220"', $tag );
				$tag = preg_replace( '#\bheight="767"#', 'height="222"', $tag );
				return $tag;
			},
			$html
		);
	}

	/* ====================================================================== */
	/* Maintenance                                                             */
	/* ====================================================================== */

	/**
	 * After a new version of this file is deployed, regenerate Elementor CSS
	 * (so the font-display filter takes effect) and purge the page cache.
	 */
	public function maybe_flush_caches() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'asn_perf_version' ) === ASN_PERF_VERSION ) {
			return;
		}

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
