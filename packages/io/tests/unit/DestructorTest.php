<?php

declare(strict_types=1);

namespace Psl\IO\Tests\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\IO\Tests\Fixture\CloseRecordingHandle;

/**
 * A handle that closes the handles it owns when it is destroyed suppresses the IO runtime failure a close may throw,
 * and nothing else (conventions E11); after an explicit close, destroying it closes nothing again.
 */
final class DestructorTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(CloseRecordingHandle): IO\CloseHandleInterface}>
     */
    public static function provideOwners(): iterable
    {
        yield 'bounded' => [
            static fn(CloseRecordingHandle $handle): IO\CloseHandleInterface => new IO\BoundedReadHandle($handle, 10),
        ];

        yield 'fixed length' => [
            static fn(CloseRecordingHandle $handle): IO\CloseHandleInterface => new IO\FixedLengthReadHandle(
                $handle,
                10,
            ),
        ];

        yield 'truncated' => [
            static fn(CloseRecordingHandle $handle): IO\CloseHandleInterface => new IO\TruncatedReadHandle($handle, 10),
        ];

        yield 'concat' => [
            static fn(CloseRecordingHandle $handle): IO\CloseHandleInterface => new IO\ConcatReadHandle(
                $handle,
                new IO\MemoryHandle(),
            ),
        ];

        yield 'joined' => [
            static fn(CloseRecordingHandle $handle): IO\CloseHandleInterface => new IO\JoinedReadWriteHandle(
                $handle,
                new IO\MemoryHandle(),
            ),
        ];

        yield 'tee' => [
            static fn(CloseRecordingHandle $handle): IO\CloseHandleInterface => new IO\TeeWriteHandle(
                $handle,
                new IO\MemoryHandle(),
            ),
        ];
    }

    /**
     * @param Closure(CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testDestructorSuppressesACloseFailure(Closure $factory): void
    {
        $handle = new CloseRecordingHandle();
        $handle->closeFailure = new IO\Exception\RuntimeException('Failed to close the handle.');
        $owner = $factory($handle);

        unset($owner);

        static::assertSame(1, $handle->closes);
    }

    /**
     * @param Closure(CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testDestructorLetsOtherFailuresThrough(Closure $factory): void
    {
        $handle = new CloseRecordingHandle();
        $failure = new LogicException('A defect in the handle.');
        $handle->closeFailure = $failure;
        $owner = $factory($handle);

        try {
            unset($owner);
            static::fail('The destructor suppressed the failure.');
        } catch (LogicException $e) {
            static::assertSame($failure, $e);
        }
    }

    /**
     * @param Closure(CloseRecordingHandle): IO\CloseHandleInterface $factory
     */
    #[DataProvider('provideOwners')]
    public function testDestructorAfterAFailedCloseClosesNothingAgain(Closure $factory): void
    {
        $handle = new CloseRecordingHandle();
        $handle->closeFailure = new IO\Exception\RuntimeException('Failed to close the handle.');
        $owner = $factory($handle);

        try {
            $owner->close();
            static::fail('The close did not fail.');
        } catch (IO\Exception\RuntimeException $e) {
            static::assertSame('Failed to close the handle.', $e->getMessage());
        }

        unset($owner);

        static::assertSame(1, $handle->closes);
    }
}
