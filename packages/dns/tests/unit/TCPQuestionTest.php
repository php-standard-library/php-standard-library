<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\RecordType;
use Psl\DNS\ResponseCode;
use Psl\DNS\TCPResolver;
use Psl\TCP;

use function pack;
use function str_repeat;
use function strlen;
use function substr;

final class TCPQuestionTest extends TestCase
{
    #[DataProvider('provideQuestions')]
    public function testResponseQuestion(string $name, int $type, int $class, int $count, bool $matches): void
    {
        $response = '';
        $stream = $this->createMock(TCP\StreamInterface::class);
        $stream
            ->expects(static::once())
            ->method('writeAll')
            ->willReturnCallback(static function (string $packet) use (&$response, $name, $type, $class, $count): void {
                $question = Encoder::encodeName($name) . pack('nn', $type, $class);
                $response = substr($packet, 2, 2) . pack('n5', 0x8180, $count, 0, 0, 0) . str_repeat($question, $count);
            });
        $stream
            ->expects(static::exactly(2))
            ->method('readFixedSize')
            ->willReturnCallback(static function (int $size) use (&$response): string {
                return $size === 2 ? pack('n', strlen($response)) : $response;
            });
        $stream->expects(static::once())->method('close');
        $connector = $this->createMock(TCP\ConnectorInterface::class);
        $connector->expects(static::once())->method('connect')->willReturn($stream);
        $resolver = new TCPResolver('127.0.0.1', connector: $connector);

        if (!$matches) {
            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessage('DNS response question does not match');
        }

        $result = $resolver->query('example.com.', RecordType::A);
        static::assertSame(ResponseCode::NoError, $result->code);
    }

    /**
     * @return array<string, array{string, int, int, int, bool}>
     */
    public static function provideQuestions(): array
    {
        return [
            'wrong name' => ['attacker.example', 1, 1, 1, false],
            'wrong type' => ['example.com', 28, 1, 1, false],
            'wrong class' => ['example.com', 1, 3, 1, false],
            'missing question' => ['example.com', 1, 1, 0, false],
            'extra question' => ['example.com', 1, 1, 2, false],
            'case-insensitive name' => ['EXAMPLE.COM', 1, 1, 1, true],
        ];
    }
}
