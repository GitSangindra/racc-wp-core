<?php
/**
 * RACC Custom Theme functions and definitions
 *
 * @package RACC_Theme
 */

if ( ! function_exists( 'racc_theme_setup' ) ) :
    function racc_theme_setup() {
        // Add default posts and comments RSS feed links to head.
        add_theme_support( 'automatic-feed-links' );

        // Let WordPress manage the document title.
        add_theme_support( 'title-tag' );

        // Enable support for Post Thumbnails on posts and pages.
        add_theme_support( 'post-thumbnails' );

        // Switch default core markup for search form, comment form, and comments to output valid HTML5.
        add_theme_support( 'html5', array(
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
        ) );
    }
endif;
add_action( 'after_setup_theme', 'racc_theme_setup' );

function racc_theme_scripts() {
    wp_enqueue_style( 'racc-theme-style', get_stylesheet_uri(), array(), '1.0.0' );
}
add_action( 'wp_enqueue_scripts', 'racc_theme_scripts' );
