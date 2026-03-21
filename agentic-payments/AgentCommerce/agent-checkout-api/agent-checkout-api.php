<?php
/*
Plugin Name: Agent Checkout API
Description: Programmatic WooCommerce checkout sessions for AI agents
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
*/

if (!defined('ABSPATH')) {
    exit;
}

define('AGENT_CHECKOUT_PATH', plugin_dir_path(__FILE__));

require_once AGENT_CHECKOUT_PATH . 'includes/class-agent-checkout-db.php';
require_once AGENT_CHECKOUT_PATH . 'includes/class-agent-checkout-session.php';
require_once AGENT_CHECKOUT_PATH . 'includes/class-agent-checkout-controller.php';

register_activation_hook(__FILE__, ['Agent_Checkout_DB', 'install']);

add_action('rest_api_init', function () {

    $controller = new Agent_Checkout_Controller();
    $controller->register_routes();

});