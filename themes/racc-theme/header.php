<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
    <style>
        /* Basic Header Styling */
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .racc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 5%;
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .racc-logo img {
            max-height: 50px;
            width: auto;
        }
        .racc-logo a {
            font-size: 24px;
            font-weight: bold;
            color: #004aad;
            text-decoration: none;
        }
        .racc-nav ul {
            list-style: none;
            display: flex;
            margin: 0;
            padding: 0;
            gap: 20px;
        }
        .racc-nav a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            font-size: 15px;
            transition: color 0.3s ease;
        }
        .racc-nav a:hover {
            color: #004aad;
        }
        .racc-header-cta .racc-btn {
            background-color: #004aad;
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.3s ease;
        }
        .racc-header-cta .racc-btn:hover {
            background-color: #003070;
        }
    </style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="racc-header">
    <div class="racc-logo">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php 
            $custom_logo_id = get_theme_mod( 'custom_logo' );
            $logo = wp_get_attachment_image_src( $custom_logo_id , 'full' );
            if ( has_custom_logo() ) {
                echo '<img src="' . esc_url( $logo[0] ) . '" alt="' . get_bloginfo( 'name' ) . '">';
            } else {
                echo get_bloginfo( 'name' );
            }
            ?>
        </a>
    </div>
    
    <nav class="racc-nav">
        <?php
        wp_nav_menu( array(
            'theme_location' => 'primary',
            'menu_id'        => 'primary-menu',
            'fallback_cb'    => false,
        ) );
        ?>
    </nav>

    <div class="racc-header-cta">
        <a href="<?php echo esc_url( home_url( '/booking' ) ); ?>" class="racc-btn">Book a Consultation</a>
    </div>
</header>
