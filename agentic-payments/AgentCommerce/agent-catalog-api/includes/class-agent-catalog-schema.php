<?php

class Agent_Catalog_Schema {

    public static function format_product($product) {

        return [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'price' => [
                'amount' => $product->get_price(),
                'currency' => get_woocommerce_currency()
            ],
            'inventory' => $product->get_stock_quantity(),
            'permalink' => get_permalink($product->get_id())
        ];
    }

    public static function format_product_detail($product) {

        return [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'price' => [
                'amount' => $product->get_price(),
                'currency' => get_woocommerce_currency()
            ],
            'inventory' => $product->get_stock_quantity(),
            'shipping_estimate_days' => 3,
            'permalink' => get_permalink($product->get_id())
        ];
    }
}