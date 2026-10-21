<?php

declare(strict_types=1);

namespace Psl\DNS\Internal;

use Psl\Cache\StoreInterface;
use Psl\DNS\Response;
use Psl\Ref;
use Throwable;
use WeakMap;

/**
 * The entries that {@see \Psl\DNS\CachedResolver} lookups computed to cache and are giving their TTL, in
 * one map per store.
 *
 * Every resolver that uses a store gets the map of that store, so a lookup of one resolver finds the entries
 * that the lookups of the other resolvers using the store keep, as it finds those entries in the store once
 * they have their TTL. The maps are held weakly: the map of a store is dropped when the store is destroyed.
 *
 * @internal
 */
final class KeptEntries
{
    /**
     * The map of each store that a resolver uses, by store.
     *
     * @var null|WeakMap<StoreInterface, Ref<array<non-empty-string, Response|CachedResponse|Throwable>>>
     */
    private static null|WeakMap $maps = null;

    /**
     * Not instantiable: the maps are reached through {@see KeptEntries::of()}.
     */
    private function __construct() {}

    /**
     * Get the map of the entries kept for the given store, by key, creating an empty one on first use.
     *
     * @return Ref<array<non-empty-string, Response|CachedResponse|Throwable>>
     */
    public static function of(StoreInterface $store): Ref
    {
        self::$maps ??= new WeakMap();

        $entries = self::$maps[$store] ?? null;
        if (null === $entries) {
            /** @var Ref<array<non-empty-string, Response|CachedResponse|Throwable>> $entries */
            $entries = new Ref([]);
            self::$maps[$store] = $entries;
        }

        return $entries;
    }
}
