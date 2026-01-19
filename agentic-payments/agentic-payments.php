<?php
/*
Plugin Name: Agentic Payments
Description: Adds support for agentic payments: REST API endpoints for agent-initiated payments and a WooCommerce payment gateway. Secure HMAC signing, webhook handling, admin settings, and hooks for developers.
Version: 1.0.0
Author: Cryptoball cryptoball7@gmail.com
Text Domain: agentic-payments
*/

/*
 TODO:

Ensure every admin action checks:

current_user_can( 'manage_woocommerce' )


Places to confirm:

Add agent

Update agent

Rotate secret

Delete agent

View admin page
*/

function agentic_require_admin() {
  if ( ! current_user_can( 'manage_woocommerce' ) ) {
      wp_die( 'Unauthorized', 403 );
  }
}

if (!defined('ABSPATH')) {
    exit;
}

class Agentic_Payments_Plugin
{

    const OPTION_KEY = 'agentic_payments_options';
    const REST_NAMESPACE = 'agentic-payments/v1';

    private static $instance = null;
    private $options = array();

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function maybe_generate_secret()
    {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(32, false);
        }
        return bin2hex(random_bytes(16)); // fallback for early load
    }

    private function init()
    {
        $this->options = get_option(self::OPTION_KEY, array(
            'enabled' => 'yes',
            'shared_secret' => '',
            $this->maybe_generate_secret(),
            'webhook_secret' => '',
            $this->maybe_generate_secret(),
            'allowed_agents' => '', // comma-separated allowed agent IDs (optional)
            'log' => 'no',
        ));

        register_activation_hook(__FILE__, array($this, 'on_activate'));
        register_uninstall_hook(__FILE__, array('Agentic_Payments_Plugin', 'on_uninstall'));

        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // If WooCommerce exists register gateway
        add_action('plugins_loaded', array($this, 'maybe_register_gateway'), 20);

        // endpoint to accept webhooks from payment processors / agent platform
        add_action('init', array($this, 'maybe_handle_webhook'));

        add_action('rest_api_init', function () {
            register_rest_route(
                'agentic/v1',
                '/payment-complete',
                [
                    'methods' => 'POST',
                    'callback' => 'agentic_handle_payment_complete',
                    'permission_callback' => '__return_true', // auth handled manually
                ]
            );
        });

        add_action('rest_api_init', function () {
            register_rest_route(
                'agentic/v1',
                '/refund',
                [
                    'methods' => 'POST',
                    'callback' => 'agentic_handle_refund',
                    'permission_callback' => '__return_true', // HMAC handles auth
                ]
            );
        });

    }

    public function on_activate()
    {
        // ensure options exist
        if (!get_option(self::OPTION_KEY)) {
            update_option(self::OPTION_KEY, $this->options);
        }
    }

    public static function on_uninstall()
    {
        // remove options on uninstall
        delete_option(self::OPTION_KEY);
    }

    private function log($message)
    {
        if (isset($this->options['log']) && $this->options['log'] === 'yes') {
            if (!is_scalar($message)) {
                $message = print_r($message, true);
            }
            error_log('[AgenticPayments] ' . $message);
        }
    }

    /* -------------------------
     * Admin settings UI
     * ------------------------- */
    public function register_admin_page()
    {
        add_options_page(
            'Agentic Payments',
            'Agentic Payments',
            'manage_options',
            'agentic-payments',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings()
    {
        register_setting('agentic_payments_group', self::OPTION_KEY, array($this, 'sanitize_options'));
        add_settings_section('agentic_main', 'Agentic Payments Settings', null, 'agentic-payments');

        add_settings_field('enabled', 'Enable Agentic Payments', array($this, 'field_enabled'), 'agentic-payments', 'agentic_main');
        add_settings_field('shared_secret', 'Shared Secret (for agent signing)', array($this, 'field_shared_secret'), 'agentic-payments', 'agentic_main');
        add_settings_field('webhook_secret', 'Webhook Secret (for incoming webhooks)', array($this, 'field_webhook_secret'), 'agentic-payments', 'agentic_main');
        add_settings_field('allowed_agents', 'Allowed Agent IDs (comma-separated)', array($this, 'field_allowed_agents'), 'agentic-payments', 'agentic_main');
        add_settings_field('log', 'Enable Logging', array($this, 'field_log'), 'agentic-payments', 'agentic_main');
    }

    public function sanitize_options($input)
    {
        $out = array();
        $out['enabled'] = (isset($input['enabled']) && $input['enabled'] === 'yes') ? 'yes' : 'no';
        $out['shared_secret'] = sanitize_text_field($input['shared_secret']);
        if (empty($out['shared_secret'])) {
            $out['shared_secret'] = wp_generate_password(32, false);
        }
        $out['webhook_secret'] = sanitize_text_field($input['webhook_secret']);
        if (empty($out['webhook_secret'])) {
            $out['webhook_secret'] = wp_generate_password(32, false);
        }
        $out['allowed_agents'] = sanitize_text_field($input['allowed_agents']);
        $out['log'] = (isset($input['log']) && $input['log'] === 'yes') ? 'yes' : 'no';
        $this->options = $out;
        return $out;
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Agentic Payments</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('agentic_payments_group');
                do_settings_sections('agentic-payments');
                submit_button();
                ?>
            </form>
            <h2>Developer / Agent Info</h2>
            <p>REST endpoint to initiate payments:
                <code><?php echo esc_html(rest_url(self::REST_NAMESPACE . '/create')); ?></code>
            </p>
            <p>Shared secret (use in HMAC signing):</p>
            <pre
                style="background:#fff;padding:8px;border:1px solid #ddd;"><?php echo esc_html($this->options['shared_secret']); ?></pre>
            <p>Webhook secret (for verifying incoming webhooks):</p>
            <pre
                style="background:#fff;padding:8px;border:1px solid #ddd;"><?php echo esc_html($this->options['webhook_secret']); ?></pre>
            <p>Allowed agent IDs (optional): <code><?php echo esc_html($this->options['allowed_agents']); ?></code></p>
        </div>
        <?php
    }

    public function field_enabled()
    {
        $val = (isset($this->options['enabled']) && $this->options['enabled'] === 'yes') ? 'yes' : 'no';
        ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]">
            <option value="yes" <?php selected($val, 'yes'); ?>>Yes</option>
            <option value="no" <?php selected($val, 'no'); ?>>No</option>
        </select>
        <?php
    }

    public function field_shared_secret()
    {
        ?>
        <input type="text" style="width:60%;" name="<?php echo esc_attr(self::OPTION_KEY); ?>[shared_secret]"
            value="<?php echo esc_attr($this->options['shared_secret']); ?>" />
        <p class="description">Secret used to sign REST requests from agents (HMAC SHA256).</p>
        <?php
    }

    public function field_webhook_secret()
    {
        ?>
        <input type="text" style="width:60%;" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_secret]"
            value="<?php echo esc_attr($this->options['webhook_secret']); ?>" />
        <p class="description">Secret to verify incoming webhooks from payment processors/agent platforms.</p>
        <?php
    }

    public function field_allowed_agents()
    {
        ?>
        <input type="text" style="width:60%;" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allowed_agents]"
            value="<?php echo esc_attr($this->options['allowed_agents']); ?>" />
        <p class="description">Comma-separated list of allowed agent IDs. Leave blank to allow all agents that can sign
            correctly.</p>
        <?php
    }

    public function field_log()
    {
        $val = (isset($this->options['log']) && $this->options['log'] === 'yes') ? 'yes' : 'no';
        ?>
        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[log]">
            <option value="no" <?php selected($val, 'no'); ?>>No</option>
            <option value="yes" <?php selected($val, 'yes'); ?>>Yes</option>
        </select>
        <p class="description">Enable debug logging to error_log (useful during setup; disable in production).</p>
        <?php
    }

    /* -------------------------
     * REST API
     * ------------------------- */
    public function register_rest_routes()
    {
        register_rest_route(self::REST_NAMESPACE, '/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_create_payment'),
            'permission_callback' => '__return_true', // we verify via HMAC within callback
        ));
    }

    /**
     * Expected JSON POST:
     * {
     *   "agent_id": "agent-123",
     *   "order_id": 123,           // optional: WP/WooCommerce order ID
     *   "amount": "12.34",         // required if no order_id
     *   "currency": "USD",
     *   "description": "Purchase",
     *   "signature": "hmac-hex",    // HMAC SHA256 of payload JSON (without signature) using shared_secret
     *   "metadata": {}
     * }
     */
    public function rest_create_payment(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_REST_Response(array('error' => 'invalid_json'), 400);
        }

        // Required fields
        $agent_id = isset($params['agent_id']) ? sanitize_text_field($params['agent_id']) : '';
        $signature = isset($params['signature']) ? sanitize_text_field($params['signature']) : '';
        if (empty($agent_id) || empty($signature)) {
            return new WP_REST_Response(array('error' => 'missing_agent_or_signature'), 400);
        }

        // verify allowed agents if set
        if (!empty($this->options['allowed_agents'])) {
            $allowed = array_map('trim', explode(',', $this->options['allowed_agents']));
            if (!in_array($agent_id, $allowed, true)) {
                $this->log("Rejected agent {$agent_id}: not in allowed list.");
                return new WP_REST_Response(array('error' => 'agent_not_allowed'), 403);
            }
        }

        // Verify signature: signature should be HMAC SHA256 over canonicalized payload (sorted keys) without the signature field
        $payload_for_sign = $params;
        unset($payload_for_sign['signature']);
        // canonicalize: json encode with sorted keys
        ksort_recursive($payload_for_sign);
        $canonical = wp_json_encode($payload_for_sign);

        $expected = hash_hmac('sha256', $canonical, $this->options['shared_secret']);

        if (!hash_equals($expected, $signature)) {
            $this->log("Signature mismatch. Expected {$expected}, got {$signature}. Canonical: {$canonical}");
            return new WP_REST_Response(array('error' => 'invalid_signature'), 403);
        }

        // Now process payment or create order
        $order_id = isset($params['order_id']) ? intval($params['order_id']) : 0;
        $amount = isset($params['amount']) ? $params['amount'] : null;
        $currency = isset($params['currency']) ? sanitize_text_field($params['currency']) : null;
        $description = isset($params['description']) ? sanitize_text_field($params['description']) : '';
        $metadata = isset($params['metadata']) && is_array($params['metadata']) ? $params['metadata'] : array();

        do_action('agentic_payment_initiated_raw', $params); // raw hook for logging / 3rd party

        // If WooCommerce available and order_id provided -> attempt to process via gateway; otherwise simulate/create simple record
        if (class_exists('WooCommerce') && $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_REST_Response(array('error' => 'order_not_found'), 404);
            }

            // Developer can hook into this to process via custom gateway
            $result = apply_filters('agentic_process_woocommerce_order', array(
                'success' => true,
                'transaction_id' => 'agentic_wc_' . time() . '_' . wp_generate_password(6, false),
            ), $order, $params);

            if (is_array($result) && !empty($result['success'])) {
                // mark order paid if requested
                if (isset($result['mark_paid']) && $result['mark_paid']) {
                    $order->payment_complete($result['transaction_id']);
                }

                do_action('agentic_payment_processed', $order, $result);
                return new WP_REST_Response(array(
                    'success' => true,
                    'order_id' => $order_id,
                    'transaction_id' => $result['transaction_id'],
                ), 200);
            } else {
                $this->log('agentic process for order returned failure: ' . print_r($result, true));
                return new WP_REST_Response(array('error' => 'processing_failed'), 500);
            }

        } else {
            // create internal payment record (post type) or simulate
            $txn_id = 'agentic_txn_' . time() . '_' . wp_generate_password(6, false);
            $record = array(
                'post_title' => 'Agentic Payment ' . $txn_id,
                'post_type' => 'agentic_payment',
                'post_status' => 'publish',
                'meta_input' => array(
                    'agent_id' => $agent_id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description,
                    'metadata' => $metadata,
                    'transaction_id' => $txn_id,
                    'created_at' => current_time('mysql'),
                )
            );
            $post_id = wp_insert_post($record);

            do_action('agentic_payment_processed_nonwc', $post_id, $txn_id, $params);

            return new WP_REST_Response(array(
                'success' => true,
                'transaction_id' => $txn_id,
                'record_id' => $post_id,
            ), 200);
        }
    }

    /* -------------------------
     * Simple webhook handler (optional)
     * ------------------------- */
    public function maybe_handle_webhook()
    {
        // Example: if someone POSTs to /?agentic_webhook=1 we accept it
        if (isset($_GET['agentic_webhook']) && $_GET['agentic_webhook'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            $sig = isset($_SERVER['HTTP_X_AGENTIC_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_AGENTIC_SIGNATURE'])) : '';

            // verify
            $expected = hash_hmac('sha256', $raw, $this->options['webhook_secret']);
            if (!hash_equals($expected, $sig)) {
                status_header(403);
                echo 'invalid_signature';
                exit;
            }

            $payload = json_decode($raw, true);
            // you may want to verify payload structure
            $this->log('Received webhook: ' . print_r($payload, true));
            do_action('agentic_webhook_received', $payload);

            status_header(200);
            echo 'ok';
            exit;
        }
    }

    /* -------------------------
     * Register includes: custom post type for non-Woo payments
     * ------------------------- */
    public function register_post_type()
    {
        register_post_type('agentic_payment', array(
            'labels' => array(
                'name' => 'Agentic Payments',
                'singular_name' => 'Agentic Payment'
            ),
            'public' => false,
            'show_ui' => true,
            'supports' => array('title'),
        ));
    }

    /* -------------------------
     * WooCommerce Gateway
     * ------------------------- */
    public function maybe_register_gateway_nondebug()
    {

        // ensure custom post type still registers
        add_action('init', array($this, 'register_post_type'));

        add_action('woocommerce_loaded', function () {
            add_filter('woocommerce_payment_gateways', function ($methods) {
                $methods[] = 'WC_Gateway_Agentic';
                return $methods;
            });
        });
    }

    public function maybe_register_gateway()
    {

        // Still register post type
        add_action('init', array($this, 'register_post_type'));

    }




    public function add_wc_gateway($gateways)
    {
        $gateways[] = 'WC_Gateway_Agentic';
        return $gateways;
    }
}

/* -------------------------
 * Utility: recursive ksort for canonicalization
 * ------------------------- */
function ksort_recursive(&$array)
{
    if (!is_array($array)) {
        return;
    }
    ksort($array);
    foreach ($array as &$value) {
        if (is_array($value)) {
            ksort_recursive($value);
        }
    }
}

// -------------------------
// WooCommerce Gateway Class
// -------------------------

add_action('woocommerce_loaded', function () {

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // Load the gateway file if you have it in includes/
    // require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-agentic.php';

    if (!class_exists('WC_Gateway_Agentic')) {

        class WC_Gateway_Agentic extends WC_Payment_Gateway
        {

            public function __construct()
            {
                $this->id = 'agentic';
                $this->method_title = 'Agentic Payments';
                $this->method_description = 'Accept agent-initiated payments via the Agentic Payments plugin.';
                $this->has_fields = false;

                $this->init_form_fields();
                $this->init_settings();

                $this->enabled = $this->get_option('enabled', 'yes');

                $this->title = $this->get_option('title', 'Agentic (programmatic)');
                $this->description = $this->get_option('description', '');

                $this->supports = [
                    'products',
                    'default_credit_card_form'
                ];

                add_action(
                    'woocommerce_update_options_payment_gateways_' . $this->id,
                    array($this, 'process_admin_options')
                );

                $this->webhook_secret = $this->get_option('webhook_secret');
            }

            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => 'Enable/Disable',
                        'type' => 'checkbox',
                        'label' => 'Enable Agentic payment gateway',
                        'default' => 'no',
                    ),
                    'title' => array(
                        'title' => 'Title',
                        'type' => 'text',
                        'default' => 'Agentic (programmatic)',
                    ),
                    'description' => array(
                        'title' => 'Description',
                        'type' => 'textarea',
                        'default' => 'Pay through an agentic payment flow.',
                    ),

                    'webhook_secret' => [
                        'title' => 'Webhook Secret',
                        'type' => 'password',
                        'description' => 'Shared secret used to verify agent callbacks (HMAC).',
                        'default' => '',
                        'desc_tip' => true,
                    ],
                );
            }

            public function process_payment($order_id)
            {

                $order = wc_get_order($order_id);

                $order->update_status(
                    'pending',
                    'Awaiting agentic payment confirmation'
                );

                return [
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                ];
            }


            public function mark_order_paid($order_id)
            {
                $order = wc_get_order($order_id);
                $order->payment_complete();
            }


        }

    }

    // Now register the gateway
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        error_log('[AgenticPayments] Registering gateway via filter');
        $gateways[] = 'WC_Gateway_Agentic';
        return $gateways;
    });

    add_action('woocommerce_blocks_loaded', function () {

        if (!class_exists(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class)) {
            return;
        }
    });

});

function agentic_get_agents() {
    $agents = get_option( 'agentic_agents', [] );

    // If stored as JSON string (e.g. via WP-CLI), decode it
    if ( is_string( $agents ) ) {
        $decoded = json_decode( $agents, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $decoded;
        }
    }

    // Ensure we always return an array
    return is_array( $agents ) ? $agents : [];
}

function agentic_get_agent( $agent_id ) {
    $agents = agentic_get_agents();
    return $agents[ $agent_id ] ?? null;
}


function agentic_get_webhook_secret()
{
    $settings = get_option('agentic_payments_options', []);
    return $settings['webhook_secret'] ?? '';
}

add_action('rest_api_init', function () {
    register_rest_route(
        'agentic/v1',
        '/order-status/(?P<order_id>\d+)',
        [
            'methods' => 'GET',
            'callback' => 'agentic_check_order_status',
            'permission_callback' => '__return_true',
        ]
    );
});

function agentic_check_order_status(WP_REST_Request $request)
{

    $order_id = absint($request['order_id']);
    $order = wc_get_order($order_id);

    if (!$order) {
        return new WP_REST_Response(['error' => 'Order not found'], 404);
    }

    return new WP_REST_Response(
        [
            'status' => $order->get_status(),
            'is_paid' => $order->is_paid(),
            'completed' => (bool) $order->get_meta('_agentic_completed'),
        ],
        200
    );
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Agentic Agents',
        'Agentic Agents',
        'manage_woocommerce',
        'agentic-agents',
        'agentic_render_agents_page'
    );
});

function agentic_render_agents_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $agents = agentic_get_agents();

    // Handle delete request
    if ( isset( $_GET['delete_agent'] ) && check_admin_referer( 'agentic_delete_agent' ) ) {
        $delete_id = sanitize_key( $_GET['delete_agent'] );
        unset( $agents[ $delete_id ] );
        update_option( 'agentic_agents', $agents );
        wp_safe_redirect( admin_url( 'admin.php?page=agentic-agents' ) );
        exit;
    }
    ?>

    <div class="wrap">
        <h1>Agentic Agents</h1>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Agent ID</th>
                    <th>Status</th>
                    <th>Can Refund</th>
                    <th>Secret</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $agents ) ) : ?>
                    <tr><td colspan="5">No agents registered.</td></tr>
                <?php else : ?>
                    <?php foreach ( $agents as $agent_id => $agent ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $agent_id ); ?></code></td>
                            <td><?php echo ! empty( $agent['active'] ) ? 'Active' : 'Disabled'; ?></td>
                            <td><?php echo ! empty( $agent['can_refund'] ) ? 'Yes' : 'No'; ?></td>
                            <td>
                                <code><?php echo esc_html( substr( $agent['secret'], 0, 6 ) . '…' ); ?></code>
                            </td>
                            <td>
                                <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=agentic-agents&delete_agent=' . urlencode($agent_id)), 'agentic_delete_agent' ); ?>" class="button button-small">Delete</a>
                                <button class="button button-small agent-rotate-secret" data-agent="<?php echo esc_attr($agent_id); ?>">Rotate</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Add / Update Agent</h2>

        <form method="post">
            <?php wp_nonce_field( 'agentic_save_agent' ); ?>
            <table class="form-table">
                <tr>
                    <th>Agent ID</th>
                    <td><input name="agent_id" required /></td>
                </tr>
                <tr>
                    <th>Secret</th>
                    <td><input name="secret" value="<?php echo esc_attr( wp_generate_password(32, false) ); ?>" /></td>
                </tr>
                <tr>
                    <th>Can refund</th>
                    <td><input type="checkbox" name="can_refund" /></td>
                </tr>
                <tr>
                    <th>Active</th>
                    <td><input type="checkbox" name="active" checked /></td>
                </tr>
            </table>

            <p><button class="button button-primary">Save Agent</button></p>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($){
        $('.agent-rotate-secret').on('click', function(e){
            e.preventDefault();
            var agentId = $(this).data('agent');
            var newSecret = Array.from({length:32},()=>Math.random().toString(36)[2]||0).join('');
            $('<input>').attr({
                type: 'hidden',
                name: 'rotate_agent',
                value: agentId
            }).appendTo('form');
            $('<input>').attr({
                type: 'hidden',
                name: 'new_secret',
                value: newSecret
            }).appendTo('form');
            $('form').submit();
        });
    });
    </script>
    <?php
}


add_action( 'admin_init', function () {

if ( ! empty( $_POST['rotate_agent'] ) && ! empty( $_POST['new_secret'] ) ) {
    if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'agentic_save_agent' ) ) return;
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $agents = agentic_get_agents();
    $agent_id = sanitize_key( $_POST['rotate_agent'] );
    $new_secret = sanitize_text_field( $_POST['new_secret'] );

    if ( isset( $agents[ $agent_id ] ) ) {
        $agents[ $agent_id ]['secret'] = $new_secret;
        update_option( 'agentic_agents', $agents );
        wp_safe_redirect( admin_url( 'admin.php?page=agentic-agents' ) );
        exit;
    }
}

    if ( empty( $_POST['agent_id'] ) ) {
        return;
    }

    if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'agentic_save_agent' ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $agents = agentic_get_agents();

    $agent_id = sanitize_key( $_POST['agent_id'] );

    $agents[ $agent_id ] = [
        'secret'     => sanitize_text_field( $_POST['secret'] ),
        'can_refund' => ! empty( $_POST['can_refund'] ),
        'active'     => ! empty( $_POST['active'] ),
    ];

    update_option( 'agentic_agents', $agents );

    wp_safe_redirect( admin_url( 'admin.php?page=agentic-agents' ) );
    exit;
});


add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'agentic-polling',
        plugins_url('/assets/js/agentic-polling.js', __FILE__),
        [],
        '1.0',
        true
    );
});

add_action('woocommerce_thankyou_agentic', function ($order_id) {

    $order = wc_get_order($order_id);

    // If order is already completed, do NOT enqueue polling
    if ($order && $order->has_status('completed')) {
        echo '<p><strong>Payment complete.</strong></p>';
        return;
    }

    // Otherwise: show waiting message + polling
    else {
        echo '<p><strong>Waiting for agent approval…</strong></p>';
        echo '<p>This page will update automatically.</p>';
    }

    wp_enqueue_script(
        'agentic-polling',
        plugins_url('/assets/js/agentic-polling.js', __FILE__),
        [],
        '1.0',
        true
    );

    wp_localize_script(
        'agentic-polling',
        'AgenticOrder',
        [
            'orderId' => $order_id,
        ]
    );
});



add_action('init', function () {
    if (isset($_GET['agentic_test_payment'])) {
        $order_id = absint($_GET['agentic_test_payment']);
        $gateway = new WC_Gateway_Agentic();
        $gateway->mark_order_paid($order_id);
        echo "Order $order_id marked paid.";
        exit;
    }
});

add_action('rest_api_init', function () {
    register_rest_route('agentic/v1', '/confirm-payment', [
        'methods' => 'POST',
        'callback' => 'agentic_confirm_payment_handler',
        'permission_callback' => '__return_true',
    ]);
});

function agentic_confirm_payment_handler(WP_REST_Request $request)
{

    $order_id = $request->get_param('order_id');

    $order = wc_get_order($order_id);
    $order->payment_complete();

    return ['status' => 'ok'];

}





add_action('woocommerce_blocks_loaded', function () {

    if (!class_exists(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class)) {
        return;
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry) {

            require_once plugin_dir_path(__FILE__) . 'includes/class-wc-agentic-blocks.php';

            $registry->register(new WC_Agentic_Blocks());
        }
    );
});






add_action("init" /*"wp_enqueue_script"*/ , function () {

    wp_register_script(
        'agentic-blocks',
        plugins_url('/assets/js/blocks-payment.js', __FILE__),
        ['wc-blocks-registry', 'wp-element', 'wp-i18n'],
        '1.0.0',
        true
    );

});

function agentic_verify_agent( array $data ) {

    if ( empty( $data['agent_id'] ) ) {
        return new WP_Error(
            'agentic_missing_agent',
            'Missing agent_id',
            [ 'status' => 403 ]
        );
    }

    $agent = agentic_get_agent( $data['agent_id'] );// error_log("[AgenticPayments] (In agentic_verify_agent()) ".$agent);

    if ( ! $agent || empty( $agent['active'] ) ) {
        return new WP_Error(
            'agentic_invalid_agent',
            'Unknown or inactive agent',
            [ 'status' => 403 ]
        );
    }

    return $agent;
}


/////////////////////////////
function agentic_verify_webhook( WP_REST_Request $request ) {

    $body      = $request->get_body();
    $signature = $request->get_header( 'x-agentic-signature' );
    $timestamp = $request->get_header( 'x-agentic-timestamp' );

    if ( ! $signature || ! $timestamp ) {
        return new WP_Error(
            'agentic_missing_headers',
            'Missing signature headers',
            [ 'status' => 401 ]
        );
    }

    $data = json_decode( $body, true );
    if ( ! is_array( $data ) || empty( $data['agent_id'] ) ) {
        return new WP_Error(
            'agentic_missing_agent',
            'Missing agent_id',
            [ 'status' => 403 ]
        );
    }

    $agent = agentic_get_agent( $data['agent_id'] );
    if ( ! $agent || empty( $agent['active'] ) ) {
        return new WP_Error(
            'agentic_invalid_agent',
            'Unknown or inactive agent',
            [ 'status' => 403 ]
        );
    }

    if ( empty( $agent['secret'] ) ) {
        return new WP_Error(
            'agentic_no_secret',
            'Agent has no secret configured',
            [ 'status' => 500 ]
        );
    }

    // Optional replay protection (recommended)
    if ( abs( time() - intval( $timestamp ) ) > 300 ) {
        return new WP_Error(
            'agentic_stale_request',
            'Request timestamp too old',
            [ 'status' => 401 ]
        );
    }

    $payload  = $timestamp . '.' . $body;
    $expected = hash_hmac( 'sha256', $payload, $agent['secret'] );

    if ( ! hash_equals( $expected, $signature ) ) {
agentic_log_event(
    $order_id ?? 0,
    'auth_failed',
    [
        'agent_id' => $data['agent_id'] ?? null,
        'reason'   => $error_code,
    ]
);
        return new WP_Error(
            'agentic_bad_signature',
            'Invalid signature',
            [ 'status' => 401 ]
        );
    }

    // ✅ Authenticated + authorized agent
    return $agent;
}

function agentic_handle_payment_complete(WP_REST_Request $request)
{

    error_log('[AgenticPayments] Callback received');

$data = json_decode( $request->get_body(), true );

$agent = agentic_verify_webhook( $request );
if ( is_wp_error( $agent ) ) {
    return $agent;
}

    // ---- HMAC SIGNATURE VERIFICATION ----

    $signature = $_SERVER['HTTP_X_AGENTIC_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_AGENTIC_TIMESTAMP'] ?? '';

    if (!$signature || !$timestamp) {
        error_log('[AgenticPayments] Missing HMAC headers');
        return new WP_REST_Response(['error' => 'Missing signature'], 401);
    }

    // Prevent replay attacks (5 min window)
    if (abs(time() - intval($timestamp)) > 300) {
        error_log('[AgenticPayments] Stale request timestamp');
        return new WP_REST_Response(['error' => 'Stale request'], 401);
    }

    // Get raw request body
    $raw_body = $request->get_body();

    // Build signed payload
    $signed_payload = $timestamp . '.' . $raw_body;

    // Compute expected signature
    $verified = agentic_verify_webhook($request);
    if (is_wp_error($verified)) {
        return $verified;
    }

    error_log('[AgenticPayments] HMAC signature verified');


    // ---- Parse payload ----
    $order_id = absint($request->get_param('order_id'));
    $transaction_id = sanitize_text_field($request->get_param('transaction_id'));
    $agent_id = sanitize_text_field($request->get_param('agent_id'));

    if (!$order_id || !$transaction_id) {
        error_log('[AgenticPayments] Missing order_id or transaction_id');
        return new WP_REST_Response(['error' => 'Invalid payload'], 400);
    }

update_post_meta( $order_id, '_agentic_agent_id', $data['agent_id'] );

    $order = wc_get_order($order_id);

    if (!$order) {
        error_log('[AgenticPayments] Order not found: ' . $order_id);
        return new WP_REST_Response(['error' => 'Order not found'], 404);
    }

    error_log('[AgenticPayments] Processing order ' . $order_id);

    // ---- IDEMPOTENCY CHECK ----
    $already_completed = $order->get_meta('_agentic_completed');

    if ($already_completed) {
        error_log('[AgenticPayments] Idempotent hit — order already completed');

        return new WP_REST_Response(
            [
                'status' => 'ok',
                'message' => 'Order already processed',
            ],
            200
        );
    }

    // ---- Optional: validate payment method ----
    if ($order->get_payment_method() !== 'agentic') {
        error_log('[AgenticPayments] Payment method mismatch');
        return new WP_REST_Response(['error' => 'Invalid payment method'], 400);
    }

    // ---- Mark paid ----
    error_log('[AgenticPayments] Calling payment_complete');

    $order->payment_complete($transaction_id);

    // ---- Force completed if needed ----
    if ($order->has_status('processing')) {
        error_log('[AgenticPayments] Forcing status to completed');
        $order->update_status('completed', 'Agentic payment finalized');
    }

    // ---- Persist idempotency flag ----
    $order->update_meta_data('_agentic_completed', time());
    $order->update_meta_data('_agentic_transaction_id', $transaction_id);
    $order->update_meta_data('_agentic_agent_id', $agent_id);
    $order->save();

    // ---- Audit trail ----
    $order->add_order_note(
        sprintf(
            'Agentic payment confirmed. Agent: %s, TX: %s',
            $agent_id ?: 'unknown',
            $transaction_id
        )
    );

    error_log('[AgenticPayments] Order completed successfully');

    return new WP_REST_Response(
        [
            'status' => 'success',
            'order_id' => $order_id,
        ],
        200
    );
}

function agentic_handle_refund(WP_REST_Request $request)
{

    error_log('[AgenticPayments] Refund callback received');

$agent = agentic_verify_webhook( $request );
if ( is_wp_error( $agent ) ) {
    return $agent;
}

if ( empty( $agent['can_refund'] ) ) {
    return new WP_Error(
        'agentic_refund_not_allowed',
        'Agent not authorized to issue refunds',
        [ 'status' => 403 ]
    );
}

    // ---- HMAC VERIFICATION ----
    // Reuse the SAME verification code you added earlier
    // (do NOT duplicate logic — extract to helper if you want)

    $signature = $_SERVER['HTTP_X_AGENTIC_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_AGENTIC_TIMESTAMP'] ?? '';

    if (!$signature || !$timestamp) {
        return new WP_REST_Response(['error' => 'Missing signature'], 401);
    }

    if (abs(time() - intval($timestamp)) > 300) {
        return new WP_REST_Response(['error' => 'Stale request'], 401);
    }

    $raw_body = $request->get_body();
    $signed_payload = $timestamp . '.' . $raw_body;

    $verified = agentic_verify_webhook($request);
    if (is_wp_error($verified)) {
        return $verified;
    }

    // ---- Parse payload ----
    $order_id = absint($request->get_param('order_id'));
    $amount = floatval($request->get_param('amount'));
    $reason = sanitize_text_field($request->get_param('reason'));
    $refund_id = sanitize_text_field($request->get_param('refund_id'));
    $agent_id = sanitize_text_field($request->get_param('agent_id'));

    if (!$order_id || !$amount || !$refund_id) {
        return new WP_REST_Response(['error' => 'Invalid payload'], 400);
    }

$original_agent = get_post_meta( $order_id, '_agentic_agent_id', true );

if ( $original_agent !== $data['agent_id'] ) {
    return new WP_Error(
        'agentic_agent_mismatch',
        'Agent cannot refund orders created by another agent',
        [ 'status' => 403 ]
    );
}

    $order = wc_get_order($order_id);

    if (!$order) {
        return new WP_REST_Response(['error' => 'Order not found'], 404);
    }

    // ---- Ensure correct gateway ----
    if ($order->get_payment_method() !== 'agentic') {
        return new WP_REST_Response(['error' => 'Invalid payment method'], 400);
    }

    // ---- IDEMPOTENCY CHECK ----
    $existing_refunds = $order->get_refunds();

    foreach ($existing_refunds as $refund) {
        if ($refund->get_meta('_agentic_refund_id') === $refund_id) {
            error_log('[AgenticPayments] Idempotent refund hit');
            return new WP_REST_Response(
                [
                    'status' => 'ok',
                    'message' => 'Refund already processed',
                ],
                200
            );
        }
    }

    // ---- Validate amount ----
    if ($amount > $order->get_remaining_refund_amount()) {
        return new WP_REST_Response(['error' => 'Refund exceeds remaining amount'], 400);
    }

    // ---- Create WooCommerce refund ----
    $refund = wc_create_refund([
        'amount' => $amount,
        'reason' => $reason ?: 'Agentic refund',
        'order_id' => $order_id,
        'refund_payment' => false, // agent already handled money
        'restock_items' => false,
    ]);

    if (is_wp_error($refund)) {
        error_log('[AgenticPayments] Refund failed: ' . $refund->get_error_message());
        return new WP_REST_Response(['error' => 'Refund failed'], 500);
    }

    // ---- Persist idempotency metadata ----
    $refund->update_meta_data('_agentic_refund_id', $refund_id);
    $refund->update_meta_data('_agentic_agent_id', $agent_id);
    $refund->save();

    // ---- Order note ----
    $order->add_order_note(
        sprintf(
            'Agentic refund processed. Amount: %s. Agent: %s. Refund ID: %s',
            wc_price($amount),
            $agent_id ?: 'unknown',
            $refund_id
        )
    );

    error_log('[AgenticPayments] Refund processed successfully');

    return new WP_REST_Response(
        [
            'status' => 'success',
            'refund_id' => $refund->get_id(),
        ],
        200
    );
}

///////////////////////////

function agentic_log_event( $order_id, $event_type, array $data = [] ) {

    $entry = [
        'event'       => $event_type,
        'timestamp'   => current_time( 'mysql', true ),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'data'        => $data,
    ];

    add_post_meta(
        $order_id,
        '_agentic_audit_log',
        wp_json_encode( $entry, JSON_UNESCAPED_SLASHES )
    );

    error_log(
        '[AgenticPayments][AUDIT] order=' . $order_id .
        ' event=' . $event_type .
        ' data=' . wp_json_encode( $data )
    );
}



/*
add_action( 'rest_api_init', function () {
    register_rest_route( 'agentic/v1', '/payment-complete', [
        'methods'  => 'POST',
        'callback' => 'agentic_handle_payment_complete',
        'permission_callback' => '__return_true',
    ]);
});
*/

/*
add_action( 'rest_api_init', function () {
    register_rest_route( 'agentic/v1', '/refund', [
        'methods'  => 'POST',
        'callback' => 'agentic_handle_refund',
        'permission_callback' => '__return_true',
    ]);
});
*/

/**
 * Verify Agentic webhook HMAC signature
 *
 * @param string $body Raw POST body
 * @param string $signature Signature sent in header
 * @param string $secret Shared secret
 * @return bool
 */

/*

function agentic_verify_hmac( $body, $signature, $secret, $timestamp = '' ) {
    $calculated = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
    return hash_equals( $calculated, $signature );
}
function agentic_handle_payment_complete( $request ) {
    $body = $request->get_body();
    $signature = $request->get_header('x-agentic-signature');
    $secret = agentic_get_webhook_secret();

$timestamp = $request->get_header('x-agentic-timestamp') ?? '';
if ( ! agentic_verify_hmac( $body, $signature, $secret, $timestamp ) ) {
    return new WP_REST_Response([
        'status' => 'error',
        'message' => 'Invalid signature',
    ], 403);
}


    $data = json_decode( $body, true );
    if ( ! isset( $data['order_id'], $data['transaction_id'] ) ) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Missing parameters',
        ], 400);
    }

    $order_id = intval( $data['order_id'] );
    $transaction_id = sanitize_text_field( $data['transaction_id'] );

    // Idempotency: check if this transaction has already been processed
    $processed_tx = get_post_meta( $order_id, '_agentic_tx_' . $transaction_id, true );
    if ( $processed_tx ) {
agentic_log_event(
    $order_id,
    'payment_duplicate',
    [
        'agent_id'       => $agent['id'] ?? null,
        'transaction_id' => $transaction_id,
    ]
);
        return [
            'status' => 'ok',
            'message' => 'Order already processed',
        ];
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Order not found',
        ], 404);
    }

    // Mark payment complete
    $order->payment_complete( $transaction_id );
agentic_log_event(
    $order_id,
    'payment_completed',
    [
        'agent_id'       => $agent['id'] ?? null,
        'transaction_id' => $transaction_id,
    ]
); // TODO: Add the rest of the log events
    update_post_meta( $order_id, '_agentic_tx_' . $transaction_id, true );

    // Optionally force completion for non-virtual products
    if ( ! $order->has_status( 'completed' ) ) {
        $order->update_status( 'completed', 'Agentic payment finalized' );
    }

    return [
        'status' => 'success',
        'order_id' => $order_id,
    ];
}

function agentic_handle_refund( $request ) {
    $body = $request->get_body();
    $signature = $request->get_header('x-agentic-signature');
    $secret = agentic_get_webhook_secret();

$timestamp = $request->get_header('x-agentic-timestamp') ?? '';
if ( ! agentic_verify_hmac( $body, $signature, $secret, $timestamp ) ) {
    return new WP_REST_Response([
        'status' => 'error',
        'message' => 'Invalid signature',
    ], 403);
}

    $data = json_decode( $body, true );
    if ( ! isset( $data['order_id'], $data['refund_id'] ) ) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Missing parameters',
        ], 400);
    }

    $order_id = intval( $data['order_id'] );
    $refund_id = sanitize_text_field( $data['refund_id'] );

    // Idempotency: check if refund has already been processed
    $processed_refund = get_post_meta( $order_id, '_agentic_refund_' . $refund_id, true );
    if ( $processed_refund ) {
        return [
            'status' => 'ok',
            'message' => 'Refund already processed',
        ];
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Order not found',
        ], 404);
    }

    // Process refund
    $refund_amount = floatval( $data['amount'] ?? 0 );
    if ( $refund_amount > 0 ) {
        $refund = wc_create_refund([
            'amount'         => $refund_amount,
            'reason'         => $data['reason'] ?? 'Agentic refund',
            'order_id'       => $order_id,
            'refund_payment' => true,
        ]);

        if ( is_wp_error( $refund ) ) {
            return [
                'status' => 'error',
                'message' => $refund->get_error_message(),
            ];
        }
        else {
agentic_log_event(
    $order_id,
    'refund_processed',
    [
        'agent_id'       => $agent['id'],
        'transaction_id' => $transaction_id,
        'amount'         => $amount,
        'reason'         => $reason ?? '',
    ]
);
        }
    }

    update_post_meta( $order_id, '_agentic_refund_' . $refund_id, true );

    return [
        'status' => 'success',
        'order_id' => $order_id,
        'refund_id' => $refund_id,
    ];
}
//////////////////////////////////////////////

*/

add_action('admin_notices', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    $settings = get_option('woocommerce_agentic_settings', []);

    if (empty($settings['webhook_secret'])) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Agentic Payments:</strong> Webhook secret is not set. Agent callbacks will fail.';
        echo '</p></div>';
    }
});




/* Boot plugin */
Agentic_Payments_Plugin::instance();

/* -------------------------
 * Helpful developer docs in plugin file (kept here for convenience)
 * ------------------------- */

/*
USAGE & EXAMPLES

1) REST example (agent signs payload):
- Agent prepares payload JSON without "signature":
  {
     "agent_id": "agent-123",
     "order_id": 456,
     "metadata": { "note": "charge customer" }
  }

- Canonicalize: sort keys recursively and JSON encode (plugin does ksort_recursive + wp_json_encode).
- Compute HMAC-SHA256 using shared_secret (visible in admin settings).
- Send POST to: https://example.com/wp-json/agentic-payments/v1/create
  Headers: Content-Type: application/json
  Body: { ... payload fields ..., "signature": "<hex-from-hmac>" }

2) JavaScript example (agent-side signing) — for demonstration only (server-side signing is more secure):

const payload = {
  agent_id: 'agent-1',
  order_id: 123,
  amount: '12.00',
  currency: 'USD'
};
// canonicalize keys on client similar to plugin (implementation depends on your agent SDK)
const canonical = JSON.stringify(payload); // (real agent should canonicalize identically)
const signature = HMAC_SHA256_HEX(canonical, SHARED_SECRET);

fetch('https://example.com/wp-json/agentic-payments/v1/create', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({...payload, signature})
});

3) Hooks:
- do_action('agentic_payment_initiated_raw', $params)
- do_action('agentic_payment_processed', $order, $result) (for Woo)
- do_action('agentic_payment_processed_nonwc', $post_id, $txn_id, $params)
- apply_filters('agentic_process_woocommerce_order', $default_result, $order, $params)
- do_action('agentic_webhook_received', $payload)

SECURITY NOTES:
- Signing should ideally happen server-side by the agent operator to avoid revealing the shared secret in client code.
- Use HTTPS.
- Rotate shared_secret/webhook_secret if compromised.
- Consider implementing nonce/timestamp checking (replay protection).
- Consider rate-limiting the REST endpoint or restricting by IP ranges if appropriate.

*/

















/**
 * Register Blocks integration
 */
add_action('woocommerce_blocks_loaded', function () {

    if (!class_exists(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class)) {
        return;
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry) {

            if ($registry->is_registered('agentic')) {
                return;
            }

            // require_once __DIR__ . '/includes/class-wc-agentic-blocks.php';
    
            $registry->register(new WC_Agentic_Blocks());
        }
    );
});

