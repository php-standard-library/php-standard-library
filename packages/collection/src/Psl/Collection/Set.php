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
use function array_key_first;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function iterator_to_array;

use const ARRAY_FILTER_USE_KEY;

/**
 * @template T of array-key
 *
 * @implements SetInterface<T>
 *
 * @api
 */
final readonly class Set implements SetInterface
{
    /**
     * @var array<T, T> $elements
     */
    private array $elements;

    /**
     * Creates a new `Set` containing the values of the given array.
     *
     * @param array<array-key, T> $elements
     *
     * @psalm-mutation-free
     */
    public function __construct(array $elements)
    {
        $set = [];
        foreach ($elements as $element) {
            $set[$element] = $element;
        }

        $this->elements = $set;
    }

    /**
     * Creates and returns a default instance of {@see Set}.
     *
     * @return static A default instance of {@see Set}.
     *
     * @pure
     */
    #[Override]
    public static function default(): static
    {
        return new self([]);
    }

    /**
     * Create a set from the given array, using the values of the array as the set values.
     *
     * @template Ts of array-key
     *
     * @param array<array-key, Ts> $elements
     *
     * @return Set<Ts>
     *
     * @pure
     */
    public static function fromArray(array $elements): Set
    {
        return new self($elements);
    }

    /**
     * Create a set from the given items, using the values of the iterable as the set values.
     *
     * @template Ts of array-key
     *
     * @param iterable<mixed, Ts> $items
     *
     * @return Set<Ts>
     */
    public static function fromItems(iterable $items): Set
    {
        $array = iterator_to_array($items, preserve_keys: false);

        return self::fromArray($array);
    }

    /**
     * Create a set from the given $elements array, using the keys of the array as the set values.
     *
     * @template Ts of array-key
     *
     * @param array<Ts, mixed> $elements
     *
     * @return Set<Ts>
     *
     * @pure
     */
    public static function fromArrayKeys(array $elements): Set
    {
        /** @var array<Ts, Ts> $set */
        $set = [];
        foreach ($elements as $key => $_) {
            $set[$key] = $key;
        }

        return new self($set);
    }

    /**
     * Returns the first value in the current `Set`.
     *
     * @return T|null The first value in the current `Set`, or `null` if the
     *                current `Set` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function first(): null|int|string
    {
        return array_key_first($this->elements);
    }

    /**
     * Returns the last value in the current `Set`.
     *
     * @return T|null The last value in the current `Set`, or `null` if the
     *                current `Set` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function last(): null|int|string
    {
        return array_key_last($this->elements);
    }

    /**
     * Retrieve an external iterator.
     *
     * @return Iterator<T, T>
     */
    #[Override]
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->elements);
    }

    /**
     * Is the `Set` empty?
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function isEmpty(): bool
    {
        return [] === $this->elements;
    }

    /**
     * Get the number of elements in the current `Set`.
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
     * Get an array copy of the current `Set`.
     *
     * @return array<T, T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function toArray(): array
    {
        return $this->elements;
    }

    /**
     * Get an array copy of the current `Set`.
     *
     * @return array<T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return array_values($this->elements);
    }

    /**
     * Returns the provided value if it exists in the current `Set`.
     *
     * As {@see Set} does not have keys, this method checks if the value exists in the set.
     * If the value exists, it is returned to indicate presence in the set. If the value does not exist,
     * an {@see Exception\OutOfBoundsException} is thrown to indicate the absence of the value.
     *
     * @param T $k
     *
     * @throws Exception\OutOfBoundsException If $k is out-of-bounds.
     *
     * @return T
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function at(int|string $k): int|string
    {
        if (!array_key_exists($k, $this->elements)) {
            throw Exception\OutOfBoundsException::for($k);
        }

        return $this->elements[$k];
    }

    /**
     * Determines if the specified value is in the current set.
     *
     * As {@see Set} does not have keys, this method checks if the value exists in the set.
     * If the value exists, it returns true to indicate presence in the set. If the value does not exist,
     * it returns false to indicate the absence of the value.
     *
     * @param T $k
     *
     * @return bool True if the value is in the set, false otherwise.
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
     * @param T $k
     *
     * @return bool True if the value is in the set, false otherwise.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function containsKey(int|string $k): bool
    {
        return $this->contains($k);
    }

    /**
     * Returns the provided value if it is part of the set, or null if it is not.
     *
     * As {@see Set} does not have keys, this method checks if the value exists in the set.
     * If the value exists, it is returned to indicate presence in the set. If the value does not exist,
     * null is returned to indicate the absence of the value.
     *
     * @param T $k
     *
     * @return T|null
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function get(int|string $k): null|int|string
    {
        return $this->elements[$k] ?? null;
    }

    /**
     * Returns the first key in the current `Set`.
     *
     * As {@see Set} does not have keys, this method acts as an alias for {@see Set::first()}.
     *
     * @return T|null The first value in the current `Set`, or `null` if the
     *                current `Set` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function firstKey(): null|int|string
    {
        return $this->first();
    }

    /**
     * Returns the last key in the current `Set`.
     *
     * As {@see Set} does not have keys, this method acts as an alias for {@see Set::last()}.
     *
     * @return T|null The last value in the current `Set`, or `null` if the
     *                current `Set` is empty.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function lastKey(): null|int|string
    {
        return $this->last();
    }

    /**
     * Returns the key of the first element that matches the search value.
     *
     * If no element matches the search value, this function returns null.
     *
     * As {@see Set} does not have keys, this method returns the value itself.
     *
     * @param T $searchValue The value that will be search for in the current `Set`.
     *
     * @return T|null The value if its found, null otherwise.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function linearSearch(mixed $searchValue): null|int|string
    {
        foreach ($this->elements as $key => $element) {
            if ($searchValue !== $element) {
                continue;
            }

            return $key;
        }

        return null;
    }

    /**
     * Returns a `Vector` containing the values of the current `Set`.
     *
     * @return Vector<T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function values(): Vector
    {
        return Vector::fromArray($this->elements);
    }

    /**
     * As {@see Set} does not have keys, this method acts as an alias for {@see Set::values()}.
     *
     * @return Vector<T>
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function keys(): Vector
    {
        return Vector::fromArray(array_keys($this->elements));
    }

    /**
     * Returns a `Set` containing the values of the current `Set`
     * that meet a supplied condition.
     *
     * Only values that meet a certain criteria are affected by a call to
     * `filter()`, while all values are affected by a call to `map()`.
     *
     * @param (Closure(T): bool) $fn The callback containing the condition to apply to the current
     *                               `Set` values.
     *
     * @return Set<T> a Set containing the values after a user-specified condition
     *                is applied.
     */
    #[Override]
    public function filter(Closure $fn): Set
    {
        return new Set(array_filter($this->elements, $fn, ARRAY_FILTER_USE_KEY));
    }

    /**
     * Applies a user-defined condition to each value in the `Set`,
     *  considering the value as both key and value.
     *
     * This method extends {@see Set::filter()} by providing the value twice to the
     *  callback function: once as the "key" and once as the "value", even though {@see Set} do not have traditional key-value pairs.
     *
     * This allows for filtering based on both the value's "key" and "value" representation, which are identical.
     * It's particularly useful when the distinction between keys and values is relevant for the condition.
     *
     * @param (Closure(T, T): bool) $fn T
     *
     * @return Set<T>
     */
    #[Override]
    public function filterWithKey(Closure $fn): Set
    {
        return $this->filter(
            /**
             * @param T $k
             */
            static fn(string|int $k): bool => $fn($k, $k),
        );
    }

    /**
     * Returns a `Set` after an operation has been applied to each value
     * in the current `Set`.
     *
     * Every value in the current Map is affected by a call to `map()`, unlike
     * `filter()` where only values that meet a certain criteria are affected.
     *
     * @template Tu of array-key
     *
     * @param (Closure(T): Tu) $fn The callback containing the operation to apply to the current
     *                             `Set` values.
     *
     * @return Set<Tu> a `Set` containing key/value pairs after a user-specified
     *                 operation is applied.
     */
    #[Override]
    public function map(Closure $fn): Set
    {
        return new Set(array_map($fn, $this->elements));
    }

    /**
     * Transform the values of the current `Set` by applying the provided callback,
     *  considering the value as both key and value.
     *
     * Similar to {@see Set::map()}, this method extends the functionality by providing the value twice to the
     *  callback function: once as the "key" and once as the "value",
     *
     * The allows for transformations that take into account the value's dual role. It's useful for operations where the distinction
     *  between keys and values is relevant.
     *
     * @template Tu of array-key
     *
     * @param (Closure(T, T): Tu) $fn
     *
     * @return Set<Tu>
     */
    #[Override]
    public function mapWithKey(Closure $fn): Set
    {
        return $this->map(
            /**
             * @param T $k
             */
            static fn(string|int $k): string|int => $fn($k, $k),
        );
    }

    /**
     * Always throws an exception since `Set` can only contain array-key values.
     *
     * @template Tu
     *
     * @param array<array-key, Tu> $elements The elements to use to combine with the elements of this `SetInterface`.
     *
     * @psalm-mutation-free
     *
     * @throws Exception\RuntimeException Always throws an exception since `Set` can only contain array-key values.
     */
    #[Override]
    public function zip(array $elements): never
    {
        throw new Exception\RuntimeException('Cannot zip a Set.');
    }

    /**
     * Returns a `Set` containing the first `n` values of the current
     * `Set`.
     *
     * The returned `Set` will always be a proper subset of the current
     * `Set`.
     *
     * `$n` is 1-based. So the first element is 1, the second 2, etc.
     *
     * @param int<0, max> $n The last element that will be included in the returned
     *                       `Set`.
     *
     * @return Set<T> A `Set` that is a proper subset of the current
     *                `Set` up to `n` elements.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function take(int $n): Set
    {
        return $this->slice(0, $n);
    }

    /**
     * Returns a `Set` containing the values of the current `Set`
     * up to but not including the first value that produces `false` when passed
     * to the specified callback.
     *
     * The returned `Set` will always be a proper subset of the current
     * `Set`.
     *
     * @param (Closure(T): bool) $fn The callback that is used to determine the stopping
     *                               condition.
     *
     * @return Set<T> A `Set` that is a proper subset of the current
     *                `Set` up until the callback returns `false`.
     */
    #[Override]
    public function takeWhile(Closure $fn): Set
    {
        $result = [];
        foreach ($this->elements as $k => $v) {
            if (!$fn($v)) {
                break;
            }

            $result[$k] = $v;
        }

        return new Set($result);
    }

    /**
     * Returns a `Set` containing the values after the `n`-th element of
     * the current `Set`.
     *
     * The returned `Set` will always be a proper subset of the current
     * `SetInterface`.
     *
     * `$n` is 1-based. So the first element is 1, the second 2, etc.
     *
     * @param int<0, max> $n The last element to be skipped; the $n+1 element will be the
     *                       first one in the returned `Set`.
     *
     * @return Set<T> A `Set` that is a proper subset of the current
     *                `Set` containing values after the specified `n`-th element.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function drop(int $n): Set
    {
        return $this->slice($n);
    }

    /**
     * Returns a `Set` containing the values of the current `Set`
     * starting after and including the first value that produces `true` when
     * passed to the specified callback.
     *
     * The returned `Set` will always be a proper subset of the current
     * `Set`.
     *
     * @param (Closure(T): bool) $fn The callback used to determine the starting element for the
     *                               returned `Set`.
     *
     * @return Set<T> A `Set` that is a proper subset of the current
     *                `Set` starting after the callback returns `true`.
     */
    #[Override]
    public function dropWhile(Closure $fn): Set
    {
        $result = [];
        $dropping = true;
        foreach ($this->elements as $k => $v) {
            if ($dropping && $fn($v)) {
                continue;
            }

            $dropping = false;
            $result[$k] = $v;
        }

        return new Set($result);
    }

    /**
     * Returns a subset of the current `Set` starting from a given index up
     * to, but not including, the element at the provided length from the starting
     * index.
     *
     * `$start` is 0-based. $len is 1-based. So `slice(0, 2)` would return the
     * elements at index 0 and 1.
     *
     * The returned `Set` will always be a proper subset of this
     * `Set`.
     *
     * @param int<0, max> $start The starting index of this set to begin the returned
     *                           `Set`.
     * @param int<0, max> $length The length of the returned `Set`.
     *
     * @return Set<T> A `Set` that is a proper subset of the current
     *                `Set` starting at `$start` up to but not including
     *                the element `$start + $length`.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function slice(int $start, null|int $length = null): Set
    {
        return self::fromArray(array_slice($this->elements, $start, $length, true));
    }

    /**
     * Returns a `Vector` containing the original `Set` split into
     * chunks of the given size.
     *
     * If the original `Set` doesn't divide evenly, the final chunk will be
     * smaller.
     *
     * @param positive-int $size The size of each chunk.
     *
     * @return Vector<Set<T>> A `Vector` containing the original `Set` split
     *                        into chunks of the given size.
     *
     * @psalm-mutation-free
     */
    #[Override]
    public function chunk(int $size): Vector
    {
        return Vector::fromArray(array_map(static::fromArray(...), array_chunk($this->toArray(), $size)));
    }
}
