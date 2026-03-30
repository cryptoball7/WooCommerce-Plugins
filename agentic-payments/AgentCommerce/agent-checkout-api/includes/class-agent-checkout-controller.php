<?php

class Agent_Checkout_Controller {

    public function register_routes() {

        register_rest_route('agent-commerce/v1', '/checkout/sessions', [
            'methods' => 'POST',
            'callback' => [$this, 'create_session'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('agent-commerce/v1', '/checkout/sessions/(?P<id>[a-zA-Z0-9-]+)/quote', [
            'methods' => 'POST',
            'callback' => [$this, 'quote_session'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('agent-commerce/v1', '/checkout/sessions/(?P<id>[a-zA-Z0-9-]+)/complete', [
            'methods' => 'POST',
            'callback' => [$this, 'complete_session'],
            'permission_callback' => '__return_true'
        ]);

register_rest_route('agent-commerce/v1', '/checkout/sessions/(?P<id>[a-zA-Z0-9-]+)/authorize', [
    'methods' => 'POST',
    'callback' => [$this, 'authorize_session'],
    'permission_callback' => '__return_true'
]);
    }

    public function create_session($request) {

        $agent_id = $request->get_param('agent_id');
        $customer_id = $request->get_param('customer_id');
        $items = $request->get_param('items');

        try {

            $session = Agent_Checkout_Session::create(
                $agent_id,
                $customer_id,
                $items
            );

            return [
                'session_id' => $session['session_id'],
                'status' => 'created',
                'total' => [
                    'amount' => $session['total'],
                    'currency' => get_woocommerce_currency()
                ]
            ];

        } catch (Exception $e) {

            return new WP_Error(
                'session_error',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    public function quote_session($request) {

        $session_id = $request['id'];

        $lock_until = Agent_Checkout_Session::lock_price($session_id);

        return [
            'status' => 'quoted',
            'price_locked_until' => $lock_until
        ];
    }

    public function complete_session($request) {

        $session_id = $request['id'];

        try {

            $order_id = Agent_Checkout_Session::complete_order($session_id);

            return [
                'order_id' => $order_id,
                'status' => 'processing'
            ];

        } catch (Exception $e) {

            return new WP_Error(
                'checkout_error',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

public function authorize_session($request) {

    $session_id = $request['id'];
    $payment_token = $request->get_param('payment_token');

    try {

        Agent_Checkout_Session::authorize($session_id, $payment_token);

        return [
            'status' => 'authorized'
        ];

    } catch (Exception $e) {

        return new WP_Error(
            'authorization_error',
            $e->getMessage(),
            ['status' => 400]
        );
    }
}

}