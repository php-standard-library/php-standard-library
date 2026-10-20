<?php

declare(strict_types=1);

namespace Psl\Collection;

use ArrayIterator;
use Closure;
use Iterator;
use Override;

use function array_chunk;
use function array_filter;
use function array_key_exists;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_search;
use function array_slice;
use function array_values;
use function count;
use function iterator_to_array;

use const ARRAY_FILTER_USE_BOTH;

/**
 * @template T
 *
 * @implements VectorInterface<T>
 *
 * @api
 */
final readonly class Vector implements VectorInterface
{
    /**
     * @var list<T> $elements
     */
    private array $elements;

    /**
     * @param array<array-key, T> $elements
     *
     * @psalm-mutation-free
     */
    public function __construct(array $elements)
    {
        $this->elements = array_values($elements);
    }

    /**
     * Creates and returns a default instance of {@see Vector}.
     *
     * @return static A default instance of {@see Vector}.
     *
     * @pure
     */
    #[Override]
    public static function default(): static
    {
        return new self([]);
    }

    /**
     * Create a vector from the given $elements array.
     *
     * @template Ts
     *
     * @param array<array-key, Ts> $elements
     *
     * @return Vector<Ts>
     *
     * @pure
     */
    public static function fromArray(array $elements): Vector
    {
        return new self($elements);
    }

    /**
     * Create a vector from the given $items iterable.
     *
     * @template Ts
     *
     * @param iterable<mixed, Ts> $items
     *
     * @return Vector<Ts>
     */
    public static function fromItems(iterable $items): Vector
    {
        $array = iterator_to_array($items, preserve_keys: false);

        return self::fromArray($array);
    }

    /**
     * Returns the first value in the current `Vector`.
     *
     * @return T|null The first value in the current `Vector`, or `null` if the
     *                current `Vector` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function first(): mixed
    {
        return $this->elements[0] ?? null;
    }

    /**
     * Returns the last value in the current `Vector`.
     *
     * @return T|null The last value in the current `Vector`, or `null` if the
     *                current `Vector` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function last(): mixed
    {
        $key = array_key_last($this->elements);
        if (null === $key) {
            return null;
        }

        return $this->elements[$key];
    }

    /**
     * Retrieve an external iterator.
     *
     * @return Iterator<int, T>
     */
    #[Override]
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->elements);
    }

    /**
     * Is the `Vector` empty?
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function isEmpty(): bool
    {
        return [] === $this->elements;
    }

    /**
     * Get the number of elements in the current `Vector`.
     *
     * @psalm-mutation-free
     *
     * @return int<0, max>
     */
    #[Override]
    public function count(): int
    {
        return count($this->elements);
    }

    /**
     * Get an array copy of the current `Vector`.
     *
     * @return list<T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toArray(): array
    {
        return $this->elements;
    }

    /**
     * Get an array copy of the current `Vector`.
     *
     * @return list<T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->elements;
    }

    /**
     * Returns the value at the specified key in the current `Vector`.
     *
     * @param int<0, max> $k
     *
     * @throws Exception\OutOfBoundsException If $k is out-of-bounds.
     *
     * @return T
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function at(int|string $k): mixed
    {
        if (!array_key_exists($k, $this->elements)) {
            throw Exception\OutOfBoundsException::for($k);
        }

        return $this->elements[$k];
    }

    /**
     * Determines if the specified key is in the current `Vector`.
     *
     * @param int<0, max> $k
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function contains(int|string $k): bool
    {
        return array_key_exists($k, $this->elements);
    }

    /**
     * Alias of `contains`.
     *
     * @param int<0, max> $k
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function containsKey(int|string $k): bool
    {
        return $this->contains($k);
    }

    /**
     * Returns the value at the specified key in the current `Vector`.
     *
     * @param int<0, max> $k
     *
     * @return T|null
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function get(int|string $k): mixed
    {
        return $this->elements[$k] ?? null;
    }

    /**
     * Returns the first key in the current `Vector`.
     *
     * @return int<0, max>|null The first key in the current `Vector`, or `null` if the
     *                          current `Vector` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function firstKey(): null|int
    {
        return [] === $this->elements ? null : 0;
    }

    /**
     * Returns the last key in the current `Vector`.
     *
     * @return int<0, max>|null The last key in the current `Vector`, or `null` if the
     *                          current `Vector` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function lastKey(): null|int
    {
        return array_key_last($this->elements);
    }

    /**
     * Returns the index of the first element that matches the search value.
     *
     * If no element matches the search value, this function returns null.
     *
     * @param T $searchValue The value that will be search for in the current
     *                        collection.
     *
     * @return int<0, max>|null The key (index) where that value is found; null if it is not found.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function linearSearch(mixed $searchValue): null|int
    {
        $key = array_search($searchValue, $this->elements, true);

        return false === $key ? null : $key;
    }

    /**
     * Returns a `Vector` containing the values of the current
     * `Vector`.
     *
     * @return Vector<T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function values(): Vector
    {
        return self::fromArray($this->elements);
    }

    /**
     * Returns a `Vector` containing the keys of the current `Vector`.
     *
     * @return Vector<int<0, max>>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function keys(): Vector
    {
        return self::fromArray(array_keys($this->elements));
    }

    /**
     * Returns a `Vector` containing the values of the current `Vector`
     * that meet a supplied condition.
     *
     * Only values that meet a certain criteria are affected by a call to
     * `filter()`, while all values are affected by a call to `map()`.
     *
     * The keys associated with the current `Vector` remain unchanged in the
     * returned `Vector`.
     *
     * @param (Closure(T): bool) $fn The callback containing the condition to apply to the current
     *                               `Vector` values.
     *
     * @return Vector<T> a Vector containing the values after a user-specified condition
     *                   is applied.
     */
    #[Override]
    public function filter(Closure $fn): Vector
    {
        return new Vector(array_filter($this->elements, $fn));
    }

    /**
     * Returns a `Vector` containing the values of the current `Vector`
     * that meet a supplied condition applied to its keys and values.
     *
     * Only keys and values that meet a certain criteria are affected by a call
     * to `filterWithKey()`, while all values are affected by a call to
     * `mapWithKey()`.
     *
     * The keys associated with the current `Vector` remain unchanged in the
     * returned `Vector`; the keys will be used in the filtering process only.
     *
     * @param (Closure(int<0, max>, T): bool) $fn The callback containing the condition to apply to the current
     *                                            `Vector` keys and values.
     *
     * @return Vector<T> a `Vector` containing the values after a user-specified
     *                   condition is applied to the keys and values of the current `Vector`.
     */
    #[Override]
    public function filterWithKey(Closure $fn): Vector
    {
        return new Vector(array_filter($this->elements, static fn($v, $k) => $fn($k, $v), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Returns a `Vector` after an operation has been applied to each value
     * in the current `Vector`.
     *
     * Every value in the current Map is affected by a call to `map()`, unlike
     * `filter()` where only values that meet a certain criteria are affected.
     *
     * The keys will remain unchanged from the current `Vector` to the
     * returned `Vector`.
     *
     * @template Tu
     *
     * @param (Closure(T): Tu) $fn The callback containing the operation to apply to the current
     *                             `Vector` values.
     *
     * @return Vector<Tu> a `Vector` containing key/value pairs after a user-specified
     *                    operation is applied.
     */
    #[Override]
    public function map(Closure $fn): Vector
    {
        return new Vector(array_map($fn, $this->elements));
    }

    /**
     * Returns a `Vector` after an operation has been applied to each key and
     * value in the current `Vector`.
     *
     * Every key and value in the current `Vector` is affected by a call to
     * `mapWithKey()`, unlike `filterWithKey()` where only values that meet a
     * certain criteria are affected.
     *
     * The keys will remain unchanged from this `Vector` to the returned
     * `Vector`. The keys are only used to help in the mapping operation.
     *
     * @template Tu
     *
     * @param (Closure(int<0, max>, T): Tu) $fn The callback containing the operation to apply to the current
     *                                          `Vector` keys and values.
     *
     * @return Vector<Tu> a `Vector` containing the values after a user-specified
     *                    operation on the current `Vector`'s keys and values is applied.
     */
    #[Override]
    public function mapWithKey(Closure $fn): Vector
    {
        $result = [];
        foreach ($this->elements as $k => $v) {
            $result[$k] = $fn($k, $v);
        }

        return new Vector($result);
    }

    /**
     * Returns a `Vector` where each element is a `array{0: Tv, 1: Tu}` that combines the
     * element of the current `VectorInterface` and the provided elements array.
     *
     * If the number of elements of the `Vector` are not equal to the
     * number of elements in `$elements`, then only the combined elements
     * up to and including the final element of the one with the least number of
     * elements is included.
     *
     * @template Tu
     *
     * @param array<array-key, Tu> $elements The elements to use to combine with the elements of this `VectorInterface`.
     *
     * @return Vector<array{0: T, 1: Tu}> The `Vector` that combines the values of the current
     *                                    `Vector` with the provided elements.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function zip(array $elements): Vector
    {
        $elements = array_values($elements);
        $result = [];
        foreach ($this->elements as $i => $v) {
            if (!array_key_exists($i, $elements)) {
                break;
            }

            $result[] = [$v, $elements[$i]];
        }

        return Vector::fromArray($result);
    }

    /**
     * Returns a `Vector` containing the first `n` values of the current
     * `Vector`.
     *
     * The returned `Vector` will always be a proper subset of the current
     * `Vector`.
     *
     * `$n` is 1-based. So the first element is 1, the second 2, etc.
     *
     * @param int<0, max> $n The last element that will be included in the returned
     *                       `Vector`.
     *
     * @return Vector<T> A `Vector` that is a proper subset of the current
     *                   `Vector` up to `n` elements.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function take(int $n): Vector
    {
        return $this->slice(0, $n);
    }

    /**
     * Returns a `Vector` containing the values of the current `Vector`
     * up to but not including the first value that produces `false` when passed
     * to the specified callback.
     *
     * The returned `Vector` will always be a proper subset of the current
     * `Vector`.
     *
     * @param (Closure(T): bool) $fn The callback that is used to determine the stopping
     *                               condition.
     *
     * @return Vector<T> A `Vector` that is a proper subset of the current
     *                   `Vector` up until the callback returns `false`.
     */
    #[Override]
    public function takeWhile(Closure $fn): Vector
    {
        $result = [];
        foreach ($this->elements as $v) {
            if (!$fn($v)) {
                break;
            }

            $result[] = $v;
        }

        return new Vector($result);
    }

    /**
     * Returns a `Vector` containing the values after the `n`-th element of
     * the current `Vector`.
     *
     * The returned `Vector` will always be a proper subset of the current
     * `VectorInterface`.
     *
     * `$n` is 1-based. So the first element is 1, the second 2, etc.
     *
     * @param int<0, max> $n The last element to be skipped; the $n+1 element will be the
     *                       first one in the returned `Vector`.
     *
     * @return Vector<T> A `Vector` that is a proper subset of the current
     *                   `Vector` containing values after the specified `n`-th element.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function drop(int $n): Vector
    {
        return $this->slice($n);
    }

    /**
     * Returns a `Vector` containing the values of the current `Vector`
     * starting after and including the first value that produces `true` when
     * passed to the specified callback.
     *
     * The returned `Vector` will always be a proper subset of the current
     * `Vector`.
     *
     * @param (Closure(T): bool) $fn The callback used to determine the starting element for the
     *                               returned `Vector`.
     *
     * @return Vector<T> A `Vector` that is a proper subset of the current
     *                   `Vector` starting after the callback returns `true`.
     */
    #[Override]
    public function dropWhile(Closure $fn): Vector
    {
        $result = [];
        $dropping = true;
        foreach ($this->elements as $v) {
            if ($dropping && $fn($v)) {
                continue;
            }

            $dropping = false;
            $result[] = $v;
        }

        return new Vector($result);
    }

    /**
     * Returns a subset of the current `Vector` starting from a given key up
     * to, but not including, the element at the provided length from the starting
     * key.
     *
     * `$start` is 0-based. $len is 1-based. So `slice(0, 2)` would return the
     * elements at key 0 and 1.
     *
     * The returned `Vector` will always be a proper subset of this
     * `Vector`.
     *
     * @param int<0, max> $start The starting key of this Vector to begin the returned
     *                           `Vector`.
     * @param null|int<0, max> $length The length of the returned `Vector`
     *
     * @return Vector<T> A `Vector` that is a proper subset of the current
     *                   `Vector` starting at `$start` up to but not including the
     *                   element `$start + $length`.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function slice(int $start, null|int $length = null): Vector
    {
        return self::fromArray(array_slice($this->elements, $start, $length, true));
    }

    /**
     * Returns a `Vector` containing the original `Vector` split into
     * chunks of the given size.
     *
     * If the original `Vector` doesn't divide evenly, the final chunk will be
     * smaller.
     *
     * @param positive-int $size The size of each chunk.
     *
     * @return Vector<Vector<T>> A `Vector` containing the original `Vector` split
     *                           into chunks of the given size.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function chunk(int $size): Vector
    {
        return static::fromArray(array_map(static::fromArray(...), array_chunk($this->toArray(), $size)));
    }
}
