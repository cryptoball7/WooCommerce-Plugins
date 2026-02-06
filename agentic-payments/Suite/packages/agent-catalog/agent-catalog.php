<?php
/**
 * Plugin Name: Agent Commerce – Catalog
 * Description: Agent-facing catalog discovery APIs for WooCommerce.
 * Version: 0.1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AgentCommerce\\Core\\Bootstrap')) {
    return;
}

// Autoloader for this plugin
spl_autoload_register(function ($class) {
    if (strpos($class, 'AgentCommerce\\\\Catalog') !== 0) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('AgentCommerce\\\\Catalog\\\\', '', $class);
    $path = str_replace('\\\\', '/', $path) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

use AgentCommerce\Catalog\Controllers\ProductsController;

add_action('rest_api_init', function () {
    ProductsController::register_routes();
});
