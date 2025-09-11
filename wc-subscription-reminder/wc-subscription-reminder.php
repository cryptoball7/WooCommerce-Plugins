<?php
/*
Plugin Name: WooCommerce Subscription Reminder Add-On
Description: Sends configurable reminder emails before subscription renewals. Adds admin settings, per-product and per-subscription overrides, and a daily scheduled check.
Version: 1.0
Author: ChatGPT (Generated)
License: GPLv2+
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Subscription_Reminder_Addon {

    const OPTION_KEY = 'wc_sr_settings';
    const CRON_HOOK = 'wc_sr_daily_event';
    const EMAIL_ID  = 'wc_sr_subscription_reminder';

    public function __construct() {
        // Activation / deactivation
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );

        // Admin settings & menus
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Product options
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_options_fields' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_options' ), 10, 1 );

        // Subscription edit meta box (for shop_subscription)
        add_action( 'add_meta_boxes', array( $this, 'add_subscription_meta_box' ) );
        add_action( 'save_post_shop_subscription', array( $this, 'save_subscription_meta' ), 10, 2 );

        // Cron: hook that runs daily
        add_action( self::CRON_HOOK, array( $this, 'daily_check_and_send' ) );

        // Register custom WooCommerce email
        add_filter( 'woocommerce_email_classes', array( $this, 'register_email_class' ) );
        add_filter( 'woocommerce_email_actions', array( $this, 'register_email_action' ) );

        // Ensure plugin loads text domain (optional)
        add_action( 'plugins_loaded', function(){ load_plugin_textdomain('wc-sr', false, dirname(plugin_basename(__FILE__)) . '/languages'); } );
    }

    /* Activation: schedule daily event if not scheduled */
    public function on_activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'daily', self::CRON_HOOK ); // run daily
        }
        // set default settings if not present
        $defaults = array(
            'enabled'      => 'yes',
            'reminder_days'=> array( 7, 3, 1 ), // default reminder days
            'email_subject'=> 'Upcoming subscription renewal',
            'email_heading'=> 'Subscription renewal reminder',
            'email_content'=> "Hi {first_name},\n\nThis is a reminder that your subscription for {product} will renew on {date}.\n\nIf you need to update payment details, please visit your account.\n\nThanks,\n{site_name}"
        );
        if ( ! get_option( self::OPTION_KEY ) ) update_option( self::OPTION_KEY, $defaults );
    }

    public function on_deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* Admin menu and settings registration */
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Subscription Reminders',
            'Subscription Reminders',
            'manage_woocommerce',
            'wc-sr-settings',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'wc_sr_settings_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );

        add_settings_section( 'wc_sr_general', 'General settings', null, 'wc-sr-settings' );

        add_settings_field( 'enabled', 'Enable reminders', array( $this, 'field_enabled' ), 'wc-sr-settings', 'wc_sr_general' );
        add_settings_field( 'reminder_days', 'Reminder days (comma separated)', array( $this, 'field_reminder_days' ), 'wc-sr-settings', 'wc_sr_general' );
        add_settings_field( 'email_subject', 'Email subject', array( $this, 'field_email_subject' ), 'wc-sr-settings', 'wc_sr_general' );
        add_settings_field( 'email_heading', 'Email heading', array( $this, 'field_email_heading' ), 'wc-sr-settings', 'wc_sr_general' );
        add_settings_field( 'email_content', 'Email content', array( $this, 'field_email_content' ), 'wc-sr-settings', 'wc_sr_general' );
    }

    public function sanitize_settings( $input ) {
        $out = get_option( self::OPTION_KEY, array() );
        $out['enabled'] = ( isset( $input['enabled'] ) && $input['enabled'] === 'yes' ) ? 'yes' : 'no';
        // parse days
        $days = isset( $input['reminder_days'] ) ? $input['reminder_days'] : '';
        $days = preg_split('/[,\s]+/', trim( $days ), -1, PREG_SPLIT_NO_EMPTY );
        $days = array_map( 'intval', $days );
        $days = array_values( array_filter( $days, function($d){ return $d > 0 && $d < 3650; } ) );
        $out['reminder_days'] = $days;
        $out['email_subject'] = sanitize_text_field( $input['email_subject'] );
        $out['email_heading'] = sanitize_text_field( $input['email_heading'] );
        $out['email_content'] = sanitize_textarea_field( $input['email_content'] );
        return $out;
    }

    public function field_enabled() {
        $opts = get_option( self::OPTION_KEY );
        $v = isset( $opts['enabled'] ) ? $opts['enabled'] : 'yes';
        echo '<label><input type="checkbox" name="'.self::OPTION_KEY.'[enabled]" value="yes" '.checked('yes', $v, false).' /> Enabled</label>';
    }
    public function field_reminder_days() {
        $opts = get_option( self::OPTION_KEY );
        $v = isset( $opts['reminder_days'] ) ? implode( ',', $opts['reminder_days'] ) : '7,3,1';
        echo '<input type="text" name="'.self::OPTION_KEY.'[reminder_days]" value="'.esc_attr( $v ).'" class="regular-text" />';
        echo '<p class="description">Comma or space separated days before renewal (e.g. 7,3,1)</p>';
    }
    public function field_email_subject() {
        $opts = get_option( self::OPTION_KEY );
        $v = isset( $opts['email_subject'] ) ? $opts['email_subject'] : '';
        echo '<input type="text" name="'.self::OPTION_KEY.'[email_subject]" value="'.esc_attr( $v ).'" class="regular-text" />';
    }
    public function field_email_heading() {
        $opts = get_option( self::OPTION_KEY );
        $v = isset( $opts['email_heading'] ) ? $opts['email_heading'] : '';
        echo '<input type="text" name="'.self::OPTION_KEY.'[email_heading]" value="'.esc_attr( $v ).'" class="regular-text" />';
    }
    public function field_email_content() {
        $opts = get_option( self::OPTION_KEY );
        $v = isset( $opts['email_content'] ) ? $opts['email_content'] : '';
        echo '<textarea name="'.self::OPTION_KEY.'[email_content]" rows="8" class="large-text code">'.esc_textarea( $v ).'</textarea>';
        echo '<p class="description">Use placeholders: {first_name}, {last_name}, {product}, {date}, {site_name}, {subscription_id}</p>';
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die('Insufficient permissions');
        ?>
        <div class="wrap">
            <h1>Subscription Reminders</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'wc_sr_settings_group' );
                    do_settings_sections( 'wc-sr-settings' );
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* Product-level options: add a checkbox and default days input */
    public function product_options_fields() {
        echo '<div class="options_group">';
        woocommerce_wp_checkbox( array(
            'id'            => '_sr_product_enable',
            'label'         => 'Enable subscription reminders for this product',
            'description'   => 'If checked, subscriptions for this product will receive reminder emails.',
        ));
        woocommerce_wp_text_input( array(
            'id'            => '_sr_product_days',
            'label'         => 'Reminder days (comma separated)',
            'description'   => 'Enter days for reminders for subscriptions of this product (e.g. 7,3,1). Leave empty to use global defaults.',
            'desc_tip'      => true,
        ));
        echo '</div>';
    }
    public function save_product_options( $post_id ) {
        $enable = isset( $_POST['_sr_product_enable'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_sr_product_enable', $enable );

        if ( isset( $_POST['_sr_product_days'] ) ) {
            $days_raw = sanitize_text_field( $_POST['_sr_product_days'] );
            update_post_meta( $post_id, '_sr_product_days', $days_raw );
        } else {
            delete_post_meta( $post_id, '_sr_product_days' );
        }
    }

    /* Subscription meta box: allow override days or disable reminders for this subscription */
    public function add_subscription_meta_box() {
        add_meta_box( 'wc_sr_subscription_box', 'Subscription Reminder', array( $this, 'subscription_meta_box_html' ), 'shop_subscription', 'side', 'default' );
    }
    public function subscription_meta_box_html( $post ) {
        $enabled = get_post_meta( $post->ID, '_sr_subscription_enable', true );
        $days = get_post_meta( $post->ID, '_sr_subscription_days', true );
        ?>
        <p>
            <label><input type="checkbox" name="_sr_subscription_enable" value="yes" <?php checked( $enabled, 'yes' ); ?> /> Enable reminders for this subscription</label>
        </p>
        <p>
            <label>Reminder days (comma separated)</label><br/>
            <input type="text" name="_sr_subscription_days" value="<?php echo esc_attr( $days ); ?>" class="widefat" />
            <small>Leave blank to use product/global defaults.</small>
        </p>
        <?php
    }
    public function save_subscription_meta( $post_id, $post ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( isset( $_POST['_sr_subscription_enable'] ) ) update_post_meta( $post_id, '_sr_subscription_enable', 'yes' ); else update_post_meta( $post_id, '_sr_subscription_enable', 'no' );
        if ( isset( $_POST['_sr_subscription_days'] ) ) update_post_meta( $post_id, '_sr_subscription_days', sanitize_text_field( $_POST['_sr_subscription_days'] ) );
    }

    /* Register WooCommerce email class */
    public function register_email_class( $emails ) {
        if ( ! class_exists( 'WC_SR_Email_Subscription_Reminder' ) ) {
            class WC_SR_Email_Subscription_Reminder extends WC_Email {
                public function __construct() {
                    $this->id          = WC_Subscription_Reminder_Addon::EMAIL_ID;
                    $this->title       = 'Subscription Reminder';
                    $this->description = 'Reminds customers about upcoming subscription renewals.';
                    $this->heading     = 'Subscription Renewal Reminder';
                    $this->subject     = 'Upcoming subscription renewal';
                    $this->template_html  = 'emails/subscription-reminder.php';
                    $this->template_plain = 'emails/plain/subscription-reminder.php';
                    parent::__construct();
                }
                public function trigger( $recipient, $data = array() ) {
                    if ( ! $recipient ) return;
                    $this->recipient = $recipient;
                    $this->find[] = $data;
                    $this->object = $data;
                    $this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
                }
                public function get_content_html() {
                    $data = $this->object;
                    ob_start();
                    echo nl2br( esc_html( $data['content'] ) );
                    return ob_get_clean();
                }
                public function get_content_plain() {
                    return $this->object['content'];
                }
            }
        }
        $emails[ WC_Subscription_Reminder_Addon::EMAIL_ID ] = new WC_SR_Email_Subscription_Reminder();
        return $emails;
    }
    public function register_email_action( $actions ) {
        // no special action; we will call the email via the class directly
        return $actions;
    }

    /* The main cron job: find subscriptions, calculate days until next payment, and send reminders */
    public function daily_check_and_send() {
        $opts = get_option( self::OPTION_KEY );
        if ( ! isset( $opts['enabled'] ) || $opts['enabled'] !== 'yes' ) return;

        $global_days = isset( $opts['reminder_days'] ) ? $opts['reminder_days'] : array(7,3,1);
        // normalize to ints
        $global_days = array_map('intval', (array)$global_days );

        // get active subscriptions
        if ( function_exists( 'wcs_get_subscriptions' ) ) {
            // WooCommerce Subscriptions present
            $subs = wcs_get_subscriptions( array( 'subscription_status' => 'active' ) );
        } else {
            // Fallback: query 'shop_subscription' posts with status 'wc-active' or 'wc-completed' etc.
            $query = new WP_Query( array(
                'post_type'      => 'shop_subscription',
                'posts_per_page' => -1,
                'post_status'    => array('wc-active','active','publish')
            ) );
            $subs = $query->posts;
        }

        if ( empty( $subs ) ) return;

        foreach ( $subs as $sub ) {
            // $sub may be a WC_Subscription object or WP_Post
            if ( is_object( $sub ) && is_a( $sub, 'WC_Subscription' ) ) {
                $subscription = $sub;
                $subscription_id = $subscription->get_id();
                $next_payment_ts = $subscription->get_time( 'next_payment' ); // returns timestamp or 0
                $customer_id = $subscription->get_user_id();
                $billing_email = $subscription->get_billing_email();
                $items = $subscription->get_items();
                // pick first product name
                $product_name = '';
                foreach( $items as $it ) { $product_name = $it->get_name(); break; }
            } else {
                // WP_Post fallback
                $subscription_id = (int) $sub->ID;
                $customer_id = get_post_meta( $subscription_id, '_customer_user', true );
                $billing_email = get_post_meta( $subscription_id, '_billing_email', true );
                $next_payment_ts = 0;
                $meta_np = get_post_meta( $subscription_id, '_next_payment', true );
                if ( $meta_np ) {
                    $next_payment_ts = strtotime( $meta_np );
                } else {
                    $meta_np2 = get_post_meta( $subscription_id, '_schedule_next_payment', true );
                    if ( $meta_np2 ) $next_payment_ts = strtotime( $meta_np2 );
                }
                // items: try to fetch associated line items via postmeta or children - we keep it simple:
                $product_name = get_post_meta( $subscription_id, '_order_items_first_product_name', true );
                if ( ! $product_name ) $product_name = 'your subscription';
            }

            if ( ! $next_payment_ts || $next_payment_ts <= 0 ) continue; // nothing to do

            // compute days until next payment (rounded down)
            $now = time();
            $diff = $next_payment_ts - $now;
            if ( $diff < 0 ) continue; // already passed

            $days_until = floor( $diff / DAY_IN_SECONDS );

            // Determine which reminder days apply (order: subscription override -> product override -> global)
            $send_days = $global_days;

            // Try product-level detection: if we have a WC_Subscription object, inspect items to get product IDs
            $product_days_override = null;
            $product_enable = null;
            if ( is_object( $sub ) && is_a( $sub, 'WC_Subscription' ) ) {
                foreach( $subscription->get_items() as $item ) {
                    // attempt to get product id for the first subscription line item
                    $prod_id = $item->get_product_id();
                    if ( $prod_id ) {
                        $prod_enable = get_post_meta( $prod_id, '_sr_product_enable', true );
                        $prod_days_raw = get_post_meta( $prod_id, '_sr_product_days', true );
                        if ( $prod_days_raw ) {
                            $list = preg_split('/[,\s]+/', trim( $prod_days_raw ), -1, PREG_SPLIT_NO_EMPTY );
                            $list = array_map( 'intval', $list );
                            $product_days_override = $list;
                        }
                        $product_enable = $prod_enable;
                        break;
                    }
                }
            } else {
                // fallback: can't reliably detect product -> use global
            }

            // Subscription-level override
            $sub_enable = get_post_meta( $subscription_id, '_sr_subscription_enable', true );
            $sub_days_raw = get_post_meta( $subscription_id, '_sr_subscription_days', true );
            $sub_days_override = null;
            if ( $sub_days_raw ) {
                $list = preg_split('/[,\s]+/', trim( $sub_days_raw ), -1, PREG_SPLIT_NO_EMPTY );
                $list = array_map( 'intval', $list );
                $sub_days_override = $list;
            }

            // Determine final enabled state
            if ( $sub_enable === 'no' ) continue; // explicitly disabled
            if ( $sub_enable === 'yes' ) {
                // subscription explicitly enabled -> use subscription override if present
                if ( is_array( $sub_days_override ) && ! empty( $sub_days_override ) ) $send_days = $sub_days_override;
                else if ( is_array( $product_days_override ) && ! empty( $product_days_override ) ) $send_days = $product_days_override;
            } else {
                // subscription didn't specify. Check product enable
                if ( $product_enable === 'no' ) continue;
                if ( $product_enable === 'yes' && is_array( $product_days_override ) && ! empty( $product_days_override ) ) {
                    $send_days = $product_days_override;
                }
                // else use global
            }

            // If days_until matches any in send_days, send reminder
            if ( in_array( (int)$days_until, array_map('intval', $send_days), true ) ) {
                // Build email content using placeholders
                $user = $customer_id ? get_user_by( 'id', $customer_id ) : null;
                $first = $user ? $user->first_name : '';
                $last  = $user ? $user->last_name : '';
                $site  = get_bloginfo( 'name' );
                $date_str = date_i18n( get_option( 'date_format' ), $next_payment_ts );

                $email_subject = isset( $opts['email_subject'] ) ? $opts['email_subject'] : 'Upcoming subscription renewal';
                $email_content_template = isset( $opts['email_content'] ) ? $opts['email_content'] : '';

                $replacements = array(
                    '{first_name}'    => $first,
                    '{last_name}'     => $last,
                    '{product}'       => $product_name,
                    '{date}'          => $date_str,
                    '{site_name}'     => $site,
                    '{subscription_id}' => $subscription_id,
                );

                $content = str_replace( array_keys($replacements), array_values($replacements), $email_content_template );
                // fallback: if empty template, craft a simple message
                if ( empty( trim($content) ) ) {
                    $content = "Hi {$first},\n\nThis is a reminder that your subscription for {$product_name} will renew on {$date_str}.\n\nThanks,\n{$site}";
                }

                $recipient = $billing_email ? $billing_email : ( $user ? $user->user_email : false );
                if ( ! $recipient ) continue;

                // Use WooCommerce email if available
                if ( function_exists( 'WC' ) && isset( WC()->mailer ) ) {
                    $mailer = WC()->mailer();
                    // get the email class if registered
                    $emails = $mailer->get_emails();
                    if ( isset( $emails[ self::EMAIL_ID ] ) ) {
                        $emails[ self::EMAIL_ID ]->heading = isset( $opts['email_heading'] ) ? $opts['email_heading'] : $emails[ self::EMAIL_ID ]->heading;
                        $emails[ self::EMAIL_ID ]->subject = isset( $opts['email_subject'] ) ? $opts['email_subject'] : $emails[ self::EMAIL_ID ]->subject;
                        $emails[ self::EMAIL_ID ]->trigger( $recipient, array(
                            'content' => $content,
                            'subscription_id' => $subscription_id,
                            'first_name' => $first,
                            'last_name' => $last,
                            'product' => $product_name,
                            'date' => $date_str,
                        ) );
                    } else {
                        // Fallback: use wp_mail
                        wp_mail( $recipient, $email_subject, $content );
                    }
                } else {
                    // fallback
                    wp_mail( $recipient, $email_subject, $content );
                }

                // add a note to subscription (if WC_Subscription object exists)
                if ( is_object( $sub ) && is_a( $sub, 'WC_Subscription' ) ) {
                    $subscription->add_order_note( sprintf( 'Sent subscription reminder for next payment on %s (%d days before).', $date_str, $days_until ) );
                } else {
                    // fallback: add post note
                    $note = sprintf( 'Sent subscription reminder for next payment on %s (%d days before).', $date_str, $days_until );
                    add_post_meta( $subscription_id, '_sr_sent_note', $note );
                }
            }
        } // foreach subs
    }

} // end class

new WC_Subscription_Reminder_Addon();
