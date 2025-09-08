<?php
/**
 * Plugin Name: Woo Bulk Discount Rules Engine
 * Description: Simple Bulk Discount Rules Engine for WooCommerce. Admins can define rules (e.g. 10% off for 5+ items). Applies discounts at cart-level and supports live cart updates.
 * Version: 1.0.0
 * Author: ChatGPT
 * Text Domain: wbdre
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WBDRE_Plugin {
    const OPTION_KEY = 'wbdre_rules';
    const NONCE      = 'wbdre_nonce';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
        add_action( 'wp_ajax_wbdre_save_rules', array( $this, 'ajax_save_rules' ) );

        // Frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );

        // Apply discounts as negative fees (safe and reversible)
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_cart_discounts' ), 20, 1 );

        // AJAX handler to update cart quantities and return refreshed totals (live update)
        add_action( 'wp_ajax_nopriv_wbdre_update_cart', array( $this, 'ajax_update_cart' ) );
        add_action( 'wp_ajax_wbdre_update_cart', array( $this, 'ajax_update_cart' ) );
    }

    /* ----------------- Admin ----------------- */
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Bulk Discount Rules',
            'Bulk Discount Rules',
            'manage_woocommerce',
            'wbdre-rules',
            array( $this, 'admin_page' )
        );
    }

    public function admin_scripts( $hook ) {
        if ( $hook !== 'woocommerce_page_wbdre-rules' ) {
            return;
        }
        wp_enqueue_script( 'wbdre-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery' ), '1.0', true );
        wp_enqueue_style( 'wbdre-admin-css', plugin_dir_url( __FILE__ ) . 'assets/admin.css' );
        wp_localize_script( 'wbdre-admin', 'wbdre_admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE ),
        ) );
    }

    public function admin_page() {
        $rules = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $rules ) ) {
            $rules = array();
        }
        ?>
        <div class="wrap">
            <h1>Bulk Discount Rules Engine</h1>
            <p>Define simple bulk discount rules. Each rule supports: scope (all products or product IDs), minimum quantity, type (percentage/fixed), and value.</p>

            <div id="wbdre-rules-app">
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Enabled</th>
                            <th>Scope</th>
                            <th>Product IDs (comma separated, leave blank for all)</th>
                            <th>Min Quantity</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="wbdre-rules-list">
                    <?php foreach ( $rules as $i => $r ): ?>
                        <tr data-index="<?php echo esc_attr( $i ); ?>">
                            <td><input type="checkbox" class="wbdre-enabled" <?php checked( isset($r['enabled']) ? $r['enabled'] : 0, 1 ); ?>></td>
                            <td>
                                <select class="wbdre-scope">
                                    <option value="all" <?php selected( isset($r['scope']) ? $r['scope'] : 'all', 'all' ); ?>>All Products</option>
                                    <option value="specific" <?php selected( isset($r['scope']) ? $r['scope'] : 'all', 'specific' ); ?>>Specific Products</option>
                                </select>
                            </td>
                            <td><input class="widefat wbdre-products" value="<?php echo esc_attr( isset($r['product_ids']) ? $r['product_ids'] : '' ); ?>"></td>
                            <td><input type="number" min="1" class="wbdre-min-qty" value="<?php echo esc_attr( isset($r['min_qty']) ? $r['min_qty'] : 1 ); ?>"></td>
                            <td>
                                <select class="wbdre-type">
                                    <option value="percent" <?php selected( isset($r['type']) ? $r['type'] : 'percent', 'percent' ); ?>>Percent</option>
                                    <option value="fixed" <?php selected( isset($r['type']) ? $r['type'] : 'percent', 'fixed' ); ?>>Fixed Amount</option>
                                </select>
                            </td>
                            <td><input type="text" class="wbdre-value" value="<?php echo esc_attr( isset($r['value']) ? $r['value'] : '' ); ?>"></td>
                            <td><button class="button wbdre-remove">Remove</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button id="wbdre-add-rule" class="button button-primary">Add Rule</button>
                    <button id="wbdre-save" class="button button-primary">Save Rules</button>
                </p>
                <div id="wbdre-save-result" style="margin-top:10px;"></div>
            </div>
        </div>
        <?php
    }

    public function ajax_save_rules() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( self::NONCE, 'nonce' );

        $rules = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
        // sanitize
        $clean = array();
        if ( is_array( $rules ) ) {
            foreach ( $rules as $r ) {
                $clean[] = array(
                    'enabled'     => isset( $r['enabled'] ) && $r['enabled'] ? 1 : 0,
                    'scope'       => in_array( $r['scope'], array( 'all', 'specific' ) ) ? $r['scope'] : 'all',
                    'product_ids' => sanitize_text_field( $r['product_ids'] ),
                    'min_qty'     => max( 1, intval( $r['min_qty'] ) ),
                    'type'        => in_array( $r['type'], array( 'percent', 'fixed' ) ) ? $r['type'] : 'percent',
                    'value'       => sanitize_text_field( $r['value'] ),
                );
            }
        }

        update_option( self::OPTION_KEY, $clean );
        wp_send_json_success( 'Saved' );
    }

    /* ----------------- Frontend ----------------- */
    public function frontend_scripts() {
        // Only enqueue on cart & checkout for performance
        if ( is_cart() || is_checkout() ) {
            wp_enqueue_script( 'wbdre-frontend', plugin_dir_url( __FILE__ ) . 'assets/frontend.js', array( 'jquery' ), '1.0', true );
            wp_localize_script( 'wbdre-frontend', 'wbdre_front', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::NONCE ),
            ) );
        }
    }

    public function apply_cart_discounts( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! is_object( $cart ) ) {
            return;
        }

        $rules = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $rules ) || empty( $rules ) ) {
            return;
        }

        // Remove previously added fees by this plugin to avoid duplicates when recalculating
        // WooCommerce does not provide direct removal of fees but fees are recalculated per request; we'll just add fresh fees.

        foreach ( $rules as $idx => $r ) {
            if ( empty( $r['enabled'] ) ) {
                continue;
            }

            $scope = isset( $r['scope'] ) ? $r['scope'] : 'all';
            $product_ids = isset( $r['product_ids'] ) ? $r['product_ids'] : '';
            $min_qty = isset( $r['min_qty'] ) ? intval( $r['min_qty'] ) : 1;
            $type = isset( $r['type'] ) ? $r['type'] : 'percent';
            $value = isset( $r['value'] ) ? $r['value'] : '';

            // calculate matched quantity and matched subtotal
            $matched_qty = 0;
            $matched_subtotal = 0.0;

            $product_id_list = array();
            if ( $scope === 'specific' && ! empty( $product_ids ) ) {
                $ids = array_map( 'trim', explode( ',', $product_ids ) );
                foreach ( $ids as $id ) {
                    if ( is_numeric( $id ) ) {
                        $product_id_list[] = intval( $id );
                    }
                }
            }

            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $qty = intval( $cart_item['quantity'] );
                $product_id = $product->get_id();

                if ( $scope === 'all' || ( $scope === 'specific' && in_array( $product_id, $product_id_list ) ) ) {
                    $matched_qty += $qty;
                    $matched_subtotal += (float) $cart_item['line_subtotal']; // excludes taxes
                }
            }

            if ( $matched_qty >= $min_qty ) {
                // compute discount amount
                $discount_amount = 0.0;
                if ( $type === 'percent' ) {
                    $percent = floatval( $value );
                    if ( $percent > 0 ) {
                        $discount_amount = $matched_subtotal * ( $percent / 100 );
                    }
                } else { // fixed
                    // interpret value as fixed per cart (not per item)
                    $fixed = floatval( $value );
                    if ( $fixed > 0 ) {
                        $discount_amount = $fixed;
                    }
                }

                if ( $discount_amount > 0 ) {
                    $label = sprintf( 'Bulk discount (%s)', ( $type === 'percent' ? $value . '%%' : wc_price( $discount_amount ) ) );
                    // Add as negative fee
                    $cart->add_fee( $label, -1 * $discount_amount );
                }
            }
        }
    }

    /**
     * AJAX: update cart quantities (server-side) and return refreshed cart totals HTML fragment
     */
    public function ajax_update_cart() {
        check_ajax_referer( self::NONCE, 'nonce' );

        // Expect quantities in $_POST['cart'] like WooCommerce form: cart[<cart_item_key>] => qty
        if ( empty( $_POST['cart'] ) || ! is_array( $_POST['cart'] ) ) {
            wp_send_json_error( array( 'message' => 'No cart data' ) );
        }

        foreach ( $_POST['cart'] as $key => $qty ) {
            $key = sanitize_text_field( $key );
            $qty = intval( $qty );
            if ( $qty < 0 ) {
                $qty = 0;
            }
            // set_quantity will recalc when calculate_totals is called
            WC()->cart->set_quantity( $key, $qty, true );
        }

        WC()->cart->calculate_totals();

        // Capture cart totals template output
        ob_start();
        wc_get_template( 'cart/cart-totals.php' );
        $totals_html = ob_get_clean();

        // Also return mini-cart fragments to keep header cart updated
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        wp_send_json_success( array(
            'totals' => $totals_html,
            'mini_cart' => $mini_cart,
        ) );
    }
}

new WBDRE_Plugin();

?>
