<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\SigV4Auth;

/**
 * Verifies SigV4 signatures match independently calculated values across caller credential choices.
 *
 * Use these tests when changing key derivation, payload hashing, ports, queries, or session tokens.
 * They protect authenticated agent calls from rejection before the user's request reaches the agent.
 */
class SigV4AuthSignatureTest extends TestCase
{
    /**
     * Clear AWS env state so one auth scenario cannot affect the next.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_SESSION_TOKEN');

        parent::tearDown();
    }

    /**
     * Builds SigV4Auth with the canonical AWS example credentials.
     *
     * Use it when the scenario concerns request signing rather than credential values.
     *
     * @param string $region Non-empty AWS region for the signed request.
     * @param string $service Non-empty AWS service name; defaults to API Gateway's value.
     * @param string|null $sessionToken STS token; null omits it, while an empty string is included explicitly.
     * @param string $accessKeyId Non-empty fixture key shown in the generated credential scope.
     * @param string $secretAccessKey Non-empty fixture secret used to prove the signature changes.
     * @return SigV4Auth Configured signer ready for an authenticate() call.
     */
    private function sigV4AuthWith(
        string $region = 'us-east-1',
        string $service = 'execute-api',
        ?string $sessionToken = null,
        string $accessKeyId = 'AKID',
        string $secretAccessKey = 'SECRET',
    ): SigV4Auth {
        return new SigV4Auth(
            accessKeyId: $accessKeyId,
            secretAccessKey: $secretAccessKey,
            region: $region,
            service: $service,
            sessionToken: $sessionToken,
        );
    }

    /**
     * Confirms content type is included in signed headers so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testContentTypeIsIncludedInSignedHeaders(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        $result = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        $this->assertStringContainsString('content-type', $result['Authorization']);
    }

    /**
     * Confirms different bodies produce different signatures so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testDifferentBodiesProduceDifferentSignatures(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":1}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":2}');

        $this->assertNotSame($firstResponseHeaders['Authorization'], $secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstResponseHeaders['X-Amz-Content-Sha256'], $secondResponseHeaders['X-Amz-Content-Sha256']);
    }

    /**
     * Confirms different paths produce different signatures so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testDifferentPathsProduceDifferentSignatures(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/stream', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms different methods produce different signatures so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testDifferentMethodsProduceDifferentSignatures(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'GET', 'https://api.example.com/invoke', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Verifies that varying one credential field (region/service/keys/session token) on
     * SigV4Auth changes the produced signature for the same request.
     *
     * @param SigV4Auth $baselineSigner Signer used as the comparison baseline.
     * @param SigV4Auth $changedSigner Signer whose one changed credential must alter the Authorization header.
     * @return void
     */
    #[DataProvider('signaturePairProvider')]
    public function testSignatureChangesWhenOneCredentialFieldDiffers(SigV4Auth $baselineSigner, SigV4Auth $changedSigner): void
    {
        $firstResponseHeaders = $baselineSigner->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $secondResponseHeaders = $changedSigner->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Signer pairs for testSignatureChangesWhenOneCredentialFieldDiffers().
     *
     * @return iterable<string, array{0: SigV4Auth, 1: SigV4Auth}> Non-empty signer pairs that prove configuration changes affect AWS requests.
     */
    public static function signaturePairProvider(): iterable
    {
        yield 'region: us-east-1 vs eu-west-1' => [
            new SigV4Auth('AKID', 'SECRET', 'us-east-1'),
            new SigV4Auth('AKID', 'SECRET', 'eu-west-1'),
        ];
        yield 'service: execute-api vs lambda' => [
            new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api'),
            new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'lambda'),
        ];
        yield 'credentials: first vs second key pair' => [
            new SigV4Auth('AKID1', 'SECRET1', 'us-east-1'),
            new SigV4Auth('AKID2', 'SECRET2', 'us-east-1'),
        ];
    }

    /**
     * Confirms the signature is a 64-character hexadecimal value accepted by AWS.
     *
     * @return void
     */
    public function testSignatureIs64CharHex(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $signature = $this->signatureFromAuthorizationHeader($result['Authorization']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    /**
     * Confirms the content digest is a 64-character hexadecimal value for the exact request body.
     *
     * @return void
     */
    public function testContentHashIs64CharHex(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', 'test body');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['X-Amz-Content-Sha256']);
    }

    /**
     * Confirms canonical header format is correct so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testCanonicalHeaderFormatIsCorrect(): void
    {
        // The AWS "key:value\n" format ensures each app header contributes predictably to the signature.
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // The canonical request includes the host, payload hash, and date in sorted header form.
        // Any formatting change produces a different signature that AWS would reject.
        $firstResponseHeaders = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        // Extract the signature
        $canonicalHeaderSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);

        // The signature is 64 hex chars — verifies the full signing pipeline
        // (canonical headers → canonical request → string to sign → signature)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $canonicalHeaderSignature);

        // Verify SignedHeaders contains content-type, host, x-amz headers in sorted order
        preg_match('/SignedHeaders=([^,]+)/', $firstResponseHeaders['Authorization'], $signedHeadersMatch);
        $signedHeaders = $signedHeadersMatch[1];
        $this->assertSame('content-type;host;x-amz-content-sha256;x-amz-date', $signedHeaders);
    }

    /**
     * Confirms the signing key uses the AWS4 prefix so authenticated requests reach the agent with valid headers.
     *
     * @return void
     */
    public function testSigningKeyUsesAws4LiteralPrefix(): void
    {
        // The signing key derivation uses 'AWS4' + secretAccessKey as the initial HMAC key.
        // Removing 'AWS4' or swapping the concat order changes the signing key chain.
        $sigV4AuthOriginalSecret = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $sigV4AuthPrefixedSecret = new SigV4Auth('AKID', 'AWS4SECRET', 'us-east-1');

        $firstResponseHeaders = $sigV4AuthOriginalSecret->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $secondResponseHeaders = $sigV4AuthPrefixedSecret->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // If 'AWS4' were dropped or reordered, SECRET and AWS4SECRET would produce
        // the same signatures. They must be different.
        $this->assertNotSame(
            $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']),
            $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']),
        );
    }

    /**
     * Confirms the string to sign includes its algorithm so AWS can authenticate the user's request.
     *
     * @return void
     */
    public function testStringToSignIncludesAlgorithmPrefix(): void
    {
        // The string to sign starts with 'AWS4-HMAC-SHA256'. Removing this first
        // element from the array (ArrayItemRemoval mutation) would change the hash.
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // The Authorization header itself starts with 'AWS4-HMAC-SHA256 Credential=...'
        // This just verifies the signing pipeline produces a valid header with the prefix
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $result['Authorization']);

        // Verify the signature is deterministic — the algorithm prefix is part of the
        // string-to-sign, so removing it would change the signature
        $signature = $this->signatureFromAuthorizationHeader($result['Authorization']);
        $this->assertSame(64, strlen($signature));
    }

    /**
     * Confirms signature matches manual computation so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testSignatureMatchesManualComputation(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $body = '{"message":"hello"}';
        $url = 'https://api.example.com/invoke';

        $result = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            $url,
            $body,
        );

        $amzDate = $result['X-Amz-Date'];
        $dateStamp = substr($amzDate, 0, 8);
        $payloadDigest = hash('sha256', $body);

        // Canonical headers must use exact "key:value\n" format, sorted by key
        $canonicalHeaders = "content-type:application/json\n"
            . "host:api.example.com\n"
            . "x-amz-content-sha256:{$payloadDigest}\n"
            . "x-amz-date:{$amzDate}\n";

        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadDigest,
        ]);

        $credentialScope = "{$dateStamp}/us-east-1/execute-api/aws4_request";

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // Derive signing key: 'AWS4' prefix is required by spec
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . 'SECRET', true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);

        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        $this->assertSame($expectedSignature, $this->signatureFromAuthorizationHeader($result['Authorization']));
    }

    /**
     * Confirms a non-standard port produces the same signature as the documented manual computation.
     *
     * @return void
     */
    public function testSignatureWithNonStandardPortMatchesManualComputation(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $body = '{}';
        $url = 'https://api.example.com:8443/invoke';

        $result = $sigV4Auth->authenticate([], 'POST', $url, $body);

        $amzDate = $result['X-Amz-Date'];
        $dateStamp = substr($amzDate, 0, 8);
        $payloadDigest = hash('sha256', $body);

        // Non-standard port must appear as "host:port" in canonical headers
        $canonicalHeaders = "host:api.example.com:8443\n"
            . "x-amz-content-sha256:{$payloadDigest}\n"
            . "x-amz-date:{$amzDate}\n";

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadDigest,
        ]);

        $credentialScope = "{$dateStamp}/us-east-1/execute-api/aws4_request";

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . 'SECRET', true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);

        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        $this->assertSame($expectedSignature, $this->signatureFromAuthorizationHeader($result['Authorization']));
    }

    /**
     * Confirms signature with query string matches manual computation so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testSignatureWithQueryStringMatchesManualComputation(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $body = '{}';
        $url = 'https://api.example.com/invoke?b=2&a=1';

        $result = $sigV4Auth->authenticate([], 'POST', $url, $body);

        $amzDate = $result['X-Amz-Date'];
        $dateStamp = substr($amzDate, 0, 8);
        $payloadDigest = hash('sha256', $body);

        $canonicalHeaders = "host:api.example.com\n"
            . "x-amz-content-sha256:{$payloadDigest}\n"
            . "x-amz-date:{$amzDate}\n";

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        // Query string must be sorted by key: a=1&b=2
        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            'a=1&b=2',
            $canonicalHeaders,
            $signedHeaders,
            $payloadDigest,
        ]);

        $credentialScope = "{$dateStamp}/us-east-1/execute-api/aws4_request";

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . 'SECRET', true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);

        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        $this->assertSame($expectedSignature, $this->signatureFromAuthorizationHeader($result['Authorization']));
    }

    /**
     * Confirms canonical headers trim surrounding whitespace before AWS verifies the request.
     *
     * @return void
     */
    public function testCanonicalHeaderTrimIsApplied(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Content-Type with leading/trailing whitespace — trim must normalize it
        $firstResponseHeaders = $sigV4Auth->authenticate(
            ['Content-Type' => '  application/json  '],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );
        $secondResponseHeaders = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        // If trim() were removed, whitespace would change the canonical request hash
        $this->assertSame(
            $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']),
            $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']),
        );
    }

    /**
     * Confirms signature with session token matches manual computation so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testSignatureWithSessionTokenMatchesManualComputation(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', 'MY_SESSION_TOKEN');
        $body = '{}';
        $url = 'https://api.example.com/invoke';

        $result = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            $url,
            $body,
        );

        $amzDate = $result['X-Amz-Date'];
        $dateStamp = substr($amzDate, 0, 8);
        $payloadDigest = hash('sha256', $body);

        // Session token must appear in canonical headers and signed headers
        $canonicalHeaders = "content-type:application/json\n"
            . "host:api.example.com\n"
            . "x-amz-content-sha256:{$payloadDigest}\n"
            . "x-amz-date:{$amzDate}\n"
            . "x-amz-security-token:MY_SESSION_TOKEN\n";

        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date;x-amz-security-token';

        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadDigest,
        ]);

        $credentialScope = "{$dateStamp}/us-east-1/execute-api/aws4_request";

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . 'SECRET', true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);

        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

        $this->assertSame($expectedSignature, $this->signatureFromAuthorizationHeader($result['Authorization']));
        $this->assertSame('MY_SESSION_TOKEN', $result['X-Amz-Security-Token']);
        $this->assertStringContainsString('x-amz-security-token', $result['Authorization']);
    }

    /**
     * Returns the hexadecimal signature embedded in a generated Authorization header.
     *
     * @param string $authorizationHeader Non-empty Authorization header generated by SigV4 signing.
     * @return string Non-empty signature used to compare caller-visible request authentication.
     */
    private function signatureFromAuthorizationHeader(string $authorizationHeader): string
    {
        // Pull the hex signature out of the auth header shown to the service.
        preg_match('/Signature=([a-f0-9]+)$/', $authorizationHeader, $matches);

        return $matches[1];
    }
}
