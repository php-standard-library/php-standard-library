<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Tests\Unit\Internal;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNSSEC\Internal\SignatureVerifier;

use function restore_error_handler;
use function set_error_handler;
use function str_repeat;

final class MalformedDnskeyTest extends TestCase
{
    #[DataProvider('malformedKeys')]
    public function testMalformedKeyFailsWithoutWarning(Algorithm $algorithm, string $key, string $signature): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            static::assertFalse(SignatureVerifier::verify($algorithm, $signature, 'signed data', $key));
        } finally {
            restore_error_handler();
        }
    }

    /** @return iterable<string, array{Algorithm, string, string}> */
    public static function malformedKeys(): iterable
    {
        yield 'short Ed448 key' => [Algorithm::ED448, "\x00", str_repeat("\x00", 114)];
        yield 'short P-256 key' => [Algorithm::ECDSAP256SHA256, "\x00", str_repeat("\x00", 64)];
        yield 'short P-384 key' => [Algorithm::ECDSAP384SHA384, "\x00", str_repeat("\x00", 96)];
        yield 'invalid P-256 point' => [
            Algorithm::ECDSAP256SHA256,
            str_repeat("\x00", 64),
            str_repeat("\x00", 64),
        ];
        yield 'invalid P-384 point' => [
            Algorithm::ECDSAP384SHA384,
            str_repeat("\x00", 96),
            str_repeat("\x00", 96),
        ];
    }
}
