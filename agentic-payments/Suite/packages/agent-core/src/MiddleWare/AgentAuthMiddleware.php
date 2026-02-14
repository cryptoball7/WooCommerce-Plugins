<?php

namespace AgentCommerce\Core\Middleware;

use WP_REST_Request;
use WP_Error;
use AgentCommerce\Core\Validation\Validator;

/**
 * Agent Authentication Middleware
 *
 * Validates:
 *  - Signature header
 *  - Timestamp freshness
 *  - Nonce uniqueness
 *  - agent_identity schema
 *
 * Attaches verified agent object to request:
 *   $request->get_param('_agent')
 */
class AgentAuthMiddleware
{
    const MAX_SKEW = 300; // 5 minutes

    public static function handle(WP_REST_Request $request)
    {
        $signature = $request->get_header('x-agent-signature');
        $timestamp = $request->get_header('x-agent-timestamp');
        $nonce     = $request->get_header('x-agent-nonce');
        $identity  = $request->get_json_params()['agent'] ?? null;

        if (!$signature || !$timestamp || !$nonce || !$identity) {
            return new WP_Error(
                'agent_auth_missing',
                'Missing authentication headers or agent payload',
                ['status' => 401]
            );
        }

        /**
         * Timestamp validation (replay protection window)
         */
        if (abs(time() - intval($timestamp)) > self::MAX_SKEW) {
            return new WP_Error(
                'agent_auth_timestamp_invalid',
                'Timestamp expired or invalid',
                ['status' => 401]
            );
        }

        /**
         * Nonce replay check
         */
        if (self::nonce_used($nonce)) {
            return new WP_Error(
                'agent_auth_replay',
                'Nonce already used',
                ['status' => 401]
            );
        }

        /**
         * Schema validation
         */
        $result = Validator::validate(
            $identity,
            AGENT_COMMERCE_PATH . '/schemas/v1/agent_identity.json'
        );

        if (is_wp_error($result)) {
            return $result;
        }

        /**
         * Signature verification
         */
        $public_key = self::get_agent_public_key($identity['id']);

        if (!$public_key) {
            return new WP_Error(
                'agent_unknown',
                'Unknown agent',
                ['status' => 401]
            );
        }

        $payload = self::build_payload($request, $timestamp, $nonce);

        if (!self::verify_signature($payload, $signature, $public_key)) {
            return new WP_Error(
                'agent_signature_invalid',
                'Invalid signature',
                ['status' => 401]
            );
        }

        /**
         * Mark nonce as used
         */
        self::store_nonce($nonce);

        /**
         * Attach agent to request
         */
        $request->set_param('_agent', $identity);

        return true;
    }

    private static function build_payload(WP_REST_Request $request, $timestamp, $nonce)
    {
        return json_encode([
            'method' => $request->get_method(),
            'route'  => $request->get_route(),
            'body'   => $request->get_body(),
            'ts'     => $timestamp,
            'nonce'  => $nonce,
        ]);
    }

    private static function verify_signature($payload, $signature, $public_key)
    {
        $decoded = base64_decode($signature);

        return openssl_verify(
            $payload,
            $decoded,
            $public_key,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    private static function get_agent_public_key($agent_id)
    {
        /**
         * Replace with DB or registry lookup later
         */
        return apply_filters('agent_commerce_public_key', null, $agent_id);
    }

    private static function nonce_used($nonce)
    {
        return (bool) get_transient('agent_nonce_' . $nonce);
    }

    private static function store_nonce($nonce)
    {
        set_transient('agent_nonce_' . $nonce, 1, self::MAX_SKEW);
    }
}
