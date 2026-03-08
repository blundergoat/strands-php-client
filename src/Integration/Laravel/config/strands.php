<?php

/**
 * Strands PHP Client configuration for Laravel.
 *
 * Publish with: php artisan vendor:publish --tag=strands-config
 */

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Agent
    |--------------------------------------------------------------------------
    |
    | The agent name to use when resolving StrandsClient from the container.
    | Must match a key in the "agents" array below.
    |
    */

    'default' => env('STRANDS_DEFAULT_AGENT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | Each key defines a named Strands agent. The service provider registers
    | each as "strands.client.<name>" in the container. The default agent
    | is also bound to StrandsClient::class for type-hint injection.
    |
    */

    'agents' => [

        'default' => [

            // REQUIRED - The URL where the Strands agent is running.
            'endpoint' => env('STRANDS_ENDPOINT', 'http://localhost:8081'),

            // Authentication settings.
            'auth' => [
                // Which auth strategy to use: 'null', 'api_key', or 'sigv4'.
                'driver' => env('STRANDS_AUTH_DRIVER', 'null'),

                // Only used when driver is 'api_key':
                'api_key' => env('STRANDS_API_KEY'),
                'header_name' => 'Authorization',
                'value_prefix' => 'Bearer ',

                // Only used when driver is 'sigv4':
                'region' => env('AWS_DEFAULT_REGION'),
                'service' => 'execute-api',
                'access_key_id' => env('AWS_ACCESS_KEY_ID'),
                'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
                'session_token' => env('AWS_SESSION_TOKEN'),
            ],

            // Response timeout in seconds (LLMs can be slow).
            'timeout' => (int) env('STRANDS_TIMEOUT', 120),

            // TCP connection timeout in seconds.
            'connect_timeout' => (int) env('STRANDS_CONNECT_TIMEOUT', 10),

            // Retry count for transient errors (429, 502, 503, 504).
            'max_retries' => (int) env('STRANDS_MAX_RETRIES', 0),

            // Base delay between retries in milliseconds (exponential backoff).
            'retry_delay_ms' => (int) env('STRANDS_RETRY_DELAY_MS', 500),

            // HTTP status codes that trigger a retry.
            'retryable_status_codes' => [429, 502, 503, 504],
        ],

    ],

];
