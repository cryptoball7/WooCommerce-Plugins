<?php
namespace AgentCommerce\Core;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Bootstrap
{
    const API_NAMESPACE = 'agent-commerce/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::API_NAMESPACE, '/health', [
            'methods'  => 'GET',
            'callback' => [self::class, 'health_check'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Basic health endpoint to verify routing and plugin loading.
     * This endpoint intentionally performs no authorization or schema validation.
     */
    public static function health_check(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'status' => 'ok',
            'service' => 'agent-commerce-core',
            'version' => '0.1.0',
            'timestamp' => gmdate('c'),
        ], 200);
    }

    /**
     * Normalize errors into a consistent agent-facing shape.
     * (Stub – to be expanded as plugins are added.)
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = 400
    ): WP_Error {
        return new WP_Error(
            $code,
            [
                'message' => $message,
                'details' => $details,
            ],
            ['status' => $status]
        );
    }
}
