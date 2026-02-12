<?php

use AgentCommerce\Catalog\Controllers\ProductsController;
use AgentCommerce\Catalog\Controllers\GetProductController;

/**
 * Registers catalog routes.
 *
 * This file is loaded by the agent-catalog plugin bootstrap.
 */
add_action('rest_api_init', function () {

    /**
     * List products
     * GET /agent-commerce/v1/catalog/products
     */
    register_rest_route('agent-commerce/v1', '/catalog/products', [
        'methods'  => 'GET',
        'callback' => [ProductsController::class, 'handle'],
        'permission_callback' => '__return_true',
    ]);

    /**
     * Get single product
     * GET /agent-commerce/v1/catalog/products/{id}
     */
    register_rest_route('agent-commerce/v1', '/catalog/products/(?P<id>[a-zA-Z0-9_\-]+)', [
        'methods'  => 'GET',
        'callback' => [GetProductController::class, 'handle'],
        'permission_callback' => '__return_true',
    ]);

});
