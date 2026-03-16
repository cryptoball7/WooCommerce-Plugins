<?php

class Agent_Catalog_Controller {

    public function register_routes() {

        register_rest_route('agent-commerce/v1', '/catalog/products', [
            'methods' => 'GET',
            'callback' => [$this, 'get_products'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('agent-commerce/v1', '/catalog/products/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('agent-commerce/v1', '/catalog/feed', [
            'methods' => 'GET',
            'callback' => [$this, 'get_feed'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function get_products($request) {

        $limit = $request->get_param('limit') ?: 20;

        $args = [
            'limit' => $limit,
            'status' => 'publish'
        ];

        $products = wc_get_products($args);

        $data = [];

        foreach ($products as $product) {

            $data[] = Agent_Catalog_Schema::format_product($product);

        }

        return rest_ensure_response([
            'products' => $data
        ]);
    }

    public function get_product($request) {

        $id = $request['id'];

        $product = wc_get_product($id);

        if (!$product) {
            return new WP_Error('not_found', 'Product not found', ['status' => 404]);
        }

        return rest_ensure_response([
            'product' => Agent_Catalog_Schema::format_product_detail($product)
        ]);
    }

    public function get_feed($request) {

        $args = [
            'limit' => -1,
            'status' => 'publish'
        ];

        $products = wc_get_products($args);

        $data = [];

        foreach ($products as $product) {

            $data[] = Agent_Catalog_Schema::format_product($product);

        }

        return rest_ensure_response([
            'version' => '1.0',
            'generated_at' => current_time('mysql'),
            'products' => $data
        ]);
    }
}