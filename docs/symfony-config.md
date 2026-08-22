# Symfony Bundle Configuration

The Symfony bundle registers `StrandsClient` services from YAML configuration. This guide explains every option and when to change it.

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
  - [retryable_status_codes](#retryable_status_codes)
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

/**
 * Answers questions submitted through a Symfony application screen.
 *
 * Use this service when the screen should send every question to the first configured agent.
 * The returned string is the completed answer a controller can render or serialize.
 */
final class AgentAnswerService
{
    /** Inject the default agent alias created by the bundle. */
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

`RequestMiddleware` receives `strands.middleware`, while `ResponseObserver` receives `strands.response_observer`. Observability can then read parsed
responses and sanitized custom-endpoint summaries without changing the request-middleware interface.

## Full Configuration Reference

Every option with its default value:

```yaml
strands:
    agents:
        # Each key becomes a service: strands.client.<name>
        my_agent:

            # REQUIRED - the URL where the Strands agent is running.
            endpoint: 'http://localhost:8081'

            # Authentication settings
            auth:
                # Null means unauthenticated access; choose api_key or sigv4 for a protected agent endpoint.
                driver: 'null'                    # default: 'null'

                # Only used when driver is 'api_key':
                api_key: ~                        # null is valid until api_key is selected, then the client cannot be created without a key
                header_name: 'Authorization'      # default: 'Authorization'
                value_prefix: 'Bearer '           # default: 'Bearer '

                # Only used when driver is 'sigv4':
                region: ~                         # null is valid until sigv4 is selected, then the client cannot be created without a region
                service: 'execute-api'            # default: 'execute-api'
                access_key_id: ~                  # with both keys null, read AWS_ACCESS_KEY_ID from the PHP process
                secret_access_key: ~              # with both keys null, read AWS_SECRET_ACCESS_KEY from the PHP process
                session_token: ~                  # null means explicit credentials have no token; env fallback reads AWS_SESSION_TOKEN

            # How long to wait for the agent to respond (seconds).
            # LLMs can be slow - 120s is generous but safe.
            timeout: 120                          # default: 120

            # This separate connection limit lets a down server fail quickly without shortening slow model generation.
            connect_timeout: 10                   # default: 10

            # How many times to retry on transient errors (429, 502, 503, 504).
            # Set to 0 to disable retries (the default).
            max_retries: 0                        # default: 0

            # Base delay between retries in milliseconds.
            # Uses exponential backoff: 500ms → 1000ms → 2000ms → ...
            retry_delay_ms: 500                   # default: 500

            # HTTP status codes that trigger a retry.
            retryable_status_codes: [429, 502, 503, 504]  # default
```

## Configuration Options

### endpoint (required)

The full URL of the Strands agent HTTP API. The client appends `/invoke` or `/stream` to this URL. Use `http://agent:8000` for a Docker service,
`http://localhost:8081` for a directly hosted local gateway, or an HTTPS URL in production.

```yaml
# Let each deployment supply its own local or production URL.
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

When `access_key_id` and `secret_access_key` are omitted, the factory reads `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the PHP process.
Temporary credentials also require `AWS_SESSION_TOKEN`.

This is an environment-variable lookup, not the AWS default credential-provider chain. For an EC2 instance profile, ECS task role, Lambda execution
role, or shared profile, resolve the credentials separately and inject or pass all required values.

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

Response timeout in seconds. It limits how long the client waits for the agent to finish; the 120-second default leaves room for model generation and
tool calls.

```yaml
# The default is 120; use 300 for tool-heavy agents or 30 for a consistently fast endpoint.
timeout: 120
```

This applies to both `invoke()` (total time) and `stream()` (time between chunks).

### connect_timeout

Connection timeout in seconds. How long to wait for the initial TCP connection to the agent server. This is separate from `timeout` so that:

- A **down server** fails quickly (connect_timeout: 10s)
- A **slow LLM response** doesn't get confused with a down server (timeout: 120s)

```yaml
# The default is 10; use 5 when the UI should report an unreachable endpoint sooner.
connect_timeout: 10
```

### max_retries

Maximum number of retries after the first request. HTTP responses retry only when their status appears in `retryable_status_codes`; connection and
response-processing failures also use this retry budget.

```yaml
# The default is 0; use 2 for three total attempts or a larger value only when the user can tolerate the added wait.
max_retries: 0
```

Retries apply to `invoke()` and `postJson()`. Streaming calls are not retried because a replacement request could duplicate text or tool activity the
user already received.

### retry_delay_ms

Base delay between retries in milliseconds. The base doubles for each retry and is capped at 30 seconds. Each actual delay is randomized to 50–100% of
that base so several application requests do not retry the agent together.

| Retry | Actual delay (500ms base) | Actual delay (1000ms base) |
|-------|---------------------------|----------------------------|
| 1st   | 250–500ms                 | 500–1000ms                 |
| 2nd   | 500–1000ms                | 1000–2000ms                |
| 3rd   | 1000–2000ms               | 2000–4000ms                |
| 4th   | 2000–4000ms               | 4000–8000ms                |

```yaml
# The default is 500; use 1000 for slower retries or 100 when the agent service recovers quickly.
retry_delay_ms: 500
```

### retryable_status_codes

HTTP statuses that may be retried when `max_retries` is greater than zero. Values must be integers from 400 through 599. An empty list disables status
retries while leaving connection and response-processing retries available.

```yaml
retryable_status_codes: [429, 502, 503, 504] # default
# Add 500 when the wrapper uses it for transient errors; use [] to retry connection and response-processing failures only.
```

## Examples

### Local Development

Minimal config for a local gateway, whether it runs directly or through Docker Compose:

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
AGENT_API_KEY=replace-with-a-secret-from-your-deployment-platform
```

### Multiple Agents (Council Pattern)

Register several named clients against one endpoint, as in `the-summit-chatroom`. Configuration creates the services; the caller supplies
`AgentContext` metadata when the wrapper uses a value such as `persona` to select agent instructions.

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

Each entry creates a separate service, such as `strands.client.analyst`, even when several use the same endpoint. The caller selects a persona through
`AgentContext` metadata at call time.

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
- Retry 1: after 250–500ms
- Retry 2: after 500–1000ms
- Retry 3: after 1000–2000ms
- Total retry delay: about 1.75–3.5 seconds before the final failure reaches the caller

## Service Injection

### Default Client

The **first** agent in your config is automatically aliased as the default `StrandsClient`. You can inject it by type-hint alone:

```php
use StrandsPhpClient\StrandsClient;

/**
 * Receives the default client for an application service that answers user questions.
 *
 * Use this pattern when every method in the service should talk to the first configured agent.
 * Add task-specific methods here rather than resolving the client from Symfony's container at each call site.
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

Each agent creates a service named `strands.client.<name>`. Access them by service ID:

```php
$client = $container->get('strands.client.analyst');
```

### Autowire Attribute

The recommended way to inject specific named clients in Symfony 6.4+:

```php
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use StrandsPhpClient\StrandsClient;

/**
 * Receives the specialist agents used by a council-style answer screen.
 *
 * Use this orchestrator when analyst, skeptic, and strategist responses contribute to one user decision.
 * Later methods can call the named clients in the order the interface presents them.
 */
final class CouncilOrchestrator
{
    /** Inject each named agent once so a request never needs a runtime service lookup. */
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
AGENT_API_KEY=replace-with-your-local-secret
AGENT_TIMEOUT=60
```

## How It Works Under the Hood

When Symfony boots, the bundle processes your config through three classes:

1. **`Configuration`** defines the allowed keys, types, and defaults. A misspelled key or invalid type fails during container compilation.

2. **`StrandsExtension`** - Reads the validated config and registers services in the DI container:
   - One `StrandsClientFactory` service (holds all agent configs)
   - One `StrandsClient` service per agent (created via the factory)
   - An alias from `StrandsClient::class` to the first agent

3. **`StrandsClientFactory`** - Called at runtime to create each `StrandsClient`. It:
   - Looks up the agent config by name
   - Resolves the auth driver (`'null'` → `NullAuth`, `'api_key'` → `ApiKeyAuth`, `'sigv4'` → `SigV4Auth`)
   - Builds a `StrandsConfig` with all settings
   - Creates the `StrandsClient` with a PSR-3 logger injected

```
YAML Config → Configuration (validate) → StrandsExtension (register services) → StrandsClientFactory (create clients)
```

The bundle injects Symfony's `logger` service, so `StrandsClient` records use the application's normal log destinations and retention policy.
