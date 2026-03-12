<?php

/*

TODO:

Update middleware signatures:

OLD:

handle(WP_REST_Request $request, callable $next)

NEW:

handle(Context $ctx, callable $next)

-----
-----Update Existing Middleware

For example LoggingMiddleware should now become:

use AgentCommerce\Core\Http\Context;

public function handle(Context $ctx, callable $next)
{
    $start = microtime(true);

    $response = $next($ctx);

    $duration = microtime(true) - $start;

    return $response;
}

Note the Context instead of WP_REST_Request.

*/

declare(strict_types=1);

namespace AgentCommerce\Core\Http;

use WP_REST_Request;

class Context
{
    protected WP_REST_Request $request;

    protected array $input = [];

    protected array $agent = [];

    protected array $capabilities = [];

    protected string $request_id;

    public function __construct(WP_REST_Request $request)
    {
        $this->request = $request;

        $this->request_id = $this->generateRequestId();
    }

    protected function generateRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /*
    |--------------------------------------------------------------------------
    | Request
    |--------------------------------------------------------------------------
    */

    public function request(): WP_REST_Request
    {
        return $this->request;
    }

    /*
    |--------------------------------------------------------------------------
    | Input
    |--------------------------------------------------------------------------
    */

    public function setInput(array $input): void
    {
        $this->input = $input;
    }

    public function input(): array
    {
        return $this->input;
    }

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    */

    public function setAgent(array $agent): void
    {
        $this->agent = $agent;
    }

    public function agent(): array
    {
        return $this->agent;
    }

    /*
    |--------------------------------------------------------------------------
    | Capabilities
    |--------------------------------------------------------------------------
    */

    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /*
    |--------------------------------------------------------------------------
    | Request ID
    |--------------------------------------------------------------------------
    */

    public function requestId(): string
    {
        return $this->request_id;
    }
}