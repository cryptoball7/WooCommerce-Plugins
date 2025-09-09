<?php
/**
 * Plugin Name: WooCommerce Reports Dashboard
 * Description: Adds a custom WooCommerce Reports Dashboard (sales by date, product, customer) with Chart.js and REST API endpoints.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: wc-reports-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Reports_Dashboard {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'WC Reports', 'wc-reports-dashboard' ),
            __( 'WC Reports', 'wc-reports-dashboard' ),
            'manage_woocommerce',
            'wc-reports-dashboard',
            array( $this, 'render_admin_page' ),
            'dashicons-chart-line',
            56
        );
    }

    public function enqueue_admin_assets( $hook ) {
        // Only load on our plugin page
        if ( $hook !== 'toplevel_page_wc-reports-dashboard' ) {
            return;
        }

        // Chart.js from CDN
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', true );

        // Our inline script handle
        wp_register_script( 'wc-reports-admin', false, array( 'chartjs', 'wp-api' ), '1.0', true );

        // Localize / pass variables
        $data = array(
            'rest_url' => esc_url_raw( rest_url( 'wc-reports/v1' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'date_default_days' => 30,
        );
        wp_localize_script( 'wc-reports-admin', 'WCReportsData', $data );

        // Inline JS (keeps this plugin as a single file for demo). For production, move to separate JS file.
        $inline_js = $this->get_admin_js();
        wp_add_inline_script( 'wc-reports-admin', $inline_js );

        wp_enqueue_script( 'wc-reports-admin' );

        // Basic styles
        wp_enqueue_style( 'wc-reports-admin-css', false );
        $inline_css = $this->get_admin_css();
        wp_add_inline_style( 'wc-reports-admin-css', $inline_css );
    }

    private function get_admin_css() {
        return "
        .wc-reports-wrap { max-width:1200px; margin:20px auto; }
        .wc-reports-cards { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        .wc-report-card { background:#fff; padding:16px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .wc-report-controls { display:flex; gap:8px; align-items:center; margin-bottom:12px; }
        @media (max-width:900px){ .wc-reports-cards { grid-template-columns:1fr; } }
        ";
    }

    private function get_admin_js() {
        // Note: keep this updated for Chart.js v4 API
        return "(function(){
            const restBase = WCReportsData.rest_url.replace(/\/+$/,'');
            const nonce = WCReportsData.nonce;

            function qs(obj){
                return Object.keys(obj).map(k=>encodeURIComponent(k)+'='+encodeURIComponent(obj[k])).join('&');
            }

            async function fetchJSON(path, params={}){
                const url = restBase + path + (Object.keys(params).length?('?'+qs(params)): '');
                const res = await fetch(url, { headers: { 'X-WP-Nonce': nonce } });
                if(!res.ok){ throw new Error('Fetch error: '+res.status); }
                return res.json();
            }

            function formatDateISO(date){
                const y = date.getFullYear();
                const m = ('0'+(date.getMonth()+1)).slice(-2);
                const d = ('0'+date.getDate()).slice(-2);
                return `${y}-${m}-${d}`;
            }

            function init(){
                const defaultDays = WCReportsData.date_default_days || 30;
                const end = new Date();
                const start = new Date(); start.setDate(end.getDate() - defaultDays + 1);

                document.getElementById('wc-reports-start').value = formatDateISO(start);
                document.getElementById('wc-reports-end').value = formatDateISO(end);

                document.getElementById('wc-reports-apply').addEventListener('click', renderAll);

                renderAll();
            }

            async function renderAll(){
                try{
                    const start = document.getElementById('wc-reports-start').value;
                    const end = document.getElementById('wc-reports-end').value;

                    // Sales by date
                    const dateData = await fetchJSON('/sales-by-date', { start: start, end: end });
                    renderLineChart('chart-sales-by-date', dateData.labels, dateData.totals, 'Sales');

                    // Sales by product
                    const productData = await fetchJSON('/sales-by-product', { start: start, end: end, limit: 10 });
                    renderBarChart('chart-sales-by-product', productData.labels, productData.totals, 'Sales by product');

                    // Sales by customer
                    const customerData = await fetchJSON('/sales-by-customer', { start: start, end: end, limit: 10 });
                    renderBarChart('chart-sales-by-customer', customerData.labels, customerData.totals, 'Sales by customer');

                }catch(err){
                    console.error(err);
                    alert('Error loading reports: '+err.message);
                }
            }

            // keep chart instances so we can destroy when updating
            const charts = {};

            function renderLineChart(ctxId, labels, data, label){
                const ctx = document.getElementById(ctxId).getContext('2d');
                if(charts[ctxId]){ charts[ctxId].destroy(); }
                charts[ctxId] = new Chart(ctx, {
                    type: 'line',
                    data: { labels: labels, datasets: [{ label: label, data: data, fill: true, tension: 0.3 }] },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }

            function renderBarChart(ctxId, labels, data, label){
                const ctx = document.getElementById(ctxId).getContext('2d');
                if(charts[ctxId]){ charts[ctxId].destroy(); }
                charts[ctxId] = new Chart(ctx, {
                    type: 'bar',
                    data: { labels: labels, datasets: [{ label: label, data: data }] },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }

            // Initialize when DOM ready
            if(document.readyState === 'loading'){
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }

        })();";
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
        }

        ?>
        <div class="wrap wc-reports-wrap">
            <h1><?php esc_html_e( 'WooCommerce Reports', 'wc-reports-dashboard' ); ?></h1>

            <div class="wc-report-card">
                <div class="wc-report-controls">
                    <label><?php esc_html_e( 'Start:', 'wc-reports-dashboard' ); ?> <input type="date" id="wc-reports-start"></label>
                    <label><?php esc_html_e( 'End:', 'wc-reports-dashboard' ); ?> <input type="date" id="wc-reports-end"></label>
                    <button class="button button-primary" id="wc-reports-apply"><?php esc_html_e( 'Apply', 'wc-reports-dashboard' ); ?></button>
                </div>
            </div>

            <div class="wc-reports-cards">
                <div class="wc-report-card">
                    <h2><?php esc_html_e( 'Sales by Date', 'wc-reports-dashboard' ); ?></h2>
                    <div style="height:320px;"><canvas id="chart-sales-by-date"></canvas></div>
                </div>

                <div class="wc-report-card">
                    <h2><?php esc_html_e( 'Top Products', 'wc-reports-dashboard' ); ?></h2>
                    <div style="height:320px;"><canvas id="chart-sales-by-product"></canvas></div>
                </div>

                <div class="wc-report-card">
                    <h2><?php esc_html_e( 'Top Customers', 'wc-reports-dashboard' ); ?></h2>
                    <div style="height:320px;"><canvas id="chart-sales-by-customer"></canvas></div>
                </div>

            </div>
        </div>
        <?php
    }

    public function register_rest_routes() {
        register_rest_route( 'wc-reports/v1', '/sales-by-date', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_sales_by_date' ),
            'permission_callback' => function(){ return current_user_can( 'manage_woocommerce' ); }
        ));

        register_rest_route( 'wc-reports/v1', '/sales-by-product', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_sales_by_product' ),
            'permission_callback' => function(){ return current_user_can( 'manage_woocommerce' ); }
        ));

        register_rest_route( 'wc-reports/v1', '/sales-by-customer', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'rest_sales_by_customer' ),
            'permission_callback' => function(){ return current_user_can( 'manage_woocommerce' ); }
        ));
    }

    /**
     * REST: sales by date
     * params: start (YYYY-MM-DD), end (YYYY-MM-DD)
     */
    public function rest_sales_by_date( WP_REST_Request $request ){
        $start = $request->get_param('start');
        $end   = $request->get_param('end');

        // defaults: last 30 days
        if ( empty( $end ) ) { $end = date( 'Y-m-d' ); }
        if ( empty( $start ) ) { $start = date( 'Y-m-d', strtotime( $end . ' -29 days' ) ); }

        // Build date range array
        $period = $this->build_date_period( $start, $end );
        $labels = array_keys( $period );

        // Get orders in range
        $args = array(
            'limit' => -1,
            'status' => array( 'wc-completed', 'completed', 'processing' ),
            'date_created' => $this->build_date_query_for_wc( $start, $end ),
            'return' => 'objects',
        );

        $orders = wc_get_orders( $args );

        foreach ( $orders as $order ) {
            $date = $order->get_date_created();
            if ( ! $date ) { continue; }
            $d = $date->date_i18n( 'Y-m-d' );
            $period[$d] += (float) $order->get_total();
        }

        $totals = array_values( $period );

        return rest_ensure_response( array( 'labels' => $labels, 'totals' => $totals ) );
    }

    /**
     * REST: sales by product
     * params: start, end, limit
     */
    public function rest_sales_by_product( WP_REST_Request $request ){
        $start = $request->get_param('start');
        $end   = $request->get_param('end');
        $limit = (int) $request->get_param('limit') ?: 10;

        if ( empty( $end ) ) { $end = date( 'Y-m-d' ); }
        if ( empty( $start ) ) { $start = date( 'Y-m-d', strtotime( $end . ' -29 days' ) ); }

        $args = array(
            'limit' => -1,
            'status' => array( 'wc-completed', 'completed', 'processing' ),
            'date_created' => $this->build_date_query_for_wc( $start, $end ),
            'return' => 'objects',
        );

        $orders = wc_get_orders( $args );

        $products = array(); // product_id => total

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $subtotal = (float) $item->get_total();
                if ( ! isset( $products[ $product_id ] ) ) { $products[ $product_id ] = 0; }
                $products[ $product_id ] += $subtotal;
            }
        }

        // sort desc, take top n
        arsort( $products );
        $top = array_slice( $products, 0, $limit, true );

        $labels = array(); $totals = array();
        foreach ( $top as $pid => $total ){
            $p = wc_get_product( $pid );
            $labels[] = $p ? $p->get_name() : sprintf( '#%d', $pid );
            $totals[] = round( (float) $total, 2 );
        }

        return rest_ensure_response( array( 'labels' => $labels, 'totals' => $totals ) );
    }

    /**
     * REST: sales by customer (billing email or name)
     * params: start, end, limit
     */
    public function rest_sales_by_customer( WP_REST_Request $request ){
        $start = $request->get_param('start');
        $end   = $request->get_param('end');
        $limit = (int) $request->get_param('limit') ?: 10;

        if ( empty( $end ) ) { $end = date( 'Y-m-d' ); }
        if ( empty( $start ) ) { $start = date( 'Y-m-d', strtotime( $end . ' -29 days' ) ); }

        $args = array(
            'limit' => -1,
            'status' => array( 'wc-completed', 'completed', 'processing' ),
            'date_created' => $this->build_date_query_for_wc( $start, $end ),
            'return' => 'objects',
        );

        $orders = wc_get_orders( $args );

        $customers = array(); // key => total

        foreach ( $orders as $order ) {
            $email = $order->get_billing_email() ?: 'Guest';
            $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $label = $name ? $name . ' <' . $email . '>' : $email;
            if ( ! isset( $customers[ $label ] ) ) { $customers[ $label ] = 0; }
            $customers[ $label ] += (float) $order->get_total();
        }

        arsort( $customers );
        $top = array_slice( $customers, 0, $limit, true );

        $labels = array_keys( $top );
        $totals = array_map( function($v){ return round((float)$v,2); }, array_values( $top ) );

        return rest_ensure_response( array( 'labels' => $labels, 'totals' => $totals ) );
    }

    /** Helpers **/
    private function build_date_period( $start, $end ){
        $period = array();
        $begin = new DateTime( $start );
        $endDT = new DateTime( $end );
        $endDT->setTime(0,0,0);

        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod( $begin, $interval, $endDT->add($interval) );

        foreach ( $daterange as $date ){
            $period[ $date->format('Y-m-d') ] = 0.0;
        }
        return $period;
    }

    private function build_date_query_for_wc( $start, $end ){
        // wc_get_orders date_created accepts array with after/before
        // Provide inclusive after start 00:00 and before end 23:59:59
        $after = $start . ' 00:00:00';
        $before = $end . ' 23:59:59';
        return array('after' => $after, 'before' => $before, 'inclusive' => true );
    }

}

new WC_Reports_Dashboard();

// EOF
