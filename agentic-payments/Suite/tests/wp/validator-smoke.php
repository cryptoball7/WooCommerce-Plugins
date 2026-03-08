<?php

declare(strict_types=1);

use AgentCommerce\Core\Validation\Validator;

/*
|--------------------------------------------------------------------------
| Load .env
|--------------------------------------------------------------------------
*/

function loadEnv(string $path): array
{
    if (!file_exists($path)) {
        echo "FAIL .env not found\n";
        exit(1);
    }

    $vars = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        [$k,$v] = array_map('trim', explode('=', $line, 2));
        $vars[$k] = $v;
    }

    return $vars;
}

$env = loadEnv(__DIR__ . '/../config/.env');

if (!isset($env['WP_PATH'])) {
    echo "FAIL WP_PATH missing in .env\n";
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Load WordPress
|--------------------------------------------------------------------------
*/

$wp_load_path = rtrim($env['WP_PATH'], '/') . '/wp-load.php';

if (!file_exists($wp_load_path)) {
    echo "FAIL WordPress not found at $wp_load_path\n";
    exit(1);
}

require_once $wp_load_path;

echo "\nValidator Smoke Test\n";
echo "--------------------\n";

/*
|--------------------------------------------------------------------------
| Schema path
|--------------------------------------------------------------------------
*/

$schema = AGENT_COMMERCE_SCHEMA_PATH . '/v1/test.schema.json';

/*
|--------------------------------------------------------------------------
| Test: Missing Required
|--------------------------------------------------------------------------
*/

$result = Validator::validate([], $schema);

if (is_wp_error($result)) {
    echo "PASS missing required detected\n";
} else {
    echo "FAIL missing required NOT detected\n";
}

/*
|--------------------------------------------------------------------------
| Test: Valid Data
|--------------------------------------------------------------------------
*/

$result = Validator::validate(['name' => 'John'], $schema);

if (!is_wp_error($result)) {
    echo "PASS valid data accepted\n";
} else {
    echo "FAIL valid data rejected\n";
}

echo "\nDone.\n";