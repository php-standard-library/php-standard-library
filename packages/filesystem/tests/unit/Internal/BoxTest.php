<?php

declare(strict_types=1);

namespace Psl\Filesystem\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\Filesystem\Internal;

use function trigger_error;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const E_USER_WARNING;

final class BoxTest extends TestCase
{
    public function testReturnsTheValueAndNoMessageWithoutAWarning(): void
    {
        [$value, $message] = Internal\box(static fn(): int => 42);

        static::assertSame(42, $value);
        static::assertNull($message);
    }

    public function testStripsTheFunctionPrefixFromTheWarning(): void
    {
        [$value, $message] = Internal\box(
            static fn(): bool => trigger_error('unlink(/tmp/Foo): Permission denied', E_USER_WARNING),
        );

        static::assertTrue($value);
        static::assertSame('Permission denied', $message);
    }

    public function testKeepsTheCaseOfTheReason(): void
    {
        [, $message] = Internal\box(
            static fn(): bool => trigger_error('filesize(): stat failed for /tmp/Some/Path', E_USER_WARNING),
        );

        static::assertSame('stat failed for /tmp/Some/Path', $message);
    }

    public function testKeepsAWarningWithoutAFunctionPrefixAsIs(): void
    {
        [, $message] = Internal\box(static fn(): bool => trigger_error('something went wrong', E_USER_WARNING));

        static::assertSame('something went wrong', $message);
    }

    public function testCapturesTheReasonOfARealFilesystemWarning(): void
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'does-not-exist' . DIRECTORY_SEPARATOR . 'file.txt';

        [$result, $message] = Internal\box(static fn(): bool => unlink($file));

        static::assertFalse($result);
        static::assertNotNull($message);
        static::assertNotSame('', $message);
        // the reason itself comes from the OS (`strerror()`), so only check that PHP's prefix is gone.
        static::assertStringStartsNotWith('unlink(', $message);
    }
}
