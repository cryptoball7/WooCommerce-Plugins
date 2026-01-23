<?php
/**
 * Plugin Name: Agent Commerce – Catalog
 * Description: Read-only, deterministic catalog exposure for AI agents.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Agent_Commerce_Catalog {

    const API_NAMESPACE = 'agent-commerce/v1/catalog';
    const SCHEMA_VERSION = '1.0';
    const REQUIRED_SCOPE = 'catalog:read';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /* --------------------------------------------------------------------- */
    /* REST ROUTES */
    /* --------------------------------------------------------------------- */

    public function register_routes() {
        register_rest_route(self::API_NAMESPACE, '/meta', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_meta'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route(self::API_NAMESPACE, '/products', [
            'methods'  => 'GET',
            'callback' => [$this, 'list_products'],
            'permission_callback' => [$this, 'authorize_request'],
            'args' => [
                'limit' => ['validate_callback' => 'is_numeric'],
                'cursor' => [],
                'category' => [],
                'in_stock' => [],
            ],
        ]);

        register_rest_route(self::API_NAMESPACE, '/products/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_product'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route(self::API_NAMESPACE, '/policies', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_policies'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);
    }

    /* --------------------------------------------------------------------- */
    /* AUTHORIZATION */
    /* --------------------------------------------------------------------- */

    public function authorize_request(WP_REST_Request $request) {
        $auth = $request->get_header('authorization');
        $agent_identity = $request->get_header('x-agent-identity');
        $agent_context = $request->get_header('x-agent-context');

        if (!$auth) {
            return new WP_Error('agent_auth_missing', 'Missing Authorization header', ['status' => 401]);
        }

        if (!$agent_identity || !$agent_context) {
            return new WP_Error('agent_headers_invalid', 'Missing agent headers', ['status' => 400]);
        }

        // Trust Agent Core to validate token + scope
        $scopes = apply_filters('agent_core_scopes_from_token', [], $auth);

        if (!in_array(self::REQUIRED_SCOPE, $scopes, true)) {
            return new WP_Error('agent_scope_missing', 'Missing required scope', ['status' => 403]);
        }

        return true;
    }

    /* --------------------------------------------------------------------- */
    /* META */
    /* --------------------------------------------------------------------- */

    public function get_meta() {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => '1.0.0',
            'supported_schema_versions' => [self::SCHEMA_VERSION],
            'extensions' => new stdClass(),
        ];
    }

    /* --------------------------------------------------------------------- */
    /* PRODUCTS */
    /* --------------------------------------------------------------------- */

    public function list_products(WP_REST_Request $request) {
        $limit = min((int) $request->get_param('limit') ?: 10, 100);
        $cursor = $request->get_param('cursor');

        $args = [
            'status' => 'publish',
            'limit' => $limit,
            'offset' => $cursor ? intval($cursor) : 0,
            'type' => 'simple',
        ];

        if ($request->get_param('in_stock') !== null) {
            $args['stock_status'] = $request->get_param('in_stock') === 'true' ? 'instock' : 'outofstock';
        }

        if ($category = $request->get_param('category')) {
            $args['category'] = [$category];
        }

        $query = new WC_Product_Query($args);
        $products = $query->get_products();

        $summaries = [];
        foreach ($products as $product) {
            $summaries[] = $this->product_summary($product);
        }

        do_action('agent_core_emit_event', 'agent.catalog.listed', [
            'agent_id' => $this->current_agent_id(),
            'product_ids' => wp_list_pluck($products, 'id'),
            'timestamp' => time(),
            'request_id' => wp_generate_uuid4(),
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'products' => $summaries,
            'next_cursor' => count($products) === $limit ? (string) ((int) $cursor + $limit) : null,
        ];
    }

    public function get_product(WP_REST_Request $request) {
        $product = wc_get_product((int) $request['id']);

        if (!$product || $product->get_status() !== 'publish') {
            return new WP_Error('not_found', 'Product not found', ['status' => 404]);
        }

        do_action('agent_core_emit_event', 'agent.catalog.viewed', [
            'agent_id' => $this->current_agent_id(),
            'product_id' => $product->get_id(),
            'timestamp' => time(),
            'request_id' => wp_generate_uuid4(),
        ]);

        return $this->product_summary($product);
    }

    /* --------------------------------------------------------------------- */
    /* PRODUCT SUMMARY */
    /* --------------------------------------------------------------------- */

    private function product_summary(WC_Product $product) {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'product_id' => (string) $product->get_id(),
            'name' => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_short_description()),
            'price' => [
                'amount_minor' => (int) round($product->get_price() * 100),
                'currency' => get_woocommerce_currency(),
            ],
            'in_stock' => $product->is_in_stock(),
            'categories' => wp_list_pluck($product->get_category_ids(), null),
            'images' => array_map('wp_get_attachment_url', $product->get_image_id() ? [$product->get_image_id()] : []),
            'extensions' => new stdClass(),
        ];
    }

    /* --------------------------------------------------------------------- */
    /* POLICIES */
    /* --------------------------------------------------------------------- */

    public function get_policies() {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'shipping' => [
                'regions' => ['US', 'EU'],
                'estimated_days' => '3-5',
            ],
            'returns' => [
                'window_days' => 30,
                'conditions' => 'unused',
            ],
            'taxes' => [
                'included' => wc_prices_include_tax(),
            ],
            'extensions' => new stdClass(),
        ];
    }

    /* --------------------------------------------------------------------- */
    /* HELPERS */
    /* --------------------------------------------------------------------- */

    private function current_agent_id() {
        return apply_filters('agent_core_current_agent_id', null);
    }
}

new Agent_Commerce_Catalog();
