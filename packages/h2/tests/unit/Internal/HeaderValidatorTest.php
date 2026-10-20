<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\H2\Exception\StreamException;
use Psl\H2\Internal\HeaderValidator;
use Psl\HPACK\Header;

final class HeaderValidatorTest extends TestCase
{
    public function testValidRequestHeaders(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header(':authority', 'example.com', false),
            new Header('host', 'example.com', false),
        ];

        HeaderValidator::validate($headers, false, false, 1);

        static::assertTrue(true);
    }

    public function testValidResponseHeaders(): void
    {
        $headers = [
            new Header(':status', '200', false),
            new Header('content-type', 'text/html', false),
        ];

        HeaderValidator::validate($headers, true, false, 1);

        static::assertTrue(true);
    }

    public function testPseudoHeaderAfterRegularHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header('host', 'example.com', false),
            new Header(':path', '/', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':path');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testDuplicatePseudoHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':method', 'POST', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Duplicate');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testMissingMethodPseudoHeader(): void
    {
        $headers = [
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':method');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testMissingSchemePseudoHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':path', '/', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':scheme');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testMissingPathPseudoHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':path');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testConnectMethodExemptFromSchemeAndPath(): void
    {
        $headers = [
            new Header(':method', 'CONNECT', false),
            new Header(':authority', 'proxy.example.com:8080', false),
        ];

        HeaderValidator::validate($headers, false, false, 1);

        static::assertTrue(true);
    }

    public function testMissingStatusPseudoHeader(): void
    {
        $headers = [
            new Header('content-type', 'text/html', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':status');

        HeaderValidator::validate($headers, true, false, 1);
    }

    public function testUppercaseHeaderName(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('Content-Type', 'text/html', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('uppercase');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testBannedConnectionHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('connection', 'keep-alive', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Banned');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testBannedTransferEncodingHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('transfer-encoding', 'chunked', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Banned');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testBannedKeepAliveHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('keep-alive', 'timeout=5', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Banned');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testBannedUpgradeHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('upgrade', 'h2c', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Banned');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testTeTrailersAllowed(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('te', 'trailers', false),
        ];

        HeaderValidator::validate($headers, false, false, 1);

        static::assertTrue(true);
    }

    public function testTeNonTrailersRejected(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('te', 'chunked', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('te');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testBannedProxyConnectionHeader(): void
    {
        $headers = [
            new Header(':method', 'GET', false),
            new Header(':scheme', 'https', false),
            new Header(':path', '/', false),
            new Header('proxy-connection', 'keep-alive', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Banned');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testPseudoHeaderInTrailingHeaders(): void
    {
        $headers = [
            new Header(':status', '200', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('trailing');

        HeaderValidator::validate($headers, true, true, 1);
    }

    public function testConnectWithSchemeRejected(): void
    {
        $headers = [
            new Header(':method', 'CONNECT', false),
            new Header(':scheme', 'https', false),
            new Header(':authority', 'proxy.example.com:8080', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':scheme');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testConnectWithPathRejected(): void
    {
        $headers = [
            new Header(':method', 'CONNECT', false),
            new Header(':path', '/', false),
            new Header(':authority', 'proxy.example.com:8080', false),
        ];

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage(':path');

        HeaderValidator::validate($headers, false, false, 1);
    }

    public function testValidTrailingHeaders(): void
    {
        $headers = [
            new Header('grpc-status', '0', false),
        ];

        HeaderValidator::validate($headers, false, true, 1);

        static::assertTrue(true);
    }

    public function testValidResponseTrailingHeaders(): void
    {
        $headers = [
            new Header('grpc-status', '0', false),
        ];

        HeaderValidator::validate($headers, true, true, 1);

        static::assertTrue(true);
    }
}
