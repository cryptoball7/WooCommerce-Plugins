<?php

namespace AgentCommerce\Core\Middleware;

use WP_REST_Request;

use AgentCommerce\Core\Http\RequestAttributes;

/**
 * Logging Middleware
 *
 * Records structured request logs for observability.
 * Designed to be storage-agnostic via filters.
 */
class LoggingMiddleware
{
    public static function handle(WP_REST_Request $request)
    {
        $start = microtime(true);

        /**
         * Store start time for later middleware / response hooks
         */
        RequestAttributes::set($request, '_log_start', $start);

        /**
         * Capture request metadata
         */
        $data = [
            'timestamp' => time(),
            'method'    => $request->get_method(),
            'route'     => $request->get_route(),
            'ip'        => self::ip(),
            'agent_id'  => $request->get_param('_agent')['id'] ?? null,
            'headers'   => self::safe_headers($request->get_headers()),
        ];

        /**
         * Allow external systems to log request start
         */
        do_action('agent_commerce_log_request', $data);

        return true;
    }

    /**
     * Call this from a response filter to log completion.
     */
    public static function log_response(WP_REST_Request $request, $response)
    {
        $start = RequestAttributes::get($request, '_log_start');

        if (!$start) {
            return $response;
        }

        $duration = round((microtime(true) - $start) * 1000, 2);

        $data = [
            'timestamp' => time(),
            'route'     => $request->get_route(),
            'status'    => self::status($response),
            'duration_ms' => $duration,
        ];

        do_action('agent_commerce_log_response', $data);

        return $response;
    }

    private static function status($response)
    {
        if (is_wp_error($response)) {
            return $response->get_error_data()['status'] ?? 500;
        }

        if (is_object($response) && method_exists($response, 'get_status')) {
            return $response->get_status();
        }

        return 200;
    }

    private static function safe_headers(array $headers): array
    {
        $blocked = [
            'authorization',
            'cookie',
            'x-agent-signature'
        ];

        foreach ($blocked as $key) {
            unset($headers[$key]);
        }

        return $headers;
    }

    private static function ip(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
