<?php

declare(strict_types=1);

namespace Psl\Network\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Pins the packages the split package refuses to be installed with.
 */
final class ComposerConflictsTest extends TestCase
{
    /**
     * Since 6.4.0, the peer address of an accepted or connected IPv6 stream carries no
     * brackets. The `DeniedDestinationsMiddleware::forPrivateNetworkRanges()` preset of
     * http-client 6.2.0 to 6.3.0 failed closed on every bracketed IPv6 peer, and once it can
     * parse them it relies on cidr's old cross-family containment for the IPv4 entries it has
     * no mapped twin of, `169.254.0.0/16` included: next to this release and cidr 6.4.0 it
     * would let `::ffff:169.254.169.254` through. Composer must refuse that combination.
     */
    public function testRefusesTheHttpClientReleasesWhoseSsrfPresetFailedClosedOnBracketedPeers(): void
    {
        static::assertSame('<6.4.0', self::conflicts()['php-standard-library/http-client']);
    }

    /**
     * The monorepo distribution of PSL 5 and earlier, which ships the same namespaces.
     */
    public function testRefusesTheMonorepoDistribution(): void
    {
        static::assertSame('*', self::conflicts()['azjezz/psl']);
    }

    /**
     * @return array<string, string>
     */
    private static function conflicts(): array
    {
        /** @var array{conflict: array<string, string>} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $manifest['conflict'];
    }
}
