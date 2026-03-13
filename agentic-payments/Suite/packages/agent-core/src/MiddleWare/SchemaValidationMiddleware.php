<?php

namespace AgentCommerce\Core\Middleware;

use WP_REST_Request;
use AgentCommerce\Core\Http\RequestAttributes;
use AgentCommerce\Core\Validation\Validator;
use AgentCommerce\Core\Bootstrap;

class SchemaValidationMiddleware
{
    private string $schemaPath;
    private string $target;

    public function __construct(string $schemaPath, string $target = 'body')
    {
        $this->schemaPath = $schemaPath;
        $this->target = $target;
    }

    public static function body(string $schema): self
    {
        return new self($schema, 'body');
    }

    public static function query(string $schema): self
    {
        return new self($schema, 'query');
    }

    public function handle(Context $ctx, callable $next)
    {
        $data = match ($this->target) {
            'query' => $request->get_query_params(),
            default => $request->get_json_params() ?? []
        };

        $result = Validator::validate($data, $this->schemaPath);

        if (is_wp_error($result)) {
            return Bootstrap::error(
                $result->get_error_code(),
                $result->get_error_message(),
                $result->get_error_data(),
                422
            );
        }

        RequestAttributes::set($request, 'validated', $data);

        return $next($request);
    }
}