<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\System\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DNS\System\Internal\IpconfigParser;

use function count;
use function file_get_contents;

final class IpconfigParserTest extends TestCase
{
    #[DataProvider('windowsFixtureProvider')]
    public function testRealWindowsOutput(string $fixture, string $expectedDns, string $expectedSearchDomain): void
    {
        $content = file_get_contents(__DIR__ . '/../../../fixture/systemconfig/' . $fixture);

        $config = IpconfigParser::parse($content);

        static::assertGreaterThanOrEqual(1, count($config->nameservers));
        static::assertSame($expectedDns, $config->nameservers[0]->host);
        static::assertSame(53, $config->nameservers[0]->port);

        static::assertNotEmpty($config->searchDomains);
        static::assertSame($expectedSearchDomain, $config->searchDomains[0]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function windowsFixtureProvider(): iterable
    {
        yield 'windows-latest' => [
            'ipconfig_windows-latest.txt',
            '168.63.129.16',
            'rlbmnw52lfyudayxnemt0qcpaf.gx.internal.cloudapp.net',
        ];

        yield 'windows-2022' => [
            'ipconfig_windows-2022.txt',
            '168.63.129.16',
            's11inifsmdwebl4xefzoyahvzd.phxx.internal.cloudapp.net',
        ];

        yield 'windows-2025' => [
            'ipconfig_windows-2025.txt',
            '168.63.129.16',
            'xariblbjf3ru1nzambwxmzlesd.gx.internal.cloudapp.net',
        ];

        yield 'windows-11-arm' => [
            'ipconfig_windows-11-arm.txt',
            '168.63.129.16',
            'j5mr0bnfrynupdysqxapqgvyhd.cx.internal.cloudapp.net',
        ];
    }

    public function testSkipsDisconnectedAdapter(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc
           DNS Suffix Search List. . . . . . : example.com

        Ethernet adapter Ethernet:

           Media State . . . . . . . . . . . : Media disconnected
           Connection-specific DNS Suffix  . :
           Description . . . . . . . . . . . : Intel Ethernet

        Wireless LAN adapter Wi-Fi:

           Connection-specific DNS Suffix  . : home.lan
           DNS Servers . . . . . . . . . . . : 192.168.1.1

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(1, $config->nameservers);
        static::assertSame('192.168.1.1', $config->nameservers[0]->host);
    }

    public function testMultipleDnsServers(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . : example.com
           DNS Servers . . . . . . . . . . . : 8.8.8.8
                                               8.8.4.4
                                               1.1.1.1
           NetBIOS over Tcpip. . . . . . . . : Enabled

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(3, $config->nameservers);
        static::assertSame('8.8.8.8', $config->nameservers[0]->host);
        static::assertSame('8.8.4.4', $config->nameservers[1]->host);
        static::assertSame('1.1.1.1', $config->nameservers[2]->host);
    }

    public function testFormFeedAndVerticalTabAreTreatedAsWhitespace(): void
    {
        $output =
            "Windows IP Configuration\r\n"
            . "\r\n"
            . "   DNS Suffix Search List. . . . . . : example.com\r\n"
            . "                                       corp.example.com\x0C\r\n"
            . "\r\n"
            . "Ethernet adapter Ethernet:\r\n"
            . "\r\n"
            . "   DNS Servers . . . . . . . . . . . : 8.8.8.8\r\n"
            . "                                       8.8.4.4\x0C\r\n"
            . "                                       1.1.1.1\x0B\r\n"
            . "   NetBIOS over Tcpip. . . . . . . . : Enabled\r\n";

        $config = IpconfigParser::parse($output);

        static::assertCount(3, $config->nameservers);
        static::assertSame('8.8.8.8', $config->nameservers[0]->host);
        static::assertSame('8.8.4.4', $config->nameservers[1]->host);
        static::assertSame('1.1.1.1', $config->nameservers[2]->host);
        static::assertSame(['example.com', 'corp.example.com'], $config->searchDomains);
    }

    public function testNulByteIsNotTreatedAsWhitespace(): void
    {
        $output =
            "Ethernet adapter Ethernet:\r\n"
            . "\r\n"
            . "   DNS Servers . . . . . . . . . . . : 8.8.8.8\r\n"
            . "                                       8.8.4.4\x00\r\n"
            . "                                       1.1.1.1\r\n"
            . "   NetBIOS over Tcpip. . . . . . . . : Enabled\r\n";

        $config = IpconfigParser::parse($output);

        static::assertCount(2, $config->nameservers);
        static::assertSame('8.8.8.8', $config->nameservers[0]->host);
        static::assertSame('1.1.1.1', $config->nameservers[1]->host);
    }

    public function testDeduplicatesAcrossAdapters(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 8.8.8.8

        Wireless LAN adapter Wi-Fi:

           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 8.8.8.8

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(1, $config->nameservers);
    }

    public function testSearchListWithMultipleDomains(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc
           DNS Suffix Search List. . . . . . : example.com
                                               corp.example.com
                                               dev.example.com

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 8.8.8.8

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(3, $config->searchDomains);
        static::assertSame('example.com', $config->searchDomains[0]);
        static::assertSame('corp.example.com', $config->searchDomains[1]);
        static::assertSame('dev.example.com', $config->searchDomains[2]);
    }

    public function testConnectionSuffixAddedToSearchDomains(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc
           DNS Suffix Search List. . . . . . : example.com

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . : special.lan
           DNS Servers . . . . . . . . . . . : 8.8.8.8

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertContains('example.com', $config->searchDomains);
        static::assertContains('special.lan', $config->searchDomains);
    }

    public function testIpv6DnsServer(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 2001:4860:4860::8888
                                               8.8.8.8
           NetBIOS over Tcpip. . . . . . . . : Enabled

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(2, $config->nameservers);
        static::assertSame('2001:4860:4860::8888', $config->nameservers[0]->host);
        static::assertSame('8.8.8.8', $config->nameservers[1]->host);
    }

    public function testEmptyOutput(): void
    {
        $config = IpconfigParser::parse('');

        static::assertCount(0, $config->nameservers);
        static::assertCount(0, $config->searchDomains);
    }

    public function testAdapterWithNoDnsServers(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc

        Ethernet adapter vEthernet (nat):

           Connection-specific DNS Suffix  . :
           Description . . . . . . . . . . . : Hyper-V Virtual Ethernet Adapter
           DHCP Enabled. . . . . . . . . . . : No
           NetBIOS over Tcpip. . . . . . . . : Enabled

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(0, $config->nameservers);
    }

    public function testEmptySearchListDoesNotJumpToNextLine(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc
           DNS Suffix Search List. . . . . . :

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 8.8.8.8

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(0, $config->searchDomains);
        static::assertCount(1, $config->nameservers);
        static::assertSame('8.8.8.8', $config->nameservers[0]->host);
    }

    public function testEmptyDnsServersDoesNotJumpToNextLine(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . : example.com
           DNS Servers . . . . . . . . . . . :
           NetBIOS over Tcpip. . . . . . . . : Enabled

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(0, $config->nameservers);
        static::assertContains('example.com', $config->searchDomains);
    }

    public function testEmptyConnectionSuffixDoesNotJumpToNextLine(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc

        Ethernet adapter Ethernet:

           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 1.1.1.1

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertCount(1, $config->nameservers);
        static::assertSame('1.1.1.1', $config->nameservers[0]->host);
        static::assertCount(0, $config->searchDomains);
    }

    public function testDuplicateStringInDescriptionDoesNotConfuseOffset(): void
    {
        $output = <<<'OUTPUT'

        Windows IP Configuration

           Host Name . . . . . . . . . . . . : test-pc
           DNS Suffix Search List. . . . . . : example.com

        Ethernet adapter DNS Servers Fake Adapter:

           Description . . . . . . . . . . . : DNS Servers . . . . . . . . . . . : fake
           Connection-specific DNS Suffix  . :
           DNS Servers . . . . . . . . . . . : 10.0.0.1
                                               10.0.0.2
           NetBIOS over Tcpip. . . . . . . . : Enabled

        OUTPUT;

        $config = IpconfigParser::parse($output);

        static::assertContains('example.com', $config->searchDomains);
        static::assertCount(2, $config->nameservers);
        static::assertSame('10.0.0.1', $config->nameservers[0]->host);
        static::assertSame('10.0.0.2', $config->nameservers[1]->host);
    }
}
