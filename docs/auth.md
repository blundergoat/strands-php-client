# Authentication

The Strands PHP Client uses a **Strategy Pattern** for authentication. Every outgoing HTTP request passes through an `AuthStrategy` implementation that can add headers (API keys, tokens, signatures) before the request is sent.

## Table of Contents

- [How It Works](#how-it-works)
- [Available Strategies](#available-strategies)
  - [NullAuth (default)](#nullauth-default)
  - [ApiKeyAuth](#apikeyauth)
- [Symfony Configuration](#symfony-configuration)
- [Laravel Configuration](#laravel-configuration)
- [Writing a Custom Strategy](#writing-a-custom-strategy)

## How It Works

Every `StrandsClient` has a `StrandsConfig`, and every `StrandsConfig` has an `AuthStrategy`. Before each HTTP request (both `invoke()` and `stream()`), the client calls:

```php
$headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);
```

The auth strategy receives the current headers, HTTP method, URL, and body, then returns a new set of headers with any authentication data added. This happens transparently -your application code doesn't need to think about auth after initial setup.

```
Your Code                StrandsClient              AuthStrategy
   |                          |                          |
   |--- invoke("Hello") ---->|                          |
   |                          |--- authenticate() ----->|
   |                          |                          |-- adds Authorization header
   |                          |<-- headers + auth -------|
   |                          |--- HTTP POST ---------->| (Python agent)
   |<-- AgentResponse --------|                          |
```

## Available Strategies

### NullAuth (default)

**Use for:** Local development, Docker Compose setups, any environment where the agent doesn't require auth.

`NullAuth` does nothing -it returns the headers exactly as received. This is the default, so you don't need to specify it:

```php
use Strands\Config\StrandsConfig;

// These are equivalent -NullAuth is the default
$config = new StrandsConfig(endpoint: 'http://localhost:8081');
$config = new StrandsConfig(endpoint: 'http://localhost:8081', auth: new NullAuth());
```

`NullAuth` follows the **Null Object Pattern** -instead of checking `if ($auth !== null)` everywhere, we use a real object that simply does nothing. This keeps the code clean and avoids null checks.

### ApiKeyAuth

**Use for:** Production deployments where your agent sits behind an API gateway, reverse proxy, or any service that requires an API key.

#### Basic usage (Bearer token)

The most common pattern -sends `Authorization: Bearer <key>`:

```php
use Strands\Auth\ApiKeyAuth;
use Strands\Config\StrandsConfig;

$config = new StrandsConfig(
    endpoint: 'https://api.example.com/agent',
    auth: new ApiKeyAuth('sk-your-api-key-here'),
);
```

This adds the following header to every request:

```
Authorization: Bearer sk-your-api-key-here
```

#### Custom header name

Some APIs expect the key in a different header like `X-API-Key`:

```php
$config = new StrandsConfig(
    endpoint: 'https://api.example.com/agent',
    auth: new ApiKeyAuth(
        apiKey: 'sk-your-api-key-here',
        headerName: 'X-API-Key',
        valuePrefix: '',              // No "Bearer " prefix
    ),
);
```

This sends:

```
X-API-Key: sk-your-api-key-here
```

#### Constructor parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `string` | *(required)* | The API key value |
| `headerName` | `string` | `'Authorization'` | HTTP header name |
| `valuePrefix` | `string` | `'Bearer '` | Prefix before the key (note the trailing space) |

## Symfony Configuration

When using the Symfony bundle, configure auth in `config/packages/strands.yaml`. See [symfony-config.md](symfony-config.md) for the full reference.

### No auth (local dev)

```yaml
strands:
    agents:
        default:
            endpoint: 'http://localhost:8081'
            # auth.driver defaults to 'null' -no config needed
```

### API key auth

```yaml
strands:
    agents:
        default:
            endpoint: 'https://api.example.com/agent'
            auth:
                driver: api_key
                api_key: '%env(AGENT_API_KEY)%'
```

### API key with custom header

```yaml
strands:
    agents:
        default:
            endpoint: 'https://api.example.com/agent'
            auth:
                driver: api_key
                api_key: '%env(AGENT_API_KEY)%'
                header_name: 'X-API-Key'
                value_prefix: ''
```

> **Security:** Never hardcode API keys in config files. Always use environment variables via `%env(...)%` in Symfony or `.env` files.

## Laravel Configuration

When using the Laravel service provider, configure auth in `config/strands.php`. See [laravel-config.md](laravel-config.md) for the full reference.

### No auth (local dev)

```php
// config/strands.php
'agents' => [
    'default' => [
        'endpoint' => env('STRANDS_ENDPOINT', 'http://localhost:8081'),
        // auth.driver defaults to 'null' - no config needed
    ],
],
```

### API key auth

```php
'agents' => [
    'default' => [
        'endpoint' => env('STRANDS_ENDPOINT'),
        'auth' => [
            'driver' => 'api_key',
            'api_key' => env('STRANDS_API_KEY'),
        ],
    ],
],
```

### API key with custom header

```php
'agents' => [
    'default' => [
        'endpoint' => env('STRANDS_ENDPOINT'),
        'auth' => [
            'driver' => 'api_key',
            'api_key' => env('STRANDS_API_KEY'),
            'header_name' => 'X-API-Key',
            'value_prefix' => '',
        ],
    ],
],
```

> **Security:** Never hardcode API keys in config files. Always use environment variables via `env()` in Laravel.

## Writing a Custom Strategy

If you need something beyond API keys (e.g., AWS SigV4 signing, OAuth2 tokens, HMAC signatures), implement the `AuthStrategy` interface:

```php
use Strands\Auth\AuthStrategy;

class OAuth2Auth implements AuthStrategy
{
    public function __construct(
        private readonly string $accessToken,
    ) {
    }

    public function authenticate(
        array $headers,
        string $method,
        string $url,
        string $body,
    ): array {
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;

        return $headers;
    }
}
```

The interface requires a single method:

```php
public function authenticate(
    array $headers,   // Existing headers (Content-Type, Accept, etc.)
    string $method,   // HTTP method ('POST')
    string $url,      // Full request URL
    string $body,     // JSON request body
): array;             // Return headers WITH auth added
```

**Parameters explained:**

- **`$headers`** -The headers already set by the client (`Content-Type: application/json`, `Accept: ...`). Add your auth headers to this array and return it. Don't remove existing headers.
- **`$method`** -Always `'POST'` for Strands requests. Included because some auth schemes (like AWS SigV4) need it for request signing.
- **`$url`** -The full URL (`https://api.example.com/agent/invoke`). Needed by auth schemes that include the URL in their signature.
- **`$body`** -The JSON request body. Needed by auth schemes that sign the body content (like AWS SigV4).

Then use it directly:

```php
$client = new StrandsClient(
    config: new StrandsConfig(
        endpoint: 'https://api.example.com/agent',
        auth: new OAuth2Auth('eyJhbGciOi...'),
    ),
);
```

> **Tip:** To add a custom auth driver to the Symfony bundle config (so it can be configured in YAML), you would need to extend `StrandsClientFactory::resolveAuth()` and `Configuration::getConfigTreeBuilder()`. See those files for the pattern used by `api_key`.
