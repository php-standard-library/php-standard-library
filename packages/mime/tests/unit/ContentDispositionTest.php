<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\ContentDisposition;
use Psl\MIME\Exception\ContentDispositionParsingException;
use Psl\MIME\Exception\InvalidArgumentException;
use Psl\MIME\Parameters;
use Stringable;

final class ContentDispositionTest extends TestCase
{
    public function testInlineFactory(): void
    {
        $cd = ContentDisposition::inline();

        static::assertSame('inline', $cd->type);
        static::assertNull($cd->filename());
    }

    public function testInlineWithFilename(): void
    {
        $cd = ContentDisposition::inline('image.png');

        static::assertSame('inline', $cd->type);
        static::assertSame('image.png', $cd->filename());
    }

    public function testAttachmentFactory(): void
    {
        $cd = ContentDisposition::attachment();

        static::assertSame('attachment', $cd->type);
        static::assertNull($cd->filename());
    }

    public function testAttachmentWithFilename(): void
    {
        $cd = ContentDisposition::attachment('report.pdf');

        static::assertSame('attachment', $cd->type);
        static::assertSame('report.pdf', $cd->filename());
    }

    public function testParseSimpleAttachment(): void
    {
        $cd = ContentDisposition::parse('attachment');

        static::assertSame('attachment', $cd->type);
        static::assertNull($cd->filename());
    }

    public function testParseAttachmentWithFilename(): void
    {
        $cd = ContentDisposition::parse('attachment; filename="report.pdf"');

        static::assertSame('attachment', $cd->type);
        static::assertSame('report.pdf', $cd->filename());
    }

    public function testParseInline(): void
    {
        $cd = ContentDisposition::parse('inline');

        static::assertSame('inline', $cd->type);
    }

    public function testParseFormData(): void
    {
        $cd = ContentDisposition::parse('form-data; name="field1"');

        static::assertSame('form-data', $cd->type);
        static::assertSame('field1', $cd->parameters->get('name'));
    }

    public function testParseCaseInsensitive(): void
    {
        $cd = ContentDisposition::parse('ATTACHMENT; FILENAME="test.txt"');

        static::assertSame('attachment', $cd->type);
        static::assertSame('test.txt', $cd->filename());
    }

    public function testParseEmptyThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        ContentDisposition::parse('');
    }

    public function testSizeParameter(): void
    {
        $cd = ContentDisposition::parse('attachment; filename="test.txt"; size=12345');

        static::assertSame(12_345, $cd->size());
    }

    public function testSizeNull(): void
    {
        $cd = ContentDisposition::parse('attachment');

        static::assertNull($cd->size());
    }

    public function testSizeInvalid(): void
    {
        $cd = ContentDisposition::parse('attachment; size=abc');

        static::assertNull($cd->size());
    }

    public function testCreationDate(): void
    {
        $cd = ContentDisposition::parse('attachment; creation-date="Wed, 12 Feb 1997 16:29:51 -0500"');

        $date = $cd->creationDate();
        static::assertNotNull($date);
        static::assertSame(1997, $date->getYear());
    }

    public function testModificationDate(): void
    {
        $cd = ContentDisposition::parse('attachment; modification-date="Wed, 12 Feb 1997 16:29:51 -0500"');

        $date = $cd->modificationDate();
        static::assertNotNull($date);
    }

    public function testReadDate(): void
    {
        $cd = ContentDisposition::parse('attachment; read-date="Wed, 12 Feb 1997 16:29:51 -0500"');

        $date = $cd->readDate();
        static::assertNotNull($date);
    }

    public function testNoDates(): void
    {
        $cd = ContentDisposition::parse('attachment');

        static::assertNull($cd->creationDate());
        static::assertNull($cd->modificationDate());
        static::assertNull($cd->readDate());
    }

    public function testToStringSimple(): void
    {
        $cd = ContentDisposition::attachment();

        static::assertSame('attachment', $cd->toString());
    }

    public function testToStringWithFilename(): void
    {
        $cd = ContentDisposition::attachment('test.txt');

        static::assertSame('attachment; filename=test.txt', $cd->toString());
    }

    public function testToStringWithQuotedFilename(): void
    {
        $cd = ContentDisposition::attachment('my file.txt');

        static::assertSame('attachment; filename="my file.txt"', $cd->toString());
    }

    public function testParseRoundTrip(): void
    {
        $input = 'attachment; filename=report.pdf';
        $cd = ContentDisposition::parse($input);
        $output = $cd->toString();
        $reparsed = ContentDisposition::parse($output);

        static::assertSame($cd->type, $reparsed->type);
        static::assertSame($cd->filename(), $reparsed->filename());
    }

    public function testConstructorNormalizesType(): void
    {
        $cd = new ContentDisposition('ATTACHMENT', Parameters::fromPairs([['filename', 'test']]));

        static::assertSame('attachment', $cd->type);
    }

    public function testConstructorControlCharThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition("\x01attachment");
    }

    public function testConstructorSpecialCharThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition('att(achment)');
    }

    public function testConstructorHighByteThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition("\x80bad");
    }

    public function testConstructorSlashThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition('inline/bad');
    }

    public function testStringable(): void
    {
        $cd = ContentDisposition::attachment('test.txt');

        static::assertInstanceOf(Stringable::class, $cd);
        static::assertSame($cd->toString(), (string) $cd);
    }

    public function testParseWhitespaceOnlyThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        ContentDisposition::parse('   ');
    }

    public function testInvalidDateReturnsNull(): void
    {
        $cd = ContentDisposition::parse('attachment; creation-date="not-a-date"');

        static::assertNull($cd->creationDate());
    }

    public function testSizeWithNonDigitChars(): void
    {
        $cd = ContentDisposition::parse('attachment; size=-123');

        static::assertNull($cd->size());
    }

    public function testSizeWithMixedChars(): void
    {
        $cd = ContentDisposition::parse('attachment; size=12abc');

        static::assertNull($cd->size());
    }

    public function testFilenameNull(): void
    {
        $cd = ContentDisposition::attachment();

        static::assertNull($cd->filename());
    }

    public function testParseMultipleParameters(): void
    {
        $cd = ContentDisposition::parse('attachment; filename="test.txt"; size=100');

        static::assertSame('attachment', $cd->type);
        static::assertSame('test.txt', $cd->filename());
        static::assertSame(100, $cd->size());
    }

    public function testConstructorEmptyThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition('');
    }

    public function testConstructorSpaceCharThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition('inline attachment');
    }

    public function testConstructorDelCharThrows(): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        new ContentDisposition("att\x7F");
    }

    public function testParseParameterWithoutSpaceAfterSemicolon(): void
    {
        $cd = ContentDisposition::parse('attachment;filename=test.txt');

        static::assertSame('attachment', $cd->type);
        static::assertSame('test.txt', $cd->filename());
    }

    public function testParseTrimsLeadingAndTrailingWhitespace(): void
    {
        $cd = ContentDisposition::parse('  attachment  ');

        static::assertSame('attachment', $cd->type);
    }

    public function testParseTrimsLinearWhitespace(): void
    {
        $cd = ContentDisposition::parse(" \tattachment\r\n ; filename=test.txt\r\n");

        static::assertSame('attachment', $cd->type);
        static::assertSame('test.txt', $cd->filename());
    }

    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function testParseRejectsControlCharactersAroundType(string $char): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        ContentDisposition::parse("{$char}attachment{$char}");
    }

    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function testParseRejectsControlCharactersBeforeParameters(string $char): void
    {
        $this->expectException(ContentDispositionParsingException::class);

        ContentDisposition::parse("attachment{$char}; filename=test.txt");
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

    public function testParseTrimsTypeBeforeSemicolon(): void
    {
        $cd = ContentDisposition::parse('  attachment  ; filename=test.txt');

        static::assertSame('attachment', $cd->type);
        static::assertSame('test.txt', $cd->filename());
    }

    public function testFilenameStripsPathTraversal(): void
    {
        $cd = ContentDisposition::parse('attachment; filename="../../../etc/passwd"');

        static::assertSame('passwd', $cd->filename());
        static::assertSame('../../../etc/passwd', $cd->unsafeFilename());
    }

    public function testFilenameStripsDirectoryComponents(): void
    {
        $cd = ContentDisposition::parse('attachment; filename="/var/www/uploads/file.txt"');

        static::assertSame('file.txt', $cd->filename());
    }

    public function testFilenameReturnsNullForDotDot(): void
    {
        $cd = ContentDisposition::parse('attachment; filename=".."');

        static::assertNull($cd->filename());
        static::assertSame('..', $cd->unsafeFilename());
    }

    public function testFilenameReturnsNullForDot(): void
    {
        $cd = ContentDisposition::parse('attachment; filename="."');

        static::assertNull($cd->filename());
    }

    public function testRejectsFilenameWithNullByte(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContentDisposition('attachment', Parameters::fromPairs([['filename', "evil\x00.txt"]]));
    }

    public function testFilenameWithWindowsPath(): void
    {
        $cd = new ContentDisposition('attachment', Parameters::fromPairs([
            ['filename', 'C:\\Users\\evil\\file.txt'],
        ]));

        static::assertSame('file.txt', $cd->filename());
    }
}
