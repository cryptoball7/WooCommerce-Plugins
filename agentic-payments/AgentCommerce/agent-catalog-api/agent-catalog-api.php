<?php
/*
Plugin Name: Agent Catalog API
Description: Machine-readable WooCommerce catalog API for AI agents
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
*/

if (!defined('ABSPATH')) {
    exit;
}

define('AGENT_CATALOG_PATH', plugin_dir_path(__FILE__));

require_once AGENT_CATALOG_PATH . 'includes/class-agent-catalog-controller.php';
require_once AGENT_CATALOG_PATH . 'includes/class-agent-catalog-schema.php';

add_action('rest_api_init', function () {

    $controller = new Agent_Catalog_Controller();
    $controller->register_routes();

});