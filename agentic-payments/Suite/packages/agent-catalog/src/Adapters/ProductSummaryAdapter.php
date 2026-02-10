<?php
namespace AgentCommerce\Catalog\Adapters;

use WC_Product;

/**
 * Maps WooCommerce products to agent-facing product_summary schemas.
 *
 * This adapter is intentionally:
 * - One-way (WC → Agent)
 * - Read-only
 * - Deterministic
 */
class ProductSummaryAdapter
{
    /**
     * Convert a WC_Product into a product_summary array.
     */
    public static function from_wc_product(WC_Product $product): array
    {
        return [
            'id' => self::get_stable_id($product),
            'title' => $product->get_name(),
            'price' => [
                'amount' => (float) $product->get_price(),
                'currency' => get_woocommerce_currency(),
            ],
            'in_stock' => $product->is_in_stock(),
            'type' => self::map_type($product),
        ];
    }

    /**
     * Generate a stable, agent-visible product identifier.
     *
     * Internal WooCommerce IDs must not be exposed directly.
     */
    protected static function get_stable_id(WC_Product $product): string
    {
        return 'wc_' . $product->get_id();
    }

    /**
     * Map WooCommerce product types to agent-safe values.
     */
    protected static function map_type(WC_Product $product): string
    {
        return match ($product->get_type()) {
            'simple'   => 'simple',
            'variable' => 'variable',
            default    => 'simple',
        };
    }
}
