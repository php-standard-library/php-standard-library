<?php

declare(strict_types=1);

namespace Psl\Terminal\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Terminal\Internal\Utf8Sequence;

final class Utf8SequenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideSequences(): iterable
    {
        yield 'empty' => ['', 0];

        yield 'U+0080' => ["\xC2\x80", 2];
        yield 'U+07FF' => ["\xDF\xBF", 2];
        yield 'U+0800' => ["\xE0\xA0\x80", 3];
        yield 'U+D7FF' => ["\xED\x9F\xBF", 3];
        yield 'U+E000' => ["\xEE\x80\x80", 3];
        yield 'U+FFFF' => ["\xEF\xBF\xBF", 3];
        yield 'U+10000' => ["\xF0\x90\x80\x80", 4];
        yield 'U+10FFFF' => ["\xF4\x8F\xBF\xBF", 4];
        yield 'followed by more input' => ["\xC3\xA9abc", 2];

        yield 'incomplete 2-byte' => ["\xC3", 0];
        yield 'incomplete 3-byte' => ["\xE2\x9C", 0];
        yield 'incomplete 4-byte' => ["\xF0\x9F\x98", 0];

        yield 'stray continuation byte' => ["\x80", -1];
        yield 'overlong lead 0xC0' => ["\xC0\xAF", -1];
        yield 'overlong lead 0xC1' => ["\xC1\xBF", -1];
        yield 'lead above U+10FFFF' => ["\xF5\x80\x80\x80", -1];
        yield 'invalid byte 0xFF' => ["\xFF", -1];

        yield 'lead followed by ASCII' => ["\xC3a", -1];
        yield 'lead followed by ESC' => ["\xC3\e[A", -1];
        yield 'lead followed by lead' => ["\xC3\xC3\xA9", -1];
        yield '3-byte lead followed by ASCII' => ["\xE2a", -1];
        yield 'truncated 3-byte followed by ASCII' => ["\xE2\x9Ca", -2];
        yield 'truncated 4-byte followed by ASCII' => ["\xF0\x9F\x98a", -3];
        yield 'overlong 3-byte' => ["\xE0\x80\x80", -1];
        yield 'surrogate' => ["\xED\xA0\x80", -1];
        yield 'overlong 4-byte' => ["\xF0\x80\x80\x80", -1];
        yield 'above U+10FFFF' => ["\xF4\x90\x80\x80", -1];
    }

    #[DataProvider('provideSequences')]
    public function testMeasure(string $bytes, int $expected): void
    {
        static::assertSame($expected, Utf8Sequence::measure($bytes));
    }
}
