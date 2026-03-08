# Symfony Bundle Configuration

The Strands PHP Client includes a Symfony bundle that registers `StrandsClient` services from YAML configuration. This guide covers every configuration option with examples.

## Table of Contents

- [Quick Start](#quick-start)
- [Bundle Registration](#bundle-registration)
- [Full Configuration Reference](#full-configuration-reference)
- [Configuration Options](#configuration-options)
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
  - [Autowire Attribute](#autowire-attribute)
- [Environment Variables](#environment-variables)
- [How It Works Under the Hood](#how-it-works-under-the-hood)

## Quick Start

1. Register the bundle:

```php
// config/bundles.php
return [
    // ... other bundles
    StrandsPhpClient\Integration\Symfony\StrandsBundle::class => ['all' => true],
];
```

2. Create the config file:

```yaml
# config/packages/strands.yaml
strands:
    agents:
        default:
            endpoint: '%env(AGENT_ENDPOINT)%'
```

3. Add the environment variable:

```dotenv
# .env
AGENT_ENDPOINT=http://localhost:8081
```

4. Inject and use:

```php
use StrandsPhpClient\StrandsClient;

class MyService
{
    public function __construct(
        private readonly StrandsClient $client,
    ) {
    }

    public function ask(string $question): string
    {
        return $this->client->invoke(message: $question)->text;
    }
}
```

## Bundle Registration

Add the bundle to `config/bundles.php`:

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    StrandsPhpClient\Integration\Symfony\StrandsBundle::class => ['all' => true],
    // ... other bundles
];
```

The bundle auto-detects `symfony/http-client` and creates `SymfonyHttpTransport` instances, so both `invoke()` and `stream()` work out of the box.

## Full Configuration Reference

Every option with its default value:

```yaml
strands:
    agents:
        # Each key becomes a service: strands.client.<name>
        my_agent:

            # REQUIRED -The URL where the Strands agent is running.
            endpoint: 'http://localhost:8081'

            # Authentication settings
            auth:
                # Which auth strategy to use: 'null', 'api_key', or 'sigv4'
                driver: 'null'                    # default: 'null'

                # Only used when driver is 'api_key':
                api_key: ~                        # default: null (required for api_key driver)
                header_name: 'Authorization'      # default: 'Authorization'
                value_prefix: 'Bearer '           # default: 'Bearer '

                # Only used when driver is 'sigv4':
                region: ~                         # default: null (required for sigv4 driver)
                service: 'execute-api'            # default: 'execute-api'
                access_key_id: ~                  # default: null (falls back to env)
                secret_access_key: ~              # default: null (falls back to env)
                session_token: ~                  # default: null

            # How long to wait for the agent to respond (seconds).
            # LLMs can be slow -120s is generous but safe.
            timeout: 120                          # default: 120

            # How long to wait for the initial TCP connection (seconds).
            # Separate from timeout so a down server fails fast
            # without affecting slow LLM generation.
            connect_timeout: 10                   # default: 10

            # How many times to retry on transient errors (429, 502, 503, 504).
            # Set to 0 to disable retries (the default).
            max_retries: 0                        # default: 0

            # Base delay between retries in milliseconds.
            # Uses exponential backoff: 500ms → 1000ms → 2000ms → ...
            retry_delay_ms: 500                   # default: 500
```

## Configuration Options

### endpoint (required)

The full URL of the Strands agent HTTP API. The client appends `/invoke` or `/stream` to this URL.

```yaml
# Local Docker setup
endpoint: 'http://agent:8000'

# Local development (no Docker)
endpoint: 'http://localhost:8081'

# Production
endpoint: 'https://api.example.com/agent'

# Using an environment variable (recommended)
endpoint: '%env(AGENT_ENDPOINT)%'
```

### auth

Authentication configuration. Controls how the client identifies itself to the agent.

#### driver: null (default)

No authentication - headers are sent as-is. Use for local development.

```yaml
auth:
    driver: 'null'
```

Or simply omit the `auth` section entirely - `null` is the default.

#### driver: api_key

Adds an API key to a configurable HTTP header on every request.

```yaml
auth:
    driver: api_key
    api_key: '%env(AGENT_API_KEY)%'      # Required
    header_name: 'Authorization'          # Optional (default)
    value_prefix: 'Bearer '              # Optional (default)
```

This sends: `Authorization: Bearer <your-key>`

For APIs that expect `X-API-Key: <key>` without a prefix:

```yaml
auth:
    driver: api_key
    api_key: '%env(AGENT_API_KEY)%'
    header_name: 'X-API-Key'
    value_prefix: ''
```

#### driver: sigv4

Signs requests with AWS Signature Version 4 for agents behind API Gateway with IAM authorization.

```yaml
auth:
    driver: sigv4
    region: '%env(AWS_DEFAULT_REGION)%'   # Required
    service: 'execute-api'                # Optional (default)
```

When `access_key_id` and `secret_access_key` are omitted, the factory calls `SigV4Auth::fromEnvironment()`, which reads `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the process environment. This is the recommended approach for ECS/EC2/Lambda deployments.

To pass credentials explicitly (e.g. from Symfony secrets):

```yaml
auth:
    driver: sigv4
    region: '%env(AWS_DEFAULT_REGION)%'
    access_key_id: '%env(AWS_ACCESS_KEY_ID)%'
    secret_access_key: '%env(AWS_SECRET_ACCESS_KEY)%'
    session_token: '%env(AWS_SESSION_TOKEN)%'   # Optional, for STS temporary credentials
```

### timeout

Response timeout in seconds. This is the maximum time to wait for the agent to finish responding. LLMs can take a while, especially with tool use, so the default of 120 seconds (2 minutes) is intentionally generous.

```yaml
timeout: 120    # default
timeout: 300    # 5 minutes for complex agent tasks with multiple tool calls
timeout: 30     # shorter timeout for simple, fast agents
```

This applies to both `invoke()` (total time) and `stream()` (time between chunks).

### connect_timeout

Connection timeout in seconds. How long to wait for the initial TCP connection to the agent server. This is separate from `timeout` so that:

- A **down server** fails quickly (connect_timeout: 10s)
- A **slow LLM response** doesn't get confused with a down server (timeout: 120s)

```yaml
connect_timeout: 10    # default
connect_timeout: 5     # fail faster if the server is unreachable
```

### max_retries

Maximum number of retries on transient HTTP errors. When a request fails with a retryable status code (429, 502, 503, 504), the client will retry up to this many times before throwing an exception.

```yaml
max_retries: 0     # default -no retries, fail immediately
max_retries: 2     # retry twice (3 total attempts)
max_retries: 5     # retry 5 times (for critical production workloads)
```

Retries only apply to `invoke()` calls. Streaming requests are not retried (you'd need to restart the entire stream).

### retry_delay_ms

Base delay between retries in milliseconds. Uses **exponential backoff** -the delay doubles after each retry:

| Retry | Delay (500ms base) | Delay (1000ms base) |
|-------|--------------------|---------------------|
| 1st   | 500ms              | 1000ms              |
| 2nd   | 1000ms             | 2000ms              |
| 3rd   | 2000ms             | 4000ms              |
| 4th   | 4000ms             | 8000ms              |

```yaml
retry_delay_ms: 500     # default
retry_delay_ms: 1000    # start with 1 second (more conservative)
retry_delay_ms: 100     # start with 100ms (aggressive retries)
```

## Examples

### Local Development

Minimal config for running against a local Docker Compose or `start-dev.sh` setup:

```yaml
# config/packages/strands.yaml
strands:
    agents:
        default:
            endpoint: '%env(AGENT_ENDPOINT)%'
```

```dotenv
# .env
AGENT_ENDPOINT=http://localhost:8081
```

### Production with API Key

Secured agent behind an API gateway:

```yaml
# config/packages/strands.yaml
strands:
    agents:
        default:
            endpoint: '%env(AGENT_ENDPOINT)%'
            timeout: 180
            connect_timeout: 5
            max_retries: 2
            retry_delay_ms: 1000
            auth:
                driver: api_key
                api_key: '%env(AGENT_API_KEY)%'
```

```dotenv
# .env (or set via your deployment platform)
AGENT_ENDPOINT=https://agent.internal.example.com
AGENT_API_KEY=sk-prod-abc123def456
```

### Multiple Agents (Council Pattern)

Multiple named agents that share the same endpoint but get different personas via context metadata (as used in the-summit-chat):

```yaml
# config/packages/strands.yaml
strands:
    agents:
        analyst:
            endpoint: '%env(AGENT_ENDPOINT)%'
            timeout: 300
        skeptic:
            endpoint: '%env(AGENT_ENDPOINT)%'
            timeout: 300
        strategist:
            endpoint: '%env(AGENT_ENDPOINT)%'
            timeout: 300
```

Each agent creates a separate `StrandsClient` service (`strands.client.analyst`, `strands.client.skeptic`, `strands.client.strategist`), even though they point to the same endpoint. The persona is selected via `AgentContext` metadata at call time.

### High-Availability with Retries

For critical production workloads where transient errors are expected:

```yaml
strands:
    agents:
        primary:
            endpoint: '%env(AGENT_ENDPOINT)%'
            timeout: 120
            connect_timeout: 5
            max_retries: 3
            retry_delay_ms: 500
            auth:
                driver: api_key
                api_key: '%env(AGENT_API_KEY)%'
```

With `max_retries: 3` and `retry_delay_ms: 500`, the retry timing is:
- Attempt 1: immediate
- Retry 1: after 500ms
- Retry 2: after 1000ms
- Retry 3: after 2000ms
- Total max wait: ~3.5 seconds of retry delays before giving up

## Service Injection

### Default Client

The **first** agent in your config is automatically aliased as the default `StrandsClient`. You can inject it by type-hint alone:

```php
use StrandsPhpClient\StrandsClient;

class MyService
{
    public function __construct(
        private readonly StrandsClient $client,
    ) {
    }
}
```

### Named Clients

Each agent creates a service named `strands.client.<name>`. Access them by service ID:

```php
$client = $container->get('strands.client.analyst');
```

### Autowire Attribute

The recommended way to inject specific named clients in Symfony 6.4+:

```php
use StrandsPhpClient\StrandsClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class CouncilOrchestrator
{
    public function __construct(
        #[Autowire(service: 'strands.client.analyst')]
        private readonly StrandsClient $analyst,

        #[Autowire(service: 'strands.client.skeptic')]
        private readonly StrandsClient $skeptic,

        #[Autowire(service: 'strands.client.strategist')]
        private readonly StrandsClient $strategist,
    ) {
    }
}
```

## Environment Variables

Use Symfony's `%env()%` processor to keep secrets out of config files:

```yaml
endpoint: '%env(AGENT_ENDPOINT)%'            # Simple string
api_key: '%env(AGENT_API_KEY)%'              # Secret value
timeout: '%env(int:AGENT_TIMEOUT)%'          # Cast to integer
```

```dotenv
# .env.local (not committed to git)
AGENT_ENDPOINT=http://localhost:8081
AGENT_API_KEY=sk-dev-abc123
AGENT_TIMEOUT=60
```

## How It Works Under the Hood

When Symfony boots, the bundle processes your config through three classes:

1. **`Configuration`** -Defines the schema (what keys are allowed, their types, defaults). Validates your YAML against this schema. If you typo a key name or use the wrong type, Symfony throws a clear error at boot time.

2. **`StrandsExtension`** -Reads the validated config and registers services in the DI container:
   - One `StrandsClientFactory` service (holds all agent configs)
   - One `StrandsClient` service per agent (created via the factory)
   - An alias from `StrandsClient::class` to the first agent

3. **`StrandsClientFactory`** -Called at runtime to create each `StrandsClient`. It:
   - Looks up the agent config by name
   - Resolves the auth driver (`'null'` → `NullAuth`, `'api_key'` → `ApiKeyAuth`, `'sigv4'` → `SigV4Auth`)
   - Builds a `StrandsConfig` with all settings
   - Creates the `StrandsClient` with a PSR-3 logger injected

```
YAML Config → Configuration (validate) → StrandsExtension (register services) → StrandsClientFactory (create clients)
```

The logger is automatically injected from Symfony's `logger` service (MonologBundle), so all `StrandsClient` debug/warning logs appear in your standard Symfony log files.
