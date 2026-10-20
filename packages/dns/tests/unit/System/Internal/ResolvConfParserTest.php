<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\System\Internal;

use PHPUnit\Framework\TestCase;
use Psl\DNS\System\Internal\ResolvConfParser;

use function file_get_contents;

final class ResolvConfParserTest extends TestCase
{
    public function testLinuxResolvConf(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../fixture/systemconfig/resolv-conf-linux.txt');

        $config = ResolvConfParser::parse($content);

        static::assertCount(3, $config->nameservers);
        static::assertSame('8.8.8.8', $config->nameservers[0]->host);
        static::assertSame(53, $config->nameservers[0]->port);
        static::assertSame('8.8.4.4', $config->nameservers[1]->host);
        static::assertSame('1.1.1.1', $config->nameservers[2]->host);

        static::assertCount(2, $config->searchDomains);
        static::assertSame('example.com', $config->searchDomains[0]);
        static::assertSame('corp.example.com', $config->searchDomains[1]);
    }

    public function testSystemdResolvConf(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../fixture/systemconfig/resolv-conf-systemd.txt');

        $config = ResolvConfParser::parse($content);

        static::assertCount(2, $config->nameservers);
        static::assertSame('192.168.1.1', $config->nameservers[0]->host);
        static::assertSame(53, $config->nameservers[0]->port);
        static::assertSame('fd00::1', $config->nameservers[1]->host);
        static::assertSame(53, $config->nameservers[1]->port);

        static::assertSame(['home.lan'], $config->searchDomains);
    }

    public function testCustomPort(): void
    {
        $config = ResolvConfParser::parse("nameserver 127.0.0.1#5353\n");

        static::assertCount(1, $config->nameservers);
        static::assertSame('127.0.0.1', $config->nameservers[0]->host);
        static::assertSame(5353, $config->nameservers[0]->port);
    }

    public function testCommentsAndEmptyLines(): void
    {
        $content = "# comment\n; another comment\n\nnameserver 1.1.1.1\n\n# trailing\n";

        $config = ResolvConfParser::parse($content);

        static::assertCount(1, $config->nameservers);
        static::assertSame('1.1.1.1', $config->nameservers[0]->host);
    }

    public function testDomainDirective(): void
    {
        $config = ResolvConfParser::parse("domain example.com\nnameserver 8.8.8.8\n");

        static::assertSame(['example.com'], $config->searchDomains);
    }

    public function testSearchOverridesDomain(): void
    {
        $content = "domain old.com\nsearch new.com other.com\nnameserver 8.8.8.8\n";

        $config = ResolvConfParser::parse($content);

        static::assertSame(['new.com', 'other.com'], $config->searchDomains);
    }

    public function testEmptyContent(): void
    {
        $config = ResolvConfParser::parse('');

        static::assertCount(0, $config->nameservers);
        static::assertCount(0, $config->searchDomains);
    }

    public function testIpv6WithInterfaceSuffix(): void
    {
        $config = ResolvConfParser::parse("nameserver fe80::1%eth0\n");

        static::assertCount(1, $config->nameservers);
        static::assertSame('fe80::1', $config->nameservers[0]->host);
    }

    public function testFormFeedAndVerticalTabAreTreatedAsWhitespace(): void
    {
        $content =
            "\x0Cnameserver 1.1.1.1\x0C\n"
            . "\x0Bnameserver 8.8.8.8 \x0C#5353\x0B\r\n"
            . "\x0Csearch\x0Cexample.com\x0Bcorp.example.com\x0C\n";

        $config = ResolvConfParser::parse($content);

        static::assertCount(2, $config->nameservers);
        static::assertSame('1.1.1.1', $config->nameservers[0]->host);
        static::assertSame(53, $config->nameservers[0]->port);
        static::assertSame('8.8.8.8', $config->nameservers[1]->host);
        static::assertSame(5353, $config->nameservers[1]->port);
        static::assertSame(['example.com', 'corp.example.com'], $config->searchDomains);
    }

    public function testNulByteIsNotTreatedAsWhitespace(): void
    {
        $config = ResolvConfParser::parse("\0nameserver 1.1.1.1\nnameserver 8.8.8.8\n");

        static::assertCount(1, $config->nameservers);
        static::assertSame('8.8.8.8', $config->nameservers[0]->host);
    }
}
