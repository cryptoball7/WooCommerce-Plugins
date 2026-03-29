<?php

class Agent_Checkout_Session {

    public static function create($agent_id, $customer_id, $items) {

        global $wpdb;

        $table = $wpdb->prefix . 'agent_checkout_sessions';

        $session_id = wp_generate_uuid4();

        $total = 0;

        foreach ($items as $item) {

            $product = wc_get_product($item['product_id']);

            if (!$product) {
                throw new Exception("Product not found");
            }

            $total += $product->get_price() * $item['quantity'];
        }

        $wpdb->insert($table, [
            'session_id' => $session_id,
            'agent_id' => $agent_id,
            'customer_id' => $customer_id,
            'status' => 'created',
            'total' => $total,
            'created_at' => current_time('mysql')
        ]);

        return [
            'session_id' => $session_id,
            'total' => $total
        ];
    }

    public static function lock_price($session_id) {

        global $wpdb;

        $table = $wpdb->prefix . 'agent_checkout_sessions';

        $lock_until = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $wpdb->update(
            $table,
            [
                'status' => 'quoted',
                'price_locked_until' => $lock_until
            ],
            ['session_id' => $session_id]
        );

        return $lock_until;
    }

    public static function complete_order($session_id) {

        global $wpdb;

        $table = $wpdb->prefix . 'agent_checkout_sessions';

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE session_id=%s", $session_id)
        );

        if (!$session) {
            throw new Exception("Session not found");
        }

        $order = wc_create_order([
            'customer_id' => $session->customer_id
        ]);

        $order->calculate_totals();

        $order->update_status('processing');

        $wpdb->update(
            $table,
            ['status' => 'completed'],
            ['session_id' => $session_id]
        );

        return $order->get_id();
    }

public static function authorize($session_id, $payment_token) {

    global $wpdb;

    $table = $wpdb->prefix . 'agent_checkout_sessions';

    $session = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table WHERE session_id=%s", $session_id)
    );

    if (!$session) {
        throw new Exception("Session not found");
    }

    if ($session->status !== 'quoted') {
        throw new Exception("Session must be quoted before authorization");
    }

    // V1: mock validation
    if (empty($payment_token)) {
        throw new Exception("Invalid payment token");
    }

    $wpdb->update(
        $table,
        [
            'status' => 'authorized',
            'payment_token' => $payment_token,
            'authorized_at' => current_time('mysql')
        ],
        ['session_id' => $session_id]
    );

    return true;
}

}