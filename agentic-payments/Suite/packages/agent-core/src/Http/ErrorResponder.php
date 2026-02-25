<?php
namespace AgentCommerce\Core\Http;

use WP_REST_Response;
use WP_Error;

class ErrorResponder
{
    public static function init(): void
    {
        add_filter(
            'rest_post_dispatch',
            [self::class, 'normalize'],
            10,
            3
        );

        add_filter(
            'rest_pre_serve_request',
            [self::class, 'serve'],
            10,
            4
        );
    }

    /**
     * Normalize WP_Error responses during dispatch
     */
    public static function normalize($response, $server, $request)
    {
        if ($response instanceof WP_Error) {
            return self::fromWpError($response);
        }

        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();

            if ($data instanceof WP_Error) {
                return self::fromWpError($data);
            }
        }

        return $response;
    }

    /**
     * Final interception before output is sent
     * Handles core WP errors like rest_no_route
     */
    public static function serve($served, $result, $request, $server)
    {
        if ($result instanceof WP_Error) {
            $response = self::fromWpError($result);
            $server->send_headers($response->get_headers());
            echo wp_json_encode($response->get_data());
            return true;
        }

        return $served;
    }

    private static function fromWpError(WP_Error $error): WP_REST_Response
    {
        $code = $error->get_error_code();
        $message = $error->get_error_message();
        $data = $error->get_error_data();

        $status = is_array($data) && isset($data['status'])
            ? (int) $data['status']
            : 400;

        $details = is_array($data)
            ? array_diff_key($data, ['status' => true])
            : [];

        return new WP_REST_Response(
            [
                'error' => [
                    'code' => $code ?: 'unknown_error',
                    'message' => $message ?: 'An unknown error occurred',
                    'details' => $details,
                ]
            ],
            $status
        );
    }
}