# Laravel Service Provider Configuration

The Strands PHP Client includes a Laravel service provider that registers `StrandsClient` services from a publishable config file. This guide covers every configuration option with examples.

## Table of Contents

- [Quick Start](#quick-start)
- [Auto-Discovery](#auto-discovery)
- [Full Configuration Reference](#full-configuration-reference)
- [Configuration Options](#configuration-options)
  - [default](#default)
  - [endpoint (required)](#endpoint-required)
  - [auth](#auth)
  - [timeout](#timeout)
  - [connect_timeout](#connect_timeout)
  - [max_retries](#max_retries)
  - [retry_delay_ms](#retry_delay_ms)
- [Examples](#examples)
  - [Local Development](#local-development)
  - [Production with API Key](#production-with-api-key)
  - [Multiple Agents (Council Pattern)](#multiple-agents-council-pattern)
  - [High-Availability with Retries](#high-availability-with-retries)
- [Service Injection](#service-injection)
  - [Default Client](#default-client)
  - [Named Clients](#named-clients)
  - [Facade](#facade)
- [Environment Variables](#environment-variables)
- [How It Works Under the Hood](#how-it-works-under-the-hood)

## Quick Start

1. Install the package:

```bash
composer require blundergoat/strands-php-client
```

2. Publish the config file:

```bash
php artisan vendor:publish --tag=strands-config
```

3. Set environment variables:

```dotenv
# .env
STRANDS_ENDPOINT=http://localhost:8081
```

4. Inject and use:

```php
use StrandsPhpClient\StrandsClient;

class ChatController extends Controller
{
    public function __construct(
        private readonly StrandsClient $client,
    ) {}

    public function ask(string $question): string
    {
        return $this->client->invoke(message: $question)->text;
    }
}
```

## Auto-Discovery

The service provider and facade are auto-discovered via the `extra.laravel` key in `composer.json`. No manual registration is needed.

If you have disabled auto-discovery, add the provider and facade manually:

```php
// config/app.php
'providers' => [
    StrandsPhpClient\Integration\Laravel\StrandsServiceProvider::class,
],

'aliases' => [
    'Strands' => StrandsPhpClient\Integration\Laravel\Facades\Strands::class,
],
```

## Full Configuration Reference

Every option with its default value:

```php
// config/strands.php
return [
    // Which agent to use when resolving StrandsClient from the container.
    'default' => env('STRANDS_DEFAULT_AGENT', 'default'),

    'agents' => [
        // Each key becomes a binding: strands.client.<name>
        'my_agent' => [

            // REQUIRED - The URL where the Strands agent is running.
            'endpoint' => env('STRANDS_ENDPOINT', 'http://localhost:8081'),

            // Authentication settings.
            'auth' => [
                // Which auth strategy to use: 'null', 'api_key', or 'sigv4'.
                'driver' => 'null',                    // default: 'null'

                // Only used when driver is 'api_key':
                'api_key' => null,                     // default: null (required for api_key driver)
                'header_name' => 'Authorization',      // default: 'Authorization'
                'value_prefix' => 'Bearer ',           // default: 'Bearer '

                // Only used when driver is 'sigv4':
                'region' => null,                      // default: null (required for sigv4 driver)
                'service' => 'execute-api',            // default: 'execute-api'
                'access_key_id' => null,               // default: null (falls back to env)
                'secret_access_key' => null,            // default: null (falls back to env)
                'session_token' => null,               // default: null
            ],

            // How long to wait for the agent to respond (seconds).
            'timeout' => 120,                          // default: 120

            // How long to wait for the initial TCP connection (seconds).
            'connect_timeout' => 10,                   // default: 10

            // How many times to retry on transient errors (429, 502, 503, 504).
            'max_retries' => 0,                        // default: 0

            // Base delay between retries in milliseconds.
            'retry_delay_ms' => 500,                   // default: 500
        ],
    ],
];
```

## Configuration Options

### default

The name of the agent to use as the default `StrandsClient` binding. Must match a key in the `agents` array.

```php
'default' => env('STRANDS_DEFAULT_AGENT', 'default'),
```

Unlike Symfony (where the first agent is the default), Laravel uses an explicit `default` key -more idiomatic for Laravel config.

### endpoint (required)

The full URL of the Strands agent HTTP API. The client appends `/invoke` or `/stream` to this URL.

```php
// Local Docker setup
'endpoint' => 'http://agent:8000',

// Local development (no Docker)
'endpoint' => 'http://localhost:8081',

// Production
'endpoint' => 'https://api.example.com/agent',

// Using an environment variable (recommended)
'endpoint' => env('STRANDS_ENDPOINT'),
```

### auth

Authentication configuration. Controls how the client identifies itself to the agent.

#### driver: null (default)

No authentication - headers are sent as-is. Use for local development.

```php
'auth' => [
    'driver' => 'null',
],
```

Or simply omit the `auth` section entirely - `null` is the default.

#### driver: api_key

Adds an API key to a configurable HTTP header on every request.

```php
'auth' => [
    'driver' => 'api_key',
    'api_key' => env('STRANDS_API_KEY'),      // Required
    'header_name' => 'Authorization',          // Optional (default)
    'value_prefix' => 'Bearer ',               // Optional (default)
],
```

This sends: `Authorization: Bearer <your-key>`

For APIs that expect `X-API-Key: <key>` without a prefix:

```php
'auth' => [
    'driver' => 'api_key',
    'api_key' => env('STRANDS_API_KEY'),
    'header_name' => 'X-API-Key',
    'value_prefix' => '',
],
```

#### driver: sigv4

Signs requests with AWS Signature Version 4 for agents behind API Gateway with IAM authorization.

```php
'auth' => [
    'driver' => 'sigv4',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),   // Required
    'service' => 'execute-api',                            // Optional (default)
],
```

When `access_key_id` and `secret_access_key` are omitted (or null), the factory calls `SigV4Auth::fromEnvironment()`, which reads `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the process environment. This is the recommended approach for ECS/EC2/Lambda deployments.

To pass credentials explicitly:

```php
'auth' => [
    'driver' => 'sigv4',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'access_key_id' => env('AWS_ACCESS_KEY_ID'),
    'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
    'session_token' => env('AWS_SESSION_TOKEN'),   // Optional, for STS temporary credentials
],
```

### timeout

Response timeout in seconds. This is the maximum time to wait for the agent to finish responding. LLMs can take a while, especially with tool use, so the default of 120 seconds (2 minutes) is intentionally generous.

```php
'timeout' => 120,    // default
'timeout' => 300,    // 5 minutes for complex agent tasks with multiple tool calls
'timeout' => 30,     // shorter timeout for simple, fast agents
```

### connect_timeout

Connection timeout in seconds. How long to wait for the initial TCP connection to the agent server. This is separate from `timeout` so that:

- A **down server** fails quickly (connect_timeout: 10s)
- A **slow LLM response** doesn't get confused with a down server (timeout: 120s)

```php
'connect_timeout' => 10,    // default
'connect_timeout' => 5,     // fail faster if the server is unreachable
```

### max_retries

Maximum number of retries on transient HTTP errors. When a request fails with a retryable status code (429, 502, 503, 504), the client will retry up to this many times before throwing an exception.

```php
'max_retries' => 0,     // default - no retries, fail immediately
'max_retries' => 2,     // retry twice (3 total attempts)
'max_retries' => 5,     // retry 5 times (for critical production workloads)
```

Retries only apply to `invoke()` calls. Streaming requests are not retried.

### retry_delay_ms

Base delay between retries in milliseconds. Uses **exponential backoff** -the delay doubles after each retry:

| Retry | Delay (500ms base) | Delay (1000ms base) |
|-------|--------------------|---------------------|
| 1st   | 500ms              | 1000ms              |
| 2nd   | 1000ms             | 2000ms              |
| 3rd   | 2000ms             | 4000ms              |
| 4th   | 4000ms             | 8000ms              |

```php
'retry_delay_ms' => 500,     // default
'retry_delay_ms' => 1000,    // start with 1 second (more conservative)
'retry_delay_ms' => 100,     // start with 100ms (aggressive retries)
```

## Examples

### Local Development

Minimal config for running against a local Docker Compose or `start-dev.sh` setup:

```php
// config/strands.php
return [
    'default' => 'default',
    'agents' => [
        'default' => [
            'endpoint' => env('STRANDS_ENDPOINT', 'http://localhost:8081'),
        ],
    ],
];
```

```dotenv
# .env
STRANDS_ENDPOINT=http://localhost:8081
```

### Production with API Key

Secured agent behind an API gateway:

```php
// config/strands.php
return [
    'default' => 'default',
    'agents' => [
        'default' => [
            'endpoint' => env('STRANDS_ENDPOINT'),
            'auth' => [
                'driver' => 'api_key',
                'api_key' => env('STRANDS_API_KEY'),
            ],
            'timeout' => 180,
            'connect_timeout' => 5,
            'max_retries' => 2,
            'retry_delay_ms' => 1000,
        ],
    ],
];
```

```dotenv
# .env (or set via your deployment platform)
STRANDS_ENDPOINT=https://agent.internal.example.com
STRANDS_API_KEY=sk-prod-abc123def456
```

### Multiple Agents (Council Pattern)

Multiple named agents that share the same endpoint but get different personas via context metadata:

```php
// config/strands.php
return [
    'default' => 'analyst',
    'agents' => [
        'analyst' => [
            'endpoint' => env('AGENT_ENDPOINT'),
            'timeout' => 300,
        ],
        'skeptic' => [
            'endpoint' => env('AGENT_ENDPOINT'),
            'timeout' => 300,
        ],
        'strategist' => [
            'endpoint' => env('AGENT_ENDPOINT'),
            'timeout' => 300,
        ],
    ],
];
```

Each agent creates a separate container binding (`strands.client.analyst`, `strands.client.skeptic`, `strands.client.strategist`).

### High-Availability with Retries

For critical production workloads where transient errors are expected:

```php
'agents' => [
    'primary' => [
        'endpoint' => env('STRANDS_ENDPOINT'),
        'timeout' => 120,
        'connect_timeout' => 5,
        'max_retries' => 3,
        'retry_delay_ms' => 500,
        'auth' => [
            'driver' => 'api_key',
            'api_key' => env('STRANDS_API_KEY'),
        ],
    ],
],
```

With `max_retries: 3` and `retry_delay_ms: 500`, the retry timing is:
- Attempt 1: immediate
- Retry 1: after 500ms
- Retry 2: after 1000ms
- Retry 3: after 2000ms
- Total max wait: ~3.5 seconds of retry delays before giving up

## Service Injection

### Default Client

The agent specified by the `default` config key is bound to `StrandsClient::class`. Inject it by type-hint:

```php
use StrandsPhpClient\StrandsClient;

class MyService
{
    public function __construct(
        private readonly StrandsClient $client,
    ) {}
}
```

### Named Clients

Each agent creates a binding named `strands.client.<name>`. Resolve them from the container:

```php
$analyst = app('strands.client.analyst');
$skeptic = app('strands.client.skeptic');
```

Or use constructor injection with Laravel's contextual binding:

```php
use StrandsPhpClient\StrandsClient;

$this->app->when(CouncilOrchestrator::class)
    ->needs(StrandsClient::class)
    ->give(fn () => app('strands.client.analyst'));
```

### Facade

The `Strands` facade proxies to the default `StrandsClient`:

```php
use StrandsPhpClient\Integration\Laravel\Facades\Strands;

// Invoke
$response = Strands::invoke('Analyse this proposal');
echo $response->text;

// Stream
$result = Strands::stream('Explain quantum computing', onEvent: function ($event) {
    echo $event->text;
});
```

## Environment Variables

Use Laravel's `env()` helper to keep secrets out of config files:

```php
'endpoint' => env('STRANDS_ENDPOINT'),           // Simple string
'api_key' => env('STRANDS_API_KEY'),             // Secret value
'timeout' => (int) env('STRANDS_TIMEOUT', 120),  // Cast to integer
```

```dotenv
# .env (not committed to git)
STRANDS_ENDPOINT=http://localhost:8081
STRANDS_API_KEY=sk-dev-abc123
STRANDS_TIMEOUT=60
```

## How It Works Under the Hood

When Laravel boots, the service provider processes your config through two steps:

1. **`StrandsServiceProvider::register()`** -Merges the default config, then registers:
   - A `StrandsClientFactory` singleton (holds all agent configs)
   - A `StrandsClient` singleton for the default agent
   - Named `strands.client.<name>` bindings for each agent

2. **`StrandsClientFactory::create()`** -Called at runtime (lazy) to create each `StrandsClient`. It:
   - Looks up the agent config by name
   - Resolves the auth driver (`'null'` -> `NullAuth`, `'api_key'` -> `ApiKeyAuth`, `'sigv4'` -> `SigV4Auth`)
   - Builds a `StrandsConfig` with all settings
   - Creates the `StrandsClient`

```
Config Array -> StrandsServiceProvider (register bindings) -> StrandsClientFactory (create clients)
```

The factory is shared between Laravel and Symfony integrations -it contains zero framework-specific code.
