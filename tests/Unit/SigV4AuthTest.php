<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\SigV4Auth;

/**
 * Verifies SigV4 builds the headers AWS expects from application credentials and request data.
 *
 * Use these tests when changing credential sources, header handling, or signature timing.
 * They protect authenticated agent calls while keeping secrets out of diagnostics.
 */
class SigV4AuthTest extends TestCase
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
     * Confirms authenticate() adds required headers so AWS can verify the request without changing caller data.
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
     * Confirms authenticate() signs and forwards a session token so temporary AWS credentials remain valid.
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
     * Confirms authenticate() omits a null session token so long-lived credentials do not send an empty header.
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
     * Confirms authenticate() includes correct region and service so AWS can verify the request without changing caller data.
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
     * Confirms authenticate() defaults to execute-api so callers can sign API Gateway requests with minimal setup.
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
     * Confirms authenticate() preserves existing headers so AWS can verify the request without changing caller data.
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
     * Confirms authenticate() sends the correct payload digest so AWS verifies the exact caller body.
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
        $expectedPayloadDigest = hash('sha256', $body);

        $result = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', $body);

        $this->assertSame($expectedPayloadDigest, $result['X-Amz-Content-Sha256']);
    }

    /**
     * Confirms authenticate() handles URL with query string so AWS can verify the request without changing caller data.
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
     * Confirms authenticate() includes a non-standard port so AWS verifies the caller's actual endpoint.
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
        $sigWithPort = $this->signatureFromAuthorizationHeader($withPort['Authorization']);
        $sigWithoutPort = $this->signatureFromAuthorizationHeader($withoutPort['Authorization']);
        $this->assertNotSame($sigWithPort, $sigWithoutPort, 'Non-standard port must affect signature');
    }

    /**
     * Confirms authenticate() omits the default HTTPS port from canonical host data.
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

        $sigWithPort = $this->signatureFromAuthorizationHeader($withPort['Authorization']);
        $sigWithoutPort = $this->signatureFromAuthorizationHeader($withoutPort['Authorization']);
        $this->assertSame($sigWithPort, $sigWithoutPort, 'Default HTTPS port should not affect signature');
    }

    /**
     * Confirms authenticate() ignores default HTTP port so AWS can verify the request without changing caller data.
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

        $sigWithPort = $this->signatureFromAuthorizationHeader($withPort['Authorization']);
        $sigWithoutPort = $this->signatureFromAuthorizationHeader($withoutPort['Authorization']);
        $this->assertSame($sigWithPort, $sigWithoutPort, 'Default HTTP port should not affect signature');
    }

    /**
     * Confirms fromEnvironment() rejects a missing access key before an application sends a request.
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
     * Confirms fromEnvironment() rejects a missing signing secret before an application sends a request.
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
     * Confirms fromEnvironment() builds authentication so deployments can load AWS settings without hard-coding them.
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
     * Confirms identical requests in one signing second receive the same signature.
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
     * @param string $method Non-empty HTTP method to sign.
     * @param string $url Non-empty request URL to sign.
     * @param string $body Request body to sign; empty models a request with no content.
     * @param SigV4Auth $sigV4Auth Signer used to build the outgoing auth header.
     * @return array{0: array<string, string>, 1: array<string, string>} Non-empty pair of signed header maps from one UTC second.
     */
    private function authenticatePairInSameSecond(SigV4Auth $sigV4Auth, string $method, string $url, string $body): array
    {
        // Retry around a UTC second boundary so the test compares headers produced for the same request time.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $firstHeaders = $sigV4Auth->authenticate([], $method, $url, $body);
            $secondHeaders = $sigV4Auth->authenticate([], $method, $url, $body);

            // Matching timestamps prove identical app requests produce a deterministic authorization header.
            if ($firstHeaders['X-Amz-Date'] === $secondHeaders['X-Amz-Date']) {
                return [$firstHeaders, $secondHeaders];
            }
        }

        $this->fail('Could not get two SigV4Auth::authenticate() calls within the same UTC second after 3 attempts');
    }

    /**
     * Confirms the authorization header follows the AWS format so authenticated requests reach the agent.
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

        $authorizationHeader = $result['Authorization'];

        // Verify full format: AWS4-HMAC-SHA256 Credential=AKID/date/region/service/aws4_request, SignedHeaders=..., Signature=...
        $this->assertMatchesRegularExpression(
            '/^AWS4-HMAC-SHA256 Credential=AKID\/\d{8}\/us-east-1\/execute-api\/aws4_request, SignedHeaders=[a-z0-9;-]+, Signature=[a-f0-9]{64}$/',
            $authorizationHeader,
        );
    }

    /**
     * Confirms signed headers are sorted so AWS can verify the request without changing caller data.
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
     * Confirms X-Amz-Date uses the UTC format AWS expects for caller requests.
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
     * Confirms credential scope carries the signing date, region, service, and AWS suffix.
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
     * Confirms host is included in signature so AWS can verify the request without changing caller data.
     *
     * @return void
     */
    public function testHostIsIncludedInSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different hosts — different signatures
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api1.example.com/invoke', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api2.example.com/invoke', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms session token affects signature so AWS can verify the request without changing caller data.
     *
     * @return void
     */
    public function testSessionTokenAffectsSignature(): void
    {
        $sigV4AuthWithToken = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', 'TOKEN');
        $sigV4AuthWithoutToken = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', null);

        $headersWithToken = $sigV4AuthWithToken->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $headersWithoutToken = $sigV4AuthWithoutToken->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // A session token adds X-Amz-Security-Token and changes the signed-header list.
        // Keeping this separate names the temporary-credential behavior an app relies on.
        $this->assertSame('TOKEN', $headersWithToken['X-Amz-Security-Token'] ?? null);
        $this->assertArrayNotHasKey('X-Amz-Security-Token', $headersWithoutToken);
        $this->assertNotSame(
            $this->signatureFromAuthorizationHeader($headersWithToken['Authorization']),
            $this->signatureFromAuthorizationHeader($headersWithoutToken['Authorization']),
        );
    }

    /**
     * Confirms fromEnvironment() treats an empty session token as absent instead of sending an unusable header.
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
     * Confirms debug output hides credentials while leaving safe authentication settings visible to operators.
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
     * Confirms debug output represents an absent session token as null without inventing a credential.
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
     * Confirms debug output names the AWS service so operators can diagnose a signing target safely.
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
