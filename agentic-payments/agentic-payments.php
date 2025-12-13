<?php
/*
Plugin Name: Agentic Payments
Description: Adds support for agentic payments: REST API endpoints for agent-initiated payments and a WooCommerce payment gateway. Secure HMAC signing, webhook handling, admin settings, and hooks for developers.
Version: 1.0.0
Author: Cryptoball cryptoball7@gmail.com
Text Domain: agentic-payments
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Agentic_Payments_Plugin {

    const OPTION_KEY = 'agentic_payments_options';
    const REST_NAMESPACE = 'agentic-payments/v1';

    private static $instance = null;
    private $options = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function maybe_generate_secret() {
        if ( function_exists( 'wp_generate_password' ) ) {
            return wp_generate_password( 32, false );
        }
        return bin2hex( random_bytes(16) ); // fallback for early load
    }

    private function init() {
        $this->options = get_option( self::OPTION_KEY, array(
            'enabled' => 'yes',
            'shared_secret' => '',$this->maybe_generate_secret(),
            'webhook_secret' => '',$this->maybe_generate_secret(),
            'allowed_agents' => '', // comma-separated allowed agent IDs (optional)
            'log' => 'no',
        ) );

        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        register_uninstall_hook( __FILE__, array( 'Agentic_Payments_Plugin', 'on_uninstall' ) );

        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // If WooCommerce exists register gateway
        add_action( 'plugins_loaded', array( $this, 'maybe_register_gateway' ), 20 );

        // endpoint to accept webhooks from payment processors / agent platform
        add_action( 'init', array( $this, 'maybe_handle_webhook' ) );
    }

    public function on_activate() {
        // ensure options exist
        if ( ! get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, $this->options );
        }
    }

    public static function on_uninstall() {
        // remove options on uninstall
        delete_option( self::OPTION_KEY );
    }

    private function log( $message ) {
        if ( isset( $this->options['log'] ) && $this->options['log'] === 'yes' ) {
            if ( ! is_scalar( $message ) ) {
                $message = print_r( $message, true );
            }
            error_log( '[AgenticPayments] ' . $message );
        }
    }

    /* -------------------------
     * Admin settings UI
     * ------------------------- */
    public function register_admin_page() {
        add_options_page(
            'Agentic Payments',
            'Agentic Payments',
            'manage_options',
            'agentic-payments',
            array( $this, 'render_admin_page' )
        );
    }

    public function register_settings() {
        register_setting( 'agentic_payments_group', self::OPTION_KEY, array( $this, 'sanitize_options' ) );
        add_settings_section( 'agentic_main', 'Agentic Payments Settings', null, 'agentic-payments' );

        add_settings_field( 'enabled', 'Enable Agentic Payments', array( $this, 'field_enabled' ), 'agentic-payments', 'agentic_main' );
        add_settings_field( 'shared_secret', 'Shared Secret (for agent signing)', array( $this, 'field_shared_secret' ), 'agentic-payments', 'agentic_main' );
        add_settings_field( 'webhook_secret', 'Webhook Secret (for incoming webhooks)', array( $this, 'field_webhook_secret' ), 'agentic-payments', 'agentic_main' );
        add_settings_field( 'allowed_agents', 'Allowed Agent IDs (comma-separated)', array( $this, 'field_allowed_agents' ), 'agentic-payments', 'agentic_main' );
        add_settings_field( 'log', 'Enable Logging', array( $this, 'field_log' ), 'agentic-payments', 'agentic_main' );
    }

    public function sanitize_options( $input ) {
        $out = array();
        $out['enabled'] = ( isset( $input['enabled'] ) && $input['enabled'] === 'yes' ) ? 'yes' : 'no';
        $out['shared_secret'] = sanitize_text_field( $input['shared_secret'] );
        if ( empty( $out['shared_secret'] ) ) {
            $out['shared_secret'] = wp_generate_password(32, false);
        }
        $out['webhook_secret'] = sanitize_text_field( $input['webhook_secret'] );
        if ( empty( $out['webhook_secret'] ) ) {
            $out['webhook_secret'] = wp_generate_password(32, false);
        }
        $out['allowed_agents'] = sanitize_text_field( $input['allowed_agents'] );
        $out['log'] = ( isset( $input['log'] ) && $input['log'] === 'yes' ) ? 'yes' : 'no';
        $this->options = $out;
        return $out;
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Agentic Payments</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'agentic_payments_group' );
                    do_settings_sections( 'agentic-payments' );
                    submit_button();
                ?>
            </form>
            <h2>Developer / Agent Info</h2>
            <p>REST endpoint to initiate payments: <code><?php echo esc_html( rest_url( self::REST_NAMESPACE . '/create' ) ); ?></code></p>
            <p>Shared secret (use in HMAC signing):</p>
            <pre style="background:#fff;padding:8px;border:1px solid #ddd;"><?php echo esc_html( $this->options['shared_secret'] ); ?></pre>
            <p>Webhook secret (for verifying incoming webhooks):</p>
            <pre style="background:#fff;padding:8px;border:1px solid #ddd;"><?php echo esc_html( $this->options['webhook_secret'] ); ?></pre>
            <p>Allowed agent IDs (optional): <code><?php echo esc_html( $this->options['allowed_agents'] ); ?></code></p>
        </div>
        <?php
    }

    public function field_enabled() {
        $val = ( isset( $this->options['enabled'] ) && $this->options['enabled'] === 'yes' ) ? 'yes' : 'no';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]">
            <option value="yes" <?php selected( $val, 'yes' ); ?>>Yes</option>
            <option value="no" <?php selected( $val, 'no' ); ?>>No</option>
        </select>
        <?php
    }

    public function field_shared_secret() {
        ?>
        <input type="text" style="width:60%;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[shared_secret]" value="<?php echo esc_attr( $this->options['shared_secret'] ); ?>" />
        <p class="description">Secret used to sign REST requests from agents (HMAC SHA256).</p>
        <?php
    }

    public function field_webhook_secret() {
        ?>
        <input type="text" style="width:60%;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[webhook_secret]" value="<?php echo esc_attr( $this->options['webhook_secret'] ); ?>" />
        <p class="description">Secret to verify incoming webhooks from payment processors/agent platforms.</p>
        <?php
    }

    public function field_allowed_agents() {
        ?>
        <input type="text" style="width:60%;" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_agents]" value="<?php echo esc_attr( $this->options['allowed_agents'] ); ?>" />
        <p class="description">Comma-separated list of allowed agent IDs. Leave blank to allow all agents that can sign correctly.</p>
        <?php
    }

    public function field_log() {
        $val = ( isset( $this->options['log'] ) && $this->options['log'] === 'yes' ) ? 'yes' : 'no';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[log]">
            <option value="no" <?php selected( $val, 'no' ); ?>>No</option>
            <option value="yes" <?php selected( $val, 'yes' ); ?>>Yes</option>
        </select>
        <p class="description">Enable debug logging to error_log (useful during setup; disable in production).</p>
        <?php
    }

    /* -------------------------
     * REST API
     * ------------------------- */
    public function register_rest_routes() {
        register_rest_route( self::REST_NAMESPACE, '/create', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'rest_create_payment' ),
            'permission_callback' => '__return_true', // we verify via HMAC within callback
        ) );
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
    public function rest_create_payment( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            return new WP_REST_Response( array( 'error' => 'invalid_json' ), 400 );
        }

        // Required fields
        $agent_id = isset( $params['agent_id'] ) ? sanitize_text_field( $params['agent_id'] ) : '';
        $signature = isset( $params['signature'] ) ? sanitize_text_field( $params['signature'] ) : '';
        if ( empty( $agent_id ) || empty( $signature ) ) {
            return new WP_REST_Response( array( 'error' => 'missing_agent_or_signature' ), 400 );
        }

        // verify allowed agents if set
        if ( ! empty( $this->options['allowed_agents'] ) ) {
            $allowed = array_map( 'trim', explode( ',', $this->options['allowed_agents'] ) );
            if ( ! in_array( $agent_id, $allowed, true ) ) {
                $this->log( "Rejected agent {$agent_id}: not in allowed list." );
                return new WP_REST_Response( array( 'error' => 'agent_not_allowed' ), 403 );
            }
        }

        // Verify signature: signature should be HMAC SHA256 over canonicalized payload (sorted keys) without the signature field
        $payload_for_sign = $params;
        unset( $payload_for_sign['signature'] );
        // canonicalize: json encode with sorted keys
        ksort_recursive( $payload_for_sign );
        $canonical = wp_json_encode( $payload_for_sign );

        $expected = hash_hmac( 'sha256', $canonical, $this->options['shared_secret'] );

        if ( ! hash_equals( $expected, $signature ) ) {
            $this->log( "Signature mismatch. Expected {$expected}, got {$signature}. Canonical: {$canonical}" );
            return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 403 );
        }

        // Now process payment or create order
        $order_id = isset( $params['order_id'] ) ? intval( $params['order_id'] ) : 0;
        $amount = isset( $params['amount'] ) ? $params['amount'] : null;
        $currency = isset( $params['currency'] ) ? sanitize_text_field( $params['currency'] ) : null;
        $description = isset( $params['description'] ) ? sanitize_text_field( $params['description'] ) : '';
        $metadata = isset( $params['metadata'] ) && is_array( $params['metadata'] ) ? $params['metadata'] : array();

        do_action( 'agentic_payment_initiated_raw', $params ); // raw hook for logging / 3rd party

        // If WooCommerce available and order_id provided -> attempt to process via gateway; otherwise simulate/create simple record
        if ( class_exists( 'WooCommerce' ) && $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_REST_Response( array( 'error' => 'order_not_found' ), 404 );
            }

            // Developer can hook into this to process via custom gateway
            $result = apply_filters( 'agentic_process_woocommerce_order', array(
                'success' => true,
                'transaction_id' => 'agentic_wc_' . time() . '_' . wp_generate_password(6, false),
            ), $order, $params );

            if ( is_array( $result ) && ! empty( $result['success'] ) ) {
                // mark order paid if requested
                if ( isset( $result['mark_paid'] ) && $result['mark_paid'] ) {
                    $order->payment_complete( $result['transaction_id'] );
                }

                do_action( 'agentic_payment_processed', $order, $result );
                return new WP_REST_Response( array(
                    'success' => true,
                    'order_id' => $order_id,
                    'transaction_id' => $result['transaction_id'],
                ), 200 );
            } else {
                $this->log( 'agentic process for order returned failure: ' . print_r( $result, true ) );
                return new WP_REST_Response( array( 'error' => 'processing_failed' ), 500 );
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
                    'created_at' => current_time( 'mysql' ),
                )
            );
            $post_id = wp_insert_post( $record );

            do_action( 'agentic_payment_processed_nonwc', $post_id, $txn_id, $params );

            return new WP_REST_Response( array(
                'success' => true,
                'transaction_id' => $txn_id,
                'record_id' => $post_id,
            ), 200 );
        }
    }

    /* -------------------------
     * Simple webhook handler (optional)
     * ------------------------- */
    public function maybe_handle_webhook() {
        // Example: if someone POSTs to /?agentic_webhook=1 we accept it
        if ( isset( $_GET['agentic_webhook'] ) && $_GET['agentic_webhook'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            $raw = file_get_contents( 'php://input' );
            $sig = isset( $_SERVER['HTTP_X_AGENTIC_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AGENTIC_SIGNATURE'] ) ) : '';

            // verify
            $expected = hash_hmac( 'sha256', $raw, $this->options['webhook_secret'] );
            if ( ! hash_equals( $expected, $sig ) ) {
                status_header( 403 );
                echo 'invalid_signature';
                exit;
            }

            $payload = json_decode( $raw, true );
            // you may want to verify payload structure
            $this->log( 'Received webhook: ' . print_r( $payload, true ) );
            do_action( 'agentic_webhook_received', $payload );

            status_header( 200 );
            echo 'ok';
            exit;
        }
    }

    /* -------------------------
     * Register includes: custom post type for non-Woo payments
     * ------------------------- */
    public function register_post_type() {
        register_post_type( 'agentic_payment', array(
            'labels' => array(
                'name' => 'Agentic Payments',
                'singular_name' => 'Agentic Payment'
            ),
            'public' => false,
            'show_ui' => true,
            'supports' => array( 'title' ),
        ) );
    }

    /* -------------------------
     * WooCommerce Gateway
     * ------------------------- */
public function maybe_register_gateway_nondebug() {

    // ensure custom post type still registers
    add_action( 'init', array( $this, 'register_post_type' ) );

    add_action( 'woocommerce_loaded', function() {
        add_filter( 'woocommerce_payment_gateways', function( $methods ) {
            $methods[] = 'WC_Gateway_Agentic';
            return $methods;
        } );
    } );
}

// debug version
public function maybe_register_gateway() {

    error_log('[AgenticPayments] maybe_register_gateway() fired');

    // Still register post type
    add_action( 'init', array( $this, 'register_post_type' ) );

    if ( ! did_action( 'woocommerce_loaded' ) ) {
        error_log('[AgenticPayments] woocommerce_loaded has NOT fired yet');
    } else {
        error_log('[AgenticPayments] woocommerce_loaded HAS fired');
    }

    add_action( 'woocommerce_loaded', function() {

        error_log('[AgenticPayments] Inside woocommerce_loaded callback');

    //debug: (2 lines)
    $gws = WC()->payment_gateways()->get_payment_gateways();
    error_log('[AgenticPayments] Installed gateways: ' . implode(', ', array_keys($gws)));

        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            error_log('[AgenticPayments] WC_Payment_Gateway class NOT found');
            return;
        }

        error_log('[AgenticPayments] WC_Payment_Gateway class IS available — registering gateway');

        add_filter( 'woocommerce_payment_gateways', function( $methods ) {
            error_log('[AgenticPayments] Adding WC_Gateway_Agentic to gateway list');
            $methods[] = 'WC_Gateway_Agentic';
            return $methods;
        } );
    });
}




    public function add_wc_gateway( $gateways ) {
        $gateways[] = 'WC_Gateway_Agentic';
        return $gateways;
    }
}

/* -------------------------
 * Utility: recursive ksort for canonicalization
 * ------------------------- */
function ksort_recursive( &$array ) {
    if ( ! is_array( $array ) ) {
        return;
    }
    ksort( $array );
    foreach ( $array as &$value ) {
        if ( is_array( $value ) ) {
            ksort_recursive( $value );
        }
    }
}

// -------------------------
// WooCommerce Gateway Class
// -------------------------


//nondebug

// if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'WC_Gateway_Agentic' ) ) {

add_action( 'woocommerce_loaded', function() {

    error_log('[AgenticPayments] woocommerce_loaded callback fired — loading gateway class');

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        error_log('[AgenticPayments] ERROR: WC_Payment_Gateway still not available');
        return;
    }

    // Load the gateway file if you have it in includes/
    // require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-agentic.php';

    if ( ! class_exists( 'WC_Gateway_Agentic' ) ) {

        error_log('[AgenticPayments] Defining WC_Gateway_Agentic now');

        class WC_Gateway_Agentic extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'agentic';
            $this->method_title       = 'Agentic Payments';
            $this->method_description = 'Accept agent-initiated payments via the Agentic Payments plugin.';
            $this->has_fields         = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled', 'yes');

            $this->title       = $this->get_option( 'title', 'Agentic (programmatic)' );
            $this->description = $this->get_option( 'description', '' );

            $this->supports = [
                'products',
                'default_credit_card_form'
            ];

            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array( $this, 'process_admin_options' )
            );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Agentic payment gateway',
                    'default' => 'no',
                ),
                'title' => array(
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'Agentic (programmatic)',
                ),
                'description' => array(
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Pay through an agentic payment flow.',
                ),
            );
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            // The agent will later complete the order via REST or a webhook
            $order->update_status(
                'on-hold',
                'Awaiting agentic payment processing.'
            );

            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        }

    }

    } else {
    error_log('[AgenticPayments] WC_Payment_Gateway missing OR WC_Gateway_Agentic already loaded');
    }

    // Now register the gateway
    add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
        error_log('[AgenticPayments] Registering gateway via filter');
        $gateways[] = 'WC_Gateway_Agentic';
        return $gateways;
    });

    add_action( 'woocommerce_blocks_loaded', function() {

    error_log('[AgenticPayments] woocommerce_blocks_loaded — preparing block integration');

    if ( ! class_exists( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class ) ) {
        error_log('[AgenticPayments] Blocks registry class missing');
        return;
    }
    });

});



add_filter('woocommerce_available_payment_gateways', function($gateways) {
    error_log('[AgenticPayments] Available gateways BEFORE filtering: ' . implode(', ', array_keys($gateways)));

    $agentic = isset($gateways['agentic']) ? 'YES' : 'NO';
    error_log('[AgenticPayments] Is Agentic present? ' . $agentic);

    return $gateways;
});

add_action('woocommerce_blocks_loaded', function() {

    error_log('[AgenticPayments] woocommerce_blocks_loaded fired');

    if ( ! class_exists( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class ) ) {
        error_log('[AgenticPayments] Blocks registry missing');
        return;
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {

            error_log('[AgenticPayments] Registering Blocks payment method');

            require_once plugin_dir_path(__FILE__) . 'class-wc-agentic-blocks-support.php';

            $registry->register( new WC_Agentic_Blocks_Support() );
        }
    );
});






wp_register_script(
    'agentic-blocks',
    plugins_url('/assets/js/blocks-payment.js', __FILE__),
    [ 'wc-blocks-registry', 'wp-element', 'wp-i18n' ],
    '1.0.0',
    true
);
error_log("[AgenticPayments][DBG] registered agentic-blocks JS");



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



// ===== Agentic Payments — deep debug instrumentation for Block Checkout =====
add_action( 'plugins_loaded', function() {
    error_log('[AgenticPayments][DBG] 1. plugins_loaded (plugin bootstrapped)');
    error_log('[AgenticPayments][DBG] PHP version: ' . phpversion());
    error_log('[AgenticPayments][DBG] WP version: ' . get_bloginfo('version'));
});

// Confirm WooCommerce presence early
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        error_log('[AgenticPayments][DBG] 2. WooCommerce class exists');
    } else {
        error_log('[AgenticPayments][DBG] 2. WooCommerce not active');
    }
});

// When plugin executes maybe_register_gateway() (where you added it), log there too
add_action( 'init', function() {
    if ( method_exists( 'Agentic_Payments_Plugin', 'maybe_register_gateway' ) ) {
        error_log('[AgenticPayments][DBG] 3. init: Agentic plugin registered maybe_register_gateway exists');
    }
});

// After your gateway is added to classic gateways, log the gateway list
add_action( 'init', function() {
    if ( class_exists( 'WC_Payment_Gateway' ) && function_exists('WC') ) {
        try {
            if ( function_exists('WC') && WC()->payment_gateways() ) {
                $gw = WC()->payment_gateways()->payment_gateways();
                error_log('[AgenticPayments][DBG] 4. Classic gateways currently registered: ' . implode(', ', array_keys($gw)));
            } else {
                error_log('[AgenticPayments][DBG] 4. WC()->payment_gateways() not available yet');
            }
        } catch (Throwable $e) {
            error_log('[AgenticPayments][DBG] 4. Exception when reading gateways: ' . $e->getMessage());
        }
    } else {
        error_log('[AgenticPayments][DBG] 4. WC_Payment_Gateway unavailable at init');
    }
});

// Confirm the gateway class exists (WC_Gateway_Agentic)
add_action( 'init', function() {
    if ( class_exists( 'WC_Gateway_Agentic' ) ) {
        error_log('[AgenticPayments][DBG] 5. WC_Gateway_Agentic class EXISTS');
    } else {
        error_log('[AgenticPayments][DBG] 5. WC_Gateway_Agentic class MISSING');
    }
});

// Track woocommerce_loaded and registration
add_action( 'woocommerce_loaded', function() {
    error_log('[AgenticPayments][DBG] 6. woocommerce_loaded fired');
});

// Instrument the exact registry hook we use for Blocks
add_action( 'woocommerce_blocks_loaded', function() {
    error_log('[AgenticPayments][DBG] 7. woocommerce_blocks_loaded fired');

    // Check for registry class
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' ) ) {
        error_log('[AgenticPayments][DBG] 8. PaymentMethodRegistry class exists');
    } else {
        error_log('[AgenticPayments][DBG] 8. PaymentMethodRegistry class MISSING');
    }

    // Add controlled registration callback that logs everything
    add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {

        try {
            $hasMethod = method_exists( $registry, 'register' );
            error_log('[AgenticPayments][DBG] 9. blocks_payment_method_type_registration fired; registry->register exists? ' . ($hasMethod ? 'YES' : 'NO'));

            // Is agentic already registered?
            if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( 'agentic' ) ) {
                error_log('[AgenticPayments][DBG] 10. agentic already registered in registry -> skipping register()');
                return;
            }

            // Include the blocks-support file (adjust path if you placed it elsewhere)
            $blocks_file = plugin_dir_path( __FILE__ ) . 'class-wc-agentic-blocks-support.php';
            if ( file_exists( $blocks_file ) ) {
                error_log('[AgenticPayments][DBG] 11. blocks-support file exists; requiring it: ' . $blocks_file);
                require_once $blocks_file;
            } else {
                error_log('[AgenticPayments][DBG] 11. blocks-support file MISSING at: ' . $blocks_file);
            }

            if ( class_exists( 'WC_Agentic_Blocks_Support' ) ) {
                error_log('[AgenticPayments][DBG] 12. WC_Agentic_Blocks_Support class exists; constructing and registering');
                $instance = new WC_Agentic_Blocks_Support();
                // optional: log what's returned by get_payment_method_data()
                if ( method_exists( $instance, 'get_payment_method_data' ) ) {
                    $data = $instance->get_payment_method_data();
                    error_log('[AgenticPayments][DBG] 13. blocks-support get_payment_method_data() -> ' . print_r( $data, true ));
                } else {
                    error_log('[AgenticPayments][DBG] 13. blocks-support missing get_payment_method_data()');
                }

                // Check is_active()
                if ( method_exists( $instance, 'is_active' ) ) {
                    $active = $instance->is_active();
                    error_log('[AgenticPayments][DBG] 14. blocks-support is_active() -> ' . ($active ? 'YES' : 'NO'));
                } else {
                    error_log('[AgenticPayments][DBG] 14. blocks-support missing is_active()');
                }

                // Last, register if possible
                if ( method_exists( $registry, 'register' ) ) {
                    $registry->register( $instance );
                    error_log('[AgenticPayments][DBG] 15. registry->register() called for agentic');
                } else {
                    error_log('[AgenticPayments][DBG] 15. registry->register method missing; cannot register');
                }
            } else {
                error_log('[AgenticPayments][DBG] 12. WC_Agentic_Blocks_Support class still missing after require_once');
            }
        } catch ( Throwable $t ) {
            error_log('[AgenticPayments][DBG] Exception during blocks registration: ' . $t->getMessage());
        }

    }, 10, 1 );
});

// Log the available gateways right before the checkout templates render
add_filter( 'woocommerce_available_payment_gateways', function( $gateways ) {
    error_log('[AgenticPayments][DBG] 16. woocommerce_available_payment_gateways BEFORE FILTER: ' . implode(', ', array_keys( $gateways ?: [] )));
    $present = isset( $gateways['agentic'] ) ? 'YES' : 'NO';
    error_log('[AgenticPayments][DBG] 17. Is agentic present in available gateways? ' . $present);
    return $gateways;
}, 100 );

// Also log the Blocks-specific gateway list (if available in WC instance)
add_action( 'wp_loaded', function() {
    if ( function_exists('WC') && WC()->payment_gateways() ) {
        try {
            $gws = WC()->payment_gateways()->payment_gateways();
            error_log('[AgenticPayments][DBG] 18. wp_loaded: Classic WC gateways list: ' . implode(', ', array_keys($gws)));
        } catch ( Throwable $e ) {
            error_log('[AgenticPayments][DBG] 18. wp_loaded: exception reading gateways: ' . $e->getMessage());
        }
    }
});
