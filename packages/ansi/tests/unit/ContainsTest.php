<?php

declare(strict_types=1);

namespace Psl\Ansi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Ansi;

final class ContainsTest extends TestCase
{
    public function testTrueForStyledText(): void
    {
        static::assertTrue(Ansi\contains("\e[1mhello\e[0m"));
    }

    public function testFalseForPlainText(): void
    {
        static::assertFalse(Ansi\contains('hello world'));
    }

    public function testDetectsSgrSequence(): void
    {
        static::assertTrue(Ansi\contains("\e[31mred\e[0m"));
    }

    public function testDetectsCursorSequence(): void
    {
        static::assertTrue(Ansi\contains("\e[5Ahello"));
    }

    public function testDetectsScreenSequence(): void
    {
        static::assertTrue(Ansi\contains("\e[2Jclear"));
    }

    public function testDetectsOsc(): void
    {
        static::assertTrue(Ansi\contains("\e]2;My Title\e\\"));
    }

    public function testDetectsOscWithBel(): void
    {
        static::assertTrue(Ansi\contains("\e]2;My Title\x07"));
    }

    public function testDetectsHyperlink(): void
    {
        static::assertTrue(Ansi\contains("\e]8;;https://example.com\e\\click\e]8;;\e\\"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSequences(): iterable
    {
        yield 'CSI with > private marker (kitty keyboard push)' => ["\e[>1u"];
        yield 'CSI with < private marker (kitty keyboard pop)' => ["\e[<u"];
        yield 'CSI with = private marker' => ["\e[=1;2u"];
        yield 'CSI with intermediate byte (DECSCUSR)' => ["\e[2 q"];
        yield 'CSI with colon sub-parameters' => ["\e[38:2::255:0:0m"];
        yield 'CSI with non-letter final byte' => ["\e[2@"];
        yield 'DCS' => ["\eP\$q m\e\\"];
        yield 'APC (kitty graphics)' => ["\e_Gf=100,a=T;AAAA\e\\"];
        yield 'PM' => ["\e^privacy message\e\\"];
        yield 'SOS' => ["\eXstart of string\e\\"];
        yield 'SS3' => ["\eOP"];
        yield 'DECSC' => ["\e7"];
        yield 'RIS' => ["\ec"];
        yield 'character set designation' => ["\e(B"];
    }

    #[DataProvider('provideSequences')]
    public function testDetectsSequence(string $text): void
    {
        static::assertTrue(Ansi\contains('hello ' . $text . ' world'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTextWithoutSequences(): iterable
    {
        yield 'lone ESC at the end' => ["hello\e"];
        yield 'ESC followed by a control character' => ["hello\e\nworld"];
        yield 'incomplete CSI' => ["hello\e["];
        yield 'unterminated OSC' => ["hello\e]2;title"];
        yield 'lone SS3 at the end' => ["hello\eO"];
        yield 'lone SS2 at the end' => ["hello\eN"];
        yield 'SS3 followed by a control character' => ["hello\eO\nworld"];
    }

    #[DataProvider('provideTextWithoutSequences')]
    public function testFalseForIncompleteSequences(string $text): void
    {
        static::assertFalse(Ansi\contains($text));
    }
}
