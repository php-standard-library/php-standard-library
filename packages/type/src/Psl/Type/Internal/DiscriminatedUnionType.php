<?php

declare(strict_types=1);

namespace Psl\Type\Internal;

use Override;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;
use stdClass;
use Throwable;

use function array_key_exists;
use function implode;
use function is_array;
use function is_int;
use function is_iterable;
use function is_string;

/**
 * @template Tk of array-key
 * @template Tv
 *
 * @extends Type\Type<Tv>
 *
 * @internal
 */
final readonly class DiscriminatedUnionType extends Type\Type
{
    /**
     * @psalm-mutation-free
     *
     * @param non-empty-array<Tk, Type\TypeInterface<Tv>> $variants
     */
    public function __construct(
        private string $key,
        private array $variants,
    ) {}

    /**
     * @psalm-assert-if-true Tv $value
     */
    #[Override]
    public function matches(mixed $value): bool
    {
        if (!is_array($value) || !array_key_exists($this->key, $value)) {
            return false;
        }

        $variant = $this->variantFor($value[$this->key]);

        return null !== $variant && $variant->matches($value);
    }

    /**
     * @throws CoercionException
     *
     * @return Tv
     */
    #[Override]
    public function coerce(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            $value = $this->iterableToArray($value);
        }

        if (!array_key_exists($this->key, $value)) {
            throw CoercionException::withValue(null, $this->allowedValues(), PathExpression::path($this->key));
        }

        $variant = $this->variantFor($value[$this->key]);
        if (null === $variant) {
            throw CoercionException::withValue(
                $value[$this->key],
                $this->allowedValues(),
                PathExpression::path($this->key),
            );
        }

        return $variant->coerce($value);
    }

    /**
     * @throws AssertException
     *
     * @return Tv
     *
     * @psalm-assert Tv $value
     */
    #[Override]
    public function assert(mixed $value): mixed
    {
        if (!is_array($value)) {
            throw AssertException::withValue($value, $this->toString());
        }

        if (!array_key_exists($this->key, $value)) {
            throw AssertException::withValue(null, $this->allowedValues(), PathExpression::path($this->key));
        }

        $variant = $this->variantFor($value[$this->key]);
        if (null === $variant) {
            throw AssertException::withValue(
                $value[$this->key],
                $this->allowedValues(),
                PathExpression::path($this->key),
            );
        }

        return $variant->assert($value);
    }

    #[Override]
    public function toString(): string
    {
        $nodes = [];
        foreach ($this->variants as $variant) {
            $nodes[] = $variant->toString();
        }

        return implode('|', $nodes);
    }

    /**
     * @return null|Type\TypeInterface<Tv>
     */
    private function variantFor(mixed $discriminator): null|Type\TypeInterface
    {
        if (!is_string($discriminator) && !is_int($discriminator)) {
            return null;
        }

        return $this->variants[$discriminator] ?? null;
    }

    private function allowedValues(): string
    {
        $nodes = [];
        foreach ($this->variants as $discriminator => $_) {
            $nodes[] = new LiteralScalarType($discriminator)->toString();
        }

        return implode('|', $nodes);
    }

    /**
     * @throws CoercionException
     *
     * @return array<array-key, mixed>
     */
    private function iterableToArray(mixed $value): array
    {
        if (!is_iterable($value)) {
            throw CoercionException::withValue($value, $this->toString());
        }

        $arrayKeyType = Type\array_key();
        $array = [];
        $k = null;
        try {
            // @mago-expect analysis:mixed-assignment,mixed-assignment
            foreach ($value as $k => $v) {
                if (!$arrayKeyType->matches($k)) {
                    continue;
                }

                $array[$k] = $v;
            }
        } catch (Throwable $e) {
            throw CoercionException::withValue(null, $this->toString(), PathExpression::iteratorError($k), $e);
        }

        return $array;
    }
}
