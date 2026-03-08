# Authentication

The Strands PHP Client uses a **Strategy Pattern** for authentication. Every outgoing HTTP request passes through an `AuthStrategy` implementation that can add headers (API keys, tokens, signatures) before the request is sent.

## Table of Contents

- [How It Works](#how-it-works)
- [Available Strategies](#available-strategies)
  - [NullAuth (default)](#nullauth-default)
  - [ApiKeyAuth](#apikeyauth)
  - [SigV4Auth](#sigv4auth)
- [Symfony Configuration](#symfony-configuration)
- [Laravel Configuration](#laravel-configuration)
- [Writing a Custom Strategy](#writing-a-custom-strategy)

## How It Works

Every `StrandsClient` has a `StrandsConfig`, and every `StrandsConfig` has an `AuthStrategy`. Three strategies are built-in: `NullAuth` (no-op, for local dev), `ApiKeyAuth` (API key in a header), and `SigV4Auth` (AWS Signature Version 4 for IAM-protected endpoints). Before each HTTP request (both `invoke()` and `stream()`), the client calls:

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
use StrandsPhpClient\Config\StrandsConfig;

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
use StrandsPhpClient\Auth\ApiKeyAuth;
use StrandsPhpClient\Config\StrandsConfig;

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

### SigV4Auth

**Use for:** Agents behind AWS API Gateway with IAM authorization, or any AWS service that requires Signature Version 4 request signing. Standalone implementation - does not require `aws/aws-sdk-php`.

#### Basic usage (explicit credentials)

```php
use StrandsPhpClient\Auth\SigV4Auth;
use StrandsPhpClient\Config\StrandsConfig;

$config = new StrandsConfig(
    endpoint: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    auth: new SigV4Auth(
        accessKeyId: 'AKIAIOSFODNN7EXAMPLE',
        secretAccessKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        region: 'us-east-1',
    ),
);
```

#### From environment variables

For EC2 instances, ECS tasks, Lambda functions, or any environment where credentials are provided via `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and optionally `AWS_SESSION_TOKEN`:

```php
$config = new StrandsConfig(
    endpoint: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    auth: SigV4Auth::fromEnvironment(region: 'us-east-1'),
);
```

`fromEnvironment()` throws `RuntimeException` if the required environment variables are not set.

#### Temporary credentials (STS)

For IAM roles assumed via STS, pass the session token:

```php
$config = new StrandsConfig(
    endpoint: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    auth: new SigV4Auth(
        accessKeyId: 'ASIA...',
        secretAccessKey: 'wJalr...',
        region: 'us-east-1',
        sessionToken: 'FwoGZXIvY...',
    ),
);
```

#### Constructor parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `accessKeyId` | `string` | *(required)* | AWS access key ID |
| `secretAccessKey` | `string` | *(required)* | AWS secret access key |
| `region` | `string` | *(required)* | AWS region (e.g. `'us-east-1'`) |
| `service` | `string` | `'execute-api'` | AWS service name for signing |
| `sessionToken` | `?string` | `null` | Session token for temporary credentials |

#### How SigV4 signing works

```mermaid
sequenceDiagram
    participant Client as StrandsClient
    participant Auth as SigV4Auth
    participant APIGW as API Gateway

    Client->>Auth: authenticate(headers, POST, url, body)
    Auth->>Auth: Hash body (SHA-256)
    Auth->>Auth: Build canonical request
    Auth->>Auth: Create string to sign
    Auth->>Auth: Derive signing key (HMAC chain)
    Auth->>Auth: Calculate HMAC-SHA256 signature
    Auth-->>Client: Headers + Authorization, X-Amz-Date, X-Amz-Content-Sha256
    Client->>APIGW: POST /invoke (with signed headers)
    APIGW->>APIGW: Verify signature against IAM
    APIGW-->>Client: Response
```

> **Security:** Never hardcode AWS credentials in source code. Use environment variables, IAM instance profiles, ECS task roles, or a secrets manager. The `fromEnvironment()` factory method is the recommended approach for production deployments.

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

### SigV4 auth (AWS IAM)

```yaml
strands:
    agents:
        default:
            endpoint: '%env(AGENT_ENDPOINT)%'
            auth:
                driver: sigv4
                region: '%env(AWS_DEFAULT_REGION)%'
                # Credentials fall back to environment variables if not set:
                # access_key_id: '%env(AWS_ACCESS_KEY_ID)%'
                # secret_access_key: '%env(AWS_SECRET_ACCESS_KEY)%'
                # session_token: '%env(AWS_SESSION_TOKEN)%'
```

When `access_key_id` and `secret_access_key` are omitted, the factory calls `SigV4Auth::fromEnvironment()`, which reads `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the process environment. This is the recommended approach for ECS/EC2/Lambda deployments.

> **Security:** Never hardcode API keys or AWS credentials in config files. Always use environment variables via `%env(...)%` in Symfony or `.env` files.

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

### SigV4 auth (AWS IAM)

```php
'agents' => [
    'default' => [
        'endpoint' => env('STRANDS_ENDPOINT'),
        'auth' => [
            'driver' => 'sigv4',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            // Credentials fall back to environment variables if not set:
            // 'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            // 'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            // 'session_token' => env('AWS_SESSION_TOKEN'),
        ],
    ],
],
```

When `access_key_id` and `secret_access_key` are omitted (or null), the factory calls `SigV4Auth::fromEnvironment()`, which reads `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the process environment. This is the recommended approach for ECS/EC2/Lambda deployments.

> **Security:** Never hardcode API keys or AWS credentials in config files. Always use environment variables via `env()` in Laravel.

## Writing a Custom Strategy

If you need something beyond the built-in strategies (e.g., OAuth2 with token refresh, HMAC signatures, mTLS headers), implement the `AuthStrategy` interface:

```php
use StrandsPhpClient\Auth\AuthStrategy;

class OAuth2RefreshAuth implements AuthStrategy
{
    private string $accessToken;

    private int $expiresAt;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenUrl,
    ) {
        $this->accessToken = '';
        $this->expiresAt = 0;
    }

    public function authenticate(
        array $headers,
        string $method,
        string $url,
        string $body,
    ): array {
        if (time() >= $this->expiresAt) {
            $this->refreshToken();
        }

        $headers['Authorization'] = 'Bearer ' . $this->accessToken;

        return $headers;
    }

    private function refreshToken(): void
    {
        // Exchange client credentials for an access token
        // (implementation depends on your OAuth2 provider)
        $response = $this->fetchToken($this->clientId, $this->clientSecret, $this->tokenUrl);
        $this->accessToken = $response['access_token'];
        $this->expiresAt = time() + $response['expires_in'] - 30; // 30s buffer
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

- **`$headers`** - The headers already set by the client (`Content-Type: application/json`, `Accept: ...`). Add your auth headers to this array and return it. Don't remove existing headers.
- **`$method`** - Always `'POST'` for Strands requests. Included because some auth schemes (like SigV4) need it for request signing.
- **`$url`** - The full URL (`https://api.example.com/agent/invoke`). Needed by auth schemes that include the URL in their signature.
- **`$body`** - The JSON request body. Needed by auth schemes that sign the body content (like SigV4 or HMAC).

Then use it directly:

```php
$client = new StrandsClient(
    config: new StrandsConfig(
        endpoint: 'https://api.example.com/agent',
        auth: new OAuth2RefreshAuth(
            clientId: 'my-app',
            clientSecret: 'secret',
            tokenUrl: 'https://auth.example.com/token',
        ),
    ),
);
```

> **Tip:** To add a custom auth driver to the Symfony bundle config (so it can be configured in YAML), you would need to extend `StrandsClientFactory::resolveAuth()` and `Configuration::getConfigTreeBuilder()`. See those files for the pattern used by `api_key` and `sigv4`.
