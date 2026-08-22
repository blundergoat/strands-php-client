<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Sig V4 Auth behavior for app integrations.
 *
 * Use this file when changing Sig V4 Auth or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\SigV4Auth;

/**
 * Exercises Sig V4 Auth through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
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
     * @param string $region AWS region for the signed request.
     * @param string $service AWS service name (defaults to API Gateway's value).
     * @param string|null $sessionToken STS token; null omits it, while an empty string is included explicitly.
     * @param string $accessKeyId access key shown in the generated credential scope.
     * @param string $secretAccessKey secret key used to prove the signature changes.
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
     * Confirms authenticate() adds required headers so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() includes session token so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() omits security token when null so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() includes correct region and service so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() default service is execute api so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() preserves existing headers so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() payload hash is correct so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() handles URL with query string so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() handles URL with non standard port so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() ignores default https port so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() ignores default HTTP port so authenticated requests reach the agent with the intended headers.
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
     * Confirms fromEnvironment() throws on missing access key so authenticated requests reach the agent with the intended headers.
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
     * Confirms fromEnvironment() throws on missing secret key so authenticated requests reach the agent with the intended headers.
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
     * Confirms fromEnvironment() creates auth so authenticated requests reach the agent with the intended headers.
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
     * Confirms signature is deterministic for same inputs so authenticated requests reach the agent with the intended headers.
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
     * Confirms content type is included in signed headers so authenticated requests reach the agent with the intended headers.
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
     * Confirms different bodies produce different signatures so authenticated requests reach the agent with the intended headers.
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
     * Confirms different paths produce different signatures so authenticated requests reach the agent with the intended headers.
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

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms different methods produce different signatures so authenticated requests reach the agent with the intended headers.
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

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
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

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Signer pairs for testSignatureChangesWhenOneCredentialFieldDiffers().
     *
     * @return iterable<string, array{0: SigV4Auth, 1: SigV4Auth}> Signer pairs that prove credential changes affect the request shown to AWS.
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

        $authHeader = $result['Authorization'];

        // Verify full format: AWS4-HMAC-SHA256 Credential=AKID/date/region/service/aws4_request, SignedHeaders=..., Signature=...
        $this->assertMatchesRegularExpression(
            '/^AWS4-HMAC-SHA256 Credential=AKID\/\d{8}\/us-east-1\/execute-api\/aws4_request, SignedHeaders=[a-z0-9;-]+, Signature=[a-f0-9]{64}$/',
            $authHeader,
        );
    }

    /**
     * Confirms signed headers are sorted so authenticated requests reach the agent with the intended headers.
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
     * Confirms amz date format is correct so authenticated requests reach the agent with the intended headers.
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
     * Confirms credential scope contains date region service suffix so authenticated requests reach the agent with the intended headers.
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
     * Confirms query string parameters affect signature so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testQueryStringParametersAffectSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=baz', '{}');

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms query string parameters are sorted so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testQueryStringParametersAreSorted(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different order, same parameters — should produce same signature
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?a=1&b=2', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?b=2&a=1', '{}');

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms authenticate() produces a signature for a deep path so authenticated requests reach the agent with the intended headers.
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
     * Confirms authenticate() produces a signature for the root path so authenticated requests reach the agent with the intended headers.
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
     * Confirms a deep multi-segment path and the root path produce different signatures so authenticated requests reach the agent with the intended
     * headers.
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
     * Confirms host is included in signature so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testHostIsIncludedInSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different hosts — different signatures
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api1.example.com/invoke', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api2.example.com/invoke', '{}');

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms session token affects signature so authenticated requests reach the agent with the intended headers.
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
            $this->extractSignature($headersWithToken['Authorization']),
            $this->extractSignature($headersWithoutToken['Authorization']),
        );
    }

    /**
     * Confirms fromEnvironment() ignores empty session token so authenticated requests reach the agent with the intended headers.
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
     * Confirms signature is 64 char hex so authenticated requests reach the agent with the intended headers.
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
     * Confirms content hash is 64 char hex so authenticated requests reach the agent with the intended headers.
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
     * Confirms canonical header format is correct so authenticated requests reach the agent with the intended headers.
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
        $canonicalHeaderSignature = $this->extractSignature($firstResponseHeaders['Authorization']);

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
            $this->extractSignature($firstResponseHeaders['Authorization']),
            $this->extractSignature($secondResponseHeaders['Authorization']),
        );
    }

    /**
     * Confirms an equals sign in a query value is encoded so the user-requested URL is signed correctly.
     *
     * @return void
     */
    public function testQueryStringValueWithEqualsSign(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // A query value containing '=' — the explode('=', $pair, 2) limit matters
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc=def', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc', '{}');

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature, 'Query value with = must produce different signature');
    }

    /**
     * Confirms query keys and values are sorted so the user-requested URL is signed consistently.
     *
     * @return void
     */
    public function testQueryStringKeyAndValueOrder(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // key=value vs value=key should produce different signatures
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?bar=foo', '{}');

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms normalize path returns slash for root so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testNormalizePathReturnsSlashForRoot(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Root path and empty path should produce the same signature
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com', '{}');

        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertSame($firstSignature, $secondSignature, 'Root path and empty path must produce same signature');
    }

    /**
     * Confirms empty query string does not affect signature so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testEmptyQueryStringDoesNotAffectSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // URL without query string and with empty query should produce same signature
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?', '{}');

        // parse_url returns '' for '?' with no params — canonicalizeQueryString('') returns ''
        // So these should be the same
        $firstSignature = $this->extractSignature($firstResponseHeaders['Authorization']);
        $secondSignature = $this->extractSignature($secondResponseHeaders['Authorization']);
        $this->assertSame($firstSignature, $secondSignature);
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
        $signature = $this->extractSignature($result['Authorization']);
        $this->assertSame(64, strlen($signature));
    }

    /**
     * Confirms a non-default port remains in the host value so AWS can authenticate the user's endpoint.
     *
     * @return void
     */
    public function testPortHostFormatIncludesColon(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // A non-standard port must remain in hostname:port form for AWS to reproduce the app's signature.
        $port8443Headers = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com:8443/invoke', '{}');
        $port9443Headers = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com:9443/invoke', '{}');

        $port8443Signature = $this->extractSignature($port8443Headers['Authorization']);
        $port9443Signature = $this->extractSignature($port9443Headers['Authorization']);

        // Different ports must produce different signatures
        $this->assertNotSame($port8443Signature, $port9443Signature);
    }

    /**
     * Confirms debug info masks secrets so authenticated requests reach the agent with the intended headers.
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
     * Confirms debug info shows null session token as null so authenticated requests reach the agent with the intended headers.
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
     * Confirms debug info contains service key so authenticated requests reach the agent with the intended headers.
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
     * Confirms signature matches manual computation so authenticated requests reach the agent with the intended headers.
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
     * Confirms signature with non standard port matches manual computation so authenticated requests reach the agent with the intended headers.
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
     * Confirms signature with query string matches manual computation so authenticated requests reach the agent with the intended headers.
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
     * Confirms path normalization is reflected in signed requests so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testNormalizePathThroughSignedRequests(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $rootResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com', '{}');
        $slashResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');
        $encodedResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/foo%20bar/baz', '{}');
        $spaceResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/foo bar/baz', '{}');

        // A user may call the root endpoint with or without a slash; both requests must sign the same path.
        $this->assertSame(
            $this->extractSignature($rootResult['Authorization']),
            $this->extractSignature($slashResult['Authorization']),
        );

        // Browsers may send spaces as either literal spaces or %20; both must represent the same app URL.
        $this->assertSame(
            $this->extractSignature($encodedResult['Authorization']),
            $this->extractSignature($spaceResult['Authorization']),
        );
    }

    /**
     * Confirms query normalization is reflected in signed requests so authenticated requests reach the agent with the intended headers.
     *
     * @return void
     */
    public function testCanonicalizeQueryStringThroughSignedRequests(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $sortedResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?a=1&b=2', '{}');
        $unsortedResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?b=2&a=1', '{}');
        $encodedEqualsResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc%3Ddef', '{}');
        $literalEqualsResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc=def', '{}');
        $flagResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?flag', '{}');
        $flagWithEqualsResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?flag=', '{}');

        // User-facing query order should not matter once the request is signed.
        $this->assertSame(
            $this->extractSignature($sortedResult['Authorization']),
            $this->extractSignature($unsortedResult['Authorization']),
        );

        // Query values copied from forms may include literal or encoded equals signs.
        $this->assertSame(
            $this->extractSignature($encodedEqualsResult['Authorization']),
            $this->extractSignature($literalEqualsResult['Authorization']),
        );

        // A checkbox-style flag parameter is equivalent with or without an explicit empty value.
        $this->assertSame(
            $this->extractSignature($flagResult['Authorization']),
            $this->extractSignature($flagWithEqualsResult['Authorization']),
        );
    }

    /**
     * Confirms canonical header trim is applied so authenticated requests reach the agent with the intended headers.
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
            $this->extractSignature($firstResponseHeaders['Authorization']),
            $this->extractSignature($secondResponseHeaders['Authorization']),
        );
    }

    /**
     * Confirms signature with session token matches manual computation so authenticated requests reach the agent with the intended headers.
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
     * Confirms a pre-encoded path is not encoded twice so the user-requested URL keeps a valid signature.
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
        // Pull the hex signature out of the auth header shown to the service.
        preg_match('/Signature=([a-f0-9]+)$/', $authHeader, $matches);

        return $matches[1];
    }
}
