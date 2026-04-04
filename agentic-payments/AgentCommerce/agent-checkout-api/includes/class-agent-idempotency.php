<?php

class Agent_Idempotency {

    public static function handle($request, $endpoint, $callback) {

        global $wpdb;

        $table = $wpdb->prefix . 'agent_idempotency_keys';

        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;

        if (!$key) {
            return $callback(); // no idempotency
        }

        $body = json_encode($request->get_json_params());
        $hash = hash('sha256', $body);

        // Check existing
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE idempotency_key=%s AND endpoint=%s",
                $key,
                $endpoint
            )
        );

        if ($existing) {

            if ($existing->request_hash !== $hash) {
                return new WP_Error(
                    'idempotency_conflict',
                    'Request body does not match previous request',
                    ['status' => 409]
                );
            }

            return rest_ensure_response(
                json_decode($existing->response, true)
            );
        }

        // Execute real logic
        $response = $callback();

        $wpdb->insert($table, [
            'idempotency_key' => $key,
            'endpoint' => $endpoint,
            'request_hash' => $hash,
            'response' => json_encode($response),
            'status_code' => 200,
            'created_at' => current_time('mysql')
        ]);

        return $response;
    }
}