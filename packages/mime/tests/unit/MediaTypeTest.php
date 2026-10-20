<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\Exception\InvalidMediaTypeComponentException;
use Psl\MIME\Exception\MediaTypeParsingException;
use Psl\MIME\MediaType;
use Psl\MIME\Parameters;
use Psl\Str;
use Stringable;

final class MediaTypeTest extends TestCase
{
    public function testConstructionBasic(): void
    {
        $type = new MediaType('text', 'plain');

        static::assertSame('text', $type->type);
        static::assertSame('plain', $type->subtype);
        static::assertSame('', $type->suffix);
        static::assertSame('', $type->tree);
        static::assertSame(0, $type->parameters->count());
    }

    public function testConstructionNormalizesCase(): void
    {
        $type = new MediaType('TEXT', 'HTML');

        static::assertSame('text', $type->type);
        static::assertSame('html', $type->subtype);
    }

    public function testConstructionWithParameters(): void
    {
        $params = Parameters::fromPairs([['charset', 'utf-8']]);
        $type = new MediaType('text', 'html', $params);

        static::assertSame('utf-8', $type->parameters->get('charset'));
    }

    public function testInvalidTypeThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('', 'plain');
    }

    public function testInvalidSubtypeThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('text', '');
    }

    public function testTypeWithSpaceThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('te xt', 'plain');
    }

    public function testParseSimple(): void
    {
        $type = MediaType::parse('application/json');

        static::assertSame('application', $type->type);
        static::assertSame('json', $type->subtype);
        static::assertSame(0, $type->parameters->count());
    }

    public function testParseWithParameters(): void
    {
        $type = MediaType::parse('text/html; charset=utf-8');

        static::assertSame('text', $type->type);
        static::assertSame('html', $type->subtype);
        static::assertSame('utf-8', $type->parameters->get('charset'));
    }

    public function testParseInvalid(): void
    {
        $this->expectException(MediaTypeParsingException::class);

        MediaType::parse('invalid');
    }

    public function testParseTrimsLinearWhitespace(): void
    {
        $type = MediaType::parse(" \t text/html; charset=utf-8 \t\r\n");

        static::assertSame('text/html', $type->essence());
        static::assertSame('utf-8', $type->parameters->get('charset'));
    }

    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function testParseRejectsSurroundingControlCharacters(string $char): void
    {
        $this->expectException(MediaTypeParsingException::class);

        MediaType::parse("{$char}text/html{$char}");
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

    public function testEssence(): void
    {
        $type = MediaType::parse('text/html; charset=utf-8');

        static::assertSame('text/html', $type->essence());
    }

    public function testToString(): void
    {
        $type = MediaType::parse('text/html; charset=utf-8');

        static::assertSame('text/html; charset=utf-8', $type->toString());
    }

    public function testToStringNoParams(): void
    {
        $type = new MediaType('application', 'json');

        static::assertSame('application/json', $type->toString());
    }

    public function testSuffixExtraction(): void
    {
        $type = new MediaType('application', 'vnd.api+json');

        static::assertSame('json', $type->suffix);
    }

    public function testSuffixExtractionXml(): void
    {
        $type = new MediaType('application', 'soap+xml');

        static::assertSame('xml', $type->suffix);
    }

    public function testNoSuffix(): void
    {
        $type = new MediaType('text', 'plain');

        static::assertSame('', $type->suffix);
    }

    public function testTreeVnd(): void
    {
        $type = new MediaType('application', 'vnd.company.app');

        static::assertSame('vnd', $type->tree);
    }

    public function testTreePrs(): void
    {
        $type = new MediaType('application', 'prs.custom');

        static::assertSame('prs', $type->tree);
    }

    public function testTreeX(): void
    {
        $type = new MediaType('application', 'x.custom');

        static::assertSame('x', $type->tree);
    }

    public function testNoTree(): void
    {
        $type = new MediaType('application', 'json');

        static::assertSame('', $type->tree);
    }

    public function testStandardsTreeNotExtracted(): void
    {
        $type = new MediaType('text', 'html');

        static::assertSame('', $type->tree);
    }

    public function testWildcardTypeRejected(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('*', 'html');
    }

    public function testWildcardSubtypeRejected(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('text', '*');
    }

    public function testParseWildcardRejected(): void
    {
        $this->expectException(MediaTypeParsingException::class);

        MediaType::parse('*/*');
    }

    public function testParseWildcardSubtypeRejected(): void
    {
        $this->expectException(MediaTypeParsingException::class);

        MediaType::parse('text/*');
    }

    public function testTreeAndSuffixCombined(): void
    {
        $type = new MediaType('application', 'vnd.company.app+json');

        static::assertSame('vnd', $type->tree);
        static::assertSame('json', $type->suffix);
    }

    public function testParseRoundTrip(): void
    {
        $input = 'application/vnd.api+json; charset=utf-8';
        $type = MediaType::parse($input);

        static::assertSame($input, $type->toString());
    }

    public function testStringable(): void
    {
        $type = new MediaType('text', 'html');

        static::assertInstanceOf(Stringable::class, $type);
        static::assertSame('text/html', (string) $type);
    }

    public function testTypeTooLongThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType(Str\repeat('a', 128), 'html');
    }

    public function testSubtypeTooLongThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('text', Str\repeat('a', 128));
    }

    public function testTypeMaxLength(): void
    {
        $type = new MediaType(Str\repeat('a', 127), 'html');

        static::assertSame(Str\repeat('a', 127), $type->type);
    }

    public function testXDashTreePrefix(): void
    {
        $type = new MediaType('application', 'x-custom');

        static::assertSame('x', $type->tree);
    }

    public function testSuffixTrailingPlus(): void
    {
        $type = new MediaType('application', 'vnd.test+');

        static::assertSame('', $type->suffix);
    }

    public function testNoSuffixNoDot(): void
    {
        $type = new MediaType('text', 'plain');

        static::assertSame('', $type->suffix);
        static::assertSame('', $type->tree);
    }

    public function testSubtypeWithDotNoTree(): void
    {
        $type = new MediaType('application', 'octet-stream');

        static::assertSame('', $type->tree);
    }

    public function testInvalidCharInTypeThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('te/xt', 'plain');
    }

    public function testInvalidCharInSubtypeThrows(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('text', 'pl@in');
    }

    public function testExtractTreeDotAtStart(): void
    {
        $type = new MediaType('application', 'json');

        static::assertSame('', $type->tree);

        $type2 = new MediaType('application', 'foo.bar');
        static::assertSame('', $type2->tree);
    }

    public function testExtractTreePrefixLogic(): void
    {
        $type = new MediaType('application', 'vnd.example');
        static::assertSame('vnd', $type->tree);

        $type2 = new MediaType('application', 'prs.example');
        static::assertSame('prs', $type2->tree);

        $type3 = new MediaType('application', 'x.example');
        static::assertSame('x', $type3->tree);

        $type4 = new MediaType('application', 'other.example');
        static::assertSame('', $type4->tree);
    }

    public function testValidateComponentFirstCharacter(): void
    {
        $type = new MediaType('a', 'b');
        static::assertSame('a', $type->type);
        static::assertSame('b', $type->subtype);
    }

    public function testFromExtensionCaseInsensitive(): void
    {
        $lower = MediaType::fromExtension('json');
        $upper = MediaType::fromExtension('JSON');
        $mixed = MediaType::fromExtension('Json');

        static::assertNotNull($lower);
        static::assertNotNull($upper);
        static::assertNotNull($mixed);

        static::assertSame($lower->essence(), $upper->essence());
        static::assertSame($lower->essence(), $mixed->essence());
    }

    public function testFromExtensionUnknownReturnsNull(): void
    {
        $result = MediaType::fromExtension('zzz_unknown_ext');

        static::assertNull($result);
    }

    public function testFromExtensionReturnsCorrectType(): void
    {
        $result = MediaType::fromExtension('html');

        static::assertNotNull($result);
        static::assertSame('text', $result->type);
        static::assertSame('html', $result->subtype);
    }

    public function testSuffixSingleCharAfterPlus(): void
    {
        $type = new MediaType('application', 'vnd+x');

        static::assertSame('x', $type->suffix);
    }

    public function testSuffixTwoCharSubtypeWithPlus(): void
    {
        $type = new MediaType('application', 'a+b');

        static::assertSame('b', $type->suffix);
    }

    public function testSuffixPlusInMiddleReturnsCorrectSuffix(): void
    {
        $type = new MediaType('application', 'soap+xml');

        static::assertSame('xml', $type->suffix);
    }

    public function testFromExtensionMultibyteCharacterHandled(): void
    {
        $result = MediaType::fromExtension("\xC3\xA9");

        static::assertNull($result);
    }

    public function testExtractSuffixTrailingPlusReturnsEmpty(): void
    {
        $type = new MediaType('application', 'vnd.test+');

        static::assertSame('', $type->suffix);
    }

    public function testExtractSuffixTrailingPlusMutationMinusToPlus(): void
    {
        $type = new MediaType('application', 'a+b');
        static::assertSame('b', $type->suffix);

        $type2 = new MediaType('application', 'vnd.api+json');
        static::assertSame('json', $type2->suffix);
    }

    public function testExtractSuffixDecrementMutation(): void
    {
        $type = new MediaType('application', 'vnd.api+json');

        static::assertSame('json', $type->suffix);
        static::assertNotSame('', $type->suffix);
    }

    public function testValidateComponentStartsAtZero(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('@test', 'html');
    }

    public function testFromExtensionMultibyteUpperCase(): void
    {
        $lower = MediaType::fromExtension('json');
        $upper = MediaType::fromExtension('JSON');

        static::assertNotNull($lower);
        static::assertNotNull($upper);
        static::assertSame($lower->essence(), $upper->essence());
    }

    public function testFromExtensionMixedCase(): void
    {
        $result = MediaType::fromExtension('Html');

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testFromExtensionAllUpperCase(): void
    {
        $result = MediaType::fromExtension('CSS');

        static::assertNotNull($result);
        static::assertSame('text', $result->type);
    }

    public function testExtractSuffixTrailingPlusReturnsEmptyString(): void
    {
        $type = new MediaType('application', 'vnd.test+');

        static::assertSame('', $type->suffix);
    }

    public function testExtractSuffixSingleCharSuffix(): void
    {
        $type = new MediaType('application', 'a+b');

        static::assertSame('b', $type->suffix);
    }

    public function testExtractSuffixLongerSuffix(): void
    {
        $type = new MediaType('application', 'vnd.api+json');

        static::assertSame('json', $type->suffix);
    }

    public function testExtractSuffixNoPlus(): void
    {
        $type = new MediaType('text', 'plain');

        static::assertSame('', $type->suffix);
    }

    public function testExtractSuffixPlusAtEnd(): void
    {
        $type = new MediaType('application', 'test+');

        static::assertSame('', $type->suffix);
    }

    public function testExtractSuffixPlusAtEndNotExtracted(): void
    {
        $type = new MediaType('application', 'xml+');

        static::assertSame('', $type->suffix);
    }

    public function testExtractTreeDotAtPositionZeroReturnsEmpty(): void
    {
        $type = new MediaType('application', 'json');

        static::assertSame('', $type->tree);
    }

    public function testExtractTreeDotAtFirstPositionNotExtracted(): void
    {
        $type = new MediaType('application', 'foo.bar');

        static::assertSame('', $type->tree);
    }

    public function testExtractTreeVndPrefix(): void
    {
        $type = new MediaType('application', 'vnd.example.test');

        static::assertSame('vnd', $type->tree);
    }

    public function testValidateComponentRejectsInvalidFirstCharInType(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('~test', 'html');
    }

    public function testValidateComponentRejectsInvalidFirstCharInSubtype(): void
    {
        $this->expectException(InvalidMediaTypeComponentException::class);

        new MediaType('text', '~html');
    }

    public function testValidateComponentAcceptsSingleCharType(): void
    {
        $type = new MediaType('a', 'b');

        static::assertSame('a', $type->type);
        static::assertSame('b', $type->subtype);
    }
}
