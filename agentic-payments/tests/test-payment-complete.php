// tests/test-payment-complete.php
class Test_Agentic_Payment_Complete extends Agentic_Test_Case {

    public function test_payment_completes_order() {

        $order = $this->create_order();

        update_option( 'agentic_agents', [
            'agent_42' => [
                'secret' => 'test_secret',
                'active' => true,
            ],
        ] );

        $body = wp_json_encode([
            'order_id'       => $order->get_id(),
            'transaction_id' => 'tx_test_1',
            'agent_id'       => 'agent_42',
        ]);

        [ $ts, $sig ] = $this->sign_payload( $body, 'test_secret' );

        $request = new WP_REST_Request( 'POST', '/agentic/v1/payment-complete' );
        $request->set_header( 'X-Agentic-Timestamp', $ts );
        $request->set_header( 'X-Agentic-Signature', $sig );
        $request->set_body( $body );

        $response = rest_do_request( $request );

        $this->assertEquals( 200, $response->get_status() );

        $order = wc_get_order( $order->get_id() );
        $this->assertEquals( 'completed', $order->get_status() );
    }
}
