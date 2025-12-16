<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Agentic_Blocks extends AbstractPaymentMethodType {

    protected $name = 'agentic';

    public function initialize() {
        error_log('[AgenticPayments][Blocks] initialize');
        $this->settings = get_option( 'woocommerce_agentic_settings', [] );
    }

    public function is_active() {
        $active = isset( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
        error_log('[AgenticPayments][Blocks] is_active = ' . ($active ? 'YES' : 'NO'));
        return $active;
    }

    public function get_payment_method_script_handles() {
        error_log('[AgenticPayments][Blocks] script handles requested');
        return [ 'agentic-blocks' ];
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->settings['title'] ?? 'Agentic (programmatic)',
            'description' => 'Pay using an autonomous agent',
        ];
    }
}
