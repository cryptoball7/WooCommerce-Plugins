<?php

namespace AgentCommerce\Core\Validation;

use WP_Error;

class Validator
{
    public static function validate(array $data, string $schemaPath)
    {
        if (!file_exists($schemaPath)) {
            return new WP_Error(
                'schema_missing',
                'Validation schema not found',
                ['schema' => $schemaPath]
            );
        }

        $schema = json_decode(file_get_contents($schemaPath), true);

        if (!$schema) {
            return new WP_Error(
                'schema_invalid',
                'Schema file is not valid JSON',
                ['schema' => $schemaPath]
            );
        }

        return self::validateObject($data, $schema);
    }

    private static function validateObject(array $data, array $schema)
    {
        $required = $schema['required'] ?? [];
        $properties = $schema['properties'] ?? [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                return new WP_Error(
                    'validation_error',
                    "Missing required field: {$field}",
                    ['field' => $field]
                );
            }
        }

        foreach ($properties as $field => $rules) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            $type = $rules['type'] ?? null;

            if ($type && !self::matchesType($value, $type)) {
                return new WP_Error(
                    'validation_error',
                    "Invalid type for {$field}, expected {$type}",
                    [
                        'field' => $field,
                        'expected' => $type,
                        'received' => gettype($value)
                    ]
                );
            }
        }

        return true;
    }

    private static function matchesType($value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_numeric($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_array($value),
            default => true
        };
    }
}