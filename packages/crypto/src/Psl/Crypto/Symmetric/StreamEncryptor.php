<?php

declare(strict_types=1);

namespace Psl\Crypto\Symmetric;

use Psl\Crypto\Exception;
use Psl\Crypto\Internal;
use Psl\IO;
use SensitiveParameter;

use function pack;
use function sodium_crypto_secretstream_xchacha20poly1305_init_pull;
use function sodium_crypto_secretstream_xchacha20poly1305_init_push;
use function sodium_crypto_secretstream_xchacha20poly1305_pull;
use function sodium_crypto_secretstream_xchacha20poly1305_push;
use function strlen;
use function unpack;

use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
use const SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

/**
 * @api
 */
final readonly class StreamEncryptor implements StreamEncryptorInterface
{
    public function __construct(
        #[SensitiveParameter]
        private Key $key,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @param int $chunkSize
     */
    public function copySealed(
        IO\ReadHandleInterface $source,
        IO\WriteHandleInterface $destination,
        int $chunkSize = 8192,
    ): void {
        if ($chunkSize < 1 || $chunkSize > namespace\MAX_CHUNK_BYTES) {
            throw new Exception\InvalidArgumentException('Chunk size must be between 1 and MAX_CHUNK_BYTES.');
        }

        /** @var array{string, string} $init */
        $init = Internal\call_sodium(fn() => sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key->bytes));
        [$state, $header] = $init;

        // Each part of the stream goes out in one write: a length prefix written on its own leaves a peer
        // holding a prefix without its frame, and on TCP two small writes in a row can stall on Nagle's
        // algorithm and delayed acknowledgements.
        $destination->writeAll($header . pack('V', $chunkSize));

        while (!$source->reachedEndOfDataSource()) {
            $chunk = $source->read($chunkSize);
            while (strlen($chunk) < $chunkSize && !$source->reachedEndOfDataSource()) {
                /** @var positive-int $remaining */
                $remaining = $chunkSize - strlen($chunk);
                $chunk .= $source->read($remaining);
            }

            $isLast = $source->reachedEndOfDataSource();
            $tag = $isLast
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $encrypted = Internal\call_sodium(static function () use (&$state, $chunk, $tag): string {
                return sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            });
            $destination->writeAll(pack('V', strlen($encrypted)) . $encrypted);

            if ($isLast) {
                return;
            }
        }

        $encrypted = Internal\call_sodium(static function () use (&$state): string {
            return sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
        });
        $destination->writeAll(pack('V', strlen($encrypted)) . $encrypted);
    }

    /**
     * {@inheritDoc}
     */
    public function copyOpened(IO\ReadHandleInterface $source, IO\WriteHandleInterface $destination): void
    {
        $header = $source->readFixedSize(namespace\STREAM_HEADER_BYTES);
        $chunkSizeBytes = $source->readFixedSize(4);
        /** @var int $chunkSize */
        $chunkSize = unpack('V', $chunkSizeBytes)[1];

        if ($chunkSize < 1 || $chunkSize > namespace\MAX_CHUNK_BYTES) {
            throw new Exception\DecryptionException('Invalid chunk size in stream header.');
        }

        $state = Internal\call_sodium(fn() => sodium_crypto_secretstream_xchacha20poly1305_init_pull(
            $header,
            $this->key->bytes,
        ));

        while (!$source->reachedEndOfDataSource()) {
            $lengthBytes = $source->read(4);
            if ($lengthBytes === '') {
                break;
            }

            if (strlen($lengthBytes) < 4) {
                /** @var positive-int $remaining */
                $remaining = 4 - strlen($lengthBytes);
                try {
                    $lengthBytes .= $source->readFixedSize($remaining);
                } catch (IO\Exception\RuntimeException $e) {
                    throw new Exception\DecryptionException(
                        'Stream decryption failed: truncated frame header.',
                        previous: $e,
                    );
                }
            }

            /** @var positive-int $frameSize */
            $frameSize = unpack('V', $lengthBytes)[1];

            if ($frameSize > ($chunkSize + namespace\STREAM_TAG_BYTES)) {
                throw new Exception\DecryptionException('Invalid frame size in stream.');
            }

            try {
                $chunk = $source->readFixedSize($frameSize);
            } catch (IO\Exception\RuntimeException $e) {
                throw new Exception\DecryptionException('Stream decryption failed: truncated frame.', previous: $e);
            }

            /** @var array{string, int}|false $result */
            $result = Internal\call_sodium(static function () use (&$state, $chunk): array|false {
                return sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            });
            if ($result === false) {
                throw new Exception\DecryptionException('Stream decryption failed.');
            }

            [$plaintext, $tag] = $result;
            $destination->writeAll($plaintext);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                return;
            }
        }

        throw new Exception\DecryptionException('Stream ended without final tag.');
    }
}
