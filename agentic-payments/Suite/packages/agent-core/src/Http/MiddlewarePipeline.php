<?php

declare(strict_types=1);

namespace AgentCommerce\Core\Http;

use WP_REST_Request;

class MiddlewarePipeline
{
    protected array $middleware = [];

    protected $controller;

    public function __construct(array $middleware, callable $controller)
    {
        $this->middleware = $middleware;
        $this->controller = $controller;
    }

    public function handle(WP_REST_Request $request)
    {
        $context = new Context($request);

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function ($next, $middleware) {

                return function ($ctx) use ($middleware, $next) {

                    if (is_string($middleware)) {
                        $middleware = new $middleware;
                    }

                    return $middleware->handle($ctx, $next);
                };

            },
            $this->controller
        );

        return $pipeline($context);
    }
}