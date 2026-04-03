<?php

class Agent_Checkout_DB {

public static function install() {

    global $wpdb;

    $charset = $wpdb->get_charset_collate();

    $table = $wpdb->prefix . 'agent_idempotency_keys';

    $sql = "CREATE TABLE $table (
        id bigint AUTO_INCREMENT PRIMARY KEY,
        idempotency_key varchar(128),
        endpoint varchar(255),
        request_hash varchar(64),
        response longtext,
        status_code int,
        created_at datetime,
        UNIQUE KEY unique_key (idempotency_key, endpoint)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
}