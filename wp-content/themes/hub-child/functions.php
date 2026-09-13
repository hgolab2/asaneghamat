<?php

add_action( 'wp_enqueue_scripts', 'liquid_child_theme_style', 99 );

function liquid_parent_theme_scripts() {
    wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
}
function liquid_child_theme_style(){
    // Version by theme version so browser/Rocket caches bust on deploy.
    wp_enqueue_style( 'child-hub-style', get_stylesheet_directory_uri() . '/style.css', array(), wp_get_theme()->get( 'Version' ) );
}
