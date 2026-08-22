# Authentication

The Strands PHP Client uses an authentication strategy for every outgoing HTTP request. An `AuthStrategy` can add API keys, tokens, or signatures just
before the request is sent.

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

Every `StrandsClient` has a `StrandsConfig`, and every config has an `AuthStrategy`. The built-in choices are `NullAuth` for unauthenticated local
gateways, `ApiKeyAuth` for header credentials, and `SigV4Auth` for IAM-protected AWS endpoints. All four request methods use the strategy:

```php
$headers = $this->config->auth->authenticate($headers, 'POST', $url, $body);
```

The strategy receives the final method, URL, body, and headers after request middleware has run. It returns the headers to send, so a signing strategy
covers any body changes middleware made. Application call sites need no authentication logic after setup.

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

`NullAuth` does nothing - it returns the headers exactly as received. This is the default, so you don't need to specify it:

```php
use StrandsPhpClient\Auth\NullAuth;
use StrandsPhpClient\Config\StrandsConfig;

// These are equivalent - NullAuth is the default
$config = new StrandsConfig(endpoint: 'http://localhost:8081');
$config = new StrandsConfig(endpoint: 'http://localhost:8081', auth: new NullAuth());
```

`NullAuth` is a no-op object rather than a nullable setting. The client can therefore authenticate every request without branching at each call site.

### ApiKeyAuth

**Use for:** Production deployments where your agent sits behind an API gateway, reverse proxy, or any service that requires an API key.

#### Basic usage (Bearer token)

The most common pattern - sends `Authorization: Bearer <key>`:

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

**Use for:** Agents behind AWS API Gateway with IAM authorization, or another AWS service that requires Signature Version 4 signing. No
`aws/aws-sdk-php` dependency is required.

#### Basic usage (explicit credentials)

```php
use StrandsPhpClient\Auth\SigV4Auth;
use StrandsPhpClient\Config\StrandsConfig;

$config = new StrandsConfig(
    endpoint: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    auth: new SigV4Auth(
        accessKeyId: 'your-access-key-id',
        secretAccessKey: 'your-secret-access-key',
        region: 'us-east-1',
    ),
);
```

#### From environment variables

Use this factory when the deployment explicitly injects `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and optional `AWS_SESSION_TOKEN` into the PHP
process:

```php
$config = new StrandsConfig(
    endpoint: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    auth: SigV4Auth::fromEnvironment(region: 'us-east-1'),
);
```

`fromEnvironment()` reads only those environment variables. It throws `RuntimeException` when either required value is missing; it does not query the
EC2 metadata service, ECS task credentials, Lambda execution-role credentials, shared AWS profiles, or another provider chain.

#### Temporary credentials (STS)

For IAM roles assumed via STS, pass the session token:

```php
$config = new StrandsConfig(
    endpoint: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    auth: new SigV4Auth(
        accessKeyId: 'your-temporary-access-key-id',
        secretAccessKey: 'your-temporary-secret-access-key',
        region: 'us-east-1',
        sessionToken: 'your-session-token',
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

> **Security:** Never hardcode AWS credentials. Inject short-lived values through environment variables or a secrets manager. For an instance profile,
> ECS task role, Lambda execution role, or shared profile, use an AWS credential provider to resolve the values before constructing `SigV4Auth`.
>
> `SigV4Auth::__debugInfo()` masks `secretAccessKey` and `sessionToken` in `var_dump()` and `print_r()`. This reduces accidental exposure but does not
> make it safe to log configuration objects.

## Symfony Configuration

When using the Symfony bundle, configure auth in `config/packages/strands.yaml`. See [symfony-config.md](symfony-config.md) for the full reference.

### No auth (local dev)

```yaml
strands:
    agents:
        default:
            endpoint: 'http://localhost:8081'
            # auth.driver defaults to 'null' - no config needed
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
                # Explicit credentials are optional because omitted values fall back to environment variables.

                # access_key_id: '%env(AWS_ACCESS_KEY_ID)%'
                # secret_access_key: '%env(AWS_SECRET_ACCESS_KEY)%'

                # Temporary credentials also need the matching session token.

                # session_token: '%env(AWS_SESSION_TOKEN)%'
```

When `access_key_id` and `secret_access_key` are omitted, the factory calls `SigV4Auth::fromEnvironment()`. The PHP process must already contain
`AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`; temporary credentials also require `AWS_SESSION_TOKEN`.

AWS roles do not populate those variables automatically. Resolve role credentials with an AWS credential provider, then inject all three temporary
values or pass them explicitly.

> **Security:** Never hardcode API keys or AWS credentials in config files. Reference secrets through Symfony `%env(...)%` values and keep production
> secrets outside committed `.env` files.

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
            // Omit both keys to read process env; otherwise add both keys and an optional session_token here.
        ],
    ],
],
```

When both explicit keys are omitted or null, the factory calls `SigV4Auth::fromEnvironment()`. The PHP process must contain the required credential
variables; temporary credentials also require `AWS_SESSION_TOKEN`.

AWS roles do not populate those variables automatically. Resolve role credentials with an AWS credential provider, then inject all three temporary
values or pass them explicitly.

> **Security:** Never hardcode API keys or AWS credentials in config files. Always use environment variables via `env()` in Laravel.

## Writing a Custom Strategy

For an application-specific header scheme, such as OAuth2 or HMAC, implement `AuthStrategy`. Mutual TLS belongs in the HTTP transport or client
configuration because it negotiates a client certificate at the TLS layer rather than adding a header.

```php
use StrandsPhpClient\Auth\AuthStrategy;

/**
 * Adds a timestamped HMAC signature that a matching gateway can verify.
 *
 * Use this example only when the agent service owns the same canonical signing format and rejects stale timestamps.
 * The timestamp, nonce, and signature let that gateway authenticate a request without exposing the shared secret to the user.
 */
final readonly class TimestampedHmacAuth implements AuthStrategy
{
    /**
     * Store the app secret used to sign requests for the agent gateway.
     * Use a secret-manager value; an empty string cannot authenticate requests and fails during client setup.
     *
     * @param string $sharedSecret Secret shared with the gateway; an empty value is rejected before any user request.
     * @throws \InvalidArgumentException When no signing secret was configured.
     */
    public function __construct(
        private string $sharedSecret,
    ) {
        // An empty secret would make every signature guessable, so stop before the app serves a request.
        if ($sharedSecret === '') {
            throw new \InvalidArgumentException('The HMAC shared secret cannot be empty.');
        }
    }

    /**
     * Add the timestamp, nonce, and signature the gateway checks before serving the user's request.
     * Use it through StrandsClient; callers should not invoke authentication separately.
     *
     * @param array<string, string> $headers Existing request headers; an empty array is valid and receives all three authentication headers.
     * @param string $method HTTP method included in the signature; an empty value cannot match a correctly configured gateway.
     * @param string $url Full agent URL included in the signature; an empty value cannot identify the protected endpoint.
     * @param string $body Serialized request body; an empty value signs an intentionally empty body.
     * @return array<string, string> Original headers plus authentication values; never empty.
     * @throws \Random\RandomException When the runtime cannot generate a secure request nonce.
     */
    public function authenticate(
        array $headers,
        string $method,
        string $url,
        string $body,
    ): array {
        $requestTimestamp = (string) time();
        $requestNonce = bin2hex(random_bytes(16));
        $canonicalRequest = implode("\n", [$requestTimestamp, $requestNonce, $method, $url, $body]);
        $headers['X-Request-Timestamp'] = $requestTimestamp;
        $headers['X-Request-Nonce'] = $requestNonce;
        $headers['X-Request-Signature'] = hash_hmac('sha256', $canonicalRequest, $this->sharedSecret);

        return $headers;
    }
}
```

The gateway must use HTTPS, compare signatures with `hash_equals()`, enforce a short timestamp window, and reject a reused nonce within that window.
Those server-side checks are part of this custom protocol; the client strategy cannot enforce them by itself.

The interface requires a single method:

```php
/** Attach authentication to the final request headers before the user's call leaves the PHP application. */
public function authenticate(
    array $headers,   // Existing headers; an empty array is valid.
    string $method,   // Non-empty HTTP method, normally 'POST'.
    string $url,      // Non-empty full request URL.
    string $body,     // JSON request body; an empty string means there is no body.
): array;             // Complete headers; authentication normally makes this non-empty.
```

**Parameters explained:**

- **`$headers`** - Headers already set by the client, such as `Content-Type` and `Accept`. Add authentication values and return the complete array.
- **`$method`** - Always `'POST'` for Strands requests. Included because some auth schemes (like SigV4) need it for request signing.
- **`$url`** - The full URL (`https://api.example.com/agent/invoke`). Needed by auth schemes that include the URL in their signature.
- **`$body`** - The JSON request body. Needed by auth schemes that sign the body content (like SigV4 or HMAC).

Then use it directly:

```php
$client = new StrandsClient(
    config: new StrandsConfig(
        endpoint: 'https://api.example.com/agent',
        auth: new TimestampedHmacAuth($secretFromYourSecretManager),
    ),
);
```

> **Tip:** Direct construction accepts any custom strategy. Adding a YAML or Laravel `driver` requires application-owned factory wiring because the
> package factory recognizes only `null`, `api_key`, and `sigv4`.
