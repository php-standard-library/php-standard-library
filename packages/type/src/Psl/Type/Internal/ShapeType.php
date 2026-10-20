<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use stdClass;
use Throwable;

use function array_diff_key;
use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function implode;
use function is_array;
use function is_int;
use function is_iterable;

/**
 * @template Tk of array-key
 * @template Tv
 *
 * @extends Type\Type<array<Tk, Tv>>
 *
 * @mago-expect lint:kan-defect
 *
 * @internal
 */
final readonly class ShapeType extends Type\Type
{
    /**
     * @var array<Tk, Type\TypeInterface<Tv>>
     */
    private array $requiredElements;

    /**
     * @psalm-mutation-free
     *
     * @param array<Tk, Type\TypeInterface<Tv>> $elements_types
     */
    public function __construct(
        private array $elements_types,
        private bool $allowUnknownFields = false,
    ) {
        $this->requiredElements = array_filter(
            $elements_types,
            static fn(Type\TypeInterface $element): bool => !$element->isOptional(),
        );
    }

    /**
     * @psalm-assert-if-true array<Tk, Tv> $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($this->elements_types as $element => $type) {
            if (array_key_exists($element, $value)) {
                if (!$type->matches($value[$element])) {
                    return false;
                }

                continue;
            }

            if (!$type->isOptional()) {
                return false;
            }
        }

        if (!$this->allowUnknownFields) {
            foreach ($value as $k => $_) {
                if (!array_key_exists($k, $this->elements_types)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @throws CoercionException
     *
     * @return array<Tk, Tv>
     */
    #[Override]
    public function coerce(mixed $value): array
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        // To whom reads this: yes, I hate this stuff as passionately as you do :-)
        if (!is_array($value)) {
            // Fallback to slow implementation - unhappy path
            return $this->coerceIterable($value);
        }

        if (array_keys(array_intersect_key($value, $this->requiredElements)) !== array_keys($this->requiredElements)) {
            // Fallback to slow implementation - unhappy path
            return $this->coerceIterable($value);
        }

        if (!$this->allowUnknownFields && array_keys($value) !== array_keys($this->elements_types)) {
            // Fallback to slow implementation - unhappy path
            return $this->coerceIterable($value);
        }

        $coerced = [];

        try {
            foreach (array_intersect_key($this->elements_types, $value) as $key => $type) {
                $coerced[$key] = $type->coerce($value[$key]);
            }
        } catch (CoercionException) {
            // Fallback to slow implementation - unhappy path. Prevents having to eagerly compute traces.
            return $this->coerceIterable($value);
        }

        foreach ($this->elements_types as $key => $type) {
            if (!(!array_key_exists($key, $coerced) && $type instanceof NullishType)) {
                continue;
            }

            $coerced[$key] = null;
        }

        /** @var mixed $additionalValue */
        foreach (array_diff_key($value, $this->elements_types) as $key => $additionalValue) {
            $coerced[$key] = $additionalValue;
        }

        return $coerced;
    }

    /**
     * @throws CoercionException
     *
     * @return array<Tk, Tv>
     */
    private function coerceIterable(mixed $value): array
    {
        if (!is_iterable($value)) {
            throw CoercionException::withValue($value, $this->toString());
        }

        $arrayKeyType = Type\array_key();
        $array = [];
        $k = null;
        try {
            /**
             * @var Tk $k
             * @var Tv $v
             */
            foreach ($value as $k => $v) {
                // @mago-expect analysis:redundant-type-comparison
                if (!$arrayKeyType->matches($k)) {
                    continue;
                }

                $array[$k] = $v;
            }
        } catch (Throwable $e) {
            throw CoercionException::withValue(null, $this->toString(), PathExpression::iteratorError($k), $e);
        }

        $result = [];
        $element = null;
        $elementValueFound = false;

        try {
            foreach ($this->elements_types as $element => $type) {
                $elementValueFound = false;
                if (array_key_exists($element, $array)) {
                    $elementValueFound = true;
                    $result[$element] = $type->coerce($array[$element]);

                    continue;
                }

                if ($type->isOptional()) {
                    if ($type instanceof NullishType) {
                        $result[$element] = null;
                    }

                    continue;
                }

                throw CoercionException::withValue(null, $this->toString(), PathExpression::path($element));
            }
        } catch (CoercionException $e) {
            throw match (true) {
                $elementValueFound => CoercionException::withValue(
                    null === $element ? null : $array[$element] ?? null,
                    $this->toString(),
                    PathExpression::path($element),
                    $e,
                ),
                default => $e,
            };
        }

        if ($this->allowUnknownFields) {
            foreach ($array as $k => $v) {
                if (array_key_exists($k, $result)) {
                    continue;
                }

                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * @throws AssertException
     *
     * @return array<Tk, Tv>
     *
     * @psalm-assert array<Tk, Tv> $value
     */
    #[Override]
    public function assert(mixed $value): array
    {
        if (!is_array($value)) {
            throw AssertException::withValue($value, $this->toString());
        }

        $result = [];
        $element = null;
        $elementValueFound = false;

        try {
            foreach ($this->elements_types as $element => $type) {
                $elementValueFound = false;
                if (array_key_exists($element, $value)) {
                    $elementValueFound = true;
                    $result[$element] = $type->assert($value[$element]);

                    continue;
                }

                if ($type->isOptional()) {
                    if ($type instanceof NullishType) {
                        $result[$element] = null;
                    }

                    continue;
                }

                throw AssertException::withValue(null, $this->toString(), PathExpression::path($element));
            }
        } catch (AssertException $e) {
            throw match (true) {
                $elementValueFound => AssertException::withValue(
                    null === $element ? null : $value[$element] ?? null,
                    $this->toString(),
                    PathExpression::path($element),
                    $e,
                ),
                default => $e,
            };
        }

        /**
         * @var Tv $v
         */
        foreach ($value as $k => $v) {
            if (array_key_exists($k, $result)) {
                continue;
            }

            if ($this->allowUnknownFields) {
                $result[$k] = $v;
                continue;
            }

            throw AssertException::withValue($v, $this->toString(), PathExpression::path($k));
        }

        return $result;
    }

    /**
     * Returns a string representation of the shape.
     */
    #[Override]
    public function toString(): string
    {
        $nodes = [];
        foreach ($this->elements_types as $element => $type) {
            $nodes[] = $this->getElementName($element) . ($type->isOptional() ? '?' : '') . ': ' . $type->toString();
        }

        return 'array{' . implode(', ', $nodes) . '}';
    }

    private function getElementName(string|int $element): string
    {
        return is_int($element) ? (string) $element : '\'' . $element . '\'';
    }
}
