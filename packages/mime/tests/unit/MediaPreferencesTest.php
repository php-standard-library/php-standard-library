<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\Exception\MediaTypeParsingException;
use Psl\MIME\MediaPreferences;
use Psl\MIME\MediaRange;
use Psl\MIME\MediaType;

use function array_map;

final class MediaPreferencesTest extends TestCase
{
    public function testParseSimple(): void
    {
        $prefs = MediaPreferences::parse('text/html');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame(1.0, $prefs->ranges[0]->weight);
    }

    public function testParseMultiple(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        static::assertCount(2, $prefs->ranges);
        static::assertSame(1.0, $prefs->ranges[0]->weight);
        static::assertSame(1.0, $prefs->ranges[1]->weight);
    }

    public function testParseWithWeights(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json;q=0.9, */*;q=0.1');

        static::assertCount(3, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame(1.0, $prefs->ranges[0]->weight);
        static::assertSame('application/json', $prefs->ranges[1]->essence());
        static::assertSame(0.9, $prefs->ranges[1]->weight);
        static::assertSame('*/*', $prefs->ranges[2]->essence());
        static::assertSame(0.1, $prefs->ranges[2]->weight);
    }

    public function testParseSingle(): void
    {
        $prefs = MediaPreferences::parse('*/*');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('*/*', $prefs->ranges[0]->essence());
    }

    public function testParseEmpty(): void
    {
        $prefs = MediaPreferences::parse('');

        static::assertCount(0, $prefs->ranges);
    }

    public function testParseTrimsWhitespace(): void
    {
        $prefs = MediaPreferences::parse(' text/html , application/json ');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    public function testRangesSortedByWeight(): void
    {
        $prefs = MediaPreferences::parse('application/xml;q=0.5, text/html;q=0.9, application/json;q=0.7');

        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('application/json', $prefs->ranges[1]->essence());
        static::assertSame('application/xml', $prefs->ranges[2]->essence());
    }

    public function testRangesSortedBySpecificity(): void
    {
        $prefs = MediaPreferences::parse('*/*,text/*,text/html');

        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('text/*', $prefs->ranges[1]->essence());
        static::assertSame('*/*', $prefs->ranges[2]->essence());
    }

    public function testBestForExactMatch(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');
        $type = new MediaType('application', 'json');

        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('application/json', $best->essence());
    }

    public function testBestForWildcardMatch(): void
    {
        $prefs = MediaPreferences::parse('text/*, application/json');
        $type = new MediaType('text', 'plain');

        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/*', $best->essence());
    }

    public function testBestForNoMatch(): void
    {
        $prefs = MediaPreferences::parse('text/html');
        $type = new MediaType('image', 'png');

        $best = $prefs->bestFor($type);

        static::assertNull($best);
    }

    public function testBestForPrefersHigherWeight(): void
    {
        $prefs = MediaPreferences::parse('text/*;q=0.5, */*;q=0.9');
        $type = new MediaType('text', 'html');

        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('*/*', $best->essence());
    }

    public function testNegotiatePicksBest(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json;q=0.9');

        $available = [
            new MediaType('application', 'json'),
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateReturnsNull(): void
    {
        $prefs = MediaPreferences::parse('text/html');

        $available = [
            new MediaType('image', 'png'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNull($result);
    }

    public function testNegotiateSkipsZeroWeight(): void
    {
        $prefs = MediaPreferences::parse('text/html;q=0, application/json');

        $available = [
            new MediaType('text', 'html'),
            new MediaType('application', 'json'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('application/json', $result->essence());
    }

    public function testNegotiateSpecificityTiebreak(): void
    {
        $prefs = MediaPreferences::parse('text/html, text/*');

        $available = [
            new MediaType('text', 'plain'),
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testCountable(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json, */*');

        static::assertCount(3, $prefs);
    }

    public function testIteratorAggregate(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        $ranges = [];
        foreach ($prefs as $range) {
            static::assertInstanceOf(MediaRange::class, $range);
            $ranges[] = $range;
        }

        static::assertCount(2, $ranges);
    }

    public function testStringable(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        static::assertSame($prefs->toString(), (string) $prefs);
    }

    public function testToStringRoundTrip(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json;q=0.9, */*;q=0.1');

        $string = $prefs->toString();

        static::assertSame('text/html, application/json; q=0.9, */*; q=0.1', $string);
    }

    public function testFrom(): void
    {
        $range1 = new MediaRange('text', 'html', weight: 0.8);
        $range2 = new MediaRange('application', 'json', weight: 1.0);

        $prefs = MediaPreferences::from($range1, $range2);

        static::assertCount(2, $prefs->ranges);
        static::assertSame('application/json', $prefs->ranges[0]->essence());
        static::assertSame('text/html', $prefs->ranges[1]->essence());
    }

    public function testParseQuotedComma(): void
    {
        $prefs = MediaPreferences::parse('text/html; boundary="hello,world", application/json');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('hello,world', $prefs->ranges[0]->parameters->get('boundary'));
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    public function testParseMultipleQuotedCommas(): void
    {
        $prefs = MediaPreferences::parse('text/html; boundary="a,b,c", application/json; name="x,y"');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('a,b,c', $prefs->ranges[0]->parameters->get('boundary'));
        static::assertSame('x,y', $prefs->ranges[1]->parameters->get('name'));
    }

    public function testParseWhitespaceOnlyIsEmpty(): void
    {
        $prefs = MediaPreferences::parse('   ');

        static::assertCount(0, $prefs->ranges);
    }

    public function testToStringReturnsMediaRangeStrings(): void
    {
        $prefs = MediaPreferences::parse('text/html');

        static::assertSame('text/html', $prefs->toString());
    }

    public function testBestForPrefersMoreSpecificAtSameWeight(): void
    {
        $prefs = MediaPreferences::from(new MediaRange('*', '*'), new MediaRange('text', 'html'));

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testNegotiateWithEqualWeightPrefersMoreSpecific(): void
    {
        $prefs = MediaPreferences::parse('text/*, text/html');

        $available = [
            new MediaType('text', 'plain'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/plain', $result->essence());
    }

    public function testParseTrimmedInput(): void
    {
        $prefs = MediaPreferences::parse('  text/html  ');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }

    public function testParseEmptyStringReturnsEmptyPreferences(): void
    {
        $prefs = MediaPreferences::parse('');

        static::assertCount(0, $prefs->ranges);
        static::assertSame('', $prefs->toString());
    }

    public function testParseTrimsEachRangePart(): void
    {
        $prefs = MediaPreferences::parse('  text/html  ,  application/json  ');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    public function testFromCreatesValidPreferences(): void
    {
        $range1 = new MediaRange('text', 'html', weight: 0.8);
        $range2 = new MediaRange('application', 'json', weight: 1.0);

        $prefs = MediaPreferences::from($range1, $range2);

        static::assertCount(2, $prefs->ranges);
        static::assertSame('application/json', $prefs->ranges[0]->essence());
        static::assertSame('text/html', $prefs->ranges[1]->essence());
    }

    public function testBestForContinuesAfterFirstMatch(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 0.5),
            new MediaRange('text', 'html', weight: 0.9),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
        static::assertSame(0.9, $best->weight);
    }

    public function testBestForSpecificityTiebreakStrictGreaterThan(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('text', '*', weight: 0.9),
            new MediaRange('text', 'html', weight: 0.9),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testBestForDoesNotPromoteOnUnequalWeight(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 0.9),
            new MediaRange('text', 'html', weight: 0.5),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('*/*', $best->essence());
    }

    public function testNegotiateWithZeroWeightOnly(): void
    {
        $prefs = MediaPreferences::parse('text/html;q=0');

        $available = [
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNull($result);
    }

    public function testNegotiateSpecificityInitialValue(): void
    {
        $prefs = MediaPreferences::parse('text/html;q=0.5, text/*;q=0.5');

        $available = [
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateContinuesWhenNoMatch(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        $available = [
            new MediaType('image', 'png'),
            new MediaType('application', 'json'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('application/json', $result->essence());
    }

    public function testNegotiatePicksHigherWeightOverSpecificity(): void
    {
        $prefs = MediaPreferences::parse('text/*;q=0.9, text/html;q=0.5');

        $available = [
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateFirstAvailableWins(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        $available = [
            new MediaType('text', 'html'),
            new MediaType('application', 'json'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateSpecificityTiebreakStrict(): void
    {
        $prefs = MediaPreferences::parse('text/html, text/*');

        $available = [
            new MediaType('text', 'plain'),
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testToStringUsesRangeToString(): void
    {
        $prefs = MediaPreferences::parse('text/html; charset=utf-8, application/json; q=0.9');

        $string = $prefs->toString();

        static::assertStringContainsString('text/html', $string);
        static::assertStringContainsString('charset=utf-8', $string);
        static::assertStringContainsString('application/json', $string);
        static::assertStringContainsString('q=0.9', $string);
    }

    public function testMostSpecificMatchReturnsHighestSpecificity(): void
    {
        $prefs = MediaPreferences::parse('*/*;q=0.1, text/*;q=0.5, text/html;q=0.9');

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testSplitRangesRespectsQuotedStrings(): void
    {
        $prefs = MediaPreferences::parse('text/html; boundary="a,b,c"');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('a,b,c', $prefs->ranges[0]->parameters->get('boundary'));
    }

    public function testSplitRangesQuoteAtStart(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        static::assertCount(2, $prefs->ranges);
    }

    public function testSplitRangesEscapedQuote(): void
    {
        $prefs = MediaPreferences::parse('text/html; boundary="a\\"b,c", application/json');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    public function testSplitRangesBackslashCheckIndex(): void
    {
        $prefs = MediaPreferences::parse('text/html; param="value", application/json');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('value', $prefs->ranges[0]->parameters->get('param'));
    }

    public function testSplitRangesQuoteDetectionLogic(): void
    {
        $input = 'text/html; boundary="hello,world", application/json';
        $prefs = MediaPreferences::parse($input);

        static::assertCount(2, $prefs->ranges);
        static::assertSame('hello,world', $prefs->ranges[0]->parameters->get('boundary'));
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    public function testToStringCallsRangeToStringNotImplicitCast(): void
    {
        $prefs = MediaPreferences::parse('text/html; charset=utf-8, application/json; q=0.9');

        $string = $prefs->toString();

        static::assertSame('text/html; charset=utf-8, application/json; q=0.9', $string);
    }

    public function testMostSpecificMatchStrictGreaterThan(): void
    {
        $prefs = MediaPreferences::parse('text/*;q=0.3, text/html;q=0.3');

        $available = [
            new MediaType('text', 'html'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testMostSpecificMatchEqualSpecificityKeepsFirst(): void
    {
        $range1 = new MediaRange('text', 'html', weight: 0.5);
        $range2 = new MediaRange('text', 'plain', weight: 0.5);

        $prefs = MediaPreferences::from($range1, $range2);

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testSplitRangesFirstCharIsQuote(): void
    {
        $prefs = MediaPreferences::parse('text/html; param="val,ue"');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('val,ue', $prefs->ranges[0]->parameters->get('param'));
    }

    public function testParseWhitespaceAroundInputIsTrimmed(): void
    {
        $prefs = MediaPreferences::parse("  \t text/html \t  ");

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }

    public function testParseEmptyInputReturnsEmptyAndDoesNotThrow(): void
    {
        $prefs = MediaPreferences::parse('');

        static::assertSame([], $prefs->ranges);
        static::assertCount(0, $prefs);
    }

    public function testParseWhitespaceOnlyInputReturnsEmpty(): void
    {
        $prefs = MediaPreferences::parse("   \t   ");

        static::assertSame([], $prefs->ranges);
    }

    public function testParseTrimsEachPartIndividually(): void
    {
        $prefs = MediaPreferences::parse('  text/html  ,  application/json  ,  text/plain  ');

        static::assertCount(3, $prefs->ranges);
        $essences = array_map(static fn(MediaRange $r): string => $r->essence(), $prefs->ranges);
        static::assertContains('text/html', $essences);
        static::assertContains('application/json', $essences);
        static::assertContains('text/plain', $essences);
    }

    public function testFromSortsInputRanges(): void
    {
        $r1 = new MediaRange('text', 'html', weight: 0.3);
        $r2 = new MediaRange('application', 'json', weight: 0.9);
        $r3 = new MediaRange('text', 'plain', weight: 0.6);

        $prefs = MediaPreferences::from($r1, $r2, $r3);

        static::assertSame('application/json', $prefs->ranges[0]->essence());
        static::assertSame('text/plain', $prefs->ranges[1]->essence());
        static::assertSame('text/html', $prefs->ranges[2]->essence());
    }

    public function testBestForWithMultipleRangesAtSameWeight(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 1.0),
            new MediaRange('text', '*', weight: 1.0),
            new MediaRange('text', 'html', weight: 1.0),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testBestForContinuesSearchAfterFirstMatchNotBest(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 0.1),
            new MediaRange('text', '*', weight: 0.5),
            new MediaRange('text', 'html', weight: 0.9),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
        static::assertSame(0.9, $best->weight);
    }

    public function testBestForSpecificityTiebreakStrictGreater(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('text', '*', weight: 0.8),
            new MediaRange('text', 'html', weight: 0.8),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testNegotiateInitialWeightIsBelowZero(): void
    {
        $prefs = MediaPreferences::parse('*/*;q=0.001');

        $available = [new MediaType('text', 'html')];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateInitialSpecificityIsZero(): void
    {
        $prefs = MediaPreferences::parse('text/html;q=0.5, text/*;q=0.5');

        $available = [new MediaType('text', 'html')];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testParseTrimmedInputNotEmpty(): void
    {
        $prefs = MediaPreferences::parse('  text/html  ');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }

    public function testParseLeadingWhitespaceIsTrimmed(): void
    {
        $prefs = MediaPreferences::parse("\t text/html");

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }

    public function testParseTrailingWhitespaceIsTrimmed(): void
    {
        $prefs = MediaPreferences::parse("text/html \t ");

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }

    public function testParseEmptyReturnsEmptyRanges(): void
    {
        $prefs = MediaPreferences::parse('');

        static::assertSame([], $prefs->ranges);
        static::assertSame(0, $prefs->count());
    }

    public function testParseEmptyReturnsSerializableResult(): void
    {
        $prefs = MediaPreferences::parse('');

        static::assertSame('', $prefs->toString());
        static::assertSame('', (string) $prefs);
    }

    public function testParseEmptyReturnsIterableResult(): void
    {
        $prefs = MediaPreferences::parse('');

        $collected = [];
        foreach ($prefs as $range) {
            $collected[] = $range;
        }

        static::assertSame([], $collected);
    }

    public function testParseTrimsEachRangePartWithSpaces(): void
    {
        $prefs = MediaPreferences::parse('  text/html  ,  application/json  ');

        static::assertCount(2, $prefs->ranges);
        $essences = array_map(static fn(MediaRange $r): string => $r->essence(), $prefs->ranges);
        static::assertContains('text/html', $essences);
        static::assertContains('application/json', $essences);
    }

    public function testParseTrimsTabsInRangeParts(): void
    {
        $prefs = MediaPreferences::parse("\ttext/html\t,\tapplication/json\t");

        static::assertCount(2, $prefs->ranges);
        $essences = array_map(static fn(MediaRange $r): string => $r->essence(), $prefs->ranges);
        static::assertContains('text/html', $essences);
        static::assertContains('application/json', $essences);
    }

    public function testParseTrailingCommaWithWhitespace(): void
    {
        $prefs = MediaPreferences::parse('text/html,  ');

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }

    public function testParseTrimsFoldedRangeParts(): void
    {
        $prefs = MediaPreferences::parse("text/html,\r\n application/json\r\n");

        static::assertCount(2, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function testParseRejectsControlCharacterRangePart(string $char): void
    {
        $this->expectException(MediaTypeParsingException::class);

        MediaPreferences::parse("text/html,{$char}");
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

    public function testBestForSearchesBeyondFirstMatch(): void
    {
        $lowWeight = new MediaRange('*', '*', weight: 0.1);
        $highWeight = new MediaRange('text', 'html', weight: 0.9);
        $prefs = MediaPreferences::from($lowWeight, $highWeight);

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
        static::assertSame(0.9, $best->weight);
    }

    public function testBestForWithThreeMatchingRangesPicksHighestWeight(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 0.1),
            new MediaRange('text', '*', weight: 0.5),
            new MediaRange('text', 'html', weight: 0.9),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame(0.9, $best->weight);
        static::assertSame('text/html', $best->essence());
    }

    public function testBestForDoesNotBreakOnFirstNull(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 0.2),
            new MediaRange('text', 'html', weight: 0.8),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testBestForSpecificityStrictlyGreaterNotEqual(): void
    {
        $range1 = new MediaRange('text', 'html', weight: 0.7);
        $range2 = new MediaRange('text', 'plain', weight: 0.7);

        $prefs = MediaPreferences::from($range1, $range2);

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testBestForEqualSpecificityEqualWeightKeepsFirst(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('text', '*', weight: 0.5),
            new MediaRange('text', '*', weight: 0.5),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/*', $best->essence());
    }

    public function testBestForThreeRangesSameWeightDifferentSpecificity(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('*', '*', weight: 0.5),
            new MediaRange('text', '*', weight: 0.5),
            new MediaRange('text', 'html', weight: 0.5),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testNegotiateInitialWeightBelowAllPositive(): void
    {
        $prefs = MediaPreferences::parse('*/*;q=0.001');

        $available = [new MediaType('text', 'html')];
        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateSmallPositiveWeightDoesNotLoseToDefault(): void
    {
        $prefs = MediaPreferences::parse('text/html;q=0.001');

        $available = [new MediaType('text', 'html')];
        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
    }

    public function testNegotiateMultipleItemsWithVerySmallWeight(): void
    {
        $prefs = MediaPreferences::parse('text/html;q=0.001, application/json;q=0.002');

        $available = [
            new MediaType('text', 'html'),
            new MediaType('application', 'json'),
        ];

        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('application/json', $result->essence());
    }

    public function testNegotiateSpecificityInitialValueIsZero(): void
    {
        $prefs = MediaPreferences::parse('*/*;q=0.5');

        $available = [new MediaType('text', 'html')];
        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateSpecificityTiebreakBetweenWildcardAndExact(): void
    {
        $prefs = MediaPreferences::parse('*/*;q=0.5, text/html;q=0.5');

        $available = [new MediaType('text', 'html')];
        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testNegotiateSpecificityTiebreakWithThreeMatches(): void
    {
        $prefs = MediaPreferences::parse('*/*;q=0.3, text/*;q=0.3, text/html;q=0.3');

        $available = [new MediaType('text', 'html')];
        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
        static::assertSame('text/html', $result->essence());
    }

    public function testToStringUsesMediaRangeToStringMethod(): void
    {
        $prefs = MediaPreferences::parse('text/html; charset=utf-8');

        $result = $prefs->toString();

        static::assertSame('text/html; charset=utf-8', $result);
    }

    public function testToStringWithMultipleRangesUsesCommaJoin(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json');

        $result = $prefs->toString();

        static::assertSame('text/html, application/json', $result);
    }

    public function testToStringWithWeightedRangesFormatsCorrectly(): void
    {
        $prefs = MediaPreferences::parse('text/html, application/json;q=0.5');

        $result = $prefs->toString();

        static::assertSame('text/html, application/json; q=0.5', $result);
    }

    public function testMostSpecificMatchStrictGreaterThanNotEqual(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('text', 'html', weight: 0.1),
            new MediaRange('text', 'html', weight: 0.1),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testMostSpecificMatchReturnsBestNotLast(): void
    {
        $prefs = MediaPreferences::from(
            new MediaRange('text', 'html', weight: 0.3),
            new MediaRange('text', '*', weight: 0.3),
            new MediaRange('*', '*', weight: 0.3),
        );

        $type = new MediaType('text', 'html');
        $best = $prefs->bestFor($type);

        static::assertNotNull($best);
        static::assertSame('text/html', $best->essence());
    }

    public function testMostSpecificMatchDoesNotUpgradeOnEqualSpecificity(): void
    {
        $prefs = MediaPreferences::parse('text/*;q=0.5, text/*;q=0.5');

        $available = [new MediaType('text', 'html')];
        $result = $prefs->negotiate($available);

        static::assertNotNull($result);
    }

    public function testSplitRangesFirstCharQuoteHandled(): void
    {
        $input = 'text/html; param="a,b"';
        $prefs = MediaPreferences::parse($input);

        static::assertCount(1, $prefs->ranges);
        static::assertSame('a,b', $prefs->ranges[0]->parameters->get('param'));
    }

    public function testSplitRangesQuoteAtIndexZero(): void
    {
        $prefs = MediaPreferences::parse('text/html; boundary="x,y", application/json');

        static::assertCount(2, $prefs->ranges);
    }

    public function testSplitRangesEscapedQuoteInsideQuotedValue(): void
    {
        $prefs = MediaPreferences::parse('text/html; p="a\\"b,c", application/json');

        static::assertCount(2, $prefs->ranges);
        static::assertSame('application/json', $prefs->ranges[1]->essence());
    }

    public function testFromPreservesAllRanges(): void
    {
        $r1 = new MediaRange('text', 'html', weight: 0.9);
        $r2 = new MediaRange('application', 'json', weight: 0.8);
        $r3 = new MediaRange('text', 'plain', weight: 0.7);

        $prefs = MediaPreferences::from($r1, $r2, $r3);

        static::assertCount(3, $prefs->ranges);
    }

    public function testFromSortsByWeight(): void
    {
        $r1 = new MediaRange('text', 'html', weight: 0.5);
        $r2 = new MediaRange('application', 'json', weight: 0.9);

        $prefs = MediaPreferences::from($r1, $r2);

        static::assertSame('application/json', $prefs->ranges[0]->essence());
        static::assertSame('text/html', $prefs->ranges[1]->essence());
    }

    public function testFromWithSingleRange(): void
    {
        $r = new MediaRange('text', 'html');
        $prefs = MediaPreferences::from($r);

        static::assertCount(1, $prefs->ranges);
        static::assertSame('text/html', $prefs->ranges[0]->essence());
    }
}
