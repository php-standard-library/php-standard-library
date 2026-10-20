<?php

declare(strict_types=1);

namespace Psl\Encoding\QuotedPrintable;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * A read handle that encodes raw text from an inner readable handle using quoted-printable encoding.
 *
 * Reads from the inner handle and encodes each complete line (terminated by CRLF, LF or CR) via
 * {@see encode_line()}. The bytes read from this handle are identical to {@see encode()} applied
 * to all the data of the inner handle, with the same `$maxLineLength` and `$lineEnding`.
 *
 * @api
 */
final class EncodingReadHandle implements IO\BufferedReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private string $buffer = '';
    private bool $eof = false;
    private readonly Internal\StreamEncoder $encoder;

    /**
     * @param positive-int $maxLineLength Maximum encoded line length (default 76 per RFC 2045).
     * @param non-empty-string $lineEnding Line ending sequence (default "\r\n" per RFC 2045).
     */
    public function __construct(
        private readonly IO\ReadHandleInterface $handle,
        int $maxLineLength = 76,
        string $lineEnding = "\r\n",
    ) {
        $this->encoder = new Internal\StreamEncoder($maxLineLength, $lineEnding);
    }

    public function reachedEndOfDataSource(): bool
    {
        return $this->eof && $this->buffer === '';
    }

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

        return $this->tryRead($maxBytes);
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

            // @codeCoverageIgnoreStart
            return $line;
            // @codeCoverageIgnoreEnd
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
        // @codeCoverageIgnoreStart
        if ($idx !== false) {
            $result = substr($this->buffer, 0, $idx);
            $this->buffer = substr($this->buffer, $idx + $suffixLen);
            return $result;
        }

        // @codeCoverageIgnoreEnd

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
     */
    private function fillBuffer(
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        bool $wait = true,
    ): void {
        while (!$this->eof) {
            $chunk = $wait ? $this->handle->read(4096, $cancellation) : $this->handle->tryRead(4096);
            if ($chunk === '' && $this->handle->reachedEndOfDataSource()) {
                $this->eof = true;
                $this->buffer .= $this->encoder->finalize();

                return;
            }

            $this->buffer .= $this->encoder->update($chunk);
            if ($this->buffer !== '' || $chunk === '') {
                return;
            }
        }
    }
}
