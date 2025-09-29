<?php
/**
 * Plugin Name: Dynamic Pricing Engine for WooCommerce
 * Plugin URI:  https://example.com/dynamic-pricing-engine
 * Description: Adjusts product prices dynamically based on demand, stock levels, and user behaviour. Lightweight, rule-driven engine with admin UI.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Author URI:  https://github.com/cryptoball7
 * Text Domain: dpe
 * Domain Path: /languages
 *
 * Notes:
 * - This plugin requires WooCommerce.
 * - Rules are stored as JSON in options (admin can edit and add rules).
 * - Pricing adjustments are applied on the fly via WooCommerce price hooks.
 * - Includes a simple JS tracker that stores product views in localStorage to emulate "demand".
 *
 * Security & Performance:
 * - Admin endpoints use nonces and capability checks.
 * - Results cached via transients for short periods.
 *
 * Installation:
 * 1. Upload this file into wp-content/plugins/dynamic-pricing-engine/
 * 2. Activate the plugin in WP Admin (Plugins -> Installed Plugins).
 * 3. Ensure WooCommerce is active.
 * 4. Go to WooCommerce -> Dynamic Pricing Engine to configure rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPE_Plugin {
    const OPTION_KEY = 'dpe_rules_json';
    const OPTION_VERSION = 'dpe_version';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'maybe_register_defaults' ) );

        // Only hook WooCommerce filters if WooCommerce is active
        add_action( 'plugins_loaded', array( $this, 'maybe_init_woocommerce_hooks' ), 20 );

        // Enqueue front JS for tracking
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_scripts' ) );

        // Admin ajax for saving rules
        add_action( 'wp_ajax_dpe_save_rules', array( $this, 'ajax_save_rules' ) );
    }

    public function maybe_init_woocommerce_hooks() {
        if ( class_exists( 'WooCommerce' ) ) {
            // For single product price retrieval
            add_filter( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 10, 2 );
            add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filter_product_price' ), 10, 2 );
            add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_product_price' ), 10, 2 );

            // For variation price display & price HTML
            add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 10, 2 );

            // Variation prices array (needed by some templates)
            add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_prices_array' ), 10, 3 );

            // Ensure cart & checkout prices reflect dynamic pricing
            add_action( 'woocommerce_before_calculate_totals', array( $this, 'maybe_adjust_cart_item_price' ), 20 );
        }
    }

    public function maybe_register_defaults() {
        if ( ! get_option( self::OPTION_VERSION ) ) {
            // define a pair of example rules
            $default_rules = array(
                // Rule syntax documented in admin UI. Each rule has: id, name, enabled, conditions, action, priority
                array(
                    'id' => 'dpe_high_demand',
                    'name' => 'High demand markup',
                    'enabled' => true,
                    'priority' => 10,
                    'conditions' => array(
                        array('type' => 'views_last_24h', 'operator' => '>=', 'value' => 20),
                    ),
                    'action' => array('type' => 'multiply', 'value' => 1.15) // +15%
                ),
                array(
                    'id' => 'dpe_low_stock',
                    'name' => 'Low stock scarcity increase',
                    'enabled' => true,
                    'priority' => 20,
                    'conditions' => array(
                        array('type' => 'stock_quantity', 'operator' => '<=', 'value' => 5),
                    ),
                    'action' => array('type' => 'add', 'value' => 5) // + $5
                ),
            );

            update_option( self::OPTION_KEY, wp_json_encode( $default_rules ) );
            update_option( self::OPTION_VERSION, '1.0.0' );
        }
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Dynamic Pricing Engine', 'dpe' ),
            __( 'Dynamic Pricing Engine', 'dpe' ),
            'manage_woocommerce',
            'dpe-settings',
            array( $this, 'admin_page' )
        );
    }

    public function admin_page() {
        // capability check
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'You do not have permission to access this page', 'dpe' ) );
        }

        $rules_json = get_option( self::OPTION_KEY, '[]' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Dynamic Pricing Engine', 'dpe' ); ?></h1>
            <p><?php esc_html_e( 'Create rules to adjust product prices dynamically. Rules are evaluated in order of priority (lower number runs first).', 'dpe' ); ?></p>

            <h2><?php esc_html_e( 'Rules (JSON)', 'dpe' ); ?></h2>
            <form id="dpe-rules-form">
                <?php wp_nonce_field( 'dpe_save_rules_nonce', 'dpe_nonce' ); ?>
                <textarea id="dpe-rules-json" name="rules_json" rows="16" style="width:100%; font-family: monospace;"><?php echo esc_textarea( $rules_json ); ?></textarea>
                <p class="description"><?php esc_html_e( 'Rules are stored as JSON. See examples and documentation below.', 'dpe' ); ?></p>
                <p>
                    <button class="button button-primary" id="dpe-save-rules"><?php esc_html_e( 'Save rules', 'dpe' ); ?></button>
                    <button class="button" id="dpe-validate-rules" type="button"><?php esc_html_e( 'Validate JSON', 'dpe' ); ?></button>
                </p>
            </form>

            <h3><?php esc_html_e( 'Rule syntax', 'dpe' ); ?></h3>
            <p><?php esc_html_e( 'Each rule should be an object with: id, name, enabled, priority, conditions(array) and action.', 'dpe' ); ?></p>
            <pre style="background:#fff;border:1px solid #ddd;padding:10px">
// Example
[
  {
    "id":"rule1",
    "name":"Increase when many views",
    "enabled":true,
    "priority":10,
    "conditions":[
      {"type":"views_last_24h","operator":">=","value":20}
    ],
    "action":{"type":"multiply","value":1.10}
  }
]
            </pre>

            <h3><?php esc_html_e( 'Available condition types', 'dpe' ); ?></h3>
            <ul>
                <li><code>views_last_24h</code> — Product views tracked in front-end (integer)</li>
                <li><code>stock_quantity</code> — Current stock quantity (integer)</li>
                <li><code>total_sales</code> — Total sales for the product (integer)</li>
                <li><code>user_role</code> — Matches current user role (string, supports operators == or !=)</li>
                <li><code>time_of_day</code> — Hour of day in 0-23 (integer)</li>
            </ul>

            <h3><?php esc_html_e( 'Available action types', 'dpe' ); ?></h3>
            <ul>
                <li><code>multiply</code> — Multiply base price by value (eg 1.2 = +20%)</li>
                <li><code>add</code> — Add a fixed amount to price (eg 5 = +$5)</li>
                <li><code>set</code> — Set price to exact value</li>
            </ul>

        </div>

        <script>
        (function(){
            const saveBtn = document.getElementById('dpe-save-rules');
            const validateBtn = document.getElementById('dpe-validate-rules');
            const textarea = document.getElementById('dpe-rules-json');

            validateBtn.addEventListener('click', function(){
                try {
                    JSON.parse(textarea.value);
                    alert('Valid JSON');
                } catch(e) {
                    alert('Invalid JSON: ' + e.message);
                }
            });

            saveBtn.addEventListener('click', function(e){
                e.preventDefault();
                const data = new FormData();
                data.append('action','dpe_save_rules');
                data.append('rules_json', textarea.value);
                data.append('dpe_nonce', document.querySelector('#dpe_nonce').value);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                }).then(r=>r.json()).then(res=>{
                    if(res.success) alert('Saved'); else alert('Error: '+(res.data||'unknown'));
                }).catch(err=>alert('Request failed: '+err));
            });
        })();
        </script>
        <?php
    }

    public function ajax_save_rules() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'forbidden' );
        }
        check_ajax_referer( 'dpe_save_rules_nonce', 'dpe_nonce' );

        $json = isset( $_POST['rules_json'] ) ? wp_unslash( $_POST['rules_json'] ) : '';
        // Basic JSON validation
        $decoded = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( 'invalid_json' );
        }

        // TODO: Optionally validate rule shapes here
        update_option( self::OPTION_KEY, wp_json_encode( $decoded ) );
        wp_send_json_success();
    }

    public function enqueue_front_scripts() {
        if ( ! function_exists( 'is_woocommerce' ) ) return;

        // only load on single product pages or shop
        if ( is_product() || is_shop() || is_product_category() ) {
            wp_enqueue_script( 'dpe-front', plugins_url( 'dpe-front.js', __FILE__ ), array( 'jquery' ), '1.0', true );
            wp_localize_script( 'dpe-front', 'dpeFront', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'dpe_front_nonce' ),
            ) );
        }
    }

    /**
     * Main price filter — determines final price based on rules
     *
     * @param string|float $price
     * @param WC_Product $product
     * @return string|float
     */
    public function filter_product_price( $price, $product ) {
        // Ensure we have product object
        if ( is_numeric( $price ) ) {
            $base_price = (float) $price;
        } else {
            $base_price = floatval( $price );
        }

        // Caching key
        $cache_key = 'dpe_price_' . $product->get_id() . '_' . ( get_current_user_id() ?: 'guest' );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $rules = $this->get_rules();

        // Sort by priority
        usort( $rules, function( $a, $b ) {
            $pa = isset($a['priority']) ? (int)$a['priority'] : 10;
            $pb = isset($b['priority']) ? (int)$b['priority'] : 10;
            return $pa - $pb;
        } );

        $current_price = $base_price;
        foreach ( $rules as $rule ) {
            if ( empty( $rule['enabled'] ) ) continue;
            if ( $this->evaluate_rule_conditions( $rule['conditions'], $product ) ) {
                $current_price = $this->apply_rule_action( $current_price, $rule['action'], $product );
                // allow next rules to modify further
            }
        }

        // Save a short-lived cache (30 seconds)
        set_transient( $cache_key, $current_price, 30 );

        return $current_price;
    }

    public function filter_price_html( $price_html, $product ) {
        // We won't attempt to recreate complex HTML — rely on WooCommerce to show price
        return $price_html;
    }

    public function filter_variation_prices_array( $price, $variation, $product ) {
        // Variation price is passed in; run through the same engine
        return $this->filter_product_price( $price, $variation );
    }

    public function maybe_adjust_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( empty( $cart_item['data'] ) ) continue;
            $product = $cart_item['data'];
            $original_price = $product->get_price();
            $new_price = $this->filter_product_price( $original_price, $product );
            if ( $new_price != $original_price ) {
                $cart_item['data']->set_price( $new_price );
            }
        }
    }

    protected function get_rules() {
        $json = get_option( self::OPTION_KEY, '[]' );
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) return array();
        return $decoded;
    }

    protected function evaluate_rule_conditions( $conditions, $product ) {
        if ( empty( $conditions ) || ! is_array( $conditions ) ) return true; // no conditions == always

        foreach ( $conditions as $cond ) {
            $type = isset( $cond['type'] ) ? $cond['type'] : '';
            $op = isset( $cond['operator'] ) ? $cond['operator'] : '==';
            $value = isset( $cond['value'] ) ? $cond['value'] : null;

            $left = null;

            switch ( $type ) {
                case 'views_last_24h':
                    $left = $this->get_product_views_last_hours( $product->get_id(), 24 );
                    break;
                case 'stock_quantity':
                    $left = $product->get_stock_quantity();
                    break;
                case 'total_sales':
                    $left = $product->get_total_sales();
                    break;
                case 'user_role':
                    $left = implode( ',', wp_get_current_user()->roles );
                    break;
                case 'time_of_day':
                    $left = (int) gmdate( 'G' ); // 0-23 UTC; admin can define rules accordingly
                    break;
                default:
                    // unknown condition -> treat as false
                    return false;
            }

            if ( ! $this->compare_values( $left, $op, $value ) ) {
                return false; // one failing cond => rule doesn't apply
            }
        }

        return true;
    }

    protected function compare_values( $left, $op, $right ) {
        switch ( $op ) {
            case '==': return (string) $left === (string) $right;
            case '!=': return (string) $left !== (string) $right;
            case '>': return floatval( $left ) > floatval( $right );
            case '>=': return floatval( $left ) >= floatval( $right );
            case '<': return floatval( $left ) < floatval( $right );
            case '<=': return floatval( $left ) <= floatval( $right );
            default: return false;
        }
    }

    protected function apply_rule_action( $current_price, $action, $product ) {
        if ( empty( $action ) || ! is_array( $action ) ) return $current_price;
        $type = isset( $action['type'] ) ? $action['type'] : '';
        $value = isset( $action['value'] ) ? $action['value'] : 0;

        switch ( $type ) {
            case 'multiply':
                return round( $current_price * floatval( $value ), wc_get_price_decimals() );
            case 'add':
                return round( $current_price + floatval( $value ), wc_get_price_decimals() );
            case 'set':
                return round( floatval( $value ), wc_get_price_decimals() );
            default:
                return $current_price;
        }
    }

    /**
     * Return approximate product views in last X hours.
     * Implementation: check a transient saved by front-end JS. If not available, returns 0.
     */
    protected function get_product_views_last_hours( $product_id, $hours = 24 ) {
        // Local view tracker stores recent views per session in a cookie via front-end script.
        // For a simplified server-side read, we check a transient where front-end may send aggregated view counts.
        $key = 'dpe_views_' . $product_id;
        $val = get_transient( $key );
        return $val ? intval( $val ) : 0;
    }
}

// Initialize
new DPE_Plugin();

// Create simple front-end JS in the plugin file location (dpe-front.js)
file_put_contents( plugin_dir_path( __FILE__ ) . 'dpe-front.js', "(function(){\n  // Simple product view tracker: stores recent views in localStorage and pings admin-ajax to increment a transient.\n  if(typeof window !== 'undefined') {\n    var pData = window.dpeProductData || {};\n    var productId = document.body.getAttribute('data-product-id') || (pData.id || '');\n    try {\n      if(productId) {\n        var key = 'dpe_views_seen_' + productId;\n        var now = Date.now();\n        // Avoid counting same tab repeatedly within 5 minutes\n        var last = localStorage.getItem(key);\n        if(!last || (now - parseInt(last,10)) > (5*60*1000)) {\n          localStorage.setItem(key, now);\n          // fire a lightweight ajax to increment transient server-side (no user data stored)\n          var f = new FormData();\n          f.append('action','dpe_increment_view');\n          f.append('product_id', productId);\n          f.append('_nonce', dpeFront && dpeFront.nonce ? dpeFront.nonce : '');\n          navigator.sendBeacon && navigator.sendBeacon(dpeFront ? dpeFront.ajax_url : '/?nojs=1', f) || fetch(dpeFront ? dpeFront.ajax_url : '/?nojs=1', {method:'POST', body:f, credentials:'same-origin'});\n        }\n      }\n    } catch(e) {}\n  }\n})();" );

// AJAX endpoint to increment view counts
add_action( 'wp_ajax_nopriv_dpe_increment_view', function(){
    // accept POST product_id, optional nonce
    $pid = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    // Basic protection: only numeric product id required; deny otherwise
    if ( ! $pid ) wp_die();

    $key = 'dpe_views_' . $pid;
    $current = get_transient( $key );
    $current = $current ? intval( $current ) : 0;
    $current++;
    // keep for 26 hours (so it approximates last 24h but allows clock slack)
    set_transient( $key, $current, 26 * HOUR_IN_SECONDS );
    wp_die();
} );

add_action( 'wp_ajax_dpe_increment_view', function(){
    // Same as nopriv
    $pid = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    if ( ! $pid ) wp_die();
    $key = 'dpe_views_' . $pid;
    $current = get_transient( $key );
    $current = $current ? intval( $current ) : 0;
    $current++;
    set_transient( $key, $current, 26 * HOUR_IN_SECONDS );
    wp_die();
} );

// Add data attribute with product id to body for front-end script
add_action( 'wp', function(){
    if ( is_product() && function_exists( 'is_woocommerce' ) ) {
        add_filter( 'body_class', function( $classes ) {
            global $post;
            if ( $post && 'product' === get_post_type( $post ) ) {
                $classes[] = 'dpe-product-id-' . $post->ID;
            }
            return $classes;
        } );

        // also print a small inline script with product id for sites where body attribute not convenient
        add_action( 'wp_head', function(){
            if ( is_product() ) {
                global $post;
                if ( $post && 'product' === get_post_type( $post ) ) {
                    echo "<script>window.dpeProductData = window.dpeProductData || {}; window.dpeProductData.id = '" . esc_js( $post->ID ) . "';</script>";
                }
            }
        }, 1 );
    }
} );

// Admin: show a column in products list with dynamic price suggestion (optional)
add_filter( 'manage_edit-product_columns', function( $columns ){
    $columns['dpe_dynamic_price'] = __( 'DPE Price', 'dpe' );
    return $columns;
} );
add_action( 'manage_product_posts_custom_column', function( $column, $post_id ){
    if ( $column === 'dpe_dynamic_price' ) {
        $product = wc_get_product( $post_id );
        if ( $product ) {
            $dpe = new DPE_Plugin();
            $p = $dpe->filter_product_price( $product->get_price(), $product );
            echo wc_price( $p );
        }
    }
}, 10, 2 );

// End of plugin file
