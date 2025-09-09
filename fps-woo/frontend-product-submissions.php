<?php
/**
 * Plugin Name: Frontend Product Submissions for WooCommerce
 * Description: Let vendors/users submit products from the frontend, with file uploads, user role "FPS Vendor", admin approval workflow, and basic product CRUD.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: fps-woo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FPS_Woo {
    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activation' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );

        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'init', array( $this, 'maybe_handle_submission' ) );

        // Admin menu for approvals
        add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
        add_action( 'admin_post_fps_approve', array( $this, 'admin_approve_product' ) );
        add_action( 'admin_post_fps_reject', array( $this, 'admin_reject_product' ) );

        // enqueue
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // add vendor info column to products list
        add_filter( 'manage_product_posts_columns', array( $this, 'product_columns' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'product_columns_content' ), 10, 2 );

        // ensure vendor role capabilities for WooCommerce product basic editing
        add_action( 'init', array( $this, 'sync_vendor_caps' ) );
    }

    public function activation() {
        $this->add_vendor_role();
        $this->sync_vendor_caps();
    }

    public function deactivation() {
        remove_role( 'fps_vendor' );
    }

    public function add_vendor_role() {
        add_role( 'fps_vendor', 'FPS Vendor', array( 'read' => true ) );
    }

    public function sync_vendor_caps() {
        $role = get_role( 'fps_vendor' );
        if ( ! $role ) return;

        // Some minimal caps to allow creating/editing only their own products via admin if needed
        $caps = array(
            'read' => true,
            'edit_products' => true,
            'edit_published_products' => true,
            'publish_products' => true,
            'delete_products' => true,
        );

        foreach ( $caps as $cap => $v ) {
            $role->add_cap( $cap );
        }
    }

    public function enqueue_scripts() {
        wp_enqueue_style( 'fps-style', plugin_dir_url( __FILE__ ) . 'assets/fps-style.css' );
        wp_enqueue_script( 'fps-script', plugin_dir_url( __FILE__ ) . 'assets/fps-script.js', array( 'jquery' ), '1.0', true );
        wp_localize_script( 'fps-script', 'fps_ajax', array( 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );
    }

    public function register_shortcodes() {
        add_shortcode( 'fps_product_form', array( $this, 'render_product_form' ) );
    }

    public function render_product_form( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>You must be logged in to submit a product.</p>';
        }

        $user = wp_get_current_user();

        // Only users with allowed roles can submit. By default allow administrators, editors, shop_manager and fps_vendor
        $allowed = array( 'administrator', 'editor', 'shop_manager', 'fps_vendor' );
        if ( ! array_intersect( $allowed, $user->roles ) ) {
            return '<p>Your account is not allowed to submit products.</p>';
        }

        ob_start();
        ?>
        <form method="post" enctype="multipart/form-data" class="fps-product-form">
            <?php wp_nonce_field( 'fps_submit_product', 'fps_nonce' ); ?>
            <input type="hidden" name="fps_action" value="submit_product">

            <p>
                <label>Product title (required)<br>
                <input type="text" name="fps_title" required></label>
            </p>

            <p>
                <label>Description<br>
                <textarea name="fps_description" rows="6"></textarea></label>
            </p>

            <p>
                <label>Price (decimal, e.g. 19.99)<br>
                <input type="text" name="fps_price"></label>
            </p>

            <p>
                <label>Product category (optional)<br>
                <?php wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'name' => 'fps_cat', 'hide_empty' => false, 'hierarchical' => true ) ); ?>
                </label>
            </p>

            <p>
                <label>Product image (single main image)<br>
                <input type="file" name="fps_image" accept="image/*"></label>
            </p>

            <p>
                <label>Gallery images (multiple)<br>
                <input type="file" name="fps_gallery[]" multiple accept="image/*"></label>
            </p>

            <p>
                <label>Attach downloadable file (optional)<br>
                <input type="file" name="fps_file"></label>
            </p>

            <p>
                <label><input type="checkbox" name="fps_notify_admin" value="1"> Notify admin by email on submission</label>
            </p>

            <p>
                <button type="submit">Submit product</button>
            </p>
        </form>
        <?php

        return ob_get_clean();
    }

    public function maybe_handle_submission() {
        if ( ! empty( $_POST['fps_action'] ) && $_POST['fps_action'] === 'submit_product' ) {
            $this->handle_frontend_submission();
        }
    }

    protected function handle_frontend_submission() {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Not allowed' );
        }

        if ( empty( $_POST['fps_nonce'] ) || ! wp_verify_nonce( $_POST['fps_nonce'], 'fps_submit_product' ) ) {
            wp_die( 'Security check failed' );
        }

        $user = wp_get_current_user();
        $allowed = array( 'administrator', 'editor', 'shop_manager', 'fps_vendor' );
        if ( ! array_intersect( $allowed, $user->roles ) ) {
            wp_die( 'Your account cannot submit products.' );
        }

        $title = sanitize_text_field( $_POST['fps_title'] );
        $description = wp_kses_post( $_POST['fps_description'] );
        $price = isset( $_POST['fps_price'] ) ? wc_format_decimal( wp_unslash( $_POST['fps_price'] ) ) : '';
        $cat = ! empty( $_POST['fps_cat'] ) ? intval( $_POST['fps_cat'] ) : 0;

        // create product post as 'pending' for admin approval
        $postarr = array(
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => 'pending', // admin approval required
            'post_type' => 'product',
            'post_author' => $user->ID,
        );

        $product_id = wp_insert_post( $postarr );

        if ( is_wp_error( $product_id ) ) {
            wp_die( 'Error creating product.' );
        }

        // store vendor id
        update_post_meta( $product_id, '_fps_vendor_id', $user->ID );

        // set catalog visibility and type
        wp_set_object_terms( $product_id, 'simple', 'product_type' );

        // set category if available
        if ( $cat ) {
            wp_set_post_terms( $product_id, array( $cat ), 'product_cat', true );
        }

        // price
        if ( $price !== '' ) {
            update_post_meta( $product_id, '_regular_price', $price );
            update_post_meta( $product_id, '_price', $price );
        }

        // handle image upload
        if ( ! empty( $_FILES['fps_image'] ) && ! empty( $_FILES['fps_image']['name'] ) ) {
            $attach_id = $this->handle_upload_and_attach( $_FILES['fps_image'], $product_id );
            if ( $attach_id ) {
                set_post_thumbnail( $product_id, $attach_id );
            }
        }

        // gallery
        if ( ! empty( $_FILES['fps_gallery'] ) && is_array( $_FILES['fps_gallery']['name'] ) ) {
            $gallery_ids = array();
            $files = $_FILES['fps_gallery'];
            $count = count( $files['name'] );
            for ( $i = 0; $i < $count; $i++ ) {
                if ( empty( $files['name'][$i] ) ) continue;
                $file = array(
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                );
                $aid = $this->handle_upload_and_attach( $file, $product_id );
                if ( $aid ) $gallery_ids[] = $aid;
            }
            if ( ! empty( $gallery_ids ) ) {
                update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
            }
        }

        // downloadable file (as attachment) - mark product as virtual & downloadable
        if ( ! empty( $_FILES['fps_file'] ) && ! empty( $_FILES['fps_file']['name'] ) ) {
            $file_attach = $this->handle_upload_and_attach( $_FILES['fps_file'], $product_id );
            if ( $file_attach ) {
                update_post_meta( $product_id, '_downloadable', 'yes' );
                update_post_meta( $product_id, '_virtual', 'yes' );

                // store downloadable file in _downloadable_files meta
                $file_url = wp_get_attachment_url( $file_attach );
                $files_array = array(
                    md5( $file_url ) => array(
                        'name' => basename( $file_url ),
                        'file' => $file_url,
                    ),
                );
                update_post_meta( $product_id, '_downloadable_files', $files_array );
            }
        }

        // notify admin
        if ( ! empty( $_POST['fps_notify_admin'] ) ) {
            $admin_email = get_option( 'admin_email' );
            $subject = sprintf( 'New product submission: %s', $title );
            $message = sprintf( "A new product was submitted by %s (ID %d). Review it here: %s", $user->user_login, $user->ID, get_edit_post_link( $product_id, 'url' ) );
            wp_mail( $admin_email, $subject, $message );
        }

        // redirect back to same page with success
        $redirect = wp_get_referer() ? wp_get_referer() : home_url();
        wp_safe_redirect( add_query_arg( 'fps_submitted', '1', $redirect ) );
        exit;
    }

    protected function handle_upload_and_attach( $file, $parent_post ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // sanitize filename
        if ( isset( $file['name'] ) ) {
            $file['name'] = sanitize_file_name( $file['name'] );
        }

        $overrides = array( 'test_form' => false );
        $movefile = wp_handle_upload( $file, $overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $filename = $movefile['file'];
            $filetype = wp_check_filetype( $filename, null );

            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title' => sanitize_file_name( basename( $filename ) ),
                'post_content' => '',
                'post_status' => 'inherit',
            );

            $attach_id = wp_insert_attachment( $attachment, $filename, $parent_post );
            if ( ! is_wp_error( $attach_id ) ) {
                $attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
                wp_update_attachment_metadata( $attach_id, $attach_data );
                return $attach_id;
            }
        }

        return false;
    }

    public function add_admin_pages() {
        add_submenu_page( 'edit.php?post_type=product', 'FPS Submissions', 'FPS Submissions', 'manage_woocommerce', 'fps-submissions', array( $this, 'render_admin_submissions' ) );
    }

    public function render_admin_submissions() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Insufficient permissions' );
        }

        $args = array(
            'post_type' => 'product',
            'post_status' => 'pending',
            'meta_key' => '_fps_vendor_id',
            'posts_per_page' => 50,
        );
        $q = new WP_Query( $args );

        echo '<div class="wrap"><h1>FPS Submissions</h1>';

        if ( $q->have_posts() ) {
            echo '<table class="widefat"><thead><tr><th>ID</th><th>Title</th><th>Vendor</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
            while ( $q->have_posts() ) {
                $q->the_post();
                $pid = get_the_ID();
                $vendor = get_post_meta( $pid, '_fps_vendor_id', true );
                $vendor_label = $vendor ? get_the_author_meta( 'user_login', $vendor ) . ' (ID ' . $vendor . ')' : '—';

                $approve_url = wp_nonce_url( admin_url( 'admin-post.php?action=fps_approve&product_id=' . $pid ), 'fps_admin_action_' . $pid );
                $reject_url = wp_nonce_url( admin_url( 'admin-post.php?action=fps_reject&product_id=' . $pid ), 'fps_admin_action_' . $pid );

                echo '<tr>';
                echo '<td>' . esc_html( $pid ) . '</td>';
                echo '<td>' . esc_html( get_the_title( $pid ) ) . '</td>';
                echo '<td>' . esc_html( $vendor_label ) . '</td>';
                echo '<td>' . esc_html( get_the_date( '', $pid ) ) . '</td>';
                echo '<td><a href="' . esc_url( get_edit_post_link( $pid ) ) . '" target="_blank">Edit</a> | <a href="' . esc_url( $approve_url ) . '">Approve</a> | <a href="' . esc_url( $reject_url ) . '">Reject</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No pending submissions.</p>';
        }

        echo '</div>';
        wp_reset_postdata();
    }

    public function admin_approve_product() {
        if ( empty( $_GET['product_id'] ) ) wp_die( 'Missing product id' );
        $pid = intval( $_GET['product_id'] );
        if ( ! wp_verify_nonce( $_SERVER['HTTP_REFERER'] ? wp_get_referer() : '', 'fps_admin_action_' . $pid ) ) {
            // We'll attempt basic nonce check with referer — but if it fails, still check capability
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );

        // publish product
        wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );

        // optionally notify vendor
        $vendor = get_post_meta( $pid, '_fps_vendor_id', true );
        if ( $vendor ) {
            $user = get_userdata( $vendor );
            if ( $user ) {
                wp_mail( $user->user_email, 'Your product has been approved', 'Your product "' . get_the_title( $pid ) . '" has been approved and is now live.' );
            }
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=fps-submissions&approved=1' ) );
        exit;
    }

    public function admin_reject_product() {
        if ( empty( $_GET['product_id'] ) ) wp_die( 'Missing product id' );
        $pid = intval( $_GET['product_id'] );

        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission' );

        // change status to draft and add a meta flag
        wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
        update_post_meta( $pid, '_fps_rejected', 1 );

        // notify vendor
        $vendor = get_post_meta( $pid, '_fps_vendor_id', true );
        if ( $vendor ) {
            $user = get_userdata( $vendor );
            if ( $user ) {
                wp_mail( $user->user_email, 'Your product was rejected', 'Your product "' . get_the_title( $pid ) . '" was rejected by admin. Please review and resubmit.' );
            }
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=fps-submissions&rejected=1' ) );
        exit;
    }

    public function product_columns( $columns ) {
        $columns['fps_vendor'] = 'Vendor';
        return $columns;
    }

    public function product_columns_content( $column, $post_id ) {
        if ( 'fps_vendor' === $column ) {
            $vendor = get_post_meta( $post_id, '_fps_vendor_id', true );
            if ( $vendor ) {
                echo esc_html( get_the_author_meta( 'user_login', $vendor ) ) . ' (ID ' . intval( $vendor ) . ')';
            } else {
                echo '—';
            }
        }
    }
}

new FPS_Woo();

// Basic assets — create them inline if absent to avoid 404s
add_action( 'init', function(){
    $css_path = plugin_dir_path( __FILE__ ) . 'assets/fps-style.css';
    if ( ! file_exists( $css_path ) ) {
        file_put_contents( $css_path, ".fps-product-form{max-width:700px;background:#fff;padding:16px;border:1px solid #ddd;border-radius:6px;}\n.fps-product-form input[type=text], .fps-product-form textarea{width:100%;box-sizing:border-box;padding:8px;margin-top:4px;}\n.fps-product-form button{background:#0073aa;color:#fff;padding:10px 14px;border:none;border-radius:4px;}" );
    }

    $js_path = plugin_dir_path( __FILE__ ) . 'assets/fps-script.js';
    if ( ! file_exists( $js_path ) ) {
        file_put_contents( $js_path, "(function($){$(function(){ /* placeholder */ });})(jQuery);" );
    }
});

?>
