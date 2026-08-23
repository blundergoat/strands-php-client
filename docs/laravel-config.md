# Laravel Service Provider Configuration

The Laravel service provider registers `StrandsClient` services from a publishable config. This guide explains every option and when to change it.

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
  - [retryable_status_codes](#retryable_status_codes)
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

/**
 * Answers questions submitted through a small Laravel chat screen.
 *
 * Use this controller when the screen should send every question to the configured default agent.
 * The returned string is the completed answer the route can render or serialize.
 */
final class ChatController extends Controller
{
    /** Inject the default agent selected by config/strands.php. */
    public function __construct(
        private readonly StrandsClient $agentClient,
    ) {
    }

    /** Return the agent's completed answer for the question submitted by the user. */
    public function answerQuestion(string $question): string
    {
        return $this->agentClient->invoke(message: $question)->text;
    }
}
```

## Auto-Discovery

The service provider and facade are auto-discovered via the `extra.laravel` key in `composer.json`. No manual registration is needed.

Tag request middleware with `strands.middleware` and response-aware observers with `strands.response_observer`. `OtelTracingMiddleware` may use the
middleware tag alone because the client detects observers already present in that stack.

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
                // Null means unauthenticated access; choose api_key or sigv4 for a protected agent endpoint.
                'driver' => 'null',                    // default: 'null'

                // Only used when driver is 'api_key':
                'api_key' => null,                     // null is valid until api_key is selected, then the client cannot be created without a key
                'header_name' => 'Authorization',      // default: 'Authorization'
                'value_prefix' => 'Bearer ',           // default: 'Bearer '

                // Only used when driver is 'sigv4':
                'region' => null,                      // null is valid until sigv4 is selected, then the client cannot be created without a region
                'service' => 'execute-api',            // default: 'execute-api'
                'access_key_id' => null,               // with both keys null, read AWS_ACCESS_KEY_ID from the PHP process
                'secret_access_key' => null,            // with both keys null, read AWS_SECRET_ACCESS_KEY from the PHP process
                'session_token' => null,               // null means no explicit temporary token; env fallback reads AWS_SESSION_TOKEN
            ],

            // How long to wait for the agent to respond (seconds).
            'timeout' => 120,                          // default: 120

            // How long to wait for the initial TCP connection (seconds).
            'connect_timeout' => 10,                   // default: 10

            // How many times to retry on transient errors (429, 502, 503, 504).
            'max_retries' => 0,                        // default: 0

            // Base delay between retries in milliseconds.
            'retry_delay_ms' => 500,                   // default: 500

            // HTTP status codes that trigger a retry.
            'retryable_status_codes' => [429, 502, 503, 504],  // default
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

Unlike Symfony (where the first agent is the default), Laravel uses an explicit `default` key - more idiomatic for Laravel config.

### endpoint (required)

The full URL of the Strands agent HTTP API. The client appends `/invoke` or `/stream` to this URL. Use `http://agent:8000` for a Docker service,
`http://localhost:8081` for a directly hosted local gateway, or an HTTPS URL in production.

```php
// Let each deployment supply its own local or production URL.
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

When both explicit keys are omitted or null, the factory reads `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the PHP process. Temporary
credentials also require `AWS_SESSION_TOKEN`.

This is an environment-variable lookup, not the AWS default credential-provider chain. For an EC2 instance profile, ECS task role, Lambda execution
role, or shared profile, resolve the credentials separately and inject or pass all required values.

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

Response timeout in seconds. It limits how long the client waits for the agent to finish; the 120-second default leaves room for model generation and
tool calls.

```php
// The default is 120; use 300 for tool-heavy agents or 30 for a consistently fast endpoint.
'timeout' => 120,
```

### connect_timeout

Connection timeout in seconds. How long to wait for the initial TCP connection to the agent server. This is separate from `timeout` so that:

- A **down server** fails quickly (connect_timeout: 10s)
- A **slow LLM response** doesn't get confused with a down server (timeout: 120s)

```php
// The default is 10; use 5 when the UI should report an unreachable endpoint sooner.
'connect_timeout' => 10,
```

### max_retries

Maximum number of retries after the first request. HTTP responses retry only when their status appears in `retryable_status_codes`; connection and
response-processing failures also use this retry budget.

```php
// The default is 0; use 2 for three total attempts or a larger value only when the user can tolerate the added wait.
'max_retries' => 0,
```

Retries apply to `invoke()` and `postJson()` calls. Streaming requests (`stream()`, `streamSse()`) are not retried.

### retry_delay_ms

Base delay between retries in milliseconds. The base doubles for each retry and is capped at 30 seconds. Each actual delay is randomized to 50–100% of
that base so several application requests do not retry the agent together.

| Retry | Actual delay (500ms base) | Actual delay (1000ms base) |
|-------|---------------------------|----------------------------|
| 1st   | 250–500ms                 | 500–1000ms                 |
| 2nd   | 500–1000ms                | 1000–2000ms                |
| 3rd   | 1000–2000ms               | 2000–4000ms                |
| 4th   | 2000–4000ms               | 4000–8000ms                |

```php
// The default is 500; use 1000 for slower retries or 100 when the agent service recovers quickly.
'retry_delay_ms' => 500,
```

### retryable_status_codes

HTTP statuses that may be retried when `max_retries` is greater than zero. Values must be integers from 400 through 599. An empty list disables status
retries while leaving connection and response-processing retries available.

```php
'retryable_status_codes' => [429, 502, 503, 504], // default
// Add 500 when the wrapper uses it for transient errors; use [] to retry connection and response-processing failures only.
```

## Examples

### Local Development

Minimal config for a local gateway, whether it runs directly or through Docker Compose:

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
STRANDS_API_KEY=replace-with-a-secret-from-your-deployment-platform
```

### Multiple Agents (Council Pattern)

Register several named clients against one endpoint. Configuration creates the clients; the caller supplies `AgentContext` metadata when the wrapper
uses a value such as `persona` to select agent instructions.

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
- Retry 1: after 250–500ms
- Retry 2: after 500–1000ms
- Retry 3: after 1000–2000ms
- Total retry delay: about 1.75–3.5 seconds before the final failure reaches the caller

## Service Injection

### Default Client

The agent specified by the `default` config key is bound to `StrandsClient::class`. Inject it by type-hint:

```php
use StrandsPhpClient\StrandsClient;

/**
 * Receives the default client for an application service that answers user questions.
 *
 * Use this pattern when every method in the service should talk to the configured default agent.
 * Add task-specific methods here rather than resolving the client from Laravel's container at each call site.
 */
final class AgentAnswerService
{
    /** Inject the default agent once for later user requests. */
    public function __construct(
        private readonly StrandsClient $agentClient,
    ) {
    }
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
use StrandsPhpClient\Streaming\StreamEvent;
use StrandsPhpClient\Streaming\StreamEventType;

// Invoke
$response = Strands::invoke('Analyse this proposal');
echo $response->text;

// Render each text update as the default agent streams it.
$result = Strands::stream('Explain quantum computing', onEvent: function (StreamEvent $event): void {
    // Non-text events drive other UI elements and should not print an empty text value.
    if ($event->type === StreamEventType::Text) {
        echo $event->text;
    }
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
STRANDS_API_KEY=replace-with-your-local-secret
STRANDS_TIMEOUT=60
```

## How It Works Under the Hood

When Laravel boots, the service provider processes your config through two steps:

1. **`StrandsServiceProvider::register()`** - Merges the default config, then registers:
   - A `StrandsClientFactory` singleton (holds all agent configs)
   - A `StrandsClient` singleton for the default agent
   - Named `strands.client.<name>` bindings for each agent

2. **`StrandsClientFactory::create()`** - Called at runtime (lazy) to create each `StrandsClient`. It:
   - Looks up the agent config by name
   - Resolves the auth driver (`'null'` -> `NullAuth`, `'api_key'` -> `ApiKeyAuth`, `'sigv4'` -> `SigV4Auth`)
   - Builds a `StrandsConfig` with all settings
   - Creates the `StrandsClient`

```
Config Array -> StrandsServiceProvider (register bindings) -> StrandsClientFactory (create clients)
```

The factory is shared between Laravel and Symfony integrations - it contains zero framework-specific code.
