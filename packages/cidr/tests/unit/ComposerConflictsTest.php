<?php

declare(strict_types=1);

namespace Psl\CIDR\Tests\Unit;

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
     * Since 6.4.0, `CIDR\Block::contains()` matches only its own address family, and the
     * `DeniedDestinationsMiddleware::forPrivateNetworkRanges()` preset of http-client 6.4.0
     * matches IPv4-mapped peers against its IPv4 entries itself. The preset of http-client
     * 6.2.0 to 6.3.0 relied on the old cross-family containment for every IPv4 entry it had
     * no mapped twin of, `169.254.0.0/16` included, so next to this release and network
     * 6.4.0, which hands it IPv6 peer addresses it can parse, it would let
     * `::ffff:169.254.169.254` through. Composer must refuse that combination.
     */
    public function testRefusesTheHttpClientReleasesWhoseSsrfPresetReliedOnCrossFamilyContainment(): void
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
