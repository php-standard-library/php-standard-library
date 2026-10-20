<?php

declare(strict_types=1);

namespace Psl\Crypto\Symmetric;

use Psl\Crypto;
use Psl\Crypto\Exception;
use Psl\IO;

/**
 * @api
 */
interface StreamEncryptorInterface extends Crypto\StreamEncryptorInterface
{
    /**
     * Encrypt a stream of data.
     *
     * @param positive-int $chunkSize The size of each plaintext chunk, at most MAX_CHUNK_BYTES.
     *
     * @throws Exception\RuntimeException If encryption fails.
     * @throws Exception\InvalidArgumentException If the chunk size is outside 1..MAX_CHUNK_BYTES.
     */
    public function copySealed(
        IO\ReadHandleInterface $source,
        IO\WriteHandleInterface $destination,
        int $chunkSize = 8192,
    ): void;

    /**
     * Decrypt a stream of data.
     *
     * @throws Exception\DecryptionException If decryption fails.
     */
    public function copyOpened(IO\ReadHandleInterface $source, IO\WriteHandleInterface $destination): void;
}
