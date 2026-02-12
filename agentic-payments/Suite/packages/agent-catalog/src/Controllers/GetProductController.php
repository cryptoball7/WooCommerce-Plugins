<?php
namespace AgentCommerce\Catalog\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Product;
use AgentCommerce\Catalog\Adapters\ProductSummaryAdapter;
use AgentCommerce\Core\Validation\Validator;
use AgentCommerce\Core\Http\Bootstrap;

/**
 * GET /agent-commerce/v1/catalog/products/{id}
 */
class GetProductController
{
    public static function handle(WP_REST_Request $request)
    {
        $agent = $request->get_attribute('agent');

        if (is_wp_error($agent)) {
            return $agent;
        }

        $id = $request['id'] ?? null;

        if (!$id) {
            return Bootstrap::error(
                'missing_product_id',
                'Product id is required',
                null,
                400
            );
        }

        $wc_id = self::extract_wc_id($id);

        if (!$wc_id) {
            return Bootstrap::error(
                'invalid_product_id',
                'Invalid product id format',
                ['received' => $id],
                400
            );
        }

        $product = wc_get_product($wc_id);

        if (!$product instanceof WC_Product) {
            return Bootstrap::error(
                'product_not_found',
                'Product not found',
                ['id' => $id],
                404
            );
        }

        $data = ProductSummaryAdapter::from_wc_product($product);

        $validated = Validator::validate(
            $data,
            AGENT_COMMERCE_PATH . '/schemas/v1/product_summary.json'
        );

        if (is_wp_error($validated)) {
            return Bootstrap::error(
                $validated->get_error_code(),
                $validated->get_error_message(),
                $validated->get_error_data(),
                500
            );
        }

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'request_id' => Bootstrap::request_id(),
                'timestamp'  => time(),
            ],
        ], 200);
    }

    /**
     * Convert agent-safe id → WooCommerce id
     */
    protected static function extract_wc_id(string $id): ?int
    {
        if (!str_starts_with($id, 'wc_')) {
            return null;
        }

        $value = substr($id, 3);

        if (!ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
