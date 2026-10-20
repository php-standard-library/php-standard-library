<?php

declare(strict_types=1);

namespace Psl\DNS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\Exception\TimeoutException;
use Psl\Async\NullCancellationToken;
use Psl\Cache\StoreInterface;
use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\DNS\Internal\CachedResponse;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\SOARecord;
use Psl\Ref;
use Throwable;

use function md5;
use function min;
use function serialize;
use function strtolower;

use const PHP_INT_MAX;

/**
 * Caching decorator for any DNS resolver.
 *
 * Wraps an existing {@see ResolverInterface} and caches responses using a
 * {@see StoreInterface}. Positive responses use the minimum answer TTL.
 * Negative responses use the lesser of the SOA TTL and SOA MINIMUM.
 * SERVFAIL, REFUSED and network failures use a short, separate TTL.
 * Responses with a zero TTL are not cached.
 *
 * @api
 */
final readonly class CachedResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * @param ResolverInterface $inner The underlying resolver to cache results from.
     * @param StoreInterface $cache The cache store for DNS responses.
     * @param int $failureTtl Seconds to cache resolution failures (RFC 9520, section 3.2).
     *
     * @throws Exception\InvalidArgumentException If the failure TTL is outside 1..300 seconds.
     */
    public function __construct(
        private ResolverInterface $inner,
        private StoreInterface $cache,
        private int $failureTtl = 5,
    ) {
        if ($failureTtl < 1 || $failureTtl > 300) {
            throw Exception\InvalidArgumentException::forFailureTtl($failureTtl);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function query(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        array $ednsOptions = [],
    ): Response {
        $cancellation->throwIfCancelled();
        $cacheKey = self::buildKey($name, $type, $ednsOptions);

        /** @var Ref<Duration|null> $ttl */
        $ttl = new Ref(null);

        $entry = $this->cache->compute(
            $cacheKey,
            function () use ($name, $type, $cancellation, $ednsOptions, $ttl): Response|CachedResponse|Throwable {
                try {
                    $response = $this->inner->query($name, $type, $cancellation, $ednsOptions);
                } catch (Exception\RuntimeException|TimeoutException|CancelledException $exception) {
                    $cancellation->throwIfCancelled();
                    if (
                        $exception instanceof CancelledException
                        && !$exception->getPrevious() instanceof TimeoutException
                    ) {
                        throw $exception;
                    }

                    $ttl->value = Duration::seconds($this->failureTtl);
                    return $exception;
                }

                if (
                    $response->code === ResponseCode::ServerFailure
                    || $response->code === ResponseCode::ServerRefused
                ) {
                    $ttl->value = Duration::seconds($this->failureTtl);
                    return new CachedResponse($response, Timestamp::monotonic(), $this->failureTtl);
                }

                if ($response->code === ResponseCode::NoError || $response->code === ResponseCode::NonExistentDomain) {
                    $minTtl = self::extractMinTtl($response, $type);
                    if ($minTtl > 0) {
                        $ttl->value = Duration::seconds($minTtl);
                        return new CachedResponse($response, Timestamp::monotonic(), $minTtl);
                    }
                }

                return $response;
            },
            Duration::zero(),
        );

        if ($ttl->value !== null) {
            $this->cache->update($cacheKey, static fn(): Response|CachedResponse|Throwable => $entry, $ttl->value);
        }

        if ($entry instanceof Throwable) {
            throw $entry;
        }

        return $entry instanceof CachedResponse ? $entry->response() : $entry;
    }

    /**
     * @param list<EDNS\OptionInterface> $ednsOptions
     *
     * @return non-empty-string
     */
    private static function buildKey(string $name, RecordType $kind, array $ednsOptions): string
    {
        $key = strtolower($name) . ':' . $kind->value;
        $cacheOptions = [];
        foreach ($ednsOptions as $option) {
            if ($option->code === 10 || $option->code === 12) {
                continue;
            }

            $cacheOptions[] = $option;
        }

        if ($cacheOptions !== []) {
            $key .= ':' . md5(serialize($cacheOptions));
        }

        return $key;
    }

    /**
     * Extract the answer TTL or the negative TTL from the SOA (RFC 2308, section 5).
     */
    private static function extractMinTtl(Response $response, RecordType $type): int
    {
        $minTtl = PHP_INT_MAX;
        $hasAnswer = false;
        foreach ($response->answers as $record) {
            $hasAnswer = $hasAnswer || $record->kind === $type;
            $minTtl = min($minTtl, (int) $record->duration->getTotalSeconds());
        }

        if ($response->code === ResponseCode::NoError && $hasAnswer) {
            return $minTtl;
        }

        $hasSoa = false;
        foreach ($response->authority as $record) {
            if (!$record instanceof SOARecord) {
                continue;
            }

            $hasSoa = true;
            $minTtl = min(
                $minTtl,
                (int) $record->duration->getTotalSeconds(),
                (int) $record->minimumTtl->getTotalSeconds(),
            );
        }

        return $hasSoa ? $minTtl : 0;
    }
}
