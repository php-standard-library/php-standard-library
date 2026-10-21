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
use Psl\DNS\Internal\KeptEntries;
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
 * With a store that serializes the calls for each key, as {@see StoreInterface} requires, the queries
 * for the same question of the resolvers that share one store query their inner resolvers one at a
 * time. Once one of them has an entry to cache, each of those queries that the store serves after it
 * gets the entry, until the entry expires or the store drops it; after a lookup that caches nothing,
 * such as a cancelled one, the next query that the store serves queries its inner resolver.
 *
 * @api
 */
final readonly class CachedResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * The entries that the lookups of the resolvers sharing this resolver's store computed to cache and
     * are giving their TTL, by key.
     *
     * A lookup keeps an entry here from inside the store's computer, as soon as it has the entry and its
     * TTL, until the call that gives the entry its TTL returns, or a store call throws. The map belongs to
     * the store ({@see KeptEntries}), so every resolver that uses the store gets the same map.
     *
     * @var Ref<array<non-empty-string, Response|CachedResponse|Throwable>>
     */
    private Ref $awaitingTtl;

    /**
     * Cache the responses of the given resolver in the given store.
     *
     * @param ResolverInterface $inner The underlying resolver to cache results from.
     * @param StoreInterface $cache The cache store for DNS responses.
     * @param int $failureTtl Seconds to cache resolution failures (RFC 9520, section 3.2).
     *
     * @throws Exception\InvalidArgumentException If the failure TTL is outside 1..300 seconds.
     */
    public function __construct(
        /**
         * The underlying resolver to cache results from.
         */
        private ResolverInterface $inner,

        /**
         * The cache store for DNS responses.
         */
        private StoreInterface $cache,

        /**
         * Seconds to cache resolution failures (RFC 9520, section 3.2).
         */
        private int $failureTtl = 5,
    ) {
        if ($failureTtl < 1 || $failureTtl > 300) {
            throw Exception\InvalidArgumentException::forFailureTtl($failureTtl);
        }

        $this->awaitingTtl = KeptEntries::of($cache);
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

        $entry = $this->lookup($cacheKey, $name, $type, $cancellation, $ednsOptions);

        if ($entry instanceof Throwable) {
            throw $entry;
        }

        return $entry instanceof CachedResponse ? $entry->response() : $entry;
    }

    /**
     * Get the entry for the given question from the store, querying the inner resolver if needed,
     * and give a computed entry the TTL of its response.
     *
     * The store gets a computed entry with a zero TTL first, as the TTL depends on the response, and
     * the response's TTL in a second call. An entry to cache is also kept in {@see $awaitingTtl}, from
     * inside the computer until the second call returns or a store call throws. A lookup for the same
     * question, of this resolver or of another one that shares its store, that the store serves between
     * the two calls finds the zero-TTL entry expired, and its computer returns the kept entry instead of
     * querying an inner resolver again.
     *
     * @param non-empty-string $cacheKey
     * @param list<EDNS\OptionInterface> $ednsOptions
     *
     * @throws CancelledException If the query is cancelled.
     */
    private function lookup(
        string $cacheKey,
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation,
        array $ednsOptions,
    ): Response|CachedResponse|Throwable {
        /** @var Ref<Duration|null> $ttl */
        $ttl = new Ref(null);

        try {
            $entry = $this->cache->compute(
                $cacheKey,
                function () use (
                    $cacheKey,
                    $name,
                    $type,
                    $cancellation,
                    $ednsOptions,
                    $ttl,
                ): Response|CachedResponse|Throwable {
                    $awaiting = $this->awaitingTtl->value[$cacheKey] ?? null;
                    if (null !== $awaiting) {
                        return $awaiting;
                    }

                    [$entry, $ttl->value] = $this->queryInner($name, $type, $cancellation, $ednsOptions);
                    if (null !== $ttl->value) {
                        $this->awaitingTtl->value[$cacheKey] = $entry;
                    }

                    return $entry;
                },
                Duration::zero(),
            );

            if (null !== $ttl->value) {
                $this->cache->update($cacheKey, static fn(): Response|CachedResponse|Throwable => $entry, $ttl->value);
            }

            return $entry;
        } finally {
            // Only the lookup that computed the kept entry has a TTL to give it.
            if (null !== $ttl->value) {
                unset($this->awaitingTtl->value[$cacheKey]);
            }
        }
    }

    /**
     * Query the inner resolver, and get the entry to store for its outcome, with the TTL to give that
     * entry, or null if the entry must not be cached.
     *
     * A runtime failure or a timeout of the inner resolver is cached as the exception itself, with the
     * failure TTL. A cancellation is thrown, and so is any other exception.
     *
     * @param list<EDNS\OptionInterface> $ednsOptions
     *
     * @throws CancelledException If the query is cancelled.
     *
     * @return array{Response|CachedResponse|Throwable, Duration|null}
     */
    private function queryInner(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation,
        array $ednsOptions,
    ): array {
        try {
            $response = $this->inner->query($name, $type, $cancellation, $ednsOptions);
        } catch (Exception\RuntimeException|TimeoutException|CancelledException $exception) {
            $cancellation->throwIfCancelled();
            if ($exception instanceof CancelledException && !$exception->getPrevious() instanceof TimeoutException) {
                throw $exception;
            }

            return [$exception, Duration::seconds($this->failureTtl)];
        }

        if ($response->code === ResponseCode::ServerFailure || $response->code === ResponseCode::ServerRefused) {
            return [
                new CachedResponse($response, Timestamp::monotonic(), $this->failureTtl),
                Duration::seconds($this->failureTtl),
            ];
        }

        if ($response->code === ResponseCode::NoError || $response->code === ResponseCode::NonExistentDomain) {
            $minTtl = self::extractMinTtl($response, $type);
            if ($minTtl > 0) {
                return [new CachedResponse($response, Timestamp::monotonic(), $minTtl), Duration::seconds($minTtl)];
            }
        }

        return [$response, null];
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
