<?php

declare(strict_types=1);

namespace AgentCommerce\Core\Http\Middleware;

use AgentCommerce\Core\Http\Context;
use WP_REST_Request;

class ContextMiddleware
{
    public function handle(WP_REST_Request $request, callable $next)
    {
        $ctx = new Context($request);

        return $next($ctx);
    }
}