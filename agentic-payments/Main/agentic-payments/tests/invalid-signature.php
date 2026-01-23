public function test_invalid_signature_fails() {

    $order = $this->create_order();

    update_option( 'agentic_agents', [
        'agent_42' => [
            'secret' => 'correct_secret',
            'active' => true,
        ],
    ] );

    $body = wp_json_encode([
        'order_id' => $order->get_id(),
        'agent_id' => 'agent_42',
    ]);

    $request = new WP_REST_Request( 'POST', '/agentic/v1/payment-complete' );
    $request->set_header( 'X-Agentic-Timestamp', time() );
    $request->set_header( 'X-Agentic-Signature', 'bad_signature' );
    $request->set_body( $body );

    $response = rest_do_request( $request );

    $this->assertEquals( 401, $response->get_status() );
}
