<?php
namespace AgentCommerce\Core\Auth;

use WP_REST_Request;
use WP_Error;

class Scope
{
    /**
     * Check whether the agent context includes a required scope.
     */
    public static function require(
        array $agent_context,
        string $required_scope
    ): true|WP_Error {
        $scopes = $agent_context['scopes'] ?? [];

        if (!is_array($scopes)) {
            return new WP_Error(
                'invalid_scopes',
                'Scopes must be an array.'
            );
        }

        if (!in_array($required_scope, $scopes, true)) {
            return new WP_Error(
                'forbidden',
                'Missing required scope.',
                ['required_scope' => $required_scope]
            );
        }

        return true;
    }
}
