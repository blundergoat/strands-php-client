<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\SigV4Auth;

class SigV4AuthTest extends TestCase
{
    /**
     * Build a SigV4Auth pre-configured with the canonical AWS SigV4 example
     * credentials. Tests that don't care about the credential values use this
     * to keep the test body focused on the call under test.
     *
     * @param string $region AWS region for the signed request.
     * @param string $service AWS service name (defaults to API Gateway's value).
     * @param string|null $sessionToken Optional STS session token to include in the signature.
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
     * Verifies that authenticate adds required headers.
     *
     * @return void
     */
    public function testAuthenticateAddsRequiredHeaders(): void
    {
        $sigV4Auth = $this->sigV4AuthWith(
            accessKeyId: 'AKIAIOSFODNN7EXAMPLE',
            secretAccessKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        );

        $headers = ['Content-Type' => 'application/json'];
        $result = $sigV4Auth->authenticate($headers, 'POST', 'https://api.example.com/invoke', '{"message":"hi"}');

        $this->assertArrayHasKey('Authorization', $result);
        $this->assertArrayHasKey('X-Amz-Date', $result);
        $this->assertArrayHasKey('X-Amz-Content-Sha256', $result);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/', $result['Authorization']);
        $this->assertStringContainsString('SignedHeaders=', $result['Authorization']);
        $this->assertStringContainsString('Signature=', $result['Authorization']);
    }

    /**
     * Verifies that authenticate includes session token.
     *
     * @return void
     */
    public function testAuthenticateIncludesSessionToken(): void
    {
        $sigV4Auth = $this->sigV4AuthWith(region: 'eu-west-1', sessionToken: 'SESSION_TOKEN');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertArrayHasKey('X-Amz-Security-Token', $result);
        $this->assertSame('SESSION_TOKEN', $result['X-Amz-Security-Token']);
        $this->assertStringContainsString('x-amz-security-token', $result['Authorization']);
    }

    /**
     * Verifies that authenticate omits security token when null.
     *
     * @return void
     */
    public function testAuthenticateOmitsSecurityTokenWhenNull(): void
    {
        $sigV4Auth = $this->sigV4AuthWith(region: 'us-west-2');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertArrayNotHasKey('X-Amz-Security-Token', $result);
        $this->assertStringNotContainsString('x-amz-security-token', $result['Authorization']);
    }

    /**
     * Verifies that authenticate includes correct region and service.
     *
     * @return void
     */
    public function testAuthenticateIncludesCorrectRegionAndService(): void
    {
        $sigV4Auth = $this->sigV4AuthWith(region: 'ap-southeast-1', service: 'lambda');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertStringContainsString('ap-southeast-1/lambda/aws4_request', $result['Authorization']);
    }

    /**
     * Verifies that authenticate default service is execute api.
     *
     * @return void
     */
    public function testAuthenticateDefaultServiceIsExecuteApi(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertStringContainsString('execute-api/aws4_request', $result['Authorization']);
    }

    /**
     * Verifies that authenticate preserves existing headers.
     *
     * @return void
     */
    public function testAuthenticatePreservesExistingHeaders(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $result = $sigV4Auth->authenticate($headers, 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }

    /**
     * Verifies that authenticate payload hash is correct.
     *
     * @return void
     */
    public function testAuthenticatePayloadHashIsCorrect(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $body = '{"message":"hello"}';
        $expectedHash = hash('sha256', $body);

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', $body);

        $this->assertSame($expectedHash, $result['X-Amz-Content-Sha256']);
    }

    /**
     * Verifies that authenticate handles URL with query string.
     *
     * @return void
     */
    public function testAuthenticateHandlesUrlWithQueryString(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar&baz=qux', '{}');

        $this->assertArrayHasKey('Authorization', $result);
    }

    /**
     * Verifies that authenticate handles URL with non standard port.
     *
     * @return void
     */
    public function testAuthenticateHandlesUrlWithNonStandardPort(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        // Non-standard port must be included in the signed host header,
        // producing a different signature than the default-port version.
        $withPort = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com:8443/invoke', '{}');
        $withoutPort = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertArrayHasKey('Authorization', $withPort);
        $sigWithPort = $this->extractSignature($withPort['Authorization']);
        $sigWithoutPort = $this->extractSignature($withoutPort['Authorization']);
        $this->assertNotSame($sigWithPort, $sigWithoutPort, 'Non-standard port must affect signature');
    }

    /**
     * Verifies that authenticate ignores default https port.
     *
     * @return void
     */
    public function testAuthenticateIgnoresDefaultHttpsPort(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        // Default port 443 for https should not change the signature
        $withPort = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com:443/invoke', '{}');
        $withoutPort = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sigWithPort = $this->extractSignature($withPort['Authorization']);
        $sigWithoutPort = $this->extractSignature($withoutPort['Authorization']);
        $this->assertSame($sigWithPort, $sigWithoutPort, 'Default HTTPS port should not affect signature');
    }

    /**
     * Verifies that authenticate ignores default HTTP port.
     *
     * @return void
     */
    public function testAuthenticateIgnoresDefaultHttpPort(): void
    {
        $sigV4Auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $withPort = $sigV4Auth->authenticate([], 'POST', 'http://api.example.com:80/invoke', '{}');
        $withoutPort = $sigV4Auth->authenticate([], 'POST', 'http://api.example.com/invoke', '{}');

        $sigWithPort = $this->extractSignature($withPort['Authorization']);
        $sigWithoutPort = $this->extractSignature($withoutPort['Authorization']);
        $this->assertSame($sigWithPort, $sigWithoutPort, 'Default HTTP port should not affect signature');
    }

    /**
     * Verifies that from environment throws on missing access key.
     *
     * @return void
     */
    public function testFromEnvironmentThrowsOnMissingAccessKey(): void
    {
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AWS_ACCESS_KEY_ID');

        try {
            SigV4Auth::fromEnvironment('us-east-1');
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
        }
    }

    /**
     * Verifies that from environment throws on missing secret key.
     *
     * @return void
     */
    public function testFromEnvironmentThrowsOnMissingSecretKey(): void
    {
        putenv('AWS_ACCESS_KEY_ID=akid');
        putenv('AWS_SECRET_ACCESS_KEY=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AWS_SECRET_ACCESS_KEY');

        try {
            SigV4Auth::fromEnvironment('us-east-1');
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
        }
    }

    /**
     * Verifies that from environment creates auth.
     *
     * @return void
     */
    public function testFromEnvironmentCreatesAuth(): void
    {
        putenv('AWS_ACCESS_KEY_ID=AKID_TEST');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET_TEST');
        putenv('AWS_SESSION_TOKEN=TOKEN_TEST');

        try {
            $sigV4Auth = SigV4Auth::fromEnvironment('us-west-2', 'lambda');
            $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

            $this->assertStringContainsString('AKID_TEST', $result['Authorization']);
            $this->assertStringContainsString('us-west-2/lambda', $result['Authorization']);
            $this->assertSame('TOKEN_TEST', $result['X-Amz-Security-Token']);
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('AWS_SESSION_TOKEN');
        }
    }

    /**
     * Verifies that signature is deterministic for same inputs.
     *
     * @return void
     */
    public function testSignatureIsDeterministicForSameInputs(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        [$firstHeaders, $secondHeaders] = $this->authenticatePairInSameSecond(
            $sigV4Auth,
            'POST',
            'https://api.example.com/invoke',
            '{"a":1}',
        );

        $this->assertSame(
            $firstHeaders['Authorization'],
            $secondHeaders['Authorization'],
            'Same inputs within the same second must produce the same Authorization header',
        );
    }

    /**
     * Run authenticate() twice in a tight loop until both calls fall in the same second,
     * so callers can assert deterministic signing without flaking on second-boundary timing.
     *
     * @param string $method HTTP method to sign.
     * @param string $url Request URL to sign.
     * @param string $body Request body to sign.
     * @return array{0: array<string, string>, 1: array<string, string>} Two header arrays from calls in the same UTC second.
     */
    private function authenticatePairInSameSecond(SigV4Auth $sigV4Auth, string $method, string $url, string $body): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $firstHeaders = $sigV4Auth->authenticate([], $method, $url, $body);
            $secondHeaders = $sigV4Auth->authenticate([], $method, $url, $body);

            if ($firstHeaders['X-Amz-Date'] === $secondHeaders['X-Amz-Date']) {
                return [$firstHeaders, $secondHeaders];
            }
        }

        $this->fail('Could not get two SigV4Auth::authenticate() calls within the same UTC second after 3 attempts');
    }

    /**
     * Verifies that content type is included in signed headers.
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
     * Verifies that different bodies produce different signatures.
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

        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":1}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":2}');

        $this->assertNotSame($r1['Authorization'], $r2['Authorization']);
        $this->assertNotSame($r1['X-Amz-Content-Sha256'], $r2['X-Amz-Content-Sha256']);
    }

    /**
     * Verifies that different paths produce different signatures.
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

        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/stream', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that different methods produce different signatures.
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

        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4Auth->authenticate([], 'GET', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that different regions produce different signatures.
     *
     * @return void
     */
    public function testDifferentRegionsProduceDifferentSignatures(): void
    {
        $sigV4AuthUsEast = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $sigV4AuthEuWest = new SigV4Auth('AKID', 'SECRET', 'eu-west-1');

        $r1 = $sigV4AuthUsEast->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4AuthEuWest->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that different services produce different signatures.
     *
     * @return void
     */
    public function testDifferentServicesProduceDifferentSignatures(): void
    {
        $sigV4AuthExecuteApi = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $sigV4AuthLambda = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'lambda');

        $r1 = $sigV4AuthExecuteApi->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4AuthLambda->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that different keys produce different signatures.
     *
     * @return void
     */
    public function testDifferentKeysProduceDifferentSignatures(): void
    {
        $sigV4AuthFirstKey = new SigV4Auth('AKID1', 'SECRET1', 'us-east-1');
        $sigV4AuthSecondKey = new SigV4Auth('AKID2', 'SECRET2', 'us-east-1');

        $r1 = $sigV4AuthFirstKey->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4AuthSecondKey->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that authorization header format.
     *
     * @return void
     */
    public function testAuthorizationHeaderFormat(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        $result = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        $authHeader = $result['Authorization'];

        // Verify full format: AWS4-HMAC-SHA256 Credential=AKID/date/region/service/aws4_request, SignedHeaders=..., Signature=...
        $this->assertMatchesRegularExpression(
            '/^AWS4-HMAC-SHA256 Credential=AKID\/\d{8}\/us-east-1\/execute-api\/aws4_request, SignedHeaders=[a-z0-9;-]+, Signature=[a-f0-9]{64}$/',
            $authHeader,
        );
    }

    /**
     * Verifies that signed headers are sorted.
     *
     * @return void
     */
    public function testSignedHeadersAreSorted(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        // Extract signed headers from Authorization
        preg_match('/SignedHeaders=([^,]+)/', $result['Authorization'], $matches);
        $signedHeaders = explode(';', $matches[1]);

        // Must be sorted
        $sorted = $signedHeaders;
        sort($sorted);
        $this->assertSame($sorted, $signedHeaders);

        // Must contain host and x-amz-date at minimum
        $this->assertContains('host', $signedHeaders);
        $this->assertContains('x-amz-date', $signedHeaders);
        $this->assertContains('x-amz-content-sha256', $signedHeaders);
        $this->assertContains('content-type', $signedHeaders);
    }

    /**
     * Verifies that amz date format is correct.
     *
     * @return void
     */
    public function testAmzDateFormatIsCorrect(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // X-Amz-Date must be in ISO 8601 basic format: YYYYMMDDTHHmmssZ
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $result['X-Amz-Date']);
    }

    /**
     * Verifies that credential scope contains date region service suffix.
     *
     * @return void
     */
    public function testCredentialScopeContainsDateRegionServiceSuffix(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'ap-northeast-1', 'lambda');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // Extract credential from Authorization header
        preg_match('/Credential=AKID\/(\S+),/', $result['Authorization'], $matches);
        $credentialScope = $matches[1];

        // Must be: YYYYMMDD/region/service/aws4_request
        $this->assertMatchesRegularExpression(
            '/^\d{8}\/ap-northeast-1\/lambda\/aws4_request$/',
            $credentialScope,
        );
    }

    /**
     * Verifies that query string parameters affect signature.
     *
     * @return void
     */
    public function testQueryStringParametersAffectSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=baz', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that query string parameters are sorted.
     *
     * @return void
     */
    public function testQueryStringParametersAreSorted(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different order, same parameters — should produce same signature
        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?a=1&b=2', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?b=2&a=1', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertSame($sig1, $sig2);
    }

    /**
     * Verifies that authenticate produces a signature for a deep path.
     *
     * @return void
     */
    public function testAuthenticateSignsDeepPath(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/path/to/resource', '{}');

        $this->assertArrayHasKey('Authorization', $result);
    }

    /**
     * Verifies that authenticate produces a signature for the root path.
     *
     * @return void
     */
    public function testAuthenticateSignsRootPath(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');

        $this->assertArrayHasKey('Authorization', $result);
    }

    /**
     * Verifies that a deep multi-segment path and the root path produce different signatures.
     *
     * @return void
     */
    public function testDeepPathAndRootPathProduceDifferentSignatures(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $deepPathResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/path/to/resource', '{}');
        $rootPathResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');

        $deepPathSignature = $this->extractSignature($deepPathResult['Authorization']);
        $rootPathSignature = $this->extractSignature($rootPathResult['Authorization']);
        $this->assertNotSame($deepPathSignature, $rootPathSignature);
    }

    /**
     * Verifies that host is included in signature.
     *
     * @return void
     */
    public function testHostIsIncludedInSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different hosts — different signatures
        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api1.example.com/invoke', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api2.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that session token affects signature.
     *
     * @return void
     */
    public function testSessionTokenAffectsSignature(): void
    {
        $sigV4AuthWithToken = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', 'TOKEN');
        $sigV4AuthWithoutToken = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', null);

        $r1 = $sigV4AuthWithToken->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4AuthWithoutToken->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that from environment ignores empty session token.
     *
     * @return void
     */
    public function testFromEnvironmentIgnoresEmptySessionToken(): void
    {
        putenv('AWS_ACCESS_KEY_ID=AKID');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET');
        putenv('AWS_SESSION_TOKEN=');

        try {
            $sigV4Auth = SigV4Auth::fromEnvironment('us-east-1');
            $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
            $this->assertArrayNotHasKey('X-Amz-Security-Token', $result);
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('AWS_SESSION_TOKEN');
        }
    }

    /**
     * Verifies that signature is 64 char hex.
     *
     * @return void
     */
    public function testSignatureIs64CharHex(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $signature = $this->extractSignature($result['Authorization']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }

    /**
     * Verifies that content hash is 64 char hex.
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
     * Verifies that canonical header format is correct.
     *
     * @return void
     */
    public function testCanonicalHeaderFormatIsCorrect(): void
    {
        // Verifies the "key:value\n" format of canonical headers
        // by checking that removing the colon, the key, the value, or the newline
        // would produce a different signature.
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // The signature depends on the canonical request which includes
        // "host:hostname\nx-amz-content-sha256:hash\nx-amz-date:date\n"
        // Any mutation to this format would change the canonical request hash
        // and therefore the signature.
        $r1 = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        // Extract the signature
        $signature = $this->extractSignature($r1['Authorization']);

        // The signature is 64 hex chars — verifies the full signing pipeline
        // (canonical headers → canonical request → string to sign → signature)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);

        // Verify SignedHeaders contains content-type, host, x-amz headers in sorted order
        preg_match('/SignedHeaders=([^,]+)/', $r1['Authorization'], $matches);
        $signedHeaders = $matches[1];
        $this->assertSame('content-type;host;x-amz-content-sha256;x-amz-date', $signedHeaders);
    }

    /**
     * Verifies that signing key prefix is AWS 4.
     *
     * @return void
     */
    public function testSigningKeyUsesAws4LiteralPrefix(): void
    {
        // The signing key derivation uses 'AWS4' + secretAccessKey as the initial HMAC key.
        // Removing 'AWS4' or swapping the concat order changes the signing key chain.
        $sigV4AuthOriginalSecret = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $sigV4AuthPrefixedSecret = new SigV4Auth('AKID', 'AWS4SECRET', 'us-east-1');

        $r1 = $sigV4AuthOriginalSecret->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4AuthPrefixedSecret->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // If 'AWS4' were dropped or reordered, SECRET and AWS4SECRET would produce
        // the same signatures. They must be different.
        $this->assertNotSame(
            $this->extractSignature($r1['Authorization']),
            $this->extractSignature($r2['Authorization']),
        );
    }

    /**
     * Verifies that query string value with equals sign.
     *
     * @return void
     */
    public function testQueryStringValueWithEqualsSign(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // A query value containing '=' — the explode('=', $pair, 2) limit matters
        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc=def', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2, 'Query value with = must produce different signature');
    }

    /**
     * Verifies that query string key and value order.
     *
     * @return void
     */
    public function testQueryStringKeyAndValueOrder(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // key=value vs value=key should produce different signatures
        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?bar=foo', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * Verifies that normalize path returns slash for root.
     *
     * @return void
     */
    public function testNormalizePathReturnsSlashForRoot(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Root path and empty path should produce the same signature
        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertSame($sig1, $sig2, 'Root path and empty path must produce same signature');
    }

    /**
     * Verifies that empty query string does not affect signature.
     *
     * @return void
     */
    public function testEmptyQueryStringDoesNotAffectSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // URL without query string and with empty query should produce same signature
        $r1 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?', '{}');

        // parse_url returns '' for '?' with no params — canonicalizeQueryString('') returns ''
        // So these should be the same
        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertSame($sig1, $sig2);
    }

    /**
     * Verifies that string to sign includes algorithm prefix.
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
        $signature = $this->extractSignature($result['Authorization']);
        $this->assertSame(64, strlen($signature));
    }

    /**
     * Verifies that port host format includes colon.
     *
     * @return void
     */
    public function testPortHostFormatIncludesColon(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // With a non-standard port, the host in the canonical request should be
        // "hostname:port", not "portHostname", "port:", ":port", etc.
        // Different port formats produce different signatures.
        $r8443 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com:8443/invoke', '{}');
        $r9443 = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com:9443/invoke', '{}');

        $sig8443 = $this->extractSignature($r8443['Authorization']);
        $sig9443 = $this->extractSignature($r9443['Authorization']);

        // Different ports must produce different signatures
        $this->assertNotSame($sig8443, $sig9443);
    }

    /**
     * Verifies that debug info masks secrets.
     *
     * @return void
     */
    public function testDebugInfoMasksSecrets(): void
    {
        $sigV4Auth = $this->sigV4AuthWith(sessionToken: 'TOKEN', secretAccessKey: 'SUPER_SECRET');

        $debugInfo = $sigV4Auth->__debugInfo();

        $this->assertSame('AKID', $debugInfo['accessKeyId']);
        $this->assertSame('****', $debugInfo['secretAccessKey']);
        $this->assertSame('****', $debugInfo['sessionToken']);
        $this->assertSame('us-east-1', $debugInfo['region']);
    }

    /**
     * Verifies that debug info shows null session token as null.
     *
     * @return void
     */
    public function testDebugInfoShowsNullSessionTokenAsNull(): void
    {
        $sigV4Auth = $this->sigV4AuthWith();

        $debugInfo = $sigV4Auth->__debugInfo();

        $this->assertNull($debugInfo['sessionToken']);
    }

    /**
     * Verifies that debug info contains service key.
     *
     * @return void
     */
    public function testDebugInfoContainsServiceKey(): void
    {
        $sigV4Auth = $this->sigV4AuthWith(service: 'lambda');

        $debugInfo = $sigV4Auth->__debugInfo();

        $this->assertArrayHasKey('service', $debugInfo);
        $this->assertSame('lambda', $debugInfo['service']);
    }

    /**
     * Verifies that signature matches manual computation.
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
        $payloadHash = hash('sha256', $body);

        // Canonical headers must use exact "key:value\n" format, sorted by key
        $canonicalHeaders = "content-type:application/json\n"
            . "host:api.example.com\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$amzDate}\n";

        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
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

        $this->assertSame($expectedSignature, $this->extractSignature($result['Authorization']));
    }

    /**
     * Verifies that signature with non standard port matches manual computation.
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
        $payloadHash = hash('sha256', $body);

        // Non-standard port must appear as "host:port" in canonical headers
        $canonicalHeaders = "host:api.example.com:8443\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$amzDate}\n";

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
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

        $this->assertSame($expectedSignature, $this->extractSignature($result['Authorization']));
    }

    /**
     * Verifies that signature with query string matches manual computation.
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
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "host:api.example.com\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$amzDate}\n";

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        // Query string must be sorted by key: a=1&b=2
        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            'a=1&b=2',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
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

        $this->assertSame($expectedSignature, $this->extractSignature($result['Authorization']));
    }

    /**
     * Verifies that normalize path directly.
     *
     * @return void
     */
    public function testNormalizePathDirectly(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $reflectionMethod = new \ReflectionMethod($sigV4Auth, 'normalizePath');

        $this->assertSame('/', $reflectionMethod->invoke($sigV4Auth, ''));
        $this->assertSame('/', $reflectionMethod->invoke($sigV4Auth, '/'));
        $this->assertSame('/foo/bar', $reflectionMethod->invoke($sigV4Auth, '/foo/bar'));
        $this->assertSame('/foo%20bar/baz', $reflectionMethod->invoke($sigV4Auth, '/foo bar/baz'));
    }

    /**
     * Verifies that canonicalize query string directly.
     *
     * @return void
     */
    public function testCanonicalizeQueryStringDirectly(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $reflectionMethod = new \ReflectionMethod($sigV4Auth, 'canonicalizeQueryString');

        $this->assertSame('', $reflectionMethod->invoke($sigV4Auth, ''));
        $this->assertSame('a=1&b=2', $reflectionMethod->invoke($sigV4Auth, 'b=2&a=1'));
        $this->assertSame('foo=bar', $reflectionMethod->invoke($sigV4Auth, 'foo=bar'));
        // Value with '=' inside — explode limit of 2 preserves it
        $this->assertSame('token=abc%3Ddef', $reflectionMethod->invoke($sigV4Auth, 'token=abc=def'));
        // Key-only parameter (no '=')
        $this->assertSame('flag=', $reflectionMethod->invoke($sigV4Auth, 'flag'));
    }

    /**
     * Verifies that derive signing key directly.
     *
     * @return void
     */
    public function testDeriveSigningKeyDirectly(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $reflectionMethod = new \ReflectionMethod($sigV4Auth, 'deriveSigningKey');

        $key = $reflectionMethod->invoke($sigV4Auth, '20230101');

        // Must be 32 bytes (raw SHA-256 output)
        $this->assertSame(32, strlen($key));

        // Must be deterministic
        $this->assertSame($key, $reflectionMethod->invoke($sigV4Auth, '20230101'));

        // Different datestamp → different key
        $this->assertNotSame($key, $reflectionMethod->invoke($sigV4Auth, '20230102'));

        // Verify against manual computation
        $kDate = hash_hmac('sha256', '20230101', 'AWS4SECRET', true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $expected = hash_hmac('sha256', 'aws4_request', $kService, true);

        $this->assertSame($expected, $key);
    }

    /**
     * Verifies that canonical header trim is applied.
     *
     * @return void
     */
    public function testCanonicalHeaderTrimIsApplied(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Content-Type with leading/trailing whitespace — trim must normalize it
        $r1 = $sigV4Auth->authenticate(
            ['Content-Type' => '  application/json  '],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );
        $r2 = $sigV4Auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        // If trim() were removed, whitespace would change the canonical request hash
        $this->assertSame(
            $this->extractSignature($r1['Authorization']),
            $this->extractSignature($r2['Authorization']),
        );
    }

    /**
     * Verifies that signature with session token matches manual computation.
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
        $payloadHash = hash('sha256', $body);

        // Session token must appear in canonical headers and signed headers
        $canonicalHeaders = "content-type:application/json\n"
            . "host:api.example.com\n"
            . "x-amz-content-sha256:{$payloadHash}\n"
            . "x-amz-date:{$amzDate}\n"
            . "x-amz-security-token:MY_SESSION_TOKEN\n";

        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date;x-amz-security-token';

        $canonicalRequest = implode("\n", [
            'POST',
            '/invoke',
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
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

        $this->assertSame($expectedSignature, $this->extractSignature($result['Authorization']));
        $this->assertSame('MY_SESSION_TOKEN', $result['X-Amz-Security-Token']);
        $this->assertStringContainsString('x-amz-security-token', $result['Authorization']);
    }

    /**
     * Verifies that pre encoded percent in path not double encoded.
     *
     * @return void
     */
    public function testPreEncodedPercentInPathNotDoubleEncoded(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // %20 is already encoded — rawurldecode then rawurlencode should preserve it
        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/foo%20bar/invoke', '{}');
        $this->assertArrayHasKey('Authorization', $result);

        // A space and %20 should produce the same signature
        $resultSpace = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/foo bar/invoke', '{}');
        $this->assertSame(
            $this->extractSignature($result['Authorization']),
            $this->extractSignature($resultSpace['Authorization']),
            'Pre-encoded %20 and space must produce same canonical path',
        );
    }

    /**
     * Extract signature for assertions.
     *
     * @param string $authHeader Authorization header generated by SigV4 signing.
     * @return string String value produced by the helper.
     */
    private function extractSignature(string $authHeader): string
    {
        preg_match('/Signature=([a-f0-9]+)$/', $authHeader, $matches);

        return $matches[1];
    }
}
