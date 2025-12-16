<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_Agentic extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'agentic';
        $this->method_title       = 'Agentic (programmatic)';
        $this->method_description = 'Agentic programmatic payments';
        $this->has_fields         = false;

        $this->supports = [
            'products',
        ];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option( 'enabled' );
        $this->title   = $this->get_option( 'title' );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Agentic payments',
                'default' => 'yes',
            ],
            'title' => [
                'title'   => 'Title',
                'type'    => 'text',
                'default' => 'Agentic (programmatic)',
            ],
        ];
    }

    public function process_payment( $order_id ) {
        return [
            'result'   => 'success',
            'redirect' => wc_get_checkout_url(),
        ];
    }
}
