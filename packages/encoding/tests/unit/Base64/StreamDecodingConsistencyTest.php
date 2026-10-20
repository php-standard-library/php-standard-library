<?php

declare(strict_types=1);

namespace Psl\Encoding\Tests\Unit\Base64;

use Closure;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Encoding\Base64;
use Psl\Encoding\Exception;
use Psl\IO;

use function str_repeat;
use function str_split;
use function substr;

/**
 * The streaming decoders must produce exactly what {@see Base64\decode()} produces for the
 * same input, variant and padding, and reject exactly the inputs it rejects, regardless of how
 * the input is chunked.
 */
final class StreamDecodingConsistencyTest extends TestCase
{
    #[DataProvider('provideRoundTripCases')]
    public function testDecodingReadHandleReversesEncode(
        string $data,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): void {
        $encoded = Base64\encode($data, $variant, $padding);

        static::assertSame($data, self::decodeWithReadHandle($encoded, $variant, $padding, $chunkSize));
    }

    #[DataProvider('provideRoundTripCases')]
    public function testDecodingWriteHandleReversesEncode(
        string $data,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): void {
        $encoded = Base64\encode($data, $variant, $padding);

        static::assertSame($data, self::decodeWithWriteHandle($encoded, $variant, $padding, $chunkSize));
    }

    #[DataProvider('provideArbitraryInputCases')]
    public function testDecodingReadHandleMatchesDecode(
        string $input,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): void {
        static::assertSame(
            self::capture(static fn(): string => Base64\decode($input, $variant, $padding)),
            self::capture(static fn(): string => self::decodeWithReadHandle($input, $variant, $padding, $chunkSize)),
        );
    }

    #[DataProvider('provideArbitraryInputCases')]
    public function testDecodingWriteHandleMatchesDecode(
        string $input,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): void {
        static::assertSame(
            self::capture(static fn(): string => Base64\decode($input, $variant, $padding)),
            self::capture(static fn(): string => self::decodeWithWriteHandle($input, $variant, $padding, $chunkSize)),
        );
    }

    public function testDecodingWriteHandleRejectsDataAfterPaddingWithoutWaitingForFlush(): void
    {
        $handle = new Base64\DecodingWriteHandle(new IO\MemoryHandle());
        $handle->writeAll('YQ==');

        $this->expectException(Exception\RangeException::class);

        $handle->writeAll('Y');
    }

    public function testDecodingReadHandleRejectsDataAfterPaddingWithoutReadingTheRest(): void
    {
        $chunks = (static function (): Generator {
            yield 'YQ==';
            yield 'Yg==';

            throw new LogicException('The data following the padding must be rejected before reading further.');
        })();

        $handle = new Base64\DecodingReadHandle(new IO\IterableReadHandle($chunks));

        $this->expectException(Exception\RangeException::class);

        $handle->readAll();
    }

    public function testFinalGroupIsReturnedWithTheRestOfTheData(): void
    {
        $handle = new Base64\DecodingReadHandle(new IO\MemoryHandle(Base64\encode('Hello')));

        static::assertSame('Hello', $handle->read());
    }

    /**
     * @return iterable<string, array{string, Base64\Variant, bool, int}>
     */
    public static function provideRoundTripCases(): iterable
    {
        $source = str_repeat("\x00\xFF\xFB\xEF\xBE\x3E\x3F Hello, World!", 20);

        foreach (Base64\Variant::cases() as $variant) {
            foreach ([true, false] as $padding) {
                foreach ([0, 1, 2, 3, 4, 56, 57, 58, 115, 200] as $length) {
                    foreach ([1, 2, 7, 78, 4096] as $chunkSize) {
                        $name =
                            $variant->name
                            . ($padding ? ', padded' : ', unpadded')
                            . ", {$length} bytes in chunks of {$chunkSize}";

                        yield $name => [substr($source, 0, $length), $variant, $padding, $chunkSize];
                    }
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string, Base64\Variant, bool, int}>
     */
    public static function provideArbitraryInputCases(): iterable
    {
        $inputs = [
            'empty' => '',
            'padded' => 'SGVsbG8sIFdvcmxkIQ==',
            'unpadded' => 'SGVsbG8sIFdvcmxkIQ',
            'CRLF line breaks' => "SGVs\r\nbG8s\r\nIFdv\r\ncmxk\r\nIQ==",
            'spaces and tabs' => "SGVs bG8s\tIFdv cmxk IQ==",
            'trailing CRLF' => "SGVsbG8sIFdvcmxkIQ==\r\n",
            'vertical tab' => "SGVsbG8sIFdvcmxkIQ==\x0B",
            'line feed ending a group' => "SGV\nbG8=",
            'padding in the middle' => 'SGVsbG8=SGVsbG8=',
            'consecutive padded groups' => 'YQ==Yg==',
            'three consecutive padded groups' => 'YQ==Yg==Yw==',
            'padded group followed by a partial group' => 'YQ==Yg',
            'padded group followed by a padding character' => 'YQ=Yg==',
            'one extra padding character' => 'SGVsbG8==',
            'two extra padding characters' => 'SGVsbG8===',
            'three padding characters' => 'SGVsbA===',
            'five characters' => 'SGVsb',
            'one character' => 'S',
            'padding inside a group' => 'SG=s',
            'invalid character' => 'SGVs!G8=',
            'standard alphabet' => 'a+/b',
            'url-safe alphabet' => 'a-_b',
            'dot-slash alphabet' => 'a./b',
        ];

        foreach ($inputs as $description => $input) {
            foreach (Base64\Variant::cases() as $variant) {
                foreach ([true, false] as $padding) {
                    foreach ([1, 3, 4, 4096] as $chunkSize) {
                        $name =
                            $description
                            . ', '
                            . $variant->name
                            . ($padding ? ', padded' : ', unpadded')
                            . ", chunks of {$chunkSize}";

                        yield $name => [$input, $variant, $padding, $chunkSize];
                    }
                }
            }
        }
    }

    private static function decodeWithReadHandle(
        string $input,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): string {
        $handle = new Base64\DecodingReadHandle(
            new IO\IterableReadHandle(self::chunk($input, $chunkSize)),
            $variant,
            $padding,
        );

        return $handle->readAll();
    }

    private static function decodeWithWriteHandle(
        string $input,
        Base64\Variant $variant,
        bool $padding,
        int $chunkSize,
    ): string {
        $inner = new IO\MemoryHandle();
        $handle = new Base64\DecodingWriteHandle($inner, $variant, $padding);
        foreach (self::chunk($input, $chunkSize) as $chunk) {
            $handle->writeAll($chunk);
        }

        $handle->flush();

        $inner->seek(0);

        return $inner->readAll();
    }

    /**
     * @param Closure(): string $operation
     *
     * @return array{string, string}
     */
    private static function capture(Closure $operation): array
    {
        try {
            return ['decoded', $operation()];
        } catch (Exception\ExceptionInterface $exception) {
            return [$exception::class, $exception->getMessage()];
        }
    }

    /**
     * @return list<string>
     */
    private static function chunk(string $data, int $chunkSize): array
    {
        return $data === '' ? [] : str_split($data, $chunkSize);
    }
}
