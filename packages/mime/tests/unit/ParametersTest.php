<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit;

use ArrayIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\Exception\InvalidArgumentException;
use Psl\MIME\Exception\InvalidMediaTypeComponentException;
use Psl\MIME\Exception\ParameterParsingException;
use Psl\MIME\Parameters;

use function strlen;

final class ParametersTest extends TestCase
{
    public function testParseSimple(): void
    {
        $params = Parameters::parse('; charset=utf-8');

        static::assertSame('utf-8', $params->get('charset'));
        static::assertTrue($params->has('charset'));
        static::assertFalse($params->has('boundary'));
        static::assertSame(1, $params->count());
    }

    public function testParseMultiple(): void
    {
        $params = Parameters::parse('; charset=utf-8; boundary=abc');

        static::assertSame('utf-8', $params->get('charset'));
        static::assertSame('abc', $params->get('boundary'));
        static::assertSame(2, $params->count());
    }

    public function testCaseInsensitiveLookup(): void
    {
        $params = Parameters::parse('; CHARSET=utf-8');

        static::assertSame('utf-8', $params->get('charset'));
        static::assertSame('utf-8', $params->get('CHARSET'));
        static::assertSame('utf-8', $params->get('Charset'));
    }

    public function testParseEmpty(): void
    {
        $params = Parameters::parse('');

        static::assertSame([], $params->all());
        static::assertSame(0, $params->count());
    }

    public function testFromPairs(): void
    {
        $params = Parameters::fromPairs([
            ['charset', 'utf-8'],
            ['boundary', 'abc'],
        ]);

        static::assertSame('utf-8', $params->get('charset'));
        static::assertSame('abc', $params->get('boundary'));
    }

    public function testFromPairsNormalizesNames(): void
    {
        $params = Parameters::fromPairs([
            ['CHARSET', 'utf-8'],
        ]);

        static::assertSame('utf-8', $params->get('charset'));
    }

    public function testFromPairsInvalidName(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ['invalid name', 'value'],
        ]);
    }

    public function testFromPairsEmptyName(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ['', 'value'],
        ]);
    }

    public function testEmpty(): void
    {
        $params = Parameters::default();

        static::assertSame([], $params->all());
        static::assertSame(0, $params->count());
        static::assertSame('', $params->toString());
    }

    public function testEmptySingleton(): void
    {
        static::assertSame(Parameters::default(), Parameters::default());
    }

    public function testGetReturnsNullForMissing(): void
    {
        $params = Parameters::parse('; charset=utf-8');

        static::assertNull($params->get('boundary'));
    }

    public function testAll(): void
    {
        $params = Parameters::parse('; charset=utf-8; boundary=abc');

        static::assertSame([['charset', 'utf-8'], ['boundary', 'abc']], $params->all());
    }

    public function testToStringSimple(): void
    {
        $params = Parameters::parse('; charset=utf-8');

        static::assertSame('; charset=utf-8', $params->toString());
    }

    public function testToStringMultiple(): void
    {
        $params = Parameters::parse('; charset=utf-8; boundary=abc');

        static::assertSame('; charset=utf-8; boundary=abc', $params->toString());
    }

    public function testToStringQuotesValuesWithSpecialChars(): void
    {
        $params = Parameters::fromPairs([
            ['boundary', '----=_Part 123'],
        ]);

        static::assertSame('; boundary="----=_Part 123"', $params->toString());
    }

    public function testToStringEscapesQuotesInValues(): void
    {
        $params = Parameters::fromPairs([
            ['filename', 'file"name.txt'],
        ]);

        static::assertSame('; filename="file\\"name.txt"', $params->toString());
    }

    public function testToStringEmptyValue(): void
    {
        $params = Parameters::fromPairs([
            ['boundary', ''],
        ]);

        static::assertSame('; boundary=""', $params->toString());
    }

    public function testParseRoundTrip(): void
    {
        $input = '; charset=utf-8; boundary=abc123';
        $params = Parameters::parse($input);
        $serialized = $params->toString();
        $reparsed = Parameters::parse($serialized);

        static::assertSame($params->all(), $reparsed->all());
    }

    public function testRfc2231Decoding(): void
    {
        $params = Parameters::parse("; title*=utf-8'en'This%20is%20fun");

        static::assertSame('This is fun', $params->get('title'));
    }

    public function testOrderPreserved(): void
    {
        $params = Parameters::parse('; z=1; a=2; m=3');

        $all = $params->all();
        static::assertSame('z', $all[0][0]);
        static::assertSame('a', $all[1][0]);
        static::assertSame('m', $all[2][0]);
    }

    public function testInvalidParameterThrows(): void
    {
        $this->expectException(ParameterParsingException::class);

        Parameters::parse('; =noname');
    }

    public function testParseTrimsLinearWhitespace(): void
    {
        $params = Parameters::parse(" \t; charset=utf-8 \t\r\n");

        static::assertSame('utf-8', $params->get('charset'));
    }

    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function testParseRejectsTrailingControlCharacters(string $char): void
    {
        $this->expectException(ParameterParsingException::class);

        Parameters::parse("; charset=utf-8{$char}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonWhitespaceControlCharacters(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    public function testCountableInterface(): void
    {
        $params = Parameters::fromPairs([
            ['a', '1'],
            ['b', '2'],
        ]);

        static::assertCount(2, $params);
    }

    public function testIteratorAggregate(): void
    {
        $params = Parameters::fromPairs([
            ['charset', 'utf-8'],
            ['boundary', 'abc'],
        ]);

        $collected = [];
        foreach ($params as $pair) {
            $collected[] = $pair;
        }

        static::assertSame([['charset', 'utf-8'], ['boundary', 'abc']], $collected);
    }

    public function testDefaultReturnsEmpty(): void
    {
        $params = Parameters::default();

        static::assertSame(0, $params->count());
        static::assertSame('', $params->toString());
    }

    public function testStringable(): void
    {
        $params = Parameters::fromPairs([['charset', 'utf-8']]);

        static::assertSame('; charset=utf-8', (string) $params);
    }

    public function testToStringEscapesBackslash(): void
    {
        $params = Parameters::fromPairs([
            ['path', 'C:\\Users\\test'],
        ]);

        static::assertSame('; path="C:\\\\Users\\\\test"', $params->toString());
    }

    public function testToStringBackslashAndQuoteCombined(): void
    {
        $params = Parameters::fromPairs([
            ['value', 'a\\"b'],
        ]);

        $serialized = $params->toString();
        $reparsed = Parameters::parse($serialized);

        static::assertSame('a\\"b', $reparsed->get('value'));
    }

    public function testFromPairsHighByteInNameThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ["\x80name", 'value'],
        ]);
    }

    public function testFromPairsSpecialCharInNameThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ['na(me', 'value'],
        ]);
    }

    public function testGetIteratorReturnsArrayIterator(): void
    {
        $params = Parameters::fromPairs([['a', '1']]);

        static::assertInstanceOf(ArrayIterator::class, $params->getIterator());
    }

    public function testFromPairsDelCharInNameThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ["na\x7Fme", 'value'],
        ]);
    }

    public function testToStringQuotesValueWithDelChar(): void
    {
        $params = Parameters::fromPairs([
            ['name', "val\x7Fue"],
        ]);

        static::assertStringContainsString('"', $params->toString());
    }

    public function testToStringEmptyReturnsEmptyString(): void
    {
        $params = Parameters::fromPairs([]);
        $result = $params->toString();
        static::assertSame('', $result);
        static::assertNotNull($result);
    }

    public function testSingleCharTokenValueNotQuoted(): void
    {
        $params = Parameters::fromPairs([['a', 'x']]);
        static::assertSame('; a=x', $params->toString());
    }

    public function testSingleCharParameterNameAccepted(): void
    {
        $params = Parameters::fromPairs([['a', 'value']]);
        static::assertSame('value', $params->get('a'));
    }

    public function testParameterNameStartingWithSpaceThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            [' name', 'value'],
        ]);
    }

    public function testToStringEmptyPairsReturnsEmptyStringNotNull(): void
    {
        $params = Parameters::default();

        $result = $params->toString();

        static::assertSame('', $result);
        static::assertIsString($result);
    }

    public function testToStringWithPairsReturnsNonEmpty(): void
    {
        $params = Parameters::fromPairs([['charset', 'utf-8']]);

        $result = $params->toString();

        static::assertNotSame('', $result);
        static::assertSame('; charset=utf-8', $result);
    }

    public function testIsTokenChecksFirstCharacter(): void
    {
        $params = Parameters::fromPairs([
            ['name', "\x01value"],
        ]);

        $result = $params->toString();
        static::assertStringContainsString('"', $result);
    }

    public function testValidateParameterNameChecksFirstCharacter(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ["\x01a", 'value'],
        ]);
    }

    public function testToStringEmptyPairsReturnsEmptyAndDoesNotFallThrough(): void
    {
        $params = Parameters::fromPairs([]);

        $result = $params->toString();

        static::assertSame('', $result);
        static::assertSame(0, strlen($result));
    }

    public function testToStringEmptyVsNonEmptyAreDifferent(): void
    {
        $empty = Parameters::fromPairs([]);
        $nonEmpty = Parameters::fromPairs([['a', 'b']]);

        static::assertSame('', $empty->toString());
        static::assertNotSame('', $nonEmpty->toString());
        static::assertNotSame($empty->toString(), $nonEmpty->toString());
    }

    public function testToStringReturnTypeForEmptyPairs(): void
    {
        $params = Parameters::default();
        $result = $params->toString();

        static::assertSame('', $result);
        static::assertEmpty($result);
    }

    public function testRejectsNullAtIndexZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Parameters::fromPairs([
            ['key', "\x00rest"],
        ]);
    }

    public function testIsTokenIteratesFromZeroIndex(): void
    {
        $params = Parameters::fromPairs([
            ['key', "ab\x01cd"],
        ]);

        $result = $params->toString();
        static::assertStringContainsString('"', $result);
    }

    public function testIsTokenAllPrintableAsciiNoQuotes(): void
    {
        $params = Parameters::fromPairs([
            ['key', 'simple'],
        ]);

        static::assertSame('; key=simple', $params->toString());
        static::assertStringNotContainsString('"', $params->toString());
    }

    public function testValidateParameterNameIteratesFromZeroIndex(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ["\x00valid", 'value'],
        ]);
    }

    public function testValidateParameterNameWithControlCharInMiddle(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        Parameters::fromPairs([
            ["ab\x01cd", 'value'],
        ]);
    }

    public function testValidateParameterNameSingleValidCharAccepted(): void
    {
        $params = Parameters::fromPairs([['x', 'v']]);
        static::assertSame('v', $params->get('x'));
    }

    #[DataProvider('unsafeParsedValues')]
    public function testUnsafeParsedValueThrowsParameterParsingException(string $input): void
    {
        $this->expectException(ParameterParsingException::class);

        Parameters::parse($input);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeParsedValues(): iterable
    {
        yield 'quoted NUL' => ["; filename=\"before\0after\""];
        yield 'encoded NUL' => ["; filename*=utf-8''before%00after"];
        yield 'encoded CR' => ["; filename*=utf-8''before%0Dafter"];
        yield 'encoded LF' => ["; filename*=utf-8''before%0Aafter"];
    }
}
