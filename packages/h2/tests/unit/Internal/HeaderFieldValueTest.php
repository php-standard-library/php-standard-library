<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\Exception\StreamException;
use Psl\H2\Internal\HeaderValidator;
use Psl\HPACK\Header;

final class HeaderFieldValueTest extends TestCase
{
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidFieldValues(string $value): void
    {
        $this->expectException(StreamException::class);

        HeaderValidator::validate([new Header(':status', '200'), new Header('x-value', $value)], true, false, 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'carriage return' => ["before\rafter"];
        yield 'line feed' => ["before\nafter"];
        yield 'NUL' => ["before\0after"];
        yield 'leading space' => [' value'];
        yield 'trailing space' => ['value '];
        yield 'leading tab' => ["\tvalue"];
        yield 'trailing tab' => ["value\t"];
    }

    public function testAllowsEmptyValuesAndInternalWhitespace(): void
    {
        HeaderValidator::validate(
            [
                new Header(':status', '200'),
                new Header('x-empty', ''),
                new Header('x-space', "one \ttwo"),
            ],
            true,
            false,
            1,
        );

        static::assertTrue(true);
    }
}
