<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\IO\Tests\Fixture\CloseRecordingHandle;

/**
 * A handle that owns two handles closes both, even when closing the first fails, closes a handle passed twice
 * only once, rethrows the first failure, and closes nothing more when it is closed again.
 */
final class CloseAllTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(CloseRecordingHandle, CloseRecordingHandle): IO\CloseHandleInterface}>
     */
    public static function provideOwners(): iterable
    {
        yield 'concat' => [
            static fn(
                CloseRecordingHandle $first,
                CloseRecordingHandle $second,
            ): IO\CloseHandleInterface => new IO\ConcatReadHandle($first, $second),
        ];

        yield 'joined' => [
            static fn(
                CloseRecordingHandle $first,
                CloseRecordingHandle $second,
            ): IO\CloseHandleInterface => new IO\JoinedReadWriteHandle($first, $second),
        ];

        yield 'tee' => [
            static fn(
                CloseRecordingHandle $first,
                CloseRecordingHandle $second,
            ): IO\CloseHandleInterface => new IO\TeeWriteHandle($first, $second),
        ];
    }

    /**
     * @param Closure(CloseRecordingHandle, CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testClosesBothWhenTheFirstFails(Closure $factory): void
    {
        $first = new CloseRecordingHandle();
        $second = new CloseRecordingHandle();
        $owner = $factory($first, $second);
        $failure = new IO\Exception\RuntimeException('Failed to close the first handle.');
        $first->closeFailure = $failure;

        try {
            $owner->close();
            static::fail('The close did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame($failure, $e);
        }

        static::assertTrue($owner->isClosed());
        static::assertSame(1, $first->closes);
        static::assertSame(1, $second->closes);
    }

    /**
     * @param Closure(CloseRecordingHandle, CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testRethrowsTheFirstFailure(Closure $factory): void
    {
        $first = new CloseRecordingHandle();
        $second = new CloseRecordingHandle();
        $owner = $factory($first, $second);
        $failure = new IO\Exception\RuntimeException('Failed to close the first handle.');
        $first->closeFailure = $failure;
        $second->closeFailure = new IO\Exception\RuntimeException('Failed to close the second handle.');

        try {
            $owner->close();
            static::fail('The close did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame($failure, $e);
        }

        static::assertSame(1, $first->closes);
        static::assertSame(1, $second->closes);
    }

    /**
     * @param Closure(CloseRecordingHandle, CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testReportsTheSecondFailure(Closure $factory): void
    {
        $first = new CloseRecordingHandle();
        $second = new CloseRecordingHandle();
        $owner = $factory($first, $second);
        $failure = new IO\Exception\RuntimeException('Failed to close the second handle.');
        $second->closeFailure = $failure;

        try {
            $owner->close();
            static::fail('The close did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame($failure, $e);
        }

        static::assertSame(1, $first->closes);
        static::assertSame(1, $second->closes);
    }

    /**
     * @param Closure(CloseRecordingHandle, CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testClosesAHandlePassedTwiceOnce(Closure $factory): void
    {
        $handle = new CloseRecordingHandle();
        $owner = $factory($handle, $handle);

        $owner->close();

        static::assertSame(1, $handle->closes);
    }

    /**
     * @param Closure(CloseRecordingHandle, CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testClosingAgainDoesNothing(Closure $factory): void
    {
        $first = new CloseRecordingHandle();
        $second = new CloseRecordingHandle();
        $owner = $factory($first, $second);
        $first->closeFailure = new IO\Exception\RuntimeException('Failed to close the first handle.');

        try {
            $owner->close();
            static::fail('The close did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame('Failed to close the first handle.', $e->getMessage());
        }

        // Closing again must not retry the failed close.
        $owner->close();

        static::assertSame(1, $first->closes);
        static::assertSame(1, $second->closes);
    }
}
