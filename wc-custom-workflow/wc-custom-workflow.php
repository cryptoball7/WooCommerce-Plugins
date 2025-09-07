<?php
/**
 * Plugin Name: WooCommerce Custom Order Status & Workflow
 * Description: Adds custom order statuses (Packing, Awaiting Courier), admin actions, status icons, and email notifications. Single-file simple workflow manager.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Text Domain: wc-custom-workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Custom_Workflow_Plugin {

	/** Simple workflow transitions (from => allowed next statuses) */
	private $workflow = array(
		'wc-pending' => array( 'wc-packing', 'wc-cancelled' ),
		'wc-processing' => array( 'wc-packing', 'wc-awaiting-courier', 'wc-completed' ),
		'wc-packing' => array( 'wc-awaiting-courier', 'wc-completed', 'wc-on-hold' ),
		'wc-awaiting-courier' => array( 'wc-completed', 'wc-on-hold' ),
	);

	/** Custom statuses to register */
	private $custom_statuses = array(
		'packing' => array(
			'slug'  => 'packing',
			'label' => 'Packing',
			'color' => '#0073aa',
			'icon'  => '<svg viewBox="0 0 24 24" width="12" height="12" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M3 7h18v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7zm2-3h14l-1 2H6L5 4z"/></svg>',
		),
		'awaiting-courier' => array(
			'slug'  => 'awaiting-courier',
			'label' => 'Awaiting Courier',
			'color' => '#ffb900',
			'icon'  => '<svg viewBox="0 0 24 24" width="12" height="12" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M3 13h2l3-6h8l3 6h2v6H3v-6zM12 4a2 2 0 1 1 .001 3.999A2 2 0 0 1 12 4z"/></svg>',
		),
	);

	public function __construct() {
		// Register statuses early
		add_action( 'init', array( $this, 'register_custom_statuses' ) );

		// Show statuses in order dropdowns and lists
		add_filter( 'wc_order_statuses', array( $this, 'add_statuses_to_list' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'include_custom_in_valid_statuses' ) );

		// Add status icons / styling in admin orders list & order edit page
		add_action( 'admin_head', array( $this, 'admin_css_for_status_icons' ) );

		// Add order row icons/buttons
		add_action( 'woocommerce_admin_order_actions', array( $this, 'add_admin_order_row_actions' ), 100, 1 );
		add_action( 'admin_post_wc_custom_workflow_change_status', array( $this, 'handle_admin_status_change' ) );

		// Add to order actions (single order screen)
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_edit_actions' ), 20 );

		// Handle order action from dropdown
		add_action( 'woocommerce_order_action_wc_cwf_set_packing', array( $this, 'process_order_action_set_status' ) );
		add_action( 'woocommerce_order_action_wc_cwf_set_awaiting_courier', array( $this, 'process_order_action_set_status' ) );

		// Add bulk actions
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action_change_status' ), 10, 3 );

		// Add email classes and actions
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
		add_filter( 'woocommerce_email_actions', array( $this, 'register_email_actions' ) );

		// Trigger actions when order changes to custom statuses
		add_action( 'woocommerce_order_status_packing', array( $this, 'notify_on_custom_status' ), 10, 2 );
		add_action( 'woocommerce_order_status_awaiting-courier', array( $this, 'notify_on_custom_status' ), 10, 2 );

		// Keep a simple workflow history
		add_action( 'woocommerce_order_status_changed', array( $this, 'record_workflow_history' ), 10, 4 );
	}

	/**
	 * Register statuses with WP/WooCommerce
	 */
	public function register_custom_statuses() {
		foreach ( $this->custom_statuses as $key => $s ) {
			$slug = 'wc-' . $s['slug'];
			register_post_status( $slug, array(
				'label'                     => $s['label'],
				'public'                    => true,
				'show_in_admin_status_list' => true,
				'show_in_admin_all_list'    => true,
				'label_count'               => _n_noop( $s['label'] . ' <span class="count">(%s)</span>', $s['label'] . ' <span class="count">(%s)</span>' ),
			) );
		}
	}

	/**
	 * Add our statuses to the Woo order statuses array so they appear in dropdowns and admin filters
	 */
	public function add_statuses_to_list( $order_statuses ) {
		$insert_after = 'wc-processing';
		$new_statuses = array();

		foreach ( $order_statuses as $slug => $label ) {
			$new_statuses[ $slug ] = $label;
			if ( $slug === $insert_after ) {
				foreach ( $this->custom_statuses as $s ) {
					$new_statuses[ 'wc-' . $s['slug'] ] = __( $s['label'], 'wc-custom-workflow' );
				}
			}
		}

		// in case processing not found, just append
		if ( ! isset( $new_statuses[ 'wc-' . $this->custom_statuses['packing']['slug'] ] ) ) {
			foreach ( $this->custom_statuses as $s ) {
				$new_statuses[ 'wc-' . $s['slug'] ] = __( $s['label'], 'wc-custom-workflow' );
			}
		}

		return $new_statuses;
	}

	/**
	 * Add custom statuses to valid statuses filter (simple inclusion)
	 */
	public function include_custom_in_valid_statuses( $statuses ) {
		foreach ( $this->custom_statuses as $s ) {
			$statuses[] = $s['slug'];
		}
		return $statuses;
	}

	/**
	 * Insert CSS in admin to show color + icon for custom statuses
	 */
	public function admin_css_for_status_icons() {
		$css = '<style>';
		foreach ( $this->custom_statuses as $s ) {
			$slug = esc_attr( 'status-' . $s['slug'] ); // class appears as order-status status-<slug>
			$color = esc_attr( $s['color'] );
			$icon  = $s['icon'];
			$css .= "
				.order-status.$slug {
					background: {$color};
					color: #fff;
					border: none;
					padding-left: 10px;
					padding-right: 10px;
				}
				.order-status.$slug:before {
					content: '';
					display:inline-block;
					width: 14px;
					height: 14px;
					margin-right:6px;
					vertical-align: text-bottom;
					background: transparent;
				}
			";
		}
		$css .= '</style>';
		echo $css;
	}

	/**
	 * Add row action icons (small buttons) on orders list
	 */
	public function add_admin_order_row_actions( $order ) {
		if ( ! $order || ! is_object( $order ) ) {
			return;
		}
		$order_id = $order->get_id();
		$current_status = 'wc-' . $order->get_status();

		$allowed_next = isset( $this->workflow[ $current_status ] ) ? $this->workflow[ $current_status ] : array();

		foreach ( $allowed_next as $next ) {

			// If next is one of our custom statuses, add button
			foreach ( $this->custom_statuses as $s ) {
				$full_slug = 'wc-' . $s['slug'];
				if ( $next === $full_slug ) {
					$label = esc_attr( $s['label'] );
					$nonce = wp_create_nonce( 'wc_cwf_change_status_' . $order_id . '_' . $s['slug'] );
					$url = esc_url( admin_url( "admin-post.php?action=wc_custom_workflow_change_status&order_id={$order_id}&status={$s['slug']}&nonce={$nonce}" ) );

					$button_title = sprintf( esc_attr__( 'Mark as: %s', 'wc-custom-workflow' ), $label );

					// output a small anchor styled by WooCommerce admin
					printf(
						'<a class="button tips" href="%s" data-tip="%s" aria-label="%s" style="margin-left:4px; padding:4px 6px; font-size:12px;">%s</a>',
						$url,
						esc_attr( $label ),
						esc_attr( $button_title ),
						esc_html( $s['label'][0] ) // single-letter visual (could be replaced by icon)
					);
				}
			}
		}
	}

	/**
	 * Handle admin-post request to change status from row click
	 */
	public function handle_admin_status_change() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( __( 'You do not have permission to change order statuses', 'wc-custom-workflow' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
		$status   = isset( $_GET['status'] ) ? sanitize_title( wp_unslash( $_GET['status'] ) ) : '';
		$nonce    = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( ! $order_id || ! $status || ! wp_verify_nonce( $nonce, 'wc_cwf_change_status_' . $order_id . '_' . $status ) ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
			exit;
		}

		$new_status = 'wc-' . $status;

		// Check allowed transitions
		$current_status = 'wc-' . $order->get_status();
		if ( isset( $this->workflow[ $current_status ] ) && in_array( $new_status, $this->workflow[ $current_status ], true ) ) {
			$order->update_status( $status, sprintf( 'Status updated to %s via Custom Workflow by %s', $status, wp_get_current_user()->user_login ) );
			// Redirect back
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	/**
	 * Add custom actions to the order edit "Actions" dropdown (single order screen)
	 */
	public function add_order_edit_actions( $actions ) {
		$actions['wc_cwf_set_packing'] = __( 'Set: Packing', 'wc-custom-workflow' );
		$actions['wc_cwf_set_awaiting_courier'] = __( 'Set: Awaiting Courier', 'wc-custom-workflow' );
		return $actions;
	}

	/**
	 * Generic processor for order actions dropped from order edit actions
	 */
	public function process_order_action_set_status( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$action = current_filter();
		if ( 'woocommerce_order_action_wc_cwf_set_packing' === $action ) {
			$order->update_status( 'packing', 'Marked packing from order actions.' );
		}
		if ( 'woocommerce_order_action_wc_cwf_set_awaiting_courier' === $action ) {
			$order->update_status( 'awaiting-courier', 'Marked awaiting courier from order actions.' );
		}
	}

	/**
	 * Add bulk actions to orders list (simple)
	 */
	public function register_bulk_actions( $bulk_actions ) {
		$bulk_actions['wc_cwf_bulk_set_packing'] = __( 'Bulk: Set Packing', 'wc-custom-workflow' );
		$bulk_actions['wc_cwf_bulk_set_awaiting_courier'] = __( 'Bulk: Set Awaiting Courier', 'wc-custom-workflow' );
		return $bulk_actions;
	}

	/**
	 * Handle bulk action changes
	 */
	public function handle_bulk_action_change_status( $redirect_to, $action, $post_ids ) {
		if ( $action === 'wc_cwf_bulk_set_packing' || $action === 'wc_cwf_bulk_set_awaiting_courier' ) {
			$new_status = $action === 'wc_cwf_bulk_set_packing' ? 'packing' : 'awaiting-courier';
			foreach ( $post_ids as $post_id ) {
				$order = wc_get_order( $post_id );
				if ( $order ) {
					$order->update_status( $new_status, "Bulk status change to {$new_status} via Custom Workflow" );
				}
			}
			$redirect_to = add_query_arg( 'wc_cwf_bulk_status', $new_status, $redirect_to );
		}
		return $redirect_to;
	}

	/**
	 * Register email classes
	 */
	public function register_emails( $emails ) {
		// these classes are defined below
		if ( ! class_exists( 'WC_Email_Order_Packing' ) ) {
			include_once( __FILE__ ); // ensure classes available in single-file plugin
		}
		$emails['WC_Email_Order_Packing'] = new WC_Email_Order_Packing();
		$emails['WC_Email_Order_Awaiting_Courier'] = new WC_Email_Order_Awaiting_Courier();
		return $emails;
	}

	/**
	 * Ensure our custom actions are available to map to emails
	 */
	public function register_email_actions( $actions ) {
		$actions[] = 'woocommerce_order_status_packing';
		$actions[] = 'woocommerce_order_status_awaiting-courier';
		return $actions;
	}

	/**
	 * Notify: when status switches to a custom status, trigger corresponding woocommerce_email action
	 */
	public function notify_on_custom_status( $order_id, $order = false ) {
		// WooCommerce will already trigger email actions, but ensuring do_action triggers with order object:
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		do_action( 'woocommerce_order_status_' . $order->get_status(), $order_id, $order );
	}

	/**
	 * Record a minimal workflow history array in post meta
	 */
	public function record_workflow_history( $order_id, $from, $to, $order ) {
		$history = get_post_meta( $order_id, '_wc_cwf_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[] = array(
			'time' => current_time( 'mysql' ),
			'from' => $from,
			'to'   => $to,
			'user' => wp_get_current_user() ? wp_get_current_user()->user_login : 'system',
		);
		update_post_meta( $order_id, '_wc_cwf_history', $history );
	}

}

new WC_Custom_Workflow_Plugin();

/**
 * ------------------------------
 * Simple WC_Email classes below
 * ------------------------------
 *
 * Two minimal email classes that send notifications to customer when the order
 * reaches the custom statuses. They integrate with WooCommerce Emails UI.
 *
 * You can customize templates here or use your theme overrides / templates folder.
 */

if ( ! class_exists( 'WC_Email_Order_Packing' ) ) {

	class WC_Email_Order_Packing extends WC_Email {

		public function __construct() {
			$this->id             = 'wc_email_order_packing';
			$this->title          = 'Order Packing';
			$this->description    = 'Sent to the customer when the order is marked as Packing.';
			$this->heading        = 'Your order is being packed';
			$this->subject        = '[{site_title}] Your order {order_number} is being packed';
			$this->template_html  = ''; // we'll build inline
			$this->template_plain = '';

			// Triggers
			add_action( 'woocommerce_order_status_packing', array( $this, 'trigger' ), 10, 2 );

			// Call parent constructor (sets recipient to customer by default)
			parent::__construct();
		}

		/**
		 * Trigger the email
		 */
		public function trigger( $order_id, $order = false ) {
			if ( ! $order ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! $order ) {
				return;
			}

			$this->object     = $order;
			$this->recipient  = $order->get_billing_email();
			$this->placeholders = array(
				'{order_date}'   => wc_format_datetime( $order->get_date_created() ),
				'{order_number}' => $order->get_order_number(),
			);

			if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
				return;
			}

			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		/**
		 * Get content HTML
		 */
		public function get_content() {
			$order = $this->object;
			ob_start();
			?>
			<p><?php echo wp_kses_post( sprintf( 'Hi %s,', $order->get_billing_first_name() ) ); ?></p>
			<p><?php echo wp_kses_post( sprintf( 'Good news — your order <strong>%s</strong> is currently being packed and will be handed to the courier soon.', $order->get_order_number() ) ); ?></p>
			<p><?php echo wp_kses_post( 'We will email you again when the order is shipped.' ); ?></p>

			<?php
			$content = ob_get_clean();
			return $this->wrap_message( $this->get_heading(), $content );
		}

		public function get_content_plain() {
			$order = $this->object;
			$text = sprintf( "Hi %s,\n\nYour order %s is currently being packed and will be handed to the courier soon.\n\nWe will email you again when the order is shipped.\n",
				$order->get_billing_first_name(),
				$order->get_order_number()
			);
			return $text;
		}

		// simple wrapper using WooCommerce template functions
		protected function wrap_message( $heading, $message ) {
			ob_start();
			wc_get_template( 'emails/email-header.php', array( 'email_heading' => $heading ), '', WC()->template_path() );
			echo $message;
			wc_get_template( 'emails/email-footer.php', array(), '', WC()->template_path() );
			return ob_get_clean();
		}
	}
}

if ( ! class_exists( 'WC_Email_Order_Awaiting_Courier' ) ) {
	class WC_Email_Order_Awaiting_Courier extends WC_Email {

		public function __construct() {
			$this->id          = 'wc_email_order_awaiting_courier';
			$this->title       = 'Order Awaiting Courier';
			$this->description = 'Sent to the customer when the order is marked as Awaiting Courier.';
			$this->heading     = 'Your order is awaiting the courier';
			$this->subject     = '[{site_title}] Order {order_number} awaiting courier';

			add_action( 'woocommerce_order_status_awaiting-courier', array( $this, 'trigger' ), 10, 2 );
			parent::__construct();
		}

		public function trigger( $order_id, $order = false ) {
			if ( ! $order ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! $order ) {
				return;
			}
			$this->object    = $order;
			$this->recipient = $order->get_billing_email();

			if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
				return;
			}

			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		public function get_content() {
			$order = $this->object;
			ob_start();
			?>
			<p><?php echo wp_kses_post( sprintf( 'Hi %s,', $order->get_billing_first_name() ) ); ?></p>
			<p><?php echo wp_kses_post( sprintf( 'Your order <strong>%s</strong> has been packed and is waiting for the courier to pick up. We will update you once it is shipped.', $order->get_order_number() ) ); ?></p>
			<?php
			$content = ob_get_clean();
			return $this->wrap_message( $this->get_heading(), $content );
		}

		public function get_content_plain() {
			$order = $this->object;
			$text = sprintf( "Hi %s,\n\nYour order %s has been packed and is waiting for the courier to pick up. We will update you once it is shipped.\n",
				$order->get_billing_first_name(),
				$order->get_order_number()
			);
			return $text;
		}

		protected function wrap_message( $heading, $message ) {
			ob_start();
			wc_get_template( 'emails/email-header.php', array( 'email_heading' => $heading ), '', WC()->template_path() );
			echo $message;
			wc_get_template( 'emails/email-footer.php', array(), '', WC()->template_path() );
			return ob_get_clean();
		}
	}
}
