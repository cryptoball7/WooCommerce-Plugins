<?php
namespace AgentCommerce\Core\Request;

use WP_REST_Request;
use WP_Error;
use AgentCommerce\Core\Schemas\Validator;

class Envelope
{
    /**
     * Parse and validate the agent request envelope.
     *
     * Expected structure (JSON body or headers mapped by transport layer):
     * - agent_identity
     * - agent_context
     */
    public static function parse(WP_REST_Request $request): array|WP_Error
    {
        $body = $request->get_json_params();

        if (!is_array($body)) {
            return new WP_Error(
                'invalid_request',
                'Request body must be valid JSON.'
            );
        }

        if (!isset($body['agent_identity']) || !is_array($body['agent_identity'])) {
            return new WP_Error(
                'missing_agent_identity',
                'agent_identity is required.'
            );
        }

        if (!isset($body['agent_context']) || !is_array($body['agent_context'])) {
            return new WP_Error(
                'missing_agent_context',
                'agent_context is required.'
            );
        }

        $identity_result = Validator::validate(
            $body['agent_identity'],
            AGENT_COMMERCE_PATH . '/schemas/v1/agent_identity.json'
        );

        if (is_wp_error($identity_result)) {
            return $identity_result;
        }

        $context_result = Validator::validate(
            $body['agent_context'],
            AGENT_COMMERCE_PATH . '/schemas/v1/agent_context.json'
        );

        if (is_wp_error($context_result)) {
            return $context_result;
        }

        return [
            'agent_identity' => $body['agent_identity'],
            'agent_context'  => $body['agent_context'],
        ];
    }
}
