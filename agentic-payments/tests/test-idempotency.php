public function test_duplicate_payment_is_ignored() {

    $order = $this->create_order();

    update_option( 'agentic_agents', [
        'agent_42' => [
            'secret' => 'test_secret',
            'active' => true,
        ],
    ] );

    $body = wp_json_encode([
        'order_id'       => $order->get_id(),
        'transaction_id' => 'tx_dupe',
        'agent_id'       => 'agent_42',
    ]);

    [ $ts, $sig ] = $this->sign_payload( $body, 'test_secret' );

    $request = new WP_REST_Request( 'POST', '/agentic/v1/payment-complete' );
    $request->set_header( 'X-Agentic-Timestamp', $ts );
    $request->set_header( 'X-Agentic-Signature', $sig );
    $request->set_body( $body );

    rest_do_request( $request );
    $response = rest_do_request( $request );

    $this->assertEquals(
        'Order already processed',
        $response->get_data()['message']
    );
}
