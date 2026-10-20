<?php

declare(strict_types=1);

namespace Psl\Encoding\Hex;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\Encoding\Exception;
use Psl\IO;

use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * A read handle that decodes hex-encoded data from an inner readable handle.
 *
 * Reads chunks from the inner handle and decodes complete 2-byte hex pairs.
 * Buffers any odd trailing character for the next read.
 * On EOF, throws if there is an incomplete hex pair remaining.
 *
 * @api
 */
final class DecodingReadHandle implements IO\BufferedReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private string $buffer = '';
    private string $remainder = '';
    private bool $eof = false;

    public function __construct(
        private readonly IO\ReadHandleInterface $handle,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function reachedEndOfDataSource(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    /**
     * {@inheritDoc}
     */
    public function tryRead(null|int $maxBytes = null): string
    {
        if ($this->buffer === '' && !$this->eof) {
            $this->fillBuffer(wait: false);
        }

        if ($this->buffer === '') {
            return '';
        }

        if (null === $maxBytes || $maxBytes >= strlen($this->buffer)) {
            $result = $this->buffer;
            $this->buffer = '';
            return $result;
        }

        $result = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, $maxBytes);
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        if ($this->eof && $this->buffer === '') {
            return '';
        }

        if ($this->buffer === '') {
            $this->fillBuffer($cancellation);
        }

        if ($this->buffer === '') {
            return '';
        }

        if (null === $maxBytes || $maxBytes >= strlen($this->buffer)) {
            $result = $this->buffer;
            $this->buffer = '';
            return $result;
        }

        $result = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, $maxBytes);
        return $result;
    }

    public function readByte(CancellationTokenInterface $cancellation = new NullCancellationToken()): string
    {
        if ($this->buffer === '' && !$this->eof) {
            $this->fillBuffer($cancellation);
        }

        if ($this->buffer === '') {
            throw new IO\Exception\RuntimeException('Reached EOF without any more data.');
        }

        $ret = $this->buffer[0];
        if ($ret === $this->buffer) {
            $this->buffer = '';
            return $ret;
        }

        $this->buffer = substr($this->buffer, 1);
        return $ret;
    }

    public function readLine(CancellationTokenInterface $cancellation = new NullCancellationToken()): null|string
    {
        $line = $this->readUntil("\n", $cancellation);
        if ($line !== null) {
            if ($line !== '' && $line[-1] === "\r") {
                return substr($line, 0, -1);
            }

            return $line;
        }

        // No EOL found; return whatever remains, or null if empty
        // @codeCoverageIgnoreStart
        if ($this->buffer === '' && !$this->eof) {
            $this->fillBuffer($cancellation);
        }

        // @codeCoverageIgnoreEnd

        if ($this->buffer === '') {
            return null;
        }

        $result = $this->buffer;
        $this->buffer = '';
        return $result;
    }

    public function readUntil(
        string $suffix,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): null|string {
        $suffixLen = strlen($suffix);
        $idx = strpos($this->buffer, $suffix);
        if ($idx !== false) {
            $result = substr($this->buffer, 0, $idx);
            $this->buffer = substr($this->buffer, $idx + $suffixLen);
            return $result;
        }

        while (!$this->eof) {
            $offset = strlen($this->buffer) - $suffixLen + 1;
            $offset = $offset > 0 ? $offset : 0;

            $this->fillBuffer($cancellation);

            $idx = strpos($this->buffer, $suffix, $offset);
            if ($idx !== false) {
                $result = substr($this->buffer, 0, $idx);
                $this->buffer = substr($this->buffer, $idx + $suffixLen);
                return $result;
            }
        }

        return null;
    }

    public function readUntilBounded(
        string $suffix,
        int $maxBytes,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): null|string {
        $suffixLen = strlen($suffix);
        $idx = strpos($this->buffer, $suffix);
        if ($idx !== false) {
            // @codeCoverageIgnoreStart
            if ($idx > $maxBytes) {
                throw new IO\Exception\OverflowException(sprintf(
                    'Exceeded maximum byte limit (%d) before encountering the suffix ("%s").',
                    $maxBytes,
                    $suffix,
                ));
            }

            $result = substr($this->buffer, 0, $idx);
            $this->buffer = substr($this->buffer, $idx + $suffixLen);
            return $result;
            // @codeCoverageIgnoreEnd
        }

        // @codeCoverageIgnoreStart
        if (strlen($this->buffer) > $maxBytes) {
            throw new IO\Exception\OverflowException(sprintf(
                'Exceeded maximum byte limit (%d) before encountering the suffix ("%s").',
                $maxBytes,
                $suffix,
            ));
        }

        // @codeCoverageIgnoreEnd

        while (!$this->eof) {
            $offset = strlen($this->buffer) - $suffixLen + 1;
            $offset = $offset > 0 ? $offset : 0;

            $this->fillBuffer($cancellation);

            $idx = strpos($this->buffer, $suffix, $offset);
            if ($idx !== false) {
                // @codeCoverageIgnoreStart
                if ($idx > $maxBytes) {
                    throw new IO\Exception\OverflowException(sprintf(
                        'Exceeded maximum byte limit (%d) before encountering the suffix ("%s").',
                        $maxBytes,
                        $suffix,
                    ));
                }

                // @codeCoverageIgnoreEnd

                $result = substr($this->buffer, 0, $idx);
                $this->buffer = substr($this->buffer, $idx + $suffixLen);
                return $result;
            }

            // @codeCoverageIgnoreStart
            if (strlen($this->buffer) > $maxBytes) {
                throw new IO\Exception\OverflowException(sprintf(
                    'Exceeded maximum byte limit (%d) before encountering the suffix ("%s").',
                    $maxBytes,
                    $suffix,
                ));
            }
            // @codeCoverageIgnoreEnd
        }

        return null;
    }

    /**
     * @param bool $wait Whether to wait for data from the inner handle, or to only take what it has
     *                  available right away, without waiting (for {@see tryRead()}).
     *
     * @throws Exception\RangeException If the hex data contains invalid characters or has an odd length at EOF.
     */
    private function fillBuffer(
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        bool $wait = true,
    ): void {
        if ($this->eof) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $chunk = $wait ? $this->handle->read(4096, $cancellation) : $this->handle->tryRead(4096);
        if ($chunk === '' && $this->handle->reachedEndOfDataSource()) {
            $this->eof = true;
            // Decode any remaining bytes.
            if ($this->remainder !== '') {
                // @codeCoverageIgnoreStart
                $this->buffer .= namespace\decode($this->remainder);
                $this->remainder = '';
                // @codeCoverageIgnoreEnd
            }

            return;
        }

        $data = $this->remainder . $chunk;

        // Decode complete 2-byte hex pairs only.
        $length = strlen($data);
        $usable = $length - ($length % 2);

        if ($usable > 0) {
            $this->buffer .= namespace\decode(substr($data, 0, $usable));
            $this->remainder = substr($data, $usable);
        } else {
            // @codeCoverageIgnoreStart
            $this->remainder = $data;
            // @codeCoverageIgnoreEnd
        }
    }
}
