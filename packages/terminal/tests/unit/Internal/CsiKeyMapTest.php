<?php

declare(strict_types=1);

namespace Psl\Terminal\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Terminal\Internal\CsiKeyMap;

final class CsiKeyMapTest extends TestCase
{
    public function testMapSimpleArrowUp(): void
    {
        $key = CsiKeyMap::map('', 'A');

        static::assertNotNull($key);
        static::assertTrue($key->is('up'));
    }

    public function testMapSimpleArrowDown(): void
    {
        $key = CsiKeyMap::map('', 'B');

        static::assertNotNull($key);
        static::assertTrue($key->is('down'));
    }

    public function testMapSimpleArrowRight(): void
    {
        $key = CsiKeyMap::map('', 'C');

        static::assertNotNull($key);
        static::assertTrue($key->is('right'));
    }

    public function testMapSimpleArrowLeft(): void
    {
        $key = CsiKeyMap::map('', 'D');

        static::assertNotNull($key);
        static::assertTrue($key->is('left'));
    }

    public function testMapSimpleHome(): void
    {
        $key = CsiKeyMap::map('', 'H');

        static::assertNotNull($key);
        static::assertTrue($key->is('home'));
    }

    public function testMapSimpleEnd(): void
    {
        $key = CsiKeyMap::map('', 'F');

        static::assertNotNull($key);
        static::assertTrue($key->is('end'));
    }

    public function testMapSimpleShiftTab(): void
    {
        $key = CsiKeyMap::map('', 'Z');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+tab'));
    }

    public function testMapSimpleUnknownReturnsNull(): void
    {
        $key = CsiKeyMap::map('', 'X');

        static::assertNull($key);
    }

    public function testMapTildeHome(): void
    {
        $key = CsiKeyMap::map('1', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('home'));
    }

    public function testMapTildeInsert(): void
    {
        $key = CsiKeyMap::map('2', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('insert'));
    }

    public function testMapTildeDelete(): void
    {
        $key = CsiKeyMap::map('3', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('delete'));
    }

    public function testMapTildeEnd(): void
    {
        $key = CsiKeyMap::map('4', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('end'));
    }

    public function testMapTildePageUp(): void
    {
        $key = CsiKeyMap::map('5', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('page_up'));
    }

    public function testMapTildePageDown(): void
    {
        $key = CsiKeyMap::map('6', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('page_down'));
    }

    public function testMapTildeF5(): void
    {
        $key = CsiKeyMap::map('15', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f5'));
    }

    public function testMapTildeF6(): void
    {
        $key = CsiKeyMap::map('17', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f6'));
    }

    public function testMapTildeF7(): void
    {
        $key = CsiKeyMap::map('18', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f7'));
    }

    public function testMapTildeF8(): void
    {
        $key = CsiKeyMap::map('19', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f8'));
    }

    public function testMapTildeF9(): void
    {
        $key = CsiKeyMap::map('20', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f9'));
    }

    public function testMapTildeF10(): void
    {
        $key = CsiKeyMap::map('21', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f10'));
    }

    public function testMapTildeF11(): void
    {
        $key = CsiKeyMap::map('23', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f11'));
    }

    public function testMapTildeF12(): void
    {
        $key = CsiKeyMap::map('24', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('f12'));
    }

    public function testMapTildeUnknownReturnsNull(): void
    {
        $key = CsiKeyMap::map('99', '~');

        static::assertNull($key);
    }

    public function testMapTildeModifiedCtrlPageUp(): void
    {
        $key = CsiKeyMap::map('5;5', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+page_up'));
    }

    public function testMapTildeModifiedCtrlPageDown(): void
    {
        $key = CsiKeyMap::map('6;5', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+page_down'));
    }

    public function testMapTildeModifiedShiftHome(): void
    {
        $key = CsiKeyMap::map('1;2', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+home'));
    }

    public function testMapTildeModifiedAltEnd(): void
    {
        $key = CsiKeyMap::map('4;3', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('alt+end'));
    }

    public function testMapTildeModifiedShiftAltInsert(): void
    {
        $key = CsiKeyMap::map('2;4', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+alt+insert'));
    }

    public function testMapTildeModifiedCtrlShiftDelete(): void
    {
        $key = CsiKeyMap::map('3;6', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+shift+delete'));
    }

    public function testMapTildeModifiedCtrlAltPageUp(): void
    {
        $key = CsiKeyMap::map('5;7', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+alt+page_up'));
    }

    public function testMapTildeModifiedCtrlShiftAltPageDown(): void
    {
        $key = CsiKeyMap::map('6;8', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+shift+alt+page_down'));
    }

    public function testMapTildeModifiedUnknownBaseReturnsNull(): void
    {
        $key = CsiKeyMap::map('99;5', '~');

        static::assertNull($key);
    }

    public function testMapTildeThreePartsReturnsNull(): void
    {
        $key = CsiKeyMap::map('5;5;5', '~');

        static::assertNull($key);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSs3NamedKeys(): iterable
    {
        yield 'F1' => ['P', 'f1'];
        yield 'F2' => ['Q', 'f2'];
        yield 'F3' => ['R', 'f3'];
        yield 'F4' => ['S', 'f4'];
        yield 'application cursor up' => ['A', 'up'];
        yield 'application cursor down' => ['B', 'down'];
        yield 'application cursor right' => ['C', 'right'];
        yield 'application cursor left' => ['D', 'left'];
        yield 'application cursor home' => ['H', 'home'];
        yield 'application cursor end' => ['F', 'end'];
        yield 'keypad tab' => ['I', 'tab'];
        yield 'keypad enter' => ['M', 'enter'];
        yield 'rxvt ctrl+up' => ['a', 'ctrl+up'];
        yield 'rxvt ctrl+down' => ['b', 'ctrl+down'];
        yield 'rxvt ctrl+right' => ['c', 'ctrl+right'];
        yield 'rxvt ctrl+left' => ['d', 'ctrl+left'];
    }

    #[DataProvider('provideSs3NamedKeys')]
    public function testMapSs3NamedKey(string $final, string $expected): void
    {
        $key = CsiKeyMap::mapSs3($final);

        static::assertNotNull($key);
        static::assertNull($key->char);
        static::assertSame($expected, $key->name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSs3KeypadCharacters(): iterable
    {
        yield 'multiply' => ['j', '*'];
        yield 'add' => ['k', '+'];
        yield 'separator' => ['l', ','];
        yield 'subtract' => ['m', '-'];
        yield 'decimal' => ['n', '.'];
        yield 'divide' => ['o', '/'];
        yield 'equal' => ['X', '='];
        yield '0' => ['p', '0'];
        yield '5' => ['u', '5'];
        yield '9' => ['y', '9'];
    }

    #[DataProvider('provideSs3KeypadCharacters')]
    public function testMapSs3KeypadCharacter(string $final, string $expected): void
    {
        $key = CsiKeyMap::mapSs3($final);

        static::assertNotNull($key);
        static::assertSame($expected, $key->char);
    }

    public function testMapSs3UnknownReturnsNull(): void
    {
        static::assertNull(CsiKeyMap::mapSs3('Z'));
        static::assertNull(CsiKeyMap::mapSs3('E'));
    }

    public function testMapKittyKeyEnterNoModifier(): void
    {
        $key = CsiKeyMap::map('13', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('enter'));
    }

    public function testMapKittyKeyTabNoModifier(): void
    {
        $key = CsiKeyMap::map('9', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('tab'));
    }

    public function testMapKittyKeyEscapeNoModifier(): void
    {
        $key = CsiKeyMap::map('27', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('escape'));
    }

    public function testMapKittyKeyBackspaceNoModifier(): void
    {
        $key = CsiKeyMap::map('127', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('backspace'));
    }

    public function testMapKittyKeyPrintableCharNoModifier(): void
    {
        $key = CsiKeyMap::map('97', 'u');

        static::assertNotNull($key);
        static::assertSame('a', $key->char);
    }

    public function testMapKittyKeyPrintableCharWithModifier(): void
    {
        $key = CsiKeyMap::map('97;5', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+a'));
    }

    public function testMapKittyKeyEnterWithShift(): void
    {
        $key = CsiKeyMap::map('13;2', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+enter'));
    }

    public function testMapKittyKeyTabWithAlt(): void
    {
        $key = CsiKeyMap::map('9;3', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('alt+tab'));
    }

    public function testMapKittyKeyUnknownCodepointReturnsNull(): void
    {
        $key = CsiKeyMap::map('1', 'u');

        static::assertNull($key);
    }

    public function testMapKittyKeyNonAsciiCodepoint(): void
    {
        $key = CsiKeyMap::map('200', 'u');

        static::assertNotNull($key);
        static::assertSame('È', $key->char);
        static::assertSame('È', $key->name);
    }

    public function testMapModifiedArrowUp(): void
    {
        $key = CsiKeyMap::map('1;5', 'A');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+up'));
    }

    public function testMapModifiedArrowDown(): void
    {
        $key = CsiKeyMap::map('1;3', 'B');

        static::assertNotNull($key);
        static::assertTrue($key->is('alt+down'));
    }

    public function testMapModifiedArrowRight(): void
    {
        $key = CsiKeyMap::map('1;2', 'C');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+right'));
    }

    public function testMapModifiedArrowLeft(): void
    {
        $key = CsiKeyMap::map('1;6', 'D');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+shift+left'));
    }

    public function testMapModifiedHome(): void
    {
        $key = CsiKeyMap::map('1;5', 'H');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+home'));
    }

    public function testMapModifiedEnd(): void
    {
        $key = CsiKeyMap::map('1;5', 'F');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+end'));
    }

    public function testMapModifiedF1(): void
    {
        $key = CsiKeyMap::map('1;5', 'P');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+f1'));
    }

    public function testMapModifiedF2(): void
    {
        $key = CsiKeyMap::map('1;3', 'Q');

        static::assertNotNull($key);
        static::assertTrue($key->is('alt+f2'));
    }

    public function testMapModifiedF3(): void
    {
        $key = CsiKeyMap::map('1;2', 'R');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+f3'));
    }

    public function testMapModifiedF4(): void
    {
        $key = CsiKeyMap::map('1;7', 'S');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+alt+f4'));
    }

    public function testMapModifiedUnknownFinalReturnsNull(): void
    {
        $key = CsiKeyMap::map('1;5', 'X');

        static::assertNull($key);
    }

    public function testMapModifiedThreePartsReturnsNull(): void
    {
        $key = CsiKeyMap::map('1;5;3', 'A');

        static::assertNull($key);
    }

    public function testMapModifierPrefixDefaultIsEmpty(): void
    {
        $key = CsiKeyMap::map('1;1', 'A');

        static::assertNotNull($key);
        static::assertTrue($key->is('up'));
    }

    public function testMapModifierPrefixIgnoresUndefinedBits(): void
    {
        $key = CsiKeyMap::map('1;257', 'A');

        static::assertNotNull($key);
        static::assertTrue($key->is('up'));
    }

    public function testMapTildeModifiedDefaultModifierPrefix(): void
    {
        $key = CsiKeyMap::map('5;1', '~');

        static::assertNotNull($key);
        static::assertTrue($key->is('page_up'));
    }

    public function testMapKittyKeyPrintableSpaceNoModifier(): void
    {
        $key = CsiKeyMap::map('32', 'u');

        static::assertNotNull($key);
        static::assertSame(' ', $key->char);
    }

    public function testMapKittyKeyPrintableTildeNoModifier(): void
    {
        $key = CsiKeyMap::map('126', 'u');

        static::assertNotNull($key);
        static::assertSame('~', $key->char);
    }

    public function testMapKittyKeyPrintableWithShift(): void
    {
        $key = CsiKeyMap::map('65;2', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('shift+A'));
    }

    public function testMapKittyKeyPrintableWithCtrlShiftAlt(): void
    {
        $key = CsiKeyMap::map('97;8', 'u');

        static::assertNotNull($key);
        static::assertTrue($key->is('ctrl+shift+alt+a'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideKittyNamedKeys(): iterable
    {
        yield 'F1' => ['', 'P', 'f1'];
        yield 'F2' => ['', 'Q', 'f2'];
        yield 'F3 (CSI R)' => ['', 'R', 'f3'];
        yield 'F4' => ['', 'S', 'f4'];
        yield 'F1 tilde' => ['11', '~', 'f1'];
        yield 'F3 tilde' => ['13', '~', 'f3'];
        yield 'home tilde' => ['7', '~', 'home'];
        yield 'end tilde' => ['8', '~', 'end'];
        yield 'ctrl+F5' => ['15;5', '~', 'ctrl+f5'];
        yield 'shift+F12' => ['24;2', '~', 'shift+f12'];
        yield 'alt+F3 tilde' => ['13;3', '~', 'alt+f3'];
        yield 'F13' => ['57376', 'u', 'f13'];
        yield 'F35' => ['57398', 'u', 'f35'];
        yield 'ctrl+F13' => ['57376;5', 'u', 'ctrl+f13'];
        yield 'keypad enter' => ['57414', 'u', 'enter'];
        yield 'keypad left' => ['57417', 'u', 'left'];
        yield 'keypad right' => ['57418', 'u', 'right'];
        yield 'keypad up' => ['57419', 'u', 'up'];
        yield 'keypad down' => ['57420', 'u', 'down'];
        yield 'keypad page up' => ['57421', 'u', 'page_up'];
        yield 'keypad page down' => ['57422', 'u', 'page_down'];
        yield 'keypad home' => ['57423', 'u', 'home'];
        yield 'keypad end' => ['57424', 'u', 'end'];
        yield 'keypad insert' => ['57425', 'u', 'insert'];
        yield 'keypad delete' => ['57426', 'u', 'delete'];
        yield 'shift+keypad up' => ['57419;2', 'u', 'shift+up'];
        yield 'ctrl+space' => ['32;5', 'u', 'ctrl+space'];
        yield 'alt+non-ASCII' => ['233;3', 'u', 'alt+é'];
        yield 'alternate key sub-parameters' => ['97:65;6', 'u', 'ctrl+shift+a'];
        yield 'associated text field' => ['97;5;97', 'u', 'ctrl+a'];
        yield 'press event type' => ['97;5:1', 'u', 'ctrl+a'];
        yield 'repeat event type' => ['97;5:2', 'u', 'ctrl+a'];
        yield 'repeat of a legacy key' => ['1;1:2', 'A', 'up'];
        yield 'ctrl with caps lock' => ['97;69', 'u', 'ctrl+a'];
        yield 'ctrl with num lock' => ['1;133', 'A', 'ctrl+up'];
        yield 'ctrl+shift with caps and num lock' => ['5;198', '~', 'ctrl+shift+page_up'];
        yield 'ctrl+shift+space' => ['32;6', 'u', 'ctrl+shift+space'];
        yield 'alt+space keeps the space character' => ['32;3', 'u', 'alt+ '];
        yield 'shift+space keeps the space character' => ['32;2', 'u', 'shift+ '];
        yield 'super+F5' => ['15;9', '~', 'super+f5'];
        yield 'super+a' => ['97;9', 'u', 'super+a'];
        yield 'hyper+a' => ['97;17', 'u', 'hyper+a'];
        yield 'meta+a' => ['97;33', 'u', 'meta+a'];
        yield 'shift+super+up' => ['1;10', 'A', 'shift+super+up'];
        yield 'ctrl+super+F1' => ['1;13', 'P', 'ctrl+super+f1'];
        yield 'alt+meta+up with caps lock' => ['1;99', 'A', 'alt+meta+up'];
        yield 'every modifier' => ['1;64', 'A', 'ctrl+shift+alt+super+hyper+meta+up'];
        yield 'super+keypad 5' => ['57404;9', 'u', 'super+5'];
    }

    #[DataProvider('provideKittyNamedKeys')]
    public function testMapKittyNamedKey(string $params, string $final, string $expected): void
    {
        $key = CsiKeyMap::map($params, $final);

        static::assertNotNull($key);
        static::assertNull($key->char);
        static::assertSame($expected, $key->name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideKittyTextKeys(): iterable
    {
        yield 'latin small letter e with acute' => ['233', 'é'];
        yield 'cyrillic' => ['1078', 'ж'];
        yield 'CJK' => ['20013', '中'];
        yield 'astral' => ['128512', "\u{1F600}"];
        yield 'keypad 0' => ['57399', '0'];
        yield 'keypad 9' => ['57408', '9'];
        yield 'keypad decimal' => ['57409', '.'];
        yield 'keypad divide' => ['57410', '/'];
        yield 'keypad multiply' => ['57411', '*'];
        yield 'keypad subtract' => ['57412', '-'];
        yield 'keypad add' => ['57413', '+'];
        yield 'keypad equal' => ['57415', '='];
        yield 'keypad separator' => ['57416', ','];
        yield 'num lock only' => ['233;129', 'é'];
    }

    #[DataProvider('provideKittyTextKeys')]
    public function testMapKittyTextKey(string $params, string $expected): void
    {
        $key = CsiKeyMap::map($params, 'u');

        static::assertNotNull($key);
        static::assertSame($expected, $key->char);
        static::assertSame($expected, $key->name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideKittyIgnoredSequences(): iterable
    {
        yield 'release of a text key' => ['97;1:3', 'u'];
        yield 'release of a modified key' => ['97;5:3', 'u'];
        yield 'release of enter' => ['13;1:3', 'u'];
        yield 'release of an arrow key' => ['1;1:3', 'A'];
        yield 'release of a tilde key' => ['5;5:3', '~'];
        yield 'release of F1' => ['1;1:3', 'P'];
        yield 'caps lock key' => ['57358', 'u'];
        yield 'media play key' => ['57428', 'u'];
        yield 'left shift key' => ['57441', 'u'];
        yield 'keypad begin' => ['57427', 'u'];
        yield 'surrogate' => ['55296', 'u'];
        yield 'above U+10FFFF' => ['1114112', 'u'];
        yield 'C1 control' => ['133', 'u'];
        yield 'keyboard flags reply' => ['?1', 'u'];
    }

    #[DataProvider('provideKittyIgnoredSequences')]
    public function testMapKittyIgnoredSequence(string $params, string $final): void
    {
        static::assertNull(CsiKeyMap::map($params, $final));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideRxvtModifiedKeys(): iterable
    {
        yield 'shift+delete' => ['3', '$', 'shift+delete'];
        yield 'shift+home' => ['7', '$', 'shift+home'];
        yield 'shift+F11' => ['23', '$', 'shift+f11'];
        yield 'ctrl+insert' => ['2', '^', 'ctrl+insert'];
        yield 'ctrl+page down' => ['6', '^', 'ctrl+page_down'];
        yield 'ctrl+F1' => ['11', '^', 'ctrl+f1'];
        yield 'ctrl+shift+end' => ['8', '@', 'ctrl+shift+end'];
    }

    #[DataProvider('provideRxvtModifiedKeys')]
    public function testMapRxvtModifiedKey(string $params, string $final, string $expected): void
    {
        $key = CsiKeyMap::map($params, $final);

        static::assertNotNull($key);
        static::assertNull($key->char);
        static::assertSame($expected, $key->name);
    }

    public function testMapRxvtFinalWithoutKnownNumberReturnsNull(): void
    {
        static::assertNull(CsiKeyMap::map('', '@'));
        static::assertNull(CsiKeyMap::map('99', '^'));
        static::assertNull(CsiKeyMap::map('3;5', '$'));
    }

    public function testMapUnknownFinalByteReturnsNull(): void
    {
        static::assertNull(CsiKeyMap::map('5', '{'));
        static::assertNull(CsiKeyMap::map('', '`'));
    }

    public function testMapLinuxConsoleFunctionKey(): void
    {
        foreach (['A' => 'f1', 'B' => 'f2', 'C' => 'f3', 'D' => 'f4', 'E' => 'f5'] as $letter => $name) {
            $key = CsiKeyMap::mapLinuxConsoleFunctionKey($letter);

            static::assertNotNull($key);
            static::assertSame($name, $key->name);
        }

        static::assertNull(CsiKeyMap::mapLinuxConsoleFunctionKey('F'));
    }
}
