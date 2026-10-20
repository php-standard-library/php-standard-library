<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use ErrorException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Dict;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use Psl\Type\Type;
use Psl\Type\TypeInterface;
use Psl\Vec;

use function restore_error_handler;
use function set_error_handler;

/**
 * @template T
 */
abstract class TypeTestCase extends TestCase
{
    /**
     * @return TypeInterface<T>
     */
    abstract public static function getType(): TypeInterface;

    /**
     * @return iterable<array{0: mixed, 1: T}>
     */
    abstract public static function getValidCoercions(): iterable;

    /**
     * @return iterable<array{0: mixed}>
     */
    abstract public static function getInvalidCoercions(): iterable;

    /**
     * @return iterable<array{0: Type<mixed>, 1: string}>
     */
    abstract public static function getToStringExamples(): iterable;

    /**
     * @return list<array{0: T}>
     */
    public static function getValidValues(): array
    {
        $nonUnique = static::getValidCoercions();
        $nonUnique = Dict\map($nonUnique, static fn(array $tuple): mixed => $tuple[1]);

        $out = [];
        foreach ($nonUnique as $v) {
            foreach ($out as $value) {
                if (!static::equals($value, $v)) {
                    continue;
                }

                break;
            }

            $out[] = [$v];
        }

        return $out;
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function getInvalidValues(): array
    {
        $rows = static::getInvalidCoercions();
        $rows = Vec\values($rows);
        foreach (static::getValidCoercions() as $arr) {
            [$value, $v] = $arr;
            if (static::equals($v, $value)) {
                continue;
            }

            $rows[] = [$value];
        }

        return $rows;
    }

    #[DataProvider('getValidValues')]
    public function testMatches(mixed $value): void
    {
        static::assertTrue(static::getType()->matches($value));
    }

    #[DataProvider('getInvalidValues')]
    public function testInvalidMatches(mixed $value): void
    {
        static::assertFalse(static::getType()->matches($value));
    }

    #[DataProvider('getValidCoercions')]
    final public function testValidCoercion(mixed $value, mixed $expected): void
    {
        $actual = static::getType()->coerce($value);

        static::assertTrue(static::equals($expected, $actual));
        static::assertTrue(static::equals($actual, static::getType()->coerce($actual)));
    }

    #[DataProvider('getInvalidCoercions')]
    public function testInvalidCoercion(mixed $value): void
    {
        $this->expectException(CoercionException::class);

        // An invalid value must be rejected with a CoercionException, not with a PHP warning or deprecation.
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            static::getType()->coerce($value);
        } finally {
            restore_error_handler();
        }
    }

    #[DataProvider('getValidValues')]
    final public function testValidAssertion(mixed $value): void
    {
        $out = static::getType()->assert($value);

        static::assertTrue(static::equals($out, $value));
    }

    #[DataProvider('getInvalidValues')]
    public function testInvalidAssertion(mixed $value): void
    {
        $this->expectException(AssertException::class);

        static::getType()->assert($value);
    }

    #[DataProvider('getToStringExamples')]
    final public function testToString(Type $ts, string $expected): void
    {
        static::assertSame($expected, $ts->toString());
    }

    /**
     * @param T $a
     * @param T $b
     */
    protected static function equals(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    protected static function stringable(string $value): object
    {
        return new class($value) {
            private string $value;

            public function __construct(string $value)
            {
                $this->value = $value;
            }

            #[Override]
            public function __toString(): string
            {
                return $this->value;
            }
        };
    }
}
