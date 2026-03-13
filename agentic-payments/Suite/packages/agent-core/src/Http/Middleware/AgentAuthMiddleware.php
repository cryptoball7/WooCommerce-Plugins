<?php

declare(strict_types=1);

namespace AgentCommerce\Core\Http\Middleware;

use AgentCommerce\Core\Http\Context;
use AgentCommerce\Core\Validation\Validator;
use AgentCommerce\Core\Bootstrap;

class AgentAuthMiddleware
{
    public function handle(Context $ctx, callable $next)
    {
        $request = $ctx->request();

        $identity_header = $request->get_header('Agent-Identity');
        $caps_header     = $request->get_header('Agent-Capabilities');

        if (!$identity_header) {
            return Bootstrap::error(
                'agent_identity_missing',
                'Agent-Identity header required',
                [],
                401
            );
        }

        $identity = json_decode($identity_header, true);

        if (!is_array($identity)) {
            return Bootstrap::error(
                'agent_identity_invalid',
                'Agent-Identity must be valid JSON',
                [],
                400
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Identity Schema
        |--------------------------------------------------------------------------
        */

        $schema = AGENT_COMMERCE_SCHEMA_PATH . '/v1/agent_identity.json';

        $result = Validator::validate($identity, $schema);

        if (is_wp_error($result)) {
            return $result;
        }

        /*
        |--------------------------------------------------------------------------
        | Parse Capabilities
        |--------------------------------------------------------------------------
        */

        $caps = [];

        if ($caps_header) {
            $caps = json_decode($caps_header, true);

            if (!is_array($caps)) {
                return Bootstrap::error(
                    'agent_capabilities_invalid',
                    'Agent-Capabilities must be JSON array',
                    [],
                    400
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Populate Context
        |--------------------------------------------------------------------------
        */

        $ctx->setAgent($identity);
        $ctx->setCapabilities($caps);

        return $next($ctx);
    }
}