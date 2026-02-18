<?php

use AgentCommerce\Catalog\Controllers\ProductsController;
use AgentCommerce\Catalog\Controllers\GetProductController;

use AgentCommerce\Core\Middleware\MiddlewareRunner;
use AgentCommerce\Core\Middleware\AgentAuthMiddleware;
use AgentCommerce\Core\Middleware\CapabilityMiddleware;
use AgentCommerce\Core\Middleware\RateLimitMiddleware;

/**
 * Registers catalog routes.
 *
 * This file is loaded by the agent-catalog plugin bootstrap.
 */
add_action('rest_api_init', function () {

    /**
     * Shared middleware stack builder
     */
    $catalog_permissions = function ($request) {

        $request->set_attribute('required_scopes', [
            'catalog:read'
        ]);

        $request->set_attribute('rate_limit', [
            'limit'  => 60,
            'window' => 60
        ]);

        return MiddlewareRunner::run($request, [
            [AgentAuthMiddleware::class, 'handle'],
            [CapabilityMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'handle'],
        ]);
    };


    /**
     * List products
     * GET /agent-commerce/v1/catalog/products
     */
    register_rest_route('agent-commerce/v1', '/catalog/products', [
        'methods'  => 'GET',
        'callback' => [ProductsController::class, 'handle'],
        'permission_callback' => $catalog_permissions,
    ]);


    /**
     * Get single product
     * GET /agent-commerce/v1/catalog/products/{id}
     */
    register_rest_route('agent-commerce/v1', '/catalog/products/(?P<id>[a-zA-Z0-9_\\-]+)', [
        'methods'  => 'GET',
        'callback' => [GetProductController::class, 'handle'],
        'permission_callback' => $catalog_permissions,
    ]);

});
