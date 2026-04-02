<?php
// محصول test CLI script for WooCommerce

// Bootstrap WordPress
require_once __DIR__ . '/wp-load.php';

// Ensure WooCommerce is loaded
if (!class_exists('WooCommerce')) {
    echo "WooCommerce is not active.\n";
    exit(1);
}

/**
 * Create a test product
 *
 * @return int Product ID
 */
function create_test_product() {
    $product = new WC_Product_Simple();

    $product->set_name('CLI Test Product ' . time());
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description('This is a test product created via CLI.');
    $product->set_short_description('CLI test product.');
    $product->set_sku('cli-test-' . uniqid());
    $product->set_regular_price('9.99');
    $product->set_manage_stock(false);

    $product_id = $product->save();

    echo "Created product with ID: {$product_id}\n";

    return $product_id;
}

/**
 * Delete a product
 *
 * @param int $product_id
 * @return void
 */
function delete_test_product($product_id) {
    if (!$product_id) {
        echo "Invalid product ID.\n";
        return;
    }

    $product = wc_get_product($product_id);

    if (!$product) {
        echo "Product not found.\n";
        return;
    }

    // Force delete (bypass trash)
    wp_delete_post($product_id, true);

    echo "Deleted product with ID: {$product_id}\n";
}


// ----------------------
// Example usage
// ----------------------

$product_id = create_test_product();

// 👉 Insert your tests here
// Example:
// $product = wc_get_product($product_id);
// var_dump($product->get_price());

delete_test_product($product_id);