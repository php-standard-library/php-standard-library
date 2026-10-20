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

    public function testMapKittyKeyHighCodepointReturnsNull(): void
    {
        $key = CsiKeyMap::map('200', 'u');

        static::assertNull($key);
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

    public function testMapModifierPrefixUnknownValueIsEmpty(): void
    {
        $key = CsiKeyMap::map('1;99', 'A');

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
}
