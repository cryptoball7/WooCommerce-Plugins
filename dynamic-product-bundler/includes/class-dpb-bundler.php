<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DPB_Bundler {

    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_dpb_calculate_price', array( __CLASS__, 'ajax_calculate_price' ) );
        add_action( 'wp_ajax_nopriv_dpb_calculate_price', array( __CLASS__, 'ajax_calculate_price' ) );

        add_shortcode( 'dpb_builder', array( __CLASS__, 'shortcode_builder' ) );

        // Add bundle metadata to cart and set custom price
        add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_cart_item_price' ), 20, 1 );

        // Prevent merging of bundle items
        add_filter( 'woocommerce_cart_item_key', array( __CLASS__, 'unique_cart_item_key' ), 10, 2 );

        // Display selected bundle items in cart/order
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_meta' ), 10, 2 );
    }

    public static function enqueue_assets() {
        wp_register_style( 'dpb-style', plugins_url( '../assets/css/dpb-style.css', __FILE__ ) );
        wp_enqueue_style( 'dpb-style' );

        wp_register_script( 'dpb-frontend', plugins_url( '../assets/js/dpb-frontend.js', __FILE__ ), array( 'jquery' ), '1.0', true );
        wp_localize_script( 'dpb-frontend', 'DPB', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dpb_nonce' ),
            'i18n'     => array(
                'calculating' => __( 'Calculating...', 'dpb' ),
                'out_of_stock' => __( 'One or more selections are out of stock.', 'dpb' ),
            ),
        ) );
        wp_enqueue_script( 'dpb-frontend' );
    }

    /**
     * Shortcode: [dpb_builder products="12,34" category="slug" title="Build your bundle"]
     * - products: comma-separated product IDs (overrides category)
     * - category: product category slug to pull items from
     * - title: header for builder
     * - discount_type: 'fixed' or 'percent'
     * - discount_value: numeric
     */
    public static function shortcode_builder( $atts ) {
        $atts = shortcode_atts( array(
            'products'       => '',
            'category'       => '',
            'title'          => __( 'Build your bundle', 'dpb' ),
            'discount_type'  => 'fixed',
            'discount_value' => '0',
        ), $atts, 'dpb_builder' );

        $products = array();

        if ( ! empty( $atts['products'] ) ) {
            $ids = array_map( 'intval', explode( ',', $atts['products'] ) );
            foreach ( $ids as $id ) {
                $p = wc_get_product( $id );
                if ( $p && $p->is_purchasable() ) $products[] = $p;
            }
        } elseif ( ! empty( $atts['category'] ) ) {
            $query = new WP_Query( array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'slug',
                        'terms'    => sanitize_text_field( $atts['category'] ),
                    ),
                ),
            ) );
            foreach ( $query->posts as $post ) {
                $p = wc_get_product( $post->ID );
                if ( $p && $p->is_purchasable() ) $products[] = $p;
            }
            wp_reset_postdata();
        } else {
            return '<p>' . __( 'No products provided to the bundle builder.', 'dpb' ) . '</p>';
        }

        // Build markup
        ob_start();
        ?>
        <div class="dpb-builder" data-discount-type="<?php echo esc_attr( $atts['discount_type'] ); ?>" data-discount-value="<?php echo esc_attr( $atts['discount_value'] ); ?>">
            <h3 class="dpb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
            <form class="dpb-form" method="post" onsubmit="return false;">
                <table class="dpb-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Product', 'dpb' ); ?></th>
                            <th><?php esc_html_e( 'Price', 'dpb' ); ?></th>
                            <th><?php esc_html_e( 'Quantity', 'dpb' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $products as $p ): 
                        $pid = $p->get_id();
                        $price = wc_get_price_to_display( $p );
                        ?>
                        <tr data-product-id="<?php echo esc_attr( $pid ); ?>">
                            <td class="dpb-name"><?php echo esc_html( $p->get_name() ); ?></td>
                            <td class="dpb-price" data-price="<?php echo esc_attr( wc_format_decimal( floatval( $price ), wc_get_price_decimals() ) ); ?>">
                                <?php echo wp_kses_post( wc_price( $price ) ); ?>
                            </td>
                            <td class="dpb-qty">
                                <input type="number" class="dpb-qty-input" name="qty[<?php echo esc_attr( $pid ); ?>]" value="0" min="0" step="1" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="dpb-summary">
                    <div class="dpb-total-label"><?php esc_html_e( 'Bundle total:', 'dpb' ); ?></div>
                    <div class="dpb-total-value" aria-live="polite"><?php echo wc_price( 0 ); ?></div>
                </div>

                <div class="dpb-actions">
                    <button type="button" class="button dpb-calc"><?php esc_html_e( 'Recalculate', 'dpb' ); ?></button>
                    <button type="button" class="button alt dpb-add-to-cart"><?php esc_html_e( 'Add bundle to cart', 'dpb' ); ?></button>
                </div>

                <input type="hidden" name="dpb_builder_nonce" value="<?php echo wp_create_nonce( 'dpb_builder_action' ); ?>" />
                <input type="hidden" name="dpb_selected" class="dpb-selected-json" value="" />
                <!-- store original product IDs for server -->
                <input type="hidden" name="dpb_product_ids" value="<?php echo esc_attr( implode( ',', array_map( function( $p ){ return intval( $p->get_id() ); }, $products ) ) ); ?>" />
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: calculate server-validated price (sum + discount)
     */
    public static function ajax_calculate_price() {
        check_ajax_referer( 'dpb_nonce', 'nonce' );
        $selected = isset( $_POST['selected'] ) ? wp_unslash( $_POST['selected'] ) : '';
        $discount_type  = isset( $_POST['discount_type'] ) ? sanitize_text_field( $_POST['discount_type'] ) : 'fixed';
        $discount_value = isset( $_POST['discount_value'] ) ? floatval( $_POST['discount_value'] ) : 0;

        $selected_arr = json_decode( wp_json_encode( $selected ), true );
        // selected will be a JSON string from the client — decode safely
        if ( is_string( $selected ) ) {
            $selected_arr = json_decode( $selected, true );
        }

        if ( ! is_array( $selected_arr ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid selection', 'dpb' ) ) );
        }

        $subtotal = 0.0;
        $out_of_stock = false;

        foreach ( $selected_arr as $pid => $qty ) {
            $pid = intval( $pid );
            $qty = intval( $qty );
            if ( $qty <= 0 ) continue;

            $product = wc_get_product( $pid );
            if ( ! $product || ! $product->is_purchasable() ) {
                wp_send_json_error( array( 'message' => sprintf( __( 'Product %d is not purchasable', 'dpb' ), $pid ) ) );
            }

            // Stock check: if managed and not enough, flag
            if ( $product->managing_stock() ) {
                $stock_qty = $product->get_stock_quantity();
                if ( $stock_qty !== null && $qty > $stock_qty ) {
                    $out_of_stock = true;
                }
            }
            $price = floatval( $product->get_price() );
            // Use price excluding tax for calculations unless store requires incl tax
            $subtotal += $price * $qty;
        }

        // Apply discount (filterable)
        $bundle_price = apply_filters( 'dpb_calculate_bundle_price', self::apply_discount( $subtotal, $discount_type, $discount_value ), $subtotal, $discount_type, $discount_value, $selected_arr );

        $formatted = wc_price( $bundle_price );

        wp_send_json_success( array(
            'subtotal' => wc_format_decimal( $subtotal, wc_get_price_decimals() ),
            'bundle_price' => wc_format_decimal( $bundle_price, wc_get_price_decimals() ),
            'formatted' => $formatted,
            'out_of_stock' => $out_of_stock,
        ) );
    }

    public static function apply_discount( $subtotal, $type, $value ) {
        $subtotal = floatval( $subtotal );
        $value = floatval( $value );
        if ( $type === 'percent' ) {
            $discount = ( $value / 100.0 ) * $subtotal;
            $final = $subtotal - $discount;
        } else {
            // fixed
            $final = max( 0, $subtotal - $value );
        }
        return $final;
    }

    /**
     * When adding to cart we expect POST data from frontend:
     * - dpb_selected: JSON string like {"12":1,"34":2}
     * - dpb_bundle_price: optional server-side price (we will recalc)
     */
    public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        // Only act if dpb_selected is present
        if ( isset( $_REQUEST['dpb_selected'] ) && ! empty( $_REQUEST['dpb_selected'] ) ) {
            $nonce = isset( $_REQUEST['dpb_builder_nonce'] ) ? $_REQUEST['dpb_builder_nonce'] : '';
            if ( ! wp_verify_nonce( $nonce, 'dpb_builder_action' ) ) {
                return $cart_item_data;
            }

            $selected_raw = wp_unslash( $_REQUEST['dpb_selected'] );
            $selected = json_decode( $selected_raw, true );
            if ( ! is_array( $selected ) ) return $cart_item_data;

            // Validate and compute price server-side (re-use calculate logic)
            $subtotal = 0.0;
            $items = array();
            foreach ( $selected as $pid => $qty ) {
                $pid = intval( $pid );
                $qty = intval( $qty );
                if ( $qty <= 0 ) continue;

                $product = wc_get_product( $pid );
                if ( ! $product ) continue;

                $items[] = array(
                    'product_id' => $pid,
                    'name'       => $product->get_name(),
                    'qty'        => $qty,
                    'unit_price' => floatval( $product->get_price() ),
                );
                $subtotal += floatval( $product->get_price() ) * $qty;
            }

            $discount_type = isset( $_REQUEST['dpb_discount_type'] ) ? sanitize_text_field( $_REQUEST['dpb_discount_type'] ) : 'fixed';
            $discount_value = isset( $_REQUEST['dpb_discount_value'] ) ? floatval( $_REQUEST['dpb_discount_value'] ) : 0;

            $bundle_price = self::apply_discount( $subtotal, $discount_type, $discount_value );
            $bundle_price = apply_filters( 'dpb_final_bundle_price_before_cart', $bundle_price, $items, $subtotal );

            // Attach as meta
            $cart_item_data['dpb_bundle'] = array(
                'items' => $items,
                'bundle_price' => wc_format_decimal( $bundle_price, wc_get_price_decimals() ),
                'subtotal' => wc_format_decimal( $subtotal, wc_get_price_decimals() ),
                'discount_type' => $discount_type,
                'discount_value' => $discount_value,
            );

            // Make each bundle a unique cart item (prevent merging)
            $cart_item_data['unique_key'] = md5( microtime() . rand() );

            // Optionally set product id for the bundle catalogue product:
            // If you want a parent "bundle product" provide its ID via dpb_parent_id in POST
            if ( isset( $_REQUEST['dpb_parent_id'] ) ) {
                $parent_id = intval( $_REQUEST['dpb_parent_id'] );
                if ( $parent_id > 0 ) {
                    $cart_item_data['product_id_override'] = $parent_id;
                }
            }
        }

        return $cart_item_data;
    }

    /**
     * Set custom price in cart before totals.
     */
    public static function set_cart_item_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['dpb_bundle'] ) ) {
                $bundle = $cart_item['dpb_bundle'];
                $price = floatval( $bundle['bundle_price'] );
                // Use set_price if available
                if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
                    $cart_item['data']->set_price( $price );
                }
                // Also set line subtotal/total for backwards compat
                $cart_item['line_total'] = $price * $cart_item['quantity'];
                $cart_item['line_subtotal'] = $price * $cart_item['quantity'];
            }
        }
    }

    /**
     * Avoid merging bundles — WooCommerce uses 'unique_key' if present, but ensure unique.
     */
    public static function unique_cart_item_key( $cart_item_key, $cart_item ) {
        if ( isset( $cart_item['dpb_bundle'] ) && isset( $cart_item['unique_key'] ) ) {
            // Append unique_key to force uniqueness
            return $cart_item_key . '_' . $cart_item['unique_key'];
        }
        return $cart_item_key;
    }

    /**
     * Display bundle composition in cart item details (under item name).
     */
    public static function display_cart_item_meta( $item_data, $cart_item ) {
        if ( isset( $cart_item['dpb_bundle'] ) ) {
            $bundle = $cart_item['dpb_bundle'];
            if ( isset( $bundle['items'] ) && is_array( $bundle['items'] ) ) {
                foreach ( $bundle['items'] as $itm ) {
                    $label = esc_html( $itm['name'] ) . ' x' . intval( $itm['qty'] );
                    $value = wc_price( floatval( $itm['unit_price'] ) );
                    $item_data[] = array(
                        'key' => $label,
                        'value' => $value,
                        'display' => $label . ' — ' . $value,
                    );
                }
                // Show bundle subtotal/price
                $item_data[] = array(
                    'key' => __( 'Bundle price', 'dpb' ),
                    'value' => wc_price( floatval( $bundle['bundle_price'] ) ),
                );
            }
        }
        return $item_data;
    }
}
