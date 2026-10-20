<?php

declare(strict_types=1);

namespace Psl\URI\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Str;
use Psl\URI;
use Psl\URI\Authority\IPHost;
use Psl\URI\Authority\RegisteredNameHost;
use Psl\URI\Exception\InvalidURIException;
use Psl\URI\PathKind;

final class ParseTest extends TestCase
{
    public function testFullURI(): void
    {
        $uri = URI\parse('http://user:pass@host:8080/path?query#frag');

        static::assertSame('http', $uri->scheme);
        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('user:pass', $authority->userInfo);
        static::assertInstanceOf(RegisteredNameHost::class, $authority->host);
        static::assertSame('host', $authority->host->toString());
        static::assertSame(8080, $authority->port);
        static::assertSame('/path', $uri->path);
        static::assertSame('query', $uri->query);
        static::assertSame('frag', $uri->fragment);
    }

    public function testSchemeNormalization(): void
    {
        $uri = URI\parse('HTTP://EXAMPLE.COM/');

        static::assertSame('http', $uri->scheme);
        static::assertNotNull($uri->authority);
        static::assertInstanceOf(RegisteredNameHost::class, $uri->authority->host);
        static::assertSame('example.com', $uri->authority->host->toString());
    }

    public function testPercentEncodingNormalizationDecodesUnreserved(): void
    {
        $uri = URI\parse('http://h/%41');

        static::assertSame('/A', $uri->path);
    }

    public function testPercentEncodingNormalizationUppercasesHex(): void
    {
        $uri = URI\parse('http://h/%2f');

        static::assertSame('/%2F', $uri->path);
    }

    public function testDotSegmentRemoval(): void
    {
        $uri = URI\parse('http://h/a/./b/../c');

        static::assertSame('/a/c', $uri->path);
    }

    public function testPathTraversal(): void
    {
        $uri = URI\parse('http://h/static/../../../etc/passwd');

        static::assertSame('/etc/passwd', $uri->path);
    }

    public function testIPv6(): void
    {
        $uri = URI\parse('http://[::1]:8080/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(IPHost::class, $authority->host);
        static::assertSame('[::1]', $authority->host->toString());
        static::assertSame(8080, $authority->port);
    }

    public function testIPv6WithZone(): void
    {
        $uri = URI\parse('http://[fe80::1%25eth0]/');

        static::assertNotNull($uri->authority);
        static::assertInstanceOf(IPHost::class, $uri->authority->host);
        static::assertSame('[fe80::1%25eth0]', $uri->authority->host->toString());
    }

    public function testFileURI(): void
    {
        $uri = URI\parse('file:///path/to/file');

        static::assertSame('file', $uri->scheme);
        static::assertNotNull($uri->authority);
        static::assertSame('', $uri->authority->host->toString());
        static::assertSame('/path/to/file', $uri->path);
    }

    public function testURN(): void
    {
        $uri = URI\parse('urn:isbn:0451450523');

        static::assertSame('urn', $uri->scheme);
        static::assertNull($uri->authority);
        static::assertSame('isbn:0451450523', $uri->path);
        static::assertSame(PathKind::Rootless, $uri->pathKind);
    }

    public function testProtocolRelative(): void
    {
        $uri = URI\parse('//host/path');

        static::assertNull($uri->scheme);
        static::assertNotNull($uri->authority);
        static::assertSame('host', $uri->authority->host->toString());
        static::assertSame('/path', $uri->path);
    }

    public function testRelativePath(): void
    {
        $uri = URI\parse('relative/path');

        static::assertNull($uri->scheme);
        static::assertNull($uri->authority);
        static::assertSame('relative/path', $uri->path);
        static::assertSame(PathKind::Rootless, $uri->pathKind);
    }

    public function testAbsolutePath(): void
    {
        $uri = URI\parse('/absolute/path');

        static::assertNull($uri->scheme);
        static::assertNull($uri->authority);
        static::assertSame('/absolute/path', $uri->path);
        static::assertSame(PathKind::Absolute, $uri->pathKind);
    }

    #[DataProvider('invalidRelativePaths')]
    public function testRejectsColonInFirstRelativePathSegment(string $input): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse($input);
    }

    /** @return iterable<array{string}> */
    public static function invalidRelativePaths(): iterable
    {
        yield [':'];
        yield [':segment'];
        yield ['::/segment'];
        yield [':segment/next?query#fragment'];
        yield ['1:segment'];
    }

    #[DataProvider('validPathsWithColon')]
    public function testAcceptsColonOutsideFirstRelativePathSegment(string $input, string $path): void
    {
        static::assertSame($path, URI\parse($input)->path);
    }

    /** @return iterable<array{string, string}> */
    public static function validPathsWithColon(): iterable
    {
        yield ['./:segment', './:segment'];
        yield ['path/:segment', 'path/:segment'];
        yield ['/:segment', '/:segment'];
        yield ['%3Asegment', '%3Asegment'];
        yield ['//host/:segment', '/:segment'];
        yield ['scheme:path:segment', 'path:segment'];
        yield ['path?query:value#fragment:value', 'path'];
    }

    public function testEmptyString(): void
    {
        $uri = URI\parse('');

        static::assertNull($uri->scheme);
        static::assertNull($uri->authority);
        static::assertSame('', $uri->path);
        static::assertSame(PathKind::None, $uri->pathKind);
    }

    public function testQueryAbsent(): void
    {
        $uri = URI\parse('http://h');

        static::assertNull($uri->query);
    }

    public function testQueryEmpty(): void
    {
        $uri = URI\parse('http://h?');

        static::assertSame('', $uri->query);
    }

    public function testQueryPresent(): void
    {
        $uri = URI\parse('http://h?x');

        static::assertSame('x', $uri->query);
    }

    public function testFragmentAbsent(): void
    {
        $uri = URI\parse('http://h');

        static::assertNull($uri->fragment);
    }

    public function testFragmentEmpty(): void
    {
        $uri = URI\parse('http://h#');

        static::assertSame('', $uri->fragment);
    }

    public function testFragmentPresent(): void
    {
        $uri = URI\parse('http://h#x');

        static::assertSame('x', $uri->fragment);
    }

    public function testUserInfoAbsent(): void
    {
        $uri = URI\parse('http://h');

        static::assertNull($uri->authority?->userInfo);
    }

    public function testUserInfoEmpty(): void
    {
        $uri = URI\parse('http://@h');

        static::assertSame('', $uri->authority?->userInfo);
    }

    public function testUserInfoPresent(): void
    {
        $uri = URI\parse('http://u@h');

        static::assertSame('u', $uri->authority?->userInfo);
    }

    public function testPortAbsent(): void
    {
        $uri = URI\parse('http://h');

        static::assertNull($uri->authority?->port);
    }

    public function testPortEmptyStripped(): void
    {
        $uri = URI\parse('http://h:');

        static::assertNull($uri->authority?->port);
    }

    public function testPortPresent(): void
    {
        $uri = URI\parse('http://h:80');

        static::assertSame(80, $uri->authority?->port);
    }

    public function testNonASCIIRejection(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse("http://\xE4\xBE\x8B\xE3\x81\x88.jp/");
    }

    public function testInvalidPercentEncoding(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://h/%GG');
    }

    public function testInvalidScheme(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('1bad:foo');
    }

    public function testMailto(): void
    {
        $uri = URI\parse('mailto:user@example.com');

        static::assertSame('mailto', $uri->scheme);
        static::assertNull($uri->authority);
        static::assertSame('user@example.com', $uri->path);
        static::assertSame(PathKind::Rootless, $uri->pathKind);
    }

    public function testSchemeWithPlus(): void
    {
        $uri = URI\parse('coap+tcp://host/');

        static::assertSame('coap+tcp', $uri->scheme);
        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('host', $authority->host->toString());
        static::assertSame('/', $uri->path);
    }

    public function testSchemeWithDash(): void
    {
        $uri = URI\parse('my-scheme://host/');

        static::assertSame('my-scheme', $uri->scheme);
        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('host', $authority->host->toString());
    }

    public function testSchemeWithDot(): void
    {
        $uri = URI\parse('a.b.c://host/');

        static::assertSame('a.b.c', $uri->scheme);
        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('host', $authority->host->toString());
    }

    public function testSchemeWithDigitsAfterAlpha(): void
    {
        $uri = URI\parse('h323://host/');

        static::assertSame('h323', $uri->scheme);
        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('host', $authority->host->toString());
    }

    public function testVeryLongURI(): void
    {
        $longPath = '/' . Str\repeat('a', 8_000);
        $uri = URI\parse('http://host' . $longPath);

        static::assertSame('http', $uri->scheme);
        static::assertSame($longPath, $uri->path);
    }

    public function testMultipleAtSignsInUserInfo(): void
    {
        $uri = URI\parse('http://user%40name@host/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('user%40name', $authority->userInfo);
        static::assertSame('host', $authority->host->toString());
    }

    public function testMultipleAtSignsLastSeparates(): void
    {
        $uri = URI\parse('http://a@b@host/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('a@b', $authority->userInfo);
        static::assertSame('host', $authority->host->toString());
    }

    public function testPortZero(): void
    {
        $uri = URI\parse('http://host:0/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame(0, $authority->port);
    }

    public function testPortMax(): void
    {
        $uri = URI\parse('http://host:65535/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame(65_535, $authority->port);
    }

    public function testPortAboveMaxThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://host:65536/');
    }

    public function testPathWithColonAfterFirstSegment(): void
    {
        $uri = URI\parse('foo/bar:baz');

        static::assertNull($uri->scheme);
        static::assertNull($uri->authority);
        static::assertSame('foo/bar:baz', $uri->path);
        static::assertSame(PathKind::Rootless, $uri->pathKind);
    }

    public function testQueryWithQuestionMarks(): void
    {
        $uri = URI\parse('http://h?a=1?b=2?c=3');

        static::assertSame('a=1?b=2?c=3', $uri->query);
    }

    public function testFragmentWithHashChars(): void
    {
        $uri = URI\parse('http://h#frag#with#hashes');

        static::assertSame('frag#with#hashes', $uri->fragment);
    }

    public function testDoubleSlashWithoutAuthority(): void
    {
        $uri = URI\parse('//');

        static::assertNull($uri->scheme);
        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertSame('', $authority->host->toString());
    }

    public function testConsecutiveDotSegments(): void
    {
        $uri = URI\parse('http://h/a/b/../../c');

        static::assertSame('/c', $uri->path);
    }

    public function testTrailingDotSegments(): void
    {
        $uri = URI\parse('http://h/a/b/..');

        static::assertSame('/a/', $uri->path);
    }

    public function testOnlyDotSegments(): void
    {
        $uri = URI\parse('http://h/../../../');

        static::assertSame('/', $uri->path);
    }

    public function testPercentEncodedHostNormalization(): void
    {
        $uri = URI\parse('http://%68%6F%73%74/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(RegisteredNameHost::class, $authority->host);
        static::assertSame('host', $authority->host->toString());
    }

    public function testIPv4Loopback(): void
    {
        $uri = URI\parse('http://127.0.0.1/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(IPHost::class, $authority->host);
        static::assertSame('127.0.0.1', $authority->host->toString());
    }

    public function testIPv4WithPort(): void
    {
        $uri = URI\parse('http://192.168.1.1:8080/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(IPHost::class, $authority->host);
        static::assertSame('192.168.1.1', $authority->host->toString());
        static::assertSame(8080, $authority->port);
    }

    public function testColonSlashSlashWithoutScheme(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('://host');
    }

    public function testQueryAndFragmentBothPresent(): void
    {
        $uri = URI\parse('http://h?query#fragment');

        static::assertSame('query', $uri->query);
        static::assertSame('fragment', $uri->fragment);
    }

    public function testQueryAndFragmentBothEmpty(): void
    {
        $uri = URI\parse('http://h?#');

        static::assertSame('', $uri->query);
        static::assertSame('', $uri->fragment);
    }

    public function testVeryLargePortNumberThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://host:999999/');
    }

    public function testSingleDotSegment(): void
    {
        $uri = URI\parse('http://h/a/./b');

        static::assertSame('/a/b', $uri->path);
    }

    public function testMultipleDotSegmentsInSequence(): void
    {
        $uri = URI\parse('http://h/a/b/c/../../d/../e');

        static::assertSame('/a/e', $uri->path);
    }

    public function testEmptyPathWithAuthority(): void
    {
        $uri = URI\parse('http://host');

        static::assertSame('', $uri->path);
        static::assertSame(PathKind::None, $uri->pathKind);
    }

    public function testDoubleSlashInPath(): void
    {
        $uri = URI\parse('http://h//a//b');

        static::assertSame('//a//b', $uri->path);
    }

    public function testInvalidIPv4LikeHostFallsToRegisteredName(): void
    {
        $uri = URI\parse('http://999.999.999.999/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(RegisteredNameHost::class, $authority->host);
        static::assertSame('999.999.999.999', $authority->host->toString());
        static::assertSame('/', $uri->path);
    }

    public function testInvalidIPv4LikeHostWithPortFallsToRegisteredName(): void
    {
        $uri = URI\parse('http://256.1.1.1:8080/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(RegisteredNameHost::class, $authority->host);
        static::assertSame('256.1.1.1', $authority->host->toString());
        static::assertSame(8080, $authority->port);
    }

    public function testIPLiteralNonNumericPortThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://[::1]:abc/');
    }

    public function testIPLiteralGarbageAfterBracketThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://[::1]xyz/');
    }

    public function testIPLiteralInvalidAddressInsideBracketsThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://[notanip]/');
    }

    public function testIPLiteralMissingCloseBracketThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://[::1/');
    }

    public function testIPLiteralPortAboveMaxThrows(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://[::1]:99999/');
    }

    public function testBareIPv6ParsedAsIPHost(): void
    {
        $uri = URI\parse('http://::1/');

        $authority = $uri->authority;
        static::assertNotNull($authority);
        static::assertInstanceOf(IPHost::class, $authority->host);
    }

    public function testPercentEncodingTrailingPercent(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://h/path%');
    }

    public function testPercentEncodingIncompleteSequence(): void
    {
        $this->expectException(InvalidURIException::class);

        URI\parse('http://h/path%A');
    }
}
