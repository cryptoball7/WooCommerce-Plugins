<?php

namespace AgentCommerce\Core\Middleware;

use WP_REST_Request;
use WP_Error;

/**
 * Middleware runner for Agent Commerce routes.
 *
 * Allows stacking reusable middleware across plugins.
 */
class MiddlewareRunner
{
    /**
     * Execute middleware stack.
     *
     * @param WP_REST_Request $request
     * @param array $stack Array of callables
     * @return true|WP_Error
     */
    public static function run(WP_REST_Request $request, array $stack)
    {
        foreach ($stack as $middleware) {
            $result = call_user_func($middleware, $request);

            if (is_wp_error($result)) {
                return $result;
            }
        }

        return true;
    }
}
