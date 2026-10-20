<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\ContentId;
use Psl\MIME\Exception\ContentIdParsingException;
use Psl\MIME\Exception\InvalidArgumentException;
use Psl\MIME\Exception\ParameterParsingException;
use Psl\MIME\Headers;
use Psl\MIME\Parameters;

final class HeaderSafetyTest extends TestCase
{
    #[DataProvider('invalidHeaders')]
    public function testFromPairsRejectsHeaderInjection(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Headers::fromPairs([[$name, $value]]);
    }

    #[DataProvider('invalidHeaders')]
    public function testWithRejectsHeaderInjection(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Headers::default()->with($name, $value);
    }

    #[DataProvider('invalidHeaders')]
    public function testReplaceRejectsHeaderInjection(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Headers::default()->replace($name, $value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidHeaders(): iterable
    {
        yield 'CRLF value' => ['Subject', "Hello\r\nBcc: victim@example.com"];
        yield 'LF value' => ['Subject', "Hello\nBcc: victim@example.com"];
        yield 'CR value' => ['Subject', "Hello\rBcc: victim@example.com"];
        yield 'NUL value' => ['Subject', "Hello\0"];
        yield 'folded value' => ['Subject', "Hello\r\n world"];
        yield 'CRLF name' => ["Subject\r\nBcc", 'value'];
        yield 'NUL name' => ["Subject\0", 'value'];
        yield 'colon name' => ['Subject:', 'value'];
        yield 'empty name' => ['', 'value'];
        yield 'space name' => ['Bad Name', 'value'];
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsParameterDelimiters(string $delimiter): void
    {
        $this->expectException(InvalidArgumentException::class);

        Parameters::fromPairs([['filename', 'file' . $delimiter]]);
    }

    #[DataProvider('headerDelimiters')]
    public function testRejectsContentIdDelimiters(string $delimiter): void
    {
        $this->expectException(ContentIdParsingException::class);

        new ContentId('id@example.com' . $delimiter);
    }

    /** @return iterable<array{string}> */
    public static function headerDelimiters(): iterable
    {
        yield ["\r"];
        yield ["\n"];
        yield ["\0"];
    }

    public function testRejectsDecodedParameterDelimiters(): void
    {
        $this->expectException(ParameterParsingException::class);

        Parameters::parse("filename*=utf-8''file%0d%0aInjected%3a%20yes");
    }
}
