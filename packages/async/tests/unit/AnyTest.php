<?php

declare(strict_types=1);

namespace Psl\Async\Tests\Unit;

use Generator;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime;
use Psl\Exception\InvariantViolationException;

final class AnyTest extends TestCase
{
    public function testAny(): void
    {
        $result = Async\any([
            Async\run(static function (): string {
                Async\sleep(DateTime\Duration::milliseconds(1));

                throw new InvariantViolationException('a');
            }),
            Async\run(static function (): string {
                Async\sleep(DateTime\Duration::milliseconds(2));

                throw new InvariantViolationException('b');
            }),
            Async\run(static function (): string {
                Async\sleep(DateTime\Duration::milliseconds(3));

                return 'c';
            }),
            Async\run(static function (): string {
                Async\sleep(DateTime\Duration::microseconds(500));

                Async\later();

                Async\sleep(DateTime\Duration::microseconds(500));

                return 'c';
            }),
        ]);

        static::assertSame('c', $result);
    }

    public function testAnyWithNoArguments(): void
    {
        $this->expectException(Async\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('$awaitables must be a non-empty-iterable.');

        Async\any([]);
    }

    public function testAnyWillFailingAwaitables(): void
    {
        $this->expectException(Async\Exception\CompositeException::class);

        Async\any([
            Async\Awaitable::error(new InvariantViolationException('foo')),
            Async\Awaitable::error(new InvariantViolationException('bar')),
        ]);
    }

    public function testAnyWithGenerator(): void
    {
        $awaitables = (static function (): Generator {
            yield Async\Awaitable::error(new InvariantViolationException('a'));
            yield Async\run(static function (): string {
                Async\sleep(DateTime\Duration::milliseconds(2));

                return 'b';
            });
            yield Async\run(static fn(): string => 'c');
        })();

        static::assertSame('c', Async\any($awaitables));
    }
}
