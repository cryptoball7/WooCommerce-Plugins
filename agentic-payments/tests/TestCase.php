// tests/TestCase.php
class Agentic_Test_Case extends WP_UnitTestCase {

    protected function create_order() {
        $order = wc_create_order();
        $order->add_product( wc_get_product( $this->factory->product->create() ), 1 );
        $order->calculate_totals();
        return $order;
    }

    protected function sign_payload( $body, $secret ) {
        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
        return [ $timestamp, $signature ];
    }
}
