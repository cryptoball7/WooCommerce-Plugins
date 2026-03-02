<?php
namespace AgentCommerce\Core\Http;

use WP_REST_Request;

class RequestAttributes
{
    public static function set(WP_REST_Request $req, string $key, $value): void
    {
        $attrs = $req->get_attributes();
        $attrs[$key] = $value;
        $req->set_attributes($attrs);
    }

    public static function get(WP_REST_Request $req, string $key, $default = null)
    {
        $attrs = $req->get_attributes();
        return $attrs[$key] ?? $default;
    }
}