<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StrandsPhpClient\Auth\SigV4Auth;

/**
 * Verifies SigV4 canonical paths, query strings, and hosts match the request an app sends to AWS.
 *
 * Use these tests when changing request normalization or signed-header construction.
 * They protect authenticated agent calls from signature mismatches at gateways and proxies.
 */
class SigV4AuthCanonicalizationTest extends TestCase
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
     * Confirms query string parameters affect signature so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testQueryStringParametersAffectSignature(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=bar', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?foo=baz', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms query string parameters are sorted so AWS can verify the exact request the application sent.
     *
     * @return void
     */
    public function testQueryStringParametersAreSorted(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Different order, same parameters — should produce same signature
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?a=1&b=2', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/invoke?b=2&a=1', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms authenticate() produces a signature for a deep path so AWS can verify the exact request the application sent.
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
     * Confirms authenticate() produces a signature for the root path so AWS can verify the exact request the application sent.
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
     * Confirms deep and root paths produce different signatures so authenticated requests reach the intended agent route.
     *
     * @return void
     */
    public function testDeepPathAndRootPathProduceDifferentSignatures(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        $deepPathResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/path/to/resource', '{}');
        $rootPathResult = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');

        $deepPathSignature = $this->signatureFromAuthorizationHeader($deepPathResult['Authorization']);
        $rootPathSignature = $this->signatureFromAuthorizationHeader($rootPathResult['Authorization']);
        $this->assertNotSame($deepPathSignature, $rootPathSignature);
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

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
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

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Confirms the root path canonicalizes to a slash so AWS and the application sign the same route.
     *
     * @return void
     */
    public function testNormalizePathReturnsSlashForRoot(): void
    {
        $sigV4Auth = new SigV4Auth('AKID', 'SECRET', 'us-east-1');

        // Root path and empty path should produce the same signature
        $firstResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com/', '{}');
        $secondResponseHeaders = $sigV4Auth->authenticate([], 'POST', 'https://api.example.com', '{}');

        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertSame($firstSignature, $secondSignature, 'Root path and empty path must produce same signature');
    }

    /**
     * Confirms an empty query string does not change the signature for the same caller URL.
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
        $firstSignature = $this->signatureFromAuthorizationHeader($firstResponseHeaders['Authorization']);
        $secondSignature = $this->signatureFromAuthorizationHeader($secondResponseHeaders['Authorization']);
        $this->assertSame($firstSignature, $secondSignature);
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

        $port8443Signature = $this->signatureFromAuthorizationHeader($port8443Headers['Authorization']);
        $port9443Signature = $this->signatureFromAuthorizationHeader($port9443Headers['Authorization']);

        // Different ports must produce different signatures
        $this->assertNotSame($port8443Signature, $port9443Signature);
    }

    /**
     * Confirms path normalization is reflected in signed requests so AWS can verify the exact request the application sent.
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
            $this->signatureFromAuthorizationHeader($rootResult['Authorization']),
            $this->signatureFromAuthorizationHeader($slashResult['Authorization']),
        );

        // Browsers may send spaces as either literal spaces or %20; both must represent the same app URL.
        $this->assertSame(
            $this->signatureFromAuthorizationHeader($encodedResult['Authorization']),
            $this->signatureFromAuthorizationHeader($spaceResult['Authorization']),
        );
    }

    /**
     * Confirms query normalization is reflected in signed requests so AWS can verify the exact request the application sent.
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
            $this->signatureFromAuthorizationHeader($sortedResult['Authorization']),
            $this->signatureFromAuthorizationHeader($unsortedResult['Authorization']),
        );

        // Query values copied from forms may include literal or encoded equals signs.
        $this->assertSame(
            $this->signatureFromAuthorizationHeader($encodedEqualsResult['Authorization']),
            $this->signatureFromAuthorizationHeader($literalEqualsResult['Authorization']),
        );

        // A checkbox-style flag parameter is equivalent with or without an explicit empty value.
        $this->assertSame(
            $this->signatureFromAuthorizationHeader($flagResult['Authorization']),
            $this->signatureFromAuthorizationHeader($flagWithEqualsResult['Authorization']),
        );
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
            $this->signatureFromAuthorizationHeader($result['Authorization']),
            $this->signatureFromAuthorizationHeader($resultSpace['Authorization']),
            'Pre-encoded %20 and space must produce same canonical path',
        );
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
