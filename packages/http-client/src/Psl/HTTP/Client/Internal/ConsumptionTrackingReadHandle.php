<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Internal;

use Override;
use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\IO;

/**
 * Wraps a request body and records whether any bytes were ever read from it.
 *
 * {@see RedirectClient} uses this to tell whether an inner client consumed the
 * body. A non-seekable body that was never read, such as one awaiting an
 * `Expect: 100-continue` that a redirect pre-empted, can be re-sent unchanged;
 * one that was partially read cannot.
 *
 * @internal
 */
final class ConsumptionTrackingReadHandle implements IO\ReadHandleInterface
{
    use IO\ReadHandleConvenienceMethodsTrait;

    private bool $consumed = false;

    public function __construct(
        private readonly IO\ReadHandleInterface $inner,
    ) {}

    /**
     * Whether any bytes have been read from the wrapped body.
     */
    public function wasConsumed(): bool
    {
        return $this->consumed;
    }

    #[Override]
    public function reachedEndOfDataSource(): bool
    {
        return $this->inner->reachedEndOfDataSource();
    }

    #[Override]
    public function tryRead(null|int $maxBytes = null): string
    {
        $data = $this->inner->tryRead($maxBytes);
        if ($data !== '') {
            $this->consumed = true;
        }

        return $data;
    }

    #[Override]
    public function read(
        null|int $maxBytes = null,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): string {
        $data = $this->inner->read($maxBytes, $cancellation);
        if ($data !== '') {
            $this->consumed = true;
        }

        return $data;
    }
}
