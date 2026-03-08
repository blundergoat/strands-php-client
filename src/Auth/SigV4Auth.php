<?php

declare(strict_types=1);

namespace StrandsPhpClient\Auth;

/**
 * AWS Signature Version 4 authentication strategy.
 *
 * Signs outgoing requests for agents behind API Gateway with IAM auth.
 * Standalone implementation (~200 lines) — does not require aws/aws-sdk-php.
 *
 * @see https://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html
 */
class SigV4Auth implements AuthStrategy
{
    private string $accessKeyId;

    private string $secretAccessKey;

    private string $region;

    private string $service;

    private ?string $sessionToken;

    /**
     * @param string      $accessKeyId      AWS access key ID.
     * @param string      $secretAccessKey   AWS secret access key.
     * @param string      $region            AWS region (e.g. 'us-east-1').
     * @param string      $service           AWS service name (default: 'execute-api').
     * @param string|null $sessionToken      Optional session token for temporary credentials.
     */
    public function __construct(
        string $accessKeyId,
        string $secretAccessKey,
        string $region,
        string $service = 'execute-api',
        ?string $sessionToken = null,
    ) {
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        $this->region = $region;
        $this->service = $service;
        $this->sessionToken = $sessionToken;
    }

    /**
     * Create from environment variables.
     *
     * @param string $region   AWS region.
     * @param string $service  AWS service name (default: 'execute-api').
     *
     * @throws \RuntimeException If required environment variables are missing.
     */
    public static function fromEnvironment(string $region, string $service = 'execute-api'): self
    {
        $accessKeyId = getenv('AWS_ACCESS_KEY_ID');
        $secretAccessKey = getenv('AWS_SECRET_ACCESS_KEY');

        if ($accessKeyId === false || $accessKeyId === '') {
            throw new \RuntimeException('AWS_ACCESS_KEY_ID environment variable is not set');
        }

        if ($secretAccessKey === false || $secretAccessKey === '') {
            throw new \RuntimeException('AWS_SECRET_ACCESS_KEY environment variable is not set');
        }

        $sessionToken = getenv('AWS_SESSION_TOKEN');

        return new self(
            accessKeyId: $accessKeyId,
            secretAccessKey: $secretAccessKey,
            region: $region,
            service: $service,
            sessionToken: $sessionToken !== false && $sessionToken !== '' ? $sessionToken : null,
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public function authenticate(array $headers, string $method, string $url, string $body): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $parsed = parse_url($url);
        /** @var string $hostname */
        $hostname = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? null;
        $scheme = $parsed['scheme'] ?? 'https';

        // Per AWS SigV4 spec, the Host header must include the port
        // if it is not the default for the scheme (80 for http, 443 for https).
        $host = $hostname;
        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $host .= ':' . $port;
        }

        $path = $parsed['path'] ?? '/';
        $queryString = $parsed['query'] ?? '';

        // Canonical URI — normalize path
        $canonicalUri = $this->normalizePath($path);

        // Canonical query string — parameters sorted by key
        $canonicalQueryString = $this->canonicalizeQueryString($queryString);

        // Content hash
        $payloadHash = hash('sha256', $body);

        // Build signed headers
        $signingHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];

        if ($this->sessionToken !== null) {
            $signingHeaders['x-amz-security-token'] = $this->sessionToken;
        }

        // Add content-type if present in original headers
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $signingHeaders['content-type'] = $value;
            }
        }

        // Sort headers by lowercase key
        ksort($signingHeaders);

        $canonicalHeaders = '';
        $signedHeaderNames = [];
        foreach ($signingHeaders as $key => $value) {
            $canonicalHeaders .= $key . ':' . trim($value) . "\n";
            $signedHeaderNames[] = $key;
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        // Canonical request
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        // Credential scope
        $credentialScope = $dateStamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

        // String to sign
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // Signing key
        $signingKey = $this->deriveSigningKey($dateStamp);

        // Signature
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        // Authorization header
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKeyId,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        $headers['Authorization'] = $authorization;
        $headers['X-Amz-Date'] = $amzDate;
        $headers['X-Amz-Content-Sha256'] = $payloadHash;

        if ($this->sessionToken !== null) {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }

        return $headers;
    }

    /**
     * Prevent credential leakage in var_dump/print_r output.
     *
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'accessKeyId' => $this->accessKeyId,
            'secretAccessKey' => '****',
            'region' => $this->region,
            'service' => $this->service,
            'sessionToken' => $this->sessionToken !== null ? '****' : null,
        ];
    }

    /**
     * Derive the SigV4 signing key via a chain of HMAC operations.
     */
    private function deriveSigningKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * Normalize the URI path component per RFC 3986.
     */
    private function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        // URI-encode each path segment, then rejoin
        $segments = explode('/', $path);
        $normalized = [];
        foreach ($segments as $segment) {
            $normalized[] = rawurlencode(rawurldecode($segment));
        }

        return implode('/', $normalized);
    }

    /**
     * Canonicalize the query string: sort by parameter name, URI-encode keys and values.
     */
    private function canonicalizeQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $params = [];
        foreach (explode('&', $queryString) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = rawurlencode(rawurldecode($parts[0]));
            $value = isset($parts[1]) ? rawurlencode(rawurldecode($parts[1])) : '';
            $params[] = $key . '=' . $value;
        }

        sort($params);

        return implode('&', $params);
    }
}
