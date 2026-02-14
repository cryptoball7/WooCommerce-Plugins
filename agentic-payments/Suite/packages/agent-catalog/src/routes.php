<?php

use AgentCommerce\Catalog\Controllers\ProductsController;
use AgentCommerce\Catalog\Controllers\GetProductController;
use AgentCommerce\Core\Middleware\MiddlewareRunner;
use AgentCommerce\Core\Middleware\AgentAuthMiddleware;

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
        'permission_callback' => function ($request) {
            return MiddlewareRunner::run($request, [
                    [AgentAuthMiddleware::class, 'handle'],
            ]);
        },

    ]);

    /**
     * Get single product
     * GET /agent-commerce/v1/catalog/products/{id}
     */
    register_rest_route('agent-commerce/v1', '/catalog/products/(?P<id>[a-zA-Z0-9_\-]+)', [
        'methods'  => 'GET',
        'callback' => [GetProductController::class, 'handle'],
        function ($request) {
            return MiddlewareRunner::run($request, [
                    [AgentAuthMiddleware::class, 'handle'],
            ]);
        },
    ]);

});
