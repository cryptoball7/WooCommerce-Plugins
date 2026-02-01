<?php
/**
 * Plugin Name: Agent Commerce – Core
 * Description: Shared infrastructure for agent-facing WooCommerce plugins.
 * Version: 0.1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) {
    exit;
}

// Simple autoloader (can be replaced with Composer later)
spl_autoload_register(function ($class) {
    if (strpos($class, 'AgentCommerce\\Core') !== 0) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('AgentCommerce\\Core\\', '', $class);
    $path = str_replace('\\', '/', $path) . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

use AgentCommerce\Core\Bootstrap;

add_action('plugins_loaded', function () {
    // Ensure WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return;
    }

    Bootstrap::init();
});
