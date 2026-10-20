<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DNS\Internal\Decoder;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\RecordType;

use function pack;
use function str_repeat;

final class DecoderQuestionTest extends TestCase
{
    #[DataProvider('provideQuestions')]
    public function testResponseQuestionMustMatchQuery(
        string $queryName,
        string $responseName,
        int $type,
        int $class,
        int $count,
        bool $matches,
    ): void {
        [$id, $query] = Encoder::encode($queryName, RecordType::A);
        $question = Encoder::encodeName($responseName) . pack('nn', $type, $class);
        $response = pack('n6', $id, 0x8180, $count, 0, 0, 0) . str_repeat($question, $count);

        static::assertSame($matches, Decoder::matchesQuestion($response, $query));
    }

    /**
     * @return array<string, array{string, string, int, int, int, bool}>
     */
    public static function provideQuestions(): array
    {
        return [
            'same question' => ['example.com', 'example.com', 1, 1, 1, true],
            'case and trailing dot' => ['EXample.COM.', 'example.com', 1, 1, 1, true],
            'punycode' => ['münchen.example', 'xn--mnchen-3ya.example', 1, 1, 1, true],
            'root' => ['.', '.', 1, 1, 1, true],
            'wrong name' => ['example.com', 'attacker.example', 1, 1, 1, false],
            'wrong type' => ['example.com', 'example.com', 28, 1, 1, false],
            'wrong class' => ['example.com', 'example.com', 1, 3, 1, false],
            'missing question' => ['example.com', 'example.com', 1, 1, 0, false],
            'extra question' => ['example.com', 'example.com', 1, 1, 2, false],
        ];
    }

    #[DataProvider('provideQuestionlessErrors')]
    public function testOnlyEmptyErrorRepliesMayOmitTheQuestion(
        int $code,
        int $answers,
        int $authority,
        int $additional,
        bool $matches,
    ): void {
        [$id, $query] = Encoder::encode('test.example', RecordType::A);
        $response = pack('n6', $id, 0x8180 | $code, 0, $answers, $authority, $additional);

        static::assertSame($matches, Decoder::matchesQuestion($response, $query, true));
        static::assertFalse(Decoder::matchesQuestion($response, $query));
    }

    /**
     * @return array<string, array{int, int, int, int, bool}>
     */
    public static function provideQuestionlessErrors(): array
    {
        return [
            'FORMERR' => [1, 0, 0, 0, true],
            'NOTIMP' => [4, 0, 0, 0, true],
            'SERVFAIL' => [2, 0, 0, 0, true],
            'success' => [0, 0, 0, 0, false],
            'answer' => [1, 1, 0, 0, false],
            'authority' => [1, 0, 1, 0, false],
            'additional' => [1, 0, 0, 1, false],
        ];
    }

    public function testShortQuestionDoesNotMatch(): void
    {
        [$id, $query] = Encoder::encode('example.com', RecordType::A);
        $response = pack('n6', $id, 0x8180, 1, 0, 0, 0) . "\x07ex";

        static::assertFalse(Decoder::matchesQuestion($response, $query));
        static::assertFalse(Decoder::matchesQuestion('', $query));
    }

    public function testDotInsideLabelDoesNotMatchLabelBoundary(): void
    {
        [$id, $query] = Encoder::encode('example.com', RecordType::A);
        $response = pack('n6', $id, 0x8180, 1, 0, 0, 0) . "\x0Bexample.com\x00" . pack('nn', 1, 1);

        static::assertFalse(Decoder::matchesQuestion($response, $query));
    }
}
