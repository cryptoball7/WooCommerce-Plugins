<?php

/**
 * Agent Commerce API Test Runner
 * Usage:
 *   php test-api.php
 */

declare(strict_types=1);

function loadEnv(string $path): array
{
    if (!file_exists($path)) {
        fwrite(STDERR, "❌ .env file not found at $path\n");
        exit(1);
    }

    $vars = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $vars[$key] = $value;
    }

    return $vars;
}

function request(string $url): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
        fwrite(STDERR, "❌ CURL error: " . curl_error($ch) . "\n");
        exit(1);
    }

    curl_close($ch);

    return [$status, $body];
}

function assertTrue(bool $condition, string $message): void
{
    if ($condition) {
        echo "✅ PASS — $message\n";
    } else {
        echo "❌ FAIL — $message\n";
    }
}

/* -----------------------------
   Load Environment
----------------------------- */

$env = loadEnv(__DIR__ . '/.env');

if (!isset($env['BASE_URL'])) {
    fwrite(STDERR, "❌ BASE_URL missing from .env\n");
    exit(1);
}

$base = rtrim($env['BASE_URL'], '/');

/* -----------------------------
   Test: Health Endpoint
----------------------------- */

echo "\nTesting Health Endpoint\n";
echo "-----------------------\n";

[$status, $body] = request("$base/wp-json/agent-commerce/v1/health");

assertTrue($status === 200, "Status code is 200");

$json = json_decode($body, true);

assertTrue(json_last_error() === JSON_ERROR_NONE, "Valid JSON returned");

assertTrue(isset($json['status']) && $json['status'] === 'ok', "Status = ok");

assertTrue(isset($json['service']) && $json['service'] === 'agent-commerce-core', "Service name correct");

assertTrue(isset($json['version']), "Version exists");

assertTrue(isset($json['timestamp']), "Timestamp exists");

echo "\nDone.\n";