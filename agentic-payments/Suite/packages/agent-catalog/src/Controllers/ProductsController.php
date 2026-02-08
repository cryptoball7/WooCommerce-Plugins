<?php
namespace AgentCommerce\Catalog\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use AgentCommerce\Core\Bootstrap;
use AgentCommerce\Core\Request\Envelope;
use AgentCommerce\Core\Auth\Scope;
use AgentCommerce\Core\Schemas\ResponseValidator;

class ProductsController
{
    public static function register_routes(): void
    {
        register_rest_route(
            Bootstrap::API_NAMESPACE,
            '/catalog/products',
            [
                'methods'  => 'GET',
                'callback' => [self::class, 'list_products'],
                'permission_callback' => '__return_true', // scope checks later
            ]
        );
    }

    public static function list_products(WP_REST_Request $request): WP_REST_Response
    {
        $envelope = Envelope::parse($request);

        if (is_wp_error($envelope)) {
            return Bootstrap::error(
                $envelope->get_error_code(),
                $envelope->get_error_message(),
                $envelope->get_error_data(),
                400
            );
        }

        $scope_check = Scope::require(
            $envelope['agent_context'],
            'catalog:read'
        );
        
        if (is_wp_error($scope_check)) {
            return Bootstrap::error(
                $scope_check->get_error_code(),
                $scope_check->get_error_message(),
                $scope_check->get_error_data(),
                403
            );
        }

        // Mock data (schema-compliant)
        $products = [
            [
                'id' => 'sku_123',
                'title' => 'Example Product',
                'price' => [
                    'amount' => 19.99,
                    'currency' => 'USD'
                ],
                'in_stock' => true,
                'type' => 'simple'
            ]
        ];

        $response = [
            'products' => $products,
            'pagination' => [
                'page' => 1,
                'per_page' => 20,
                'total_items' => 1,
                'total_pages' => 1
            ]
        ];
        
        $validation = ResponseValidator::validate(
            $response,
            AGENT_COMMERCE_PATH . '/schemas/v1/catalog_products_response.json'
        );
        
        if (is_wp_error($validation)) {
            return Bootstrap::error(
                $validation->get_error_code(),
                $validation->get_error_message(),
                $validation->get_error_data(),
                500
            );
        }
        
        return new WP_REST_Response($response, 200);

    }
}
