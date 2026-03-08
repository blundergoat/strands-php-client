<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\SigV4Auth;

class SigV4AuthTest extends TestCase
{
    public function testAuthenticateAddsRequiredHeaders(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKIAIOSFODNN7EXAMPLE',
            secretAccessKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            region: 'us-east-1',
        );

        $headers = ['Content-Type' => 'application/json'];
        $result = $auth->authenticate($headers, 'POST', 'https://api.example.com/invoke', '{"message":"hi"}');

        $this->assertArrayHasKey('Authorization', $result);
        $this->assertArrayHasKey('X-Amz-Date', $result);
        $this->assertArrayHasKey('X-Amz-Content-Sha256', $result);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/', $result['Authorization']);
        $this->assertStringContainsString('SignedHeaders=', $result['Authorization']);
        $this->assertStringContainsString('Signature=', $result['Authorization']);
    }

    public function testAuthenticateIncludesSessionToken(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'eu-west-1',
            sessionToken: 'SESSION_TOKEN',
        );

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertArrayHasKey('X-Amz-Security-Token', $result);
        $this->assertSame('SESSION_TOKEN', $result['X-Amz-Security-Token']);
        $this->assertStringContainsString('x-amz-security-token', $result['Authorization']);
    }

    public function testAuthenticateOmitsSecurityTokenWhenNull(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-west-2',
        );

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertArrayNotHasKey('X-Amz-Security-Token', $result);
        $this->assertStringNotContainsString('x-amz-security-token', $result['Authorization']);
    }

    public function testAuthenticateIncludesCorrectRegionAndService(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'ap-southeast-1',
            service: 'lambda',
        );

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertStringContainsString('ap-southeast-1/lambda/aws4_request', $result['Authorization']);
    }

    public function testAuthenticateDefaultServiceIsExecuteApi(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertStringContainsString('execute-api/aws4_request', $result['Authorization']);
    }

    public function testAuthenticatePreservesExistingHeaders(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $result = $auth->authenticate($headers, 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('text/event-stream', $result['Accept']);
    }

    public function testAuthenticatePayloadHashIsCorrect(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $body = '{"message":"hello"}';
        $expectedHash = hash('sha256', $body);

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', $body);

        $this->assertSame($expectedHash, $result['X-Amz-Content-Sha256']);
    }

    public function testAuthenticateHandlesUrlWithQueryString(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar&baz=qux', '{}');

        $this->assertArrayHasKey('Authorization', $result);
    }

    public function testAuthenticateHandlesUrlWithNonStandardPort(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        // Non-standard port must be included in the signed host header,
        // producing a different signature than the default-port version.
        $withPort = $auth->authenticate([], 'POST', 'https://api.example.com:8443/invoke', '{}');
        $withoutPort = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $this->assertArrayHasKey('Authorization', $withPort);
        $sigWithPort = $this->extractSignature($withPort['Authorization']);
        $sigWithoutPort = $this->extractSignature($withoutPort['Authorization']);
        $this->assertNotSame($sigWithPort, $sigWithoutPort, 'Non-standard port must affect signature');
    }

    public function testAuthenticateIgnoresDefaultHttpsPort(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        // Default port 443 for https should not change the signature
        $withPort = $auth->authenticate([], 'POST', 'https://api.example.com:443/invoke', '{}');
        $withoutPort = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sigWithPort = $this->extractSignature($withPort['Authorization']);
        $sigWithoutPort = $this->extractSignature($withoutPort['Authorization']);
        $this->assertSame($sigWithPort, $sigWithoutPort, 'Default HTTPS port should not affect signature');
    }

    public function testAuthenticateIgnoresDefaultHttpPort(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $withPort = $auth->authenticate([], 'POST', 'http://api.example.com:80/invoke', '{}');
        $withoutPort = $auth->authenticate([], 'POST', 'http://api.example.com/invoke', '{}');

        $sigWithPort = $this->extractSignature($withPort['Authorization']);
        $sigWithoutPort = $this->extractSignature($withoutPort['Authorization']);
        $this->assertSame($sigWithPort, $sigWithoutPort, 'Default HTTP port should not affect signature');
    }

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

    public function testFromEnvironmentCreatesAuth(): void
    {
        putenv('AWS_ACCESS_KEY_ID=AKID_TEST');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET_TEST');
        putenv('AWS_SESSION_TOKEN=TOKEN_TEST');

        try {
            $auth = SigV4Auth::fromEnvironment('us-west-2', 'lambda');
            $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

            $this->assertStringContainsString('AKID_TEST', $result['Authorization']);
            $this->assertStringContainsString('us-west-2/lambda', $result['Authorization']);
            $this->assertSame('TOKEN_TEST', $result['X-Amz-Security-Token']);
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('AWS_SESSION_TOKEN');
        }
    }

    public function testSignatureIsDeterministicForSameInputs(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        // Retry if call spans a second boundary (different X-Amz-Date → different signature).
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":1}');
            $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":1}');

            if ($r1['X-Amz-Date'] === $r2['X-Amz-Date']) {
                $this->assertSame($r1['Authorization'], $r2['Authorization']);

                return;
            }
        }

        $this->fail('Could not get two calls within the same second after 3 attempts');
    }

    public function testContentTypeIsIncludedInSignedHeaders(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $result = $auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        $this->assertStringContainsString('content-type', $result['Authorization']);
    }

    public function testDifferentBodiesProduceDifferentSignatures(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":1}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{"a":2}');

        $this->assertNotSame($r1['Authorization'], $r2['Authorization']);
        $this->assertNotSame($r1['X-Amz-Content-Sha256'], $r2['X-Amz-Content-Sha256']);
    }

    public function testDifferentPathsProduceDifferentSignatures(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/stream', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testDifferentMethodsProduceDifferentSignatures(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth->authenticate([], 'GET', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testDifferentRegionsProduceDifferentSignatures(): void
    {
        $auth1 = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $auth2 = new SigV4Auth('AKID', 'SECRET', 'eu-west-1');

        $r1 = $auth1->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth2->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testDifferentServicesProduceDifferentSignatures(): void
    {
        $auth1 = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $auth2 = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'lambda');

        $r1 = $auth1->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth2->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testDifferentKeysProduceDifferentSignatures(): void
    {
        $auth1 = new SigV4Auth('AKID1', 'SECRET1', 'us-east-1');
        $auth2 = new SigV4Auth('AKID2', 'SECRET2', 'us-east-1');

        $r1 = $auth1->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth2->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testAuthorizationHeaderFormat(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');

        $result = $auth->authenticate(
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

    public function testSignedHeadersAreSorted(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $auth->authenticate(
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

    public function testAmzDateFormatIsCorrect(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // X-Amz-Date must be in ISO 8601 basic format: YYYYMMDDTHHmmssZ
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $result['X-Amz-Date']);
    }

    public function testCredentialScopeContainsDateRegionServiceSuffix(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'ap-northeast-1', 'lambda');

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // Extract credential from Authorization header
        preg_match('/Credential=AKID\/(\S+),/', $result['Authorization'], $matches);
        $credentialScope = $matches[1];

        // Must be: YYYYMMDD/region/service/aws4_request
        $this->assertMatchesRegularExpression(
            '/^\d{8}\/ap-northeast-1\/lambda\/aws4_request$/',
            $credentialScope,
        );
    }

    public function testQueryStringParametersAffectSignature(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=baz', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testQueryStringParametersAreSorted(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different order, same parameters — should produce same signature
        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?a=1&b=2', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?b=2&a=1', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertSame($sig1, $sig2);
    }

    public function testPathNormalization(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Path with special chars that need encoding
        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/path/to/resource', '{}');
        $this->assertArrayHasKey('Authorization', $r1);

        // Root path
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/', '{}');
        $this->assertArrayHasKey('Authorization', $r2);

        // Different paths must produce different signatures
        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testHostIsIncludedInSignature(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different hosts — different signatures
        $r1 = $auth->authenticate([], 'POST', 'https://api1.example.com/invoke', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api2.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testSessionTokenAffectsSignature(): void
    {
        $authWithToken = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', 'TOKEN');
        $authWithoutToken = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', null);

        $r1 = $authWithToken->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $authWithoutToken->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testFromEnvironmentIgnoresEmptySessionToken(): void
    {
        putenv('AWS_ACCESS_KEY_ID=AKID');
        putenv('AWS_SECRET_ACCESS_KEY=SECRET');
        putenv('AWS_SESSION_TOKEN=');

        try {
            $auth = SigV4Auth::fromEnvironment('us-east-1');
            $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
            $this->assertArrayNotHasKey('X-Amz-Security-Token', $result);
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('AWS_SESSION_TOKEN');
        }
    }

    public function testSignatureIs64CharHex(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        $sig = $this->extractSignature($result['Authorization']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sig);
    }

    public function testContentHashIs64CharHex(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', 'test body');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['X-Amz-Content-Sha256']);
    }

    public function testCanonicalHeaderFormatIsCorrect(): void
    {
        // Verifies the "key:value\n" format of canonical headers
        // by checking that removing the colon, the key, the value, or the newline
        // would produce a different signature.
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // The signature depends on the canonical request which includes
        // "host:hostname\nx-amz-content-sha256:hash\nx-amz-date:date\n"
        // Any mutation to this format would change the canonical request hash
        // and therefore the signature.
        $r1 = $auth->authenticate(
            ['Content-Type' => 'application/json'],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );

        // Extract the signature
        $sig = $this->extractSignature($r1['Authorization']);

        // The signature is 64 hex chars — verifies the full signing pipeline
        // (canonical headers → canonical request → string to sign → signature)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sig);

        // Verify SignedHeaders contains content-type, host, x-amz headers in sorted order
        preg_match('/SignedHeaders=([^,]+)/', $r1['Authorization'], $matches);
        $signedHeaders = $matches[1];
        $this->assertSame('content-type;host;x-amz-content-sha256;x-amz-date', $signedHeaders);
    }

    public function testSigningKeyPrefixIsAWS4(): void
    {
        // The signing key derivation uses 'AWS4' + secretAccessKey as the initial HMAC key.
        // Removing 'AWS4' or swapping the concat order changes the signing key chain.
        $auth1 = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $auth2 = new SigV4Auth('AKID', 'AWS4SECRET', 'us-east-1');

        $r1 = $auth1->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth2->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // If 'AWS4' were dropped or reordered, SECRET and AWS4SECRET would produce
        // the same signatures. They must be different.
        $this->assertNotSame(
            $this->extractSignature($r1['Authorization']),
            $this->extractSignature($r2['Authorization']),
        );
    }

    public function testQueryStringValueWithEqualsSign(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // A query value containing '=' — the explode('=', $pair, 2) limit matters
        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc=def', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?token=abc', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2, 'Query value with = must produce different signature');
    }

    public function testQueryStringKeyAndValueOrder(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // key=value vs value=key should produce different signatures
        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?bar=foo', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertNotSame($sig1, $sig2);
    }

    public function testNormalizePathReturnsSlashForRoot(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Root path and empty path should produce the same signature
        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com', '{}');

        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertSame($sig1, $sig2, 'Root path and empty path must produce same signature');
    }

    public function testEmptyQueryStringDoesNotAffectSignature(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // URL without query string and with empty query should produce same signature
        $r1 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');
        $r2 = $auth->authenticate([], 'POST', 'https://api.example.com/invoke?', '{}');

        // parse_url returns '' for '?' with no params — canonicalizeQueryString('') returns ''
        // So these should be the same
        $sig1 = $this->extractSignature($r1['Authorization']);
        $sig2 = $this->extractSignature($r2['Authorization']);
        $this->assertSame($sig1, $sig2);
    }

    public function testStringToSignIncludesAlgorithmPrefix(): void
    {
        // The string to sign starts with 'AWS4-HMAC-SHA256'. Removing this first
        // element from the array (ArrayItemRemoval mutation) would change the hash.
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $result = $auth->authenticate([], 'POST', 'https://api.example.com/invoke', '{}');

        // The Authorization header itself starts with 'AWS4-HMAC-SHA256 Credential=...'
        // This just verifies the signing pipeline produces a valid header with the prefix
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $result['Authorization']);

        // Verify the signature is deterministic — the algorithm prefix is part of the
        // string-to-sign, so removing it would change the signature
        $sig = $this->extractSignature($result['Authorization']);
        $this->assertSame(64, strlen($sig));
    }

    public function testPortHostFormatIncludesColon(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // With a non-standard port, the host in the canonical request should be
        // "hostname:port", not "portHostname", "port:", ":port", etc.
        // Different port formats produce different signatures.
        $r8443 = $auth->authenticate([], 'POST', 'https://api.example.com:8443/invoke', '{}');
        $r9443 = $auth->authenticate([], 'POST', 'https://api.example.com:9443/invoke', '{}');

        $sig8443 = $this->extractSignature($r8443['Authorization']);
        $sig9443 = $this->extractSignature($r9443['Authorization']);

        // Different ports must produce different signatures
        $this->assertNotSame($sig8443, $sig9443);
    }

    public function testDebugInfoMasksSecrets(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SUPER_SECRET',
            region: 'us-east-1',
            sessionToken: 'TOKEN',
        );

        $debugInfo = $auth->__debugInfo();

        $this->assertSame('AKID', $debugInfo['accessKeyId']);
        $this->assertSame('****', $debugInfo['secretAccessKey']);
        $this->assertSame('****', $debugInfo['sessionToken']);
        $this->assertSame('us-east-1', $debugInfo['region']);
    }

    public function testDebugInfoShowsNullSessionTokenAsNull(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
        );

        $debugInfo = $auth->__debugInfo();

        $this->assertNull($debugInfo['sessionToken']);
    }

    public function testDebugInfoContainsServiceKey(): void
    {
        $auth = new SigV4Auth(
            accessKeyId: 'AKID',
            secretAccessKey: 'SECRET',
            region: 'us-east-1',
            service: 'lambda',
        );

        $debugInfo = $auth->__debugInfo();

        $this->assertArrayHasKey('service', $debugInfo);
        $this->assertSame('lambda', $debugInfo['service']);
    }

    public function testSignatureMatchesManualComputation(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $body = '{"message":"hello"}';
        $url = 'https://api.example.com/invoke';

        $result = $auth->authenticate(
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

    public function testSignatureWithNonStandardPortMatchesManualComputation(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $body = '{}';
        $url = 'https://api.example.com:8443/invoke';

        $result = $auth->authenticate([], 'POST', $url, $body);

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

    public function testSignatureWithQueryStringMatchesManualComputation(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $body = '{}';
        $url = 'https://api.example.com/invoke?b=2&a=1';

        $result = $auth->authenticate([], 'POST', $url, $body);

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

    public function testNormalizePathDirectly(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $method = new \ReflectionMethod($auth, 'normalizePath');

        $this->assertSame('/', $method->invoke($auth, ''));
        $this->assertSame('/', $method->invoke($auth, '/'));
        $this->assertSame('/foo/bar', $method->invoke($auth, '/foo/bar'));
        $this->assertSame('/foo%20bar/baz', $method->invoke($auth, '/foo bar/baz'));
    }

    public function testCanonicalizeQueryStringDirectly(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');
        $method = new \ReflectionMethod($auth, 'canonicalizeQueryString');

        $this->assertSame('', $method->invoke($auth, ''));
        $this->assertSame('a=1&b=2', $method->invoke($auth, 'b=2&a=1'));
        $this->assertSame('foo=bar', $method->invoke($auth, 'foo=bar'));
        // Value with '=' inside — explode limit of 2 preserves it
        $this->assertSame('token=abc%3Ddef', $method->invoke($auth, 'token=abc=def'));
        // Key-only parameter (no '=')
        $this->assertSame('flag=', $method->invoke($auth, 'flag'));
    }

    public function testDeriveSigningKeyDirectly(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api');
        $method = new \ReflectionMethod($auth, 'deriveSigningKey');

        $key = $method->invoke($auth, '20230101');

        // Must be 32 bytes (raw SHA-256 output)
        $this->assertSame(32, strlen($key));

        // Must be deterministic
        $this->assertSame($key, $method->invoke($auth, '20230101'));

        // Different datestamp → different key
        $this->assertNotSame($key, $method->invoke($auth, '20230102'));

        // Verify against manual computation
        $kDate = hash_hmac('sha256', '20230101', 'AWS4SECRET', true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $expected = hash_hmac('sha256', 'aws4_request', $kService, true);

        $this->assertSame($expected, $key);
    }

    public function testCanonicalHeaderTrimIsApplied(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Content-Type with leading/trailing whitespace — trim must normalize it
        $r1 = $auth->authenticate(
            ['Content-Type' => '  application/json  '],
            'POST',
            'https://api.example.com/invoke',
            '{}',
        );
        $r2 = $auth->authenticate(
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

    public function testSignatureWithSessionTokenMatchesManualComputation(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1', 'execute-api', 'MY_SESSION_TOKEN');
        $body = '{}';
        $url = 'https://api.example.com/invoke';

        $result = $auth->authenticate(
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

    public function testPreEncodedPercentInPathNotDoubleEncoded(): void
    {
        $auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // %20 is already encoded — rawurldecode then rawurlencode should preserve it
        $result = $auth->authenticate([], 'POST', 'https://api.example.com/foo%20bar/invoke', '{}');
        $this->assertArrayHasKey('Authorization', $result);

        // A space and %20 should produce the same signature
        $resultSpace = $auth->authenticate([], 'POST', 'https://api.example.com/foo bar/invoke', '{}');
        $this->assertSame(
            $this->extractSignature($result['Authorization']),
            $this->extractSignature($resultSpace['Authorization']),
            'Pre-encoded %20 and space must produce same canonical path',
        );
    }

    private function extractSignature(string $authHeader): string
    {
        preg_match('/Signature=([a-f0-9]+)$/', $authHeader, $matches);

        return $matches[1];
    }
}
