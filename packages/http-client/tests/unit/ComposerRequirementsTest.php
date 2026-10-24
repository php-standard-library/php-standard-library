<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Pins the version constraints of the split package on the PSL packages whose
 * symbols it uses: a symbol introduced in a later release than the constraint's
 * lower bound makes the split package fail at runtime in an installation that
 * holds an older release of the dependency.
 */
final class ComposerRequirementsTest extends TestCase
{
    /**
     * `H2\Exception\StreamException::$streamId`, which the HTTP/2 multiplexer reads
     * to fail the affected request, first ships in h2 6.4.0.
     */
    public function testH2RequirementCoversTheStreamExceptionIdentifier(): void
    {
        static::assertSame('^6.4', self::requirements()['php-standard-library/h2']);
    }

    /**
     * `Network\Exception\NonRetryableExceptionInterface`, which `RetryClient` checks
     * before it retries, first ships in network 6.4.0.
     */
    public function testNetworkRequirementCoversTheNonRetryableInterface(): void
    {
        static::assertSame('^6.4', self::requirements()['php-standard-library/network']);
    }

    /**
     * @return array<string, string>
     */
    private static function requirements(): array
    {
        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $manifest['require'];
    }
}
