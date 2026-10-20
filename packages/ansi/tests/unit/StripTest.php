<?php

declare(strict_types=1);

namespace Psl\Ansi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Ansi;

final class StripTest extends TestCase
{
    public function testStripsSgrCodes(): void
    {
        $styled = "\e[1;31mhello\e[0m";

        static::assertSame('hello', Ansi\strip($styled));
    }

    public function testStripsCursorMovement(): void
    {
        $text = "\e[5Ahello";

        static::assertSame('hello', Ansi\strip($text));
    }

    public function testPlainTextUnchanged(): void
    {
        static::assertSame('hello world', Ansi\strip('hello world'));
    }

    public function testStripsMultipleSequences(): void
    {
        $text = "\e[1m\e[31mhello\e[0m \e[4mworld\e[0m";

        static::assertSame('hello world', Ansi\strip($text));
    }

    public function testStripsOscWithSt(): void
    {
        $text = "\e]2;My Title\e\\hello";

        static::assertSame('hello', Ansi\strip($text));
    }

    public function testStripsOscWithBel(): void
    {
        $text = "\e]2;My Title\x07hello";

        static::assertSame('hello', Ansi\strip($text));
    }

    public function testStripsHyperlink(): void
    {
        $text = "\e]8;;https://example.com\e\\click here\e]8;;\e\\";

        static::assertSame('click here', Ansi\strip($text));
    }

    public function testStripsMixedCsiAndOsc(): void
    {
        $text = "\e[1m\e]8;;https://example.com\e\\click here\e]8;;\e\\\e[0m";

        static::assertSame('click here', Ansi\strip($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSequences(): iterable
    {
        yield 'CSI with > private marker (kitty keyboard push)' => ["\e[>1uhello", 'hello'];
        yield 'CSI with < private marker (kitty keyboard pop)' => ["hello\e[<u", 'hello'];
        yield 'CSI with = private marker' => ["\e[=1;2uhello", 'hello'];
        yield 'CSI with ? private marker' => ["\e[?1049hhello\e[?1049l", 'hello'];
        yield 'CSI with intermediate byte (DECSCUSR)' => ["\e[2 qhello", 'hello'];
        yield 'CSI with intermediate byte (DECSTR)' => ["\e[!phello", 'hello'];
        yield 'CSI with colon sub-parameters' => ["\e[38:2::255:0:0mhello\e[0m", 'hello'];
        yield 'CSI with non-letter final byte' => ["\e[2@hello\e[3`", 'hello'];
        yield 'DCS terminated by ST' => ["\eP\$q m\e\\hello", 'hello'];
        yield 'DCS terminated by BEL' => ["\eP1\$r0m\x07hello", 'hello'];
        yield 'APC (kitty graphics)' => ["\e_Gf=100,a=T;AAAA\e\\hello", 'hello'];
        yield 'PM' => ["\e^privacy message\e\\hello", 'hello'];
        yield 'SOS' => ["\eXstart of string\e\\hello", 'hello'];
        yield 'SS3' => ["\eOPhello", 'hello'];
        yield 'SS2' => ["\eNahello", 'hello'];
        yield 'DECSC and DECRC' => ["\e7hello\e8", 'hello'];
        yield 'RIS' => ["\echello", 'hello'];
        yield 'DECKPAM and DECKPNM' => ["\e=hello\e>", 'hello'];
        yield 'reverse index' => ["\eMhello", 'hello'];
        yield 'character set designation' => ["\e(Bhello\e)0", 'hello'];
        yield 'DEC screen alignment test' => ["\e#8hello", 'hello'];
        yield 'lone ST' => ["hello\e\\", 'hello'];
        yield 'multi-byte text around sequences' => ["h\u{E9}llo \e[>1u\e_Ga=d\e\\w\u{F6}rld", "h\u{E9}llo w\u{F6}rld"];
    }

    #[DataProvider('provideSequences')]
    public function testStripsSequence(string $text, string $expected): void
    {
        static::assertSame($expected, Ansi\strip($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTextLeftUntouched(): iterable
    {
        yield 'lone ESC at the end' => ["hello\e"];
        yield 'ESC followed by a control character' => ["hello\e\nworld"];
        yield 'incomplete CSI' => ["hello\e["];
        yield 'unterminated OSC' => ["hello\e]2;title"];
        yield 'unterminated DCS' => ["hello\ePdata"];
        yield 'lone SS3 at the end' => ["hello\eO"];
        yield 'lone SS2 at the end' => ["hello\eN"];
        yield 'SS3 followed by a control character' => ["hello\eO\nworld"];
    }

    #[DataProvider('provideTextLeftUntouched')]
    public function testLeavesIncompleteSequencesUntouched(string $text): void
    {
        static::assertSame($text, Ansi\strip($text));
    }
}
