<?php

declare(strict_types=1);

namespace Psl\Crypto\Tests\Unit\Symmetric;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\Crypto\Exception;
use Psl\Crypto\Symmetric;
use Psl\Crypto\Tests\Fixture\SocketLikeReadHandle;
use Psl\Crypto\Tests\Fixture\TrickleReadHandle;
use Psl\IO;
use Psl\SecureRandom;
use Psl\Str;
use Psl\Str\Byte;

use function array_filter;
use function pack;
use function unpack;

use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

final class StreamEncryptionTest extends TestCase
{
    #[TestWith([0])]
    #[TestWith([-1])]
    public function testCopySealedRejectsNonPositiveChunksBeforeWriting(int $chunkSize): void
    {
        $encrypted = new IO\MemoryHandle();
        $encryptor = new Symmetric\StreamEncryptor(Symmetric\generate_key());

        $this->expectException(Exception\InvalidArgumentException::class);

        try {
            $encryptor->copySealed(new IO\MemoryHandle(), $encrypted, $chunkSize);
        } finally {
            $encrypted->seek(0);
            static::assertSame('', $encrypted->readAll());
        }
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'Hello, streaming encryption!';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testLargeDataEncryptDecrypt(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('A', 100_000);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 4096);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $key1 = Symmetric\generate_key();
        $key2 = Symmetric\generate_key();

        $source = new IO\MemoryHandle('test data');
        $encrypted = new IO\MemoryHandle();

        new Symmetric\StreamEncryptor($key1)->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();

        $this->expectException(Exception\DecryptionException::class);
        new Symmetric\StreamEncryptor($key2)->copyOpened($encrypted, $decrypted);
    }

    public function testEmptyStreamEncryptDecrypt(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('');
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame('', $decrypted->readAll());
    }

    public function testSmallChunkSize(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'This message will be split into very small chunks for encryption.';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 8);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testVariousChunkSizes(): void
    {
        $key = Symmetric\generate_key();
        $plaintext = Str\repeat('Z', 10_000);

        foreach ([16, 64, 256, 1024, 8192] as $chunkSize) {
            $encryptor = new Symmetric\StreamEncryptor($key);
            $source = new IO\MemoryHandle($plaintext);
            $encrypted = new IO\MemoryHandle();

            $encryptor->copySealed($source, $encrypted, $chunkSize);

            $encrypted->seek(0);
            $decrypted = new IO\MemoryHandle();
            $encryptor->copyOpened($encrypted, $decrypted);

            $decrypted->seek(0);
            static::assertSame($plaintext, $decrypted->readAll(), "Failed with chunk size {$chunkSize}");
        }
    }

    public function testDecryptTamperedStreamFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('secret data');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $data = $encrypted->readAll();
        $pos = Symmetric\STREAM_HEADER_BYTES + 4 + 1;
        $data[$pos] = Byte\chr(Byte\ord($data[$pos]) ^ 0xFF);

        $tampered = new IO\MemoryHandle($data);
        $decrypted = new IO\MemoryHandle();

        $this->expectException(Exception\DecryptionException::class);
        $encryptor->copyOpened($tampered, $decrypted);
    }

    public function testDecryptTruncatedStreamFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle(Str\repeat('A', 1000));
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $data = $encrypted->readAll();
        $truncated = new IO\MemoryHandle(Byte\slice($data, 0, (int) (Byte\length($data) / 2)));

        $decrypted = new IO\MemoryHandle();

        $this->expectException(Exception\DecryptionException::class);
        $encryptor->copyOpened($truncated, $decrypted);
    }

    public function testStreamEncryptionProducesDifferentOutputEachTime(): void
    {
        $key = Symmetric\generate_key();
        $plaintext = 'same message';

        $enc1 = new IO\MemoryHandle();
        new Symmetric\StreamEncryptor($key)->copySealed(new IO\MemoryHandle($plaintext), $enc1);

        $enc2 = new IO\MemoryHandle();
        new Symmetric\StreamEncryptor($key)->copySealed(new IO\MemoryHandle($plaintext), $enc2);

        $enc1->seek(0);
        $enc2->seek(0);
        static::assertNotSame($enc1->readAll(), $enc2->readAll());
    }

    public function testBinaryDataStreamRoundtrip(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = SecureRandom\bytes(5000);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 512);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDecryptSocketLikeSourceWithShortFinalChunk(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'Short final chunk';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 8192);

        $encrypted->seek(0);
        $ciphertextBytes = $encrypted->readAll();

        $socketSource = new SocketLikeReadHandle($ciphertextBytes);
        $decrypted = new IO\MemoryHandle();

        $encryptor->copyOpened($socketSource, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDecryptSocketLikeSourceWithMultipleChunks(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('X', 8192 + 500);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 8192);

        $encrypted->seek(0);
        $ciphertextBytes = $encrypted->readAll();

        $socketSource = new SocketLikeReadHandle($ciphertextBytes);
        $decrypted = new IO\MemoryHandle();

        $encryptor->copyOpened($socketSource, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDecryptTrickleSourceOneByte(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'Trickle test with partial frame headers';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 16);

        $encrypted->seek(0);
        $ciphertextBytes = $encrypted->readAll();

        $trickleSource = new TrickleReadHandle($ciphertextBytes, 1);
        $decrypted = new IO\MemoryHandle();

        $encryptor->copyOpened($trickleSource, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDecryptTrickleSourceTwoBytes(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('Y', 500);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 64);

        $encrypted->seek(0);
        $ciphertextBytes = $encrypted->readAll();

        $trickleSource = new TrickleReadHandle($ciphertextBytes, 2);
        $decrypted = new IO\MemoryHandle();

        $encryptor->copyOpened($trickleSource, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDecryptInvalidFrameSizeFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('test');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted, 16);

        $encrypted->seek(0);
        $data = $encrypted->readAll();

        $frameLengthOffset = Symmetric\STREAM_HEADER_BYTES + 4;
        $oversizedFrame = pack('V', 99_999);
        $corrupted =
            Byte\slice($data, 0, $frameLengthOffset) . $oversizedFrame . Byte\slice($data, $frameLengthOffset + 4);

        $corruptedSource = new IO\MemoryHandle($corrupted);
        $decrypted = new IO\MemoryHandle();

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid frame size in stream.');
        $encryptor->copyOpened($corruptedSource, $decrypted);
    }

    public function testDecryptInvalidChunkSizeZeroFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('test');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $data = $encrypted->readAll();

        $corrupted =
            Byte\slice($data, 0, Symmetric\STREAM_HEADER_BYTES)
            . pack('V', 0)
            . Byte\slice($data, Symmetric\STREAM_HEADER_BYTES + 4);

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid chunk size in stream header.');
        $encryptor->copyOpened(new IO\MemoryHandle($corrupted), new IO\MemoryHandle());
    }

    public function testDecryptTruncatedFrameHeaderFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('test data for truncation');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $data = $encrypted->readAll();

        $truncated = Byte\slice($data, 0, Symmetric\STREAM_HEADER_BYTES + 4 + 2);

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Stream decryption failed: truncated frame header.');
        $encryptor->copyOpened(new TrickleReadHandle($truncated, 1), new IO\MemoryHandle());
    }

    public function testDecryptStreamWithoutFinalTagFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('A', 100);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted, 16);

        $encrypted->seek(0);
        $data = $encrypted->readAll();

        $pos = Symmetric\STREAM_HEADER_BYTES + 4;
        $lastFrameStart = $pos;
        while ($pos < Byte\length($data)) {
            $lastFrameStart = $pos;
            /** @var int $frameLen */
            $frameLen = unpack('V', Byte\slice($data, $pos, 4))[1];
            $pos += 4 + $frameLen;
        }

        $truncated = Byte\slice($data, 0, $lastFrameStart);

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Stream ended without final tag.');
        $encryptor->copyOpened(new IO\MemoryHandle($truncated), new IO\MemoryHandle());
    }

    public function testDecryptStreamWithoutFinalTagNonEofSourceFails(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('A', 100);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted, 16);

        $encrypted->seek(0);
        $data = $encrypted->readAll();

        $pos = Symmetric\STREAM_HEADER_BYTES + 4;
        $lastFrameStart = $pos;
        while ($pos < Byte\length($data)) {
            $lastFrameStart = $pos;
            /** @var int $frameLen */
            $frameLen = unpack('V', Byte\slice($data, $pos, 4))[1];
            $pos += 4 + $frameLen;
        }

        $truncated = Byte\slice($data, 0, $lastFrameStart);

        $handle = new readonly class($truncated) implements IO\ReadHandleInterface {
            private IO\MemoryHandle $inner;

            public function __construct(string $data)
            {
                $this->inner = new IO\MemoryHandle($data);
            }

            public function reachedEndOfDataSource(): bool
            {
                return false; // never reports EOF
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                return $this->inner->tryRead($maxBytes);
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->read($maxBytes, $cancellation);
            }

            public function readAll(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readAll($maxBytes, $cancellation);
            }

            public function readFixedSize(
                int $size,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readFixedSize($size, $cancellation);
            }
        };

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Stream ended without final tag.');
        $encryptor->copyOpened($handle, new IO\MemoryHandle());
    }

    public function testExplicitChunkSizeIsUsed(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('A', 500);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 64);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testCopyOpenedRejectsZeroChunkSize(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('test data');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $raw = $encrypted->readAll();
        $headerLen = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $tampered = Byte\slice($raw, 0, $headerLen) . "\x00\x00\x00\x00" . Byte\slice($raw, $headerLen + 4);

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid chunk size in stream header.');
        $encryptor->copyOpened(new IO\MemoryHandle($tampered), new IO\MemoryHandle());
    }

    public function testCopyOpenedRejectsOversizedChunk(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('test data');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $raw = $encrypted->readAll();
        $headerLen = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $tampered = Byte\slice($raw, 0, $headerLen) . "\x01\x00\x00\x01" . Byte\slice($raw, $headerLen + 4);

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid chunk size in stream header.');
        $encryptor->copyOpened(new IO\MemoryHandle($tampered), new IO\MemoryHandle());
    }

    public function testCopyOpenedRejectsChunkSizeAtBoundaryPlusOne(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $source = new IO\MemoryHandle('test');
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $raw = $encrypted->readAll();
        $headerLen = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $chunkBytes = pack('V', (16 * 1024 * 1024) + 1);
        $tampered = Byte\slice($raw, 0, $headerLen) . $chunkBytes . Byte\slice($raw, $headerLen + 4);

        $this->expectException(Exception\DecryptionException::class);
        $this->expectExceptionMessage('Invalid chunk size in stream header.');
        $encryptor->copyOpened(new IO\MemoryHandle($tampered), new IO\MemoryHandle());
    }

    public function testChunkSizeOneIsValid(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'small';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 1);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testIsLastConditionWithExactChunkSize(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('B', 64);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 64);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testIsLastConditionWithDataSmallerThanChunk(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'hi';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, 8192);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testDefaultChunkSizeIsExactly8192(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'test default chunk size';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $data = $encrypted->readAll();

        $chunkSizeBytes = Byte\slice($data, Symmetric\STREAM_HEADER_BYTES, 4);
        /** @var int $storedChunkSize */
        $storedChunkSize = unpack('V', $chunkSizeBytes)[1];
        static::assertSame(8192, $storedChunkSize);
    }

    public function testCopySealedWaitsForEofAfterEmptyChunk(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $sourceHandle = new class('more data after empty') implements IO\ReadHandleInterface {
            private IO\MemoryHandle $inner;
            private bool $firstRead = true;

            public function __construct(string $data)
            {
                $this->inner = new IO\MemoryHandle($data);
            }

            public function reachedEndOfDataSource(): bool
            {
                return !$this->firstRead && $this->inner->reachedEndOfDataSource();
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                return $this->doRead($maxBytes);
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->doRead($maxBytes);
            }

            public function readAll(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readAll($maxBytes, $cancellation);
            }

            public function readFixedSize(
                int $size,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readFixedSize($size, $cancellation);
            }

            private function doRead(null|int $maxBytes): string
            {
                if ($this->firstRead) {
                    $this->firstRead = false;
                    return '';
                }

                return $this->inner->read($maxBytes);
            }
        };

        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($sourceHandle, $encrypted);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame('more data after empty', $decrypted->readAll());
    }

    public function testIsLastWithShortChunkButNotEof(): void
    {
        $encryptor = new Symmetric\StreamEncryptor(Symmetric\generate_key());
        $plaintext = 'Short reads must preserve the whole source.';
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed(new TrickleReadHandle($plaintext, 3), $encrypted, 8);

        $encrypted->seek(0);
        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($encrypted, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testCopySealedDoesNotFinalizeAnOpenSource(): void
    {
        $encryptor = new Symmetric\StreamEncryptor(Symmetric\generate_key());

        $this->expectException(CancelledException::class);
        $encryptor->copySealed(new SocketLikeReadHandle('short'), new IO\MemoryHandle());
    }

    public function testCopySealedRejectsOversizedChunksBeforeWriting(): void
    {
        $encryptor = new Symmetric\StreamEncryptor(Symmetric\generate_key());
        $destination = new IO\MemoryHandle();

        try {
            $this->expectException(Exception\InvalidArgumentException::class);
            $encryptor->copySealed(new IO\MemoryHandle('data'), $destination, Symmetric\MAX_CHUNK_BYTES + 1);
        } finally {
            $destination->seek(0);
            static::assertSame('', $destination->readAll());
        }
    }

    public function testMaxChunkBytesIsAccepted(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'test max chunk';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();

        $encryptor->copySealed($source, $encrypted, Symmetric\MAX_CHUNK_BYTES);

        $encrypted->seek(0);
        $data = $encrypted->readAll();
        $chunkSizeBytes = Byte\slice($data, Symmetric\STREAM_HEADER_BYTES, 4);
        /** @var int $storedChunkSize */
        $storedChunkSize = unpack('V', $chunkSizeBytes)[1];
        static::assertSame(Symmetric\MAX_CHUNK_BYTES, $storedChunkSize);

        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened(new IO\MemoryHandle($data), $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testCopyOpenedReadsExactly4BytesForFrameHeader(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = Str\repeat('D', 100);
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted, 32);

        $encrypted->seek(0);
        $ciphertextBytes = $encrypted->readAll();

        $trackingHandle = new class($ciphertextBytes) implements IO\ReadHandleInterface {
            private IO\MemoryHandle $inner;
            /** @var list<int|null> */
            public array $readArgs = [];

            public function __construct(string $data)
            {
                $this->inner = new IO\MemoryHandle($data);
            }

            public function reachedEndOfDataSource(): bool
            {
                return $this->inner->reachedEndOfDataSource();
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                return $this->inner->tryRead($maxBytes);
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                $this->readArgs[] = $maxBytes;
                return $this->inner->read($maxBytes, $cancellation);
            }

            public function readAll(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readAll($maxBytes, $cancellation);
            }

            public function readFixedSize(
                int $size,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readFixedSize($size, $cancellation);
            }
        };

        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($trackingHandle, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());

        $readFours = array_filter($trackingHandle->readArgs, static fn($arg) => $arg === 4);
        static::assertNotEmpty($readFours, 'Expected at least one read(4) call for frame headers.');

        foreach ($trackingHandle->readArgs as $arg) {
            if ($arg === null || $arg > 4) {
                continue;
            }

            static::assertSame(4, $arg, 'Frame header read should request exactly 4 bytes.');
        }
    }

    public function testCopyOpenedSkipsRecoveryWhenFull4BytesRead(): void
    {
        $key = Symmetric\generate_key();
        $encryptor = new Symmetric\StreamEncryptor($key);

        $plaintext = 'exactly four bytes test';
        $source = new IO\MemoryHandle($plaintext);
        $encrypted = new IO\MemoryHandle();
        $encryptor->copySealed($source, $encrypted);

        $encrypted->seek(0);
        $ciphertextBytes = $encrypted->readAll();

        $strictHandle = new class($ciphertextBytes) implements IO\ReadHandleInterface {
            private IO\MemoryHandle $inner;

            public function __construct(string $data)
            {
                $this->inner = new IO\MemoryHandle($data);
            }

            public function reachedEndOfDataSource(): bool
            {
                return $this->inner->reachedEndOfDataSource();
            }

            public function tryRead(null|int $maxBytes = null): string
            {
                return $this->inner->tryRead($maxBytes);
            }

            public function read(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->read($maxBytes, $cancellation);
            }

            public function readAll(
                null|int $maxBytes = null,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                return $this->inner->readAll($maxBytes, $cancellation);
            }

            public function readFixedSize(
                int $size,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): string {
                if ($size === 0) {
                    throw new IO\Exception\RuntimeException(
                        'readFixedSize(0) should never be called: the < 4 check should prevent this.',
                    );
                }

                return $this->inner->readFixedSize($size, $cancellation);
            }
        };

        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened($strictHandle, $decrypted);

        $decrypted->seek(0);
        static::assertSame($plaintext, $decrypted->readAll());
    }

    public function testCopySealedWritesEachFrameWithItsLengthInOneWrite(): void
    {
        $encryptor = new Symmetric\StreamEncryptor(Symmetric\generate_key());
        $destination = new class() implements IO\WriteHandleInterface {
            use IO\WriteHandleConvenienceMethodsTrait;

            /**
             * @var list<string>
             */
            public array $writes = [];

            public function tryWrite(string $bytes): int
            {
                $this->writes[] = $bytes;

                return Byte\length($bytes);
            }

            public function write(
                string $bytes,
                CancellationTokenInterface $cancellation = new NullCancellationToken(),
            ): int {
                return $this->tryWrite($bytes);
            }
        };

        // 20 bytes in chunks of 8: two full frames and a short final one.
        $encryptor->copySealed(new TrickleReadHandle('0123456789abcdefghij', 3), $destination, 8);

        static::assertCount(4, $destination->writes);
        static::assertSame(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 4,
            Byte\length($destination->writes[0]),
        );
        static::assertSame(8, unpack('V', Byte\slice($destination->writes[0], -4))[1]);
        foreach ([8, 8, 4] as $index => $plaintextLength) {
            $frame = $destination->writes[$index + 1];
            $frameLength = Symmetric\STREAM_TAG_BYTES + $plaintextLength;
            static::assertSame(4 + $frameLength, Byte\length($frame));
            static::assertSame($frameLength, unpack('V', Byte\slice($frame, 0, 4))[1]);
        }

        $decrypted = new IO\MemoryHandle();
        $encryptor->copyOpened(new IO\MemoryHandle(Str\join($destination->writes, '')), $decrypted);
        static::assertSame('0123456789abcdefghij', $decrypted->getBuffer());
    }
}
