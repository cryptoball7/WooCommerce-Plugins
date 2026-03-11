<?php

declare(strict_types=1);

namespace AgentCommerce\Core\Middleware;

use AgentCommerce\Core\Http\Context;

interface MiddlewareInterface
{
    public function handle(Context $ctx, callable $next);
}