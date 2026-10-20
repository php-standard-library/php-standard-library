<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\System\HostsFile\Internal;

use PHPUnit\Framework\TestCase;
use Psl\DNS\System\HostsFile\Internal\Parser;
use Psl\IP\Family;

use function count;

final class ParserTest extends TestCase
{
    public function testBasicEntries(): void
    {
        $content = "127.0.0.1 localhost\n::1 localhost\n";

        $hostsFile = Parser::parse($content);

        $addresses = $hostsFile->lookup('localhost');
        static::assertCount(2, $addresses);
        static::assertSame(Family::V4, $addresses[0]->family);
        static::assertSame(Family::V6, $addresses[1]->family);
    }

    public function testMultipleHostnamesPerLine(): void
    {
        $content = "192.168.1.50 myapp.local myapp app\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('myapp.local'));
        static::assertCount(1, $hostsFile->lookup('myapp'));
        static::assertCount(1, $hostsFile->lookup('app'));
        static::assertSame('192.168.1.50', $hostsFile->lookup('myapp.local')[0]->toString());
    }

    public function testCommentsIgnored(): void
    {
        $content = "# This is a comment\n127.0.0.1 localhost\n# Another comment\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('localhost'));
    }

    public function testInlineCommentsStripped(): void
    {
        $content = "127.0.0.1 localhost # loopback\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('localhost'));
        static::assertSame([], $hostsFile->lookup('loopback'));
    }

    public function testEmptyLinesIgnored(): void
    {
        $content = "\n\n127.0.0.1 localhost\n\n\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('localhost'));
    }

    public function testCaseInsensitiveLookup(): void
    {
        $content = "127.0.0.1 MyApp.Local\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('myapp.local'));
        static::assertCount(1, $hostsFile->lookup('MYAPP.LOCAL'));
        static::assertCount(1, $hostsFile->lookup('MyApp.Local'));
    }

    public function testTabSeparator(): void
    {
        $content = "127.0.0.1\tlocalhost\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('localhost'));
    }

    public function testMixedWhitespace(): void
    {
        $content = "127.0.0.1   \t  localhost  \t  alias\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('localhost'));
        static::assertCount(1, $hostsFile->lookup('alias'));
    }

    public function testInvalidIpSkipped(): void
    {
        $content = "not.an.ip hostname\n127.0.0.1 valid\n";

        $hostsFile = Parser::parse($content);

        static::assertSame([], $hostsFile->lookup('hostname'));
        static::assertCount(1, $hostsFile->lookup('valid'));
    }

    public function testIpv6Entries(): void
    {
        $content = "::1 localhost\nfe80::1 gateway.local\n2001:db8::1 server.example.com\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(1, $hostsFile->lookup('localhost'));
        static::assertSame(Family::V6, $hostsFile->lookup('localhost')[0]->family);
        static::assertCount(1, $hostsFile->lookup('gateway.local'));
        static::assertCount(1, $hostsFile->lookup('server.example.com'));
    }

    public function testMultipleAddressesForSameHostname(): void
    {
        $content = "192.168.1.1 db\n10.0.0.1 db\n";

        $hostsFile = Parser::parse($content);

        $addresses = $hostsFile->lookup('db');
        static::assertCount(2, $addresses);
        static::assertSame('192.168.1.1', $addresses[0]->toString());
        static::assertSame('10.0.0.1', $addresses[1]->toString());
    }

    public function testEmptyContent(): void
    {
        $hostsFile = Parser::parse('');

        static::assertSame([], $hostsFile->entries);
    }

    public function testOnlyComments(): void
    {
        $content = "# comment 1\n# comment 2\n";

        $hostsFile = Parser::parse($content);

        static::assertSame([], $hostsFile->entries);
    }

    public function testLineWithOnlyIpNoHostname(): void
    {
        $content = "127.0.0.1\n";

        $hostsFile = Parser::parse($content);

        static::assertSame([], $hostsFile->entries);
    }

    public function testWindowsLineEndings(): void
    {
        $content = "127.0.0.1 localhost\r\n::1 localhost\r\n";

        $hostsFile = Parser::parse($content);

        static::assertCount(2, $hostsFile->lookup('localhost'));
    }

    public function testFormFeedAndVerticalTabAreTreatedAsWhitespace(): void
    {
        $content = "\x0C127.0.0.1\x0Clocalhost\x0C\n\x0B::1\x0Blocalhost \x0C# comment\x0B\r\n";

        $hostsFile = Parser::parse($content);

        $addresses = $hostsFile->lookup('localhost');
        static::assertCount(2, $addresses);
        static::assertSame('127.0.0.1', $addresses[0]->toString());
        static::assertSame('::1', $addresses[1]->toString());
    }

    public function testNulByteIsNotTreatedAsWhitespace(): void
    {
        $hostsFile = Parser::parse("\x00127.0.0.1 localhost\n::1 localhost\n");

        $addresses = $hostsFile->lookup('localhost');
        static::assertCount(1, $addresses);
        static::assertSame('::1', $addresses[0]->toString());
    }

    public function testRealWorldHostsFile(): void
    {
        $content = <<<'HOSTS'
        # Host Database
        #
        # localhost is used to configure the loopback interface
        # when the system is booting.  Do not change this entry.
        ##
        127.0.0.1	localhost
        255.255.255.255	broadcasthost
        ::1             localhost
        fe80::1%lo0	localhost

        # Custom entries
        192.168.1.100	myapp.dev www.myapp.dev
        10.0.0.50	db.internal
        HOSTS;

        $hostsFile = Parser::parse($content);

        $localhost = $hostsFile->lookup('localhost');
        static::assertGreaterThanOrEqual(2, count($localhost));

        static::assertCount(1, $hostsFile->lookup('myapp.dev'));
        static::assertCount(1, $hostsFile->lookup('www.myapp.dev'));
        static::assertCount(1, $hostsFile->lookup('db.internal'));
        static::assertSame('10.0.0.50', $hostsFile->lookup('db.internal')[0]->toString());
    }
}
