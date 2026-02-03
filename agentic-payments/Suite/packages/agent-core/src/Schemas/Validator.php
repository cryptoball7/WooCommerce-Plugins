<?php
namespace AgentCommerce\Core\Schemas;

use WP_Error;

class Validator
{
    /**
     * Validate an associative array against a JSON Schema file.
     *
     * This is intentionally minimal in v1. It enforces presence,
     * basic structure, and required fields without introducing
     * heavy dependencies.
     */
    public static function validate(array $data, string $schema_path): true|WP_Error
    {
        if (!file_exists($schema_path)) {
            return new WP_Error(
                'schema_not_found',
                'Schema file not found.',
                ['schema' => $schema_path]
            );
        }

        $schema = json_decode(file_get_contents($schema_path), true);

        if (!$schema) {
            return new WP_Error(
                'invalid_schema',
                'Schema file is not valid JSON.',
                ['schema' => $schema_path]
            );
        }

        // Required field validation only (v1 scope)
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $field) {
                if (!array_key_exists($field, $data)) {
                    return new WP_Error(
                        'schema_validation_failed',
                        'Missing required field.',
                        ['field' => $field]
                    );
                }
            }
        }

        // Type validation (top-level only)
        foreach ($schema['properties'] ?? [] as $field => $definition) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            if (isset($definition['type'])) {
                if (!self::validate_type($data[$field], $definition['type'])) {
                    return new WP_Error(
                        'schema_validation_failed',
                        'Invalid field type.',
                        ['field' => $field, 'expected' => $definition['type']]
                    );
                }
            }
        }

        return true;
    }

    private static function validate_type(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value),
            'number'  => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array'   => is_array($value),
            'object'  => is_array($value),
            default   => true,
        };
    }
}
