<?php

class Agent_Checkout_DB {

    public static function install() {

        global $wpdb;

        $table = $wpdb->prefix . 'agent_checkout_sessions';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            session_id varchar(64) PRIMARY KEY,
            agent_id varchar(64),
            customer_id bigint,
            status varchar(32),
            total decimal(10,2),
            payment_token varchar(128),
            authorized_at datetime NULL,
            price_locked_until datetime,
            created_at datetime
        ) $charset;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql);
    }
}