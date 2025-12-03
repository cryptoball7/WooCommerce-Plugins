<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Agentic_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'agentic';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_agentic_settings', [] );
    }

    public function is_active() {
        return isset( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->settings['title'] ?? 'Agentic (programmatic)',
            'description' => $this->settings['description'] ?? '',
        ];
    }
}
