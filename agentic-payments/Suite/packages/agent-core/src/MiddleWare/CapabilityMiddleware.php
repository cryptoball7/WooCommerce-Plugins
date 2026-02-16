<?php

namespace AgentCommerce\Core\Middleware;

use WP_REST_Request;
use WP_Error;

/**
 * Capability Middleware
 *
 * Validates that an authenticated agent has permission
 * to access the requested resource.
 *
 * Supports:
 *  - exact scopes        orders:create
 *  - wildcard scopes     orders:*
 *  - parent scopes       orders
 *
 * Route must define required scopes via:
 *   $request->set_attribute('required_scopes', [...])
 */
class CapabilityMiddleware
{
    public static function handle(WP_REST_Request $request)
    {
        $agent = $request->get_param('_agent');

        if (!$agent) {
            return new WP_Error(
                'agent_missing',
                'Agent context missing',
                ['status' => 500]
            );
        }

        $required = $request->get_attribute('required_scopes') ?? [];
        $granted  = $agent['scopes'] ?? [];

        if (!$required) {
            return true; // no scopes required
        }

        if (!is_array($granted)) {
            return new WP_Error(
                'invalid_scopes',
                'Agent scopes malformed',
                ['status' => 403]
            );
        }

        foreach ($required as $needed) {
            if (!self::has_scope($granted, $needed)) {
                return new WP_Error(
                    'insufficient_scope',
                    'Missing required capability',
                    [
                        'status' => 403,
                        'required' => $needed
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Determine if granted scopes satisfy requirement.
     */
    private static function has_scope(array $granted, string $required): bool
    {
        foreach ($granted as $scope) {

            // exact match
            if ($scope === $required) {
                return true;
            }

            // wildcard match: orders:* matches orders:create
            if (str_ends_with($scope, ':*')) {
                $prefix = substr($scope, 0, -2);
                if (str_starts_with($required, $prefix . ':')) {
                    return true;
                }
            }

            // parent scope match: orders matches orders:create
            if (!str_contains($scope, ':')) {
                if (str_starts_with($required, $scope . ':') || $required === $scope) {
                    return true;
                }
            }
        }

        return false;
    }
}
