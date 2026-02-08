<?php
namespace AgentCommerce\Core\Schemas;

use WP_Error;

class ResponseValidator
{
    /**
     * Validate an outgoing response payload against a JSON Schema.
     *
     * This is intentionally symmetric with request validation:
     * - required fields
     * - top-level types
     *
     * It should be called immediately before returning a response.
     */
    public static function validate(array $data, string $schema_path): true|WP_Error
    {
        if (!file_exists($schema_path)) {
            return new WP_Error(
                'schema_not_found',
                'Response schema file not found.',
                ['schema' => $schema_path]
            );
        }

        $schema = json_decode(file_get_contents($schema_path), true);

        if (!$schema) {
            return new WP_Error(
                'invalid_schema',
                'Response schema is not valid JSON.',
                ['schema' => $schema_path]
            );
        }

        if (isset($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $data)) {
                    return new WP_Error(
                        'response_validation_failed',
                        'Missing required response field.',
                        ['field' => $field]
                    );
                }
            }
        }

        foreach ($schema['properties'] ?? [] as $field => $definition) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            if (isset($definition['type'])) {
                if (!Validator::validate_type($data[$field], $definition['type'])) {
                    return new WP_Error(
                        'response_validation_failed',
                        'Invalid response field type.',
                        ['field' => $field, 'expected' => $definition['type']]
                    );
                }
            }
        }

        return true;
    }
}
