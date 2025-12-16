<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Agentic_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name (must match gateway ID)
     */
    protected $name = 'agentic';

    /**
     * Initialize settings
     */
    public function initialize() {
        error_log("[AgenticPayments][DBG] blocks initialize() called");
        $this->settings = get_option( 'woocommerce_agentic_settings', [] );
    }

    /**
     * Whether the payment method is active in checkout
     */
    public function is_active() {
        error_log("is_active() called, returning: ");
        error_log(isset( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes');
        return isset( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
    }

    /**
     * Data sent to the frontend
     */
    public function get_payment_method_data() {
        return [
            'title'       => $this->settings['title'] ?? 'Agentic (programmatic)',
            'description' => $this->settings['description'] ?? '',
        ];
    }

    public function get_name() { return 'agentic'; }
    public function get_payment_method_script_handles() {
      error_log('[AgenticPayments][DBG] get_payment_method_script_handles called');
      return [ 'agentic-blocks' ];
    }


}
