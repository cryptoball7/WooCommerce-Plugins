<?php

namespace AgentCommerce\Core\Middleware;

use WP_REST_Request;
use WP_Error;

/**
 * Rate Limit Middleware
 *
 * Enforces per-agent and per-route request limits.
 * Uses WordPress transients for storage.
 * Can be swapped with Redis later via filters.
 */
class RateLimitMiddleware
{
    const DEFAULT_LIMIT = 60;
    const DEFAULT_WINDOW = 60; // seconds

    public static function handle(WP_REST_Request $request)
    {
        $agent = $request->get_param('_agent');

        if (!$agent || empty($agent['id'])) {
            return new WP_Error(
                'rate_limit_agent_missing',
                'Agent context missing for rate limiting',
                ['status' => 500]
            );
        }

        $agent_id = $agent['id'];
        $route    = $request->get_route();

        $config = self::resolve_config($request);

        $key = "rl_{$agent_id}_" . md5($route);

        $bucket = self::get_bucket($key);

        if (!$bucket) {
            $bucket = [
                'count' => 0,
                'reset' => time() + $config['window']
            ];
        }

        if (time() > $bucket['reset']) {
            $bucket = [
                'count' => 0,
                'reset' => time() + $config['window']
            ];
        }

        $bucket['count']++;

        self::store_bucket($key, $bucket, $config['window']);

        self::attach_headers($bucket, $config);

        if ($bucket['count'] > $config['limit']) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests',
                [
                    'status' => 429,
                    'limit'  => $config['limit'],
                    'reset'  => $bucket['reset']
                ]
            );
        }

        return true;
    }

    /**
     * Determine limit config
     */
    private static function resolve_config(WP_REST_Request $request): array
    {
        $custom = $request->get_attribute('rate_limit');

        if (is_array($custom)) {
            return array_merge([
                'limit' => self::DEFAULT_LIMIT,
                'window' => self::DEFAULT_WINDOW
            ], $custom);
        }

        return [
            'limit' => self::DEFAULT_LIMIT,
            'window' => self::DEFAULT_WINDOW
        ];
    }

    private static function get_bucket(string $key)
    {
        return apply_filters(
            'agent_commerce_rate_limit_get',
            get_transient($key),
            $key
        );
    }

    private static function store_bucket(string $key, array $bucket, int $ttl)
    {
        apply_filters(
            'agent_commerce_rate_limit_set',
            set_transient($key, $bucket, $ttl),
            $key,
            $bucket,
            $ttl
        );
    }

    private static function attach_headers(array $bucket, array $config)
    {
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $config['limit']);
            header('X-RateLimit-Remaining: ' . max(0, $config['limit'] - $bucket['count']));
            header('X-RateLimit-Reset: ' . $bucket['reset']);
        }
    }
}
