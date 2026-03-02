<?php
namespace AgentCommerce\Core\Http;

use WP_REST_Response;
use WP_Error;

class ErrorResponder
{
    public static function init(): void
    {
add_action( 'template_redirect', [self::class, 'serve']);
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

        add_filter(
            'rest_no_route',
            [self::class, 'serve'],
            10,
            4
        );

        add_filter(
            'rest_post_serve_request',
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
    // Case 1 — raw WP_Error
    if ($result instanceof WP_Error) {
        $response = self::fromWpError($result);
        $server->send_headers($response->get_headers());
        echo wp_json_encode($response->get_data());
        return true;
    }

    // Case 2 — WP_REST_Response containing error array
    if ($result instanceof \WP_REST_Response) {
        $data = $result->get_data();

        if (is_array($data) && isset($data['code'], $data['message'])) {

            $status = $data['data']['status'] ?? 400;

            $normalized = [
                'error' => [
                    'code' => $data['code'],
                    'message' => $data['message'],
                    'details' => $data['data'] ?? []
                ]
            ];

            status_header($status);
            echo wp_json_encode($normalized);
            return true;
        }
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