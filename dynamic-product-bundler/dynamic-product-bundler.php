<?php
/**
 * Plugin Name: Dynamic Product Bundler
 * Description: Let customers build their own product bundles with dynamic pricing, cart metadata, and composite product handling for WooCommerce.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: dpb
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Minimum WooCommerce check
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>Dynamic Product Bundler requires WooCommerce to be installed and active.</p></div>';
        } );
        return;
    }

    require_once __DIR__ . '/includes/class-dpb-bundler.php';
    DPB_Bundler::init();
} );
