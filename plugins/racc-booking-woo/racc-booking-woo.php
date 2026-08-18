<?php
/**
 * Plugin Name: RACC Booking — WooCommerce Bridge
 * Plugin URI:  https://racc.net.au
 * Description: Connects RACC Booking engine with WooCommerce. Manages booking services as WC products, handles payment before confirming appointments.
 * Version:     1.0.0
 * Author:      RACC
 * Author URI:  https://racc.net.au
 * License:     GPL-2.0+
 * Text Domain: racc-booking-woo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RACC_BOOKING_WOO_VERSION', '1.0.0' );
define( 'RACC_BOOKING_WOO_FILE',    __FILE__ );
define( 'RACC_BOOKING_WOO_PATH',    plugin_dir_path( __FILE__ ) );
define( 'RACC_BOOKING_WOO_URL',     plugin_dir_url( __FILE__ ) );

// ─── Compatibility declarations ───────────────────────────────────────────────
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// ─── Activation ───────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'racc_booking_woo_activate' );

function racc_booking_woo_ensure_booking_columns() {
    global $wpdb;

    $table = $wpdb->prefix . 'racc_bookings';

    $required_columns = [
        'woo_product_id' => "ALTER TABLE `{$table}` ADD COLUMN `woo_product_id` bigint(20) unsigned DEFAULT NULL COMMENT 'WooCommerce Product ID' AFTER `service_type`",
        'woo_order_id'   => "ALTER TABLE `{$table}` ADD COLUMN `woo_order_id` bigint(20) unsigned DEFAULT NULL COMMENT 'WooCommerce Order ID' AFTER `agentcis_sync_error`",
    ];

    foreach ( $required_columns as $column_name => $alter_sql ) {
        $column_exists = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $column_name
        ) );

        if ( empty( $column_exists ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $alter_sql );
        }
    }
}

function racc_booking_woo_activate() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'RACC Booking WooCommerce Bridge requires WooCommerce to be installed and active.', 'racc-booking-woo' ) );
    }

    if ( ! defined( 'RACC_BOOKING_VERSION' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'RACC Booking WooCommerce Bridge requires the RACC Booking plugin to be installed and active.', 'racc-booking-woo' ) );
    }

    racc_booking_woo_ensure_booking_columns();

    // Ensure default option exists.
    if ( ! get_option( 'racc_woo_bridge_settings' ) ) {
        update_option( 'racc_woo_bridge_settings', [
            'category_slug'        => 'booking-services',
            'pending_hold_minutes' => 30,
            'price_display'        => 'yes',
        ] );
    }

    flush_rewrite_rules();
}

// ─── Boot ─────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'racc_booking_woo_init', 20 );

function racc_booking_woo_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'RACC Booking WooCommerce Bridge requires WooCommerce. Please install and activate WooCommerce.', 'racc-booking-woo' );
            echo '</p></div>';
        } );
        return;
    }

    if ( ! defined( 'RACC_BOOKING_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'RACC Booking WooCommerce Bridge requires the RACC Booking plugin to be installed and active.', 'racc-booking-woo' );
            echo '</p></div>';
        } );
        return;
    }

    racc_booking_woo_ensure_booking_columns();

    require_once RACC_BOOKING_WOO_PATH . 'includes/class-woo-bridge.php';

    new RACC_Booking_Woo\Woo_Bridge();
}
