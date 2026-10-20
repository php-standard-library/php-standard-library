<?php

declare(strict_types=1);

namespace Psl\Message\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psl\Message\Exception\ParsingException;
use Psl\Message\MessageId;

use function strlen;
use function strpos;
use function substr;

final class MessageIdTest extends TestCase
{
    #[Test]
    public function constructWithValidId(): void
    {
        $id = new MessageId('unique-id@example.com');

        self::assertSame('unique-id@example.com', $id->id);
    }

    #[Test]
    public function constructWithEmptyIdThrows(): void
    {
        $this->expectException(ParsingException::class);

        new MessageId('');
    }

    #[Test]
    public function generate(): void
    {
        $id = MessageId::generate();

        self::assertStringContainsString('@php-standard-library.dev', $id->id);
        self::assertNotEmpty($id->id);
    }

    #[Test]
    public function generateWithCustomDomain(): void
    {
        $id = MessageId::generate('example.com');

        self::assertStringContainsString('@example.com', $id->id);
    }

    #[Test]
    public function generateProducesUniqueIds(): void
    {
        $a = MessageId::generate();
        $b = MessageId::generate();

        self::assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function parseAngleBracketForm(): void
    {
        $id = MessageId::parse('<unique@example.com>');

        self::assertSame('unique@example.com', $id->id);
    }

    #[Test]
    public function parseBareForm(): void
    {
        $id = MessageId::parse('unique@example.com');

        self::assertSame('unique@example.com', $id->id);
    }

    #[Test]
    public function parseTrimsWhitespace(): void
    {
        $id = MessageId::parse('  <unique@example.com>  ');

        self::assertSame('unique@example.com', $id->id);
    }

    #[Test]
    public function parseTrimsFoldingWhitespace(): void
    {
        $id = MessageId::parse("\r\n \t<unique@example.com>\r\n");

        self::assertSame('unique@example.com', $id->id);
    }

    #[Test]
    #[DataProvider('provideNonWhitespaceControlCharacters')]
    public function parseDoesNotTrimControlCharacters(string $char): void
    {
        if ($char === "\0") {
            $this->expectException(ParsingException::class);
        }

        $id = MessageId::parse("<unique@example.com>{$char}");

        self::assertSame("<unique@example.com>{$char}", $id->id);
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

    #[Test]
    public function parseEmptyThrows(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('');
    }

    #[Test]
    public function parseEmptyAngleBracketsThrows(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('<>');
    }

    #[Test]
    public function parseWhitespaceOnlyThrows(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('   ');
    }

    #[Test]
    public function toStringReturnsAngleBracketForm(): void
    {
        $id = new MessageId('unique@example.com');

        self::assertSame('<unique@example.com>', $id->toString());
        self::assertSame('<unique@example.com>', (string) $id);
    }

    #[Test]
    public function parseBareIdWithoutAtSign(): void
    {
        $id = MessageId::parse('local-only');

        self::assertSame('local-only', $id->id);
    }

    #[Test]
    public function generateProducesCorrectLength(): void
    {
        $id = MessageId::generate('example.com');

        $atPos = strpos($id->id, '@');
        static::assertNotFalse($atPos);

        $hexPart = substr($id->id, 0, $atPos);
        static::assertSame(32, strlen($hexPart), 'The hex portion should be exactly 32 characters (16 bytes)');
    }

    #[Test]
    public function parseEmptyStringThrowsException(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('');
    }

    #[Test]
    public function parseOnlyEndAngleBracketIsNotStripped(): void
    {
        $id = MessageId::parse('unique@example.com>');

        static::assertSame('unique@example.com>', $id->id);
    }

    #[Test]
    public function parseOnlyStartAngleBracketIsNotStripped(): void
    {
        $id = MessageId::parse('<unique@example.com');

        static::assertSame('<unique@example.com', $id->id);
    }

    #[Test]
    public function parseEmptyAngleBracketsThrowsException(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('<>');
    }

    #[Test]
    public function parseEmptyStringThrowsAndDoesNotProceed(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('');
    }

    #[Test]
    public function parseEmptyAngleBracketsThrowsAndDoesNotReturn(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('<>');
    }

    #[Test]
    public function parseEmptyStringThrowsBeforeReachingBracketLogic(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('');
    }

    #[Test]
    public function parseWhitespaceOnlyThrowsAfterTrim(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('   ');
    }

    #[Test]
    public function parseEmptyStringDoesNotReturnValue(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('');
    }

    #[Test]
    public function parseEmptyAngleBracketsThrowsNotReturnsEmptyId(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse('<>');
    }

    #[Test]
    public function parseTabOnlyInputThrowsException(): void
    {
        $this->expectException(ParsingException::class);

        MessageId::parse("\t");
    }

    #[Test]
    public function parseEmptyAngleBracketsExceptionMessageContainsInput(): void
    {
        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage('<>');

        MessageId::parse('<>');
    }
}
