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
        error_log("WC_Agentic_Blocks_Support initialized.");
        $this->settings = get_option( 'woocommerce_agentic_settings', [] );
    }

    /**
     * Whether the payment method is active in checkout
     */
    public function is_active() {
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
}
