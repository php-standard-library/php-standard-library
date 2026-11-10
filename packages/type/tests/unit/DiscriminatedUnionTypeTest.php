<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use ArrayIterator;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psl\Str;
use Psl\Type;
use RuntimeException;
use stdClass;

/**
 * @extends TypeTestCase<array>
 */
final class DiscriminatedUnionTypeTest extends TypeTestCase
{
    private const string COMPANY = "array{'type': \"company\", 'name': string}";
    private const string PERSON = "array{'type': \"person\", 'firstName': string, 'age': int}";
    private const string UNION = self::COMPANY . '|' . self::PERSON;

    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\discriminated_union('type', [
            'company' => Type\shape([
                'type' => Type\literal_scalar('company'),
                'name' => Type\string(),
            ]),
            'person' => Type\shape([
                'type' => Type\literal_scalar('person'),
                'firstName' => Type\string(),
                'age' => Type\int(),
            ]),
        ]);
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        $company = ['type' => 'company', 'name' => 'Acme'];
        $person = ['type' => 'person', 'firstName' => 'Ada', 'age' => 36];

        yield [$company, $company];
        yield [$person, $person];
        yield [['type' => 'person', 'firstName' => 'Ada', 'age' => '36'], $person];
        yield [(object) $company, $company];
        yield [new ArrayIterator($person), $person];
        yield [
            (static function () use ($company): iterable {
                yield new stdClass() => 'skipped';
                yield from $company;
            })(),
            $company,
        ];
    }

    #[Override]
    public static function getInvalidCoercions(): iterable
    {
        yield [null];
        yield ['company'];
        yield [1];
        yield [[]];
        yield [['name' => 'Acme']];
        yield [['type' => 'unknown', 'name' => 'Acme']];
        yield [['type' => null, 'name' => 'Acme']];
        yield [['type' => ['company'], 'name' => 'Acme']];
        yield [['type' => 'company', 'firstName' => 'Ada', 'age' => 36]];
        yield [['type' => 'person', 'firstName' => 'Ada']];
        yield [['type' => 'person', 'firstName' => 'Ada', 'age' => 'old']];
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [static::getType(), self::UNION];
        yield [
            Type\discriminated_union('kind', [
                1 => Type\shape(['kind' => Type\literal_scalar(1)]),
                2 => Type\shape(['kind' => Type\literal_scalar(2)]),
            ]),
            "array{'kind': 1}|array{'kind': 2}",
        ];
    }

    public static function provideAssertExceptionExpectations(): iterable
    {
        yield 'non-array input' => [
            static::getType(),
            'company',
            'Expected "' . self::UNION . '", got "string".',
        ];
        yield 'missing key' => [
            static::getType(),
            ['name' => 'Acme'],
            'Expected ""company"|"person"", got "null" at path "type".',
        ];
        yield 'unknown key value' => [
            static::getType(),
            ['type' => 'unknown', 'name' => 'Acme'],
            'Expected ""company"|"person"", got "string" at path "type".',
        ];
        yield 'non array-key key value' => [
            static::getType(),
            ['type' => 1.5, 'name' => 'Acme'],
            'Expected ""company"|"person"", got "float" at path "type".',
        ];
        yield 'wrong field inside variant' => [
            static::getType(),
            ['type' => 'person', 'firstName' => 'Ada', 'age' => 'old'],
            'Expected "' . self::PERSON . '", got "string" at path "age".',
        ];
        yield 'nested in shape' => [
            Type\shape(['party' => static::getType()]),
            ['party' => ['type' => 'person', 'firstName' => 'Ada', 'age' => 'old']],
            'Expected "array{\'party\': ' . self::UNION . '}", got "string" at path "party.age".',
        ];
        yield 'nested in vec and shape' => [
            Type\vec(Type\shape(['party' => static::getType()])),
            [
                ['party' => ['type' => 'company', 'name' => 'Acme']],
                ['party' => ['type' => 'person', 'firstName' => 'Ada', 'age' => 'old']],
            ],
            'Expected "vec<array{\'party\': ' . self::UNION . '}>", got "string" at path "1.party.age".',
        ];
        yield 'missing key nested in vec and shape' => [
            Type\vec(Type\shape(['party' => static::getType()])),
            [['party' => ['name' => 'Acme']]],
            'Expected "vec<array{\'party\': ' . self::UNION . '}>", got "null" at path "0.party.type".',
        ];
    }

    public static function provideCoerceExceptionExpectations(): iterable
    {
        yield 'non-array input' => [
            static::getType(),
            'company',
            'Could not coerce "string" to type "' . self::UNION . '".',
        ];
        yield 'missing key' => [
            static::getType(),
            ['name' => 'Acme'],
            'Could not coerce "null" to type ""company"|"person"" at path "type".',
        ];
        yield 'unknown key value' => [
            static::getType(),
            ['type' => 'unknown', 'name' => 'Acme'],
            'Could not coerce "string" to type ""company"|"person"" at path "type".',
        ];
        yield 'wrong field inside variant' => [
            static::getType(),
            ['type' => 'person', 'firstName' => 'Ada', 'age' => 'old'],
            'Could not coerce "string" to type "' . self::PERSON . '" at path "age".',
        ];
        yield 'nested in vec and shape' => [
            Type\vec(Type\shape(['party' => static::getType()])),
            [
                ['party' => ['type' => 'company', 'name' => 'Acme']],
                ['party' => ['type' => 'person', 'firstName' => 'Ada', 'age' => 'old']],
            ],
            'Could not coerce "string" to type "vec<array{\'party\': ' . self::UNION . '}>" at path "1.party.age".',
        ];
        yield 'iterator throwing exception' => [
            static::getType(),
            (static function (): iterable {
                yield 'type' => 'company';
                throw new RuntimeException('whoops');
            })(),
            'Could not coerce "null" to type "' . self::UNION . '" at path "type.next()": whoops.',
        ];
    }

    #[DataProvider('provideAssertExceptionExpectations')]
    public function testInvalidAssertionTypeExceptions(
        Type\TypeInterface $type,
        mixed $data,
        string $expectedMessage,
    ): void {
        try {
            $type->assert($data);
            static::fail(Str\format('Expected "%s" exception to be thrown.', Type\Exception\AssertException::class));
        } catch (Type\Exception\AssertException $e) {
            static::assertSame($expectedMessage, $e->getMessage());
        }
    }

    #[DataProvider('provideCoerceExceptionExpectations')]
    public function testInvalidCoercionTypeExceptions(
        Type\TypeInterface $type,
        mixed $data,
        string $expectedMessage,
    ): void {
        try {
            $type->coerce($data);
            static::fail(Str\format('Expected "%s" exception to be thrown.', Type\Exception\CoercionException::class));
        } catch (Type\Exception\CoercionException $e) {
            static::assertSame($expectedMessage, $e->getMessage());
        }
    }

    public function testOnlyTheSelectedVariantIsConsulted(): void
    {
        $type = Type\discriminated_union('type', [
            'a' => Type\shape(['type' => Type\string(), 'value' => Type\int()]),
            'b' => Type\shape(['type' => Type\string(), 'value' => Type\string()]),
        ]);

        static::assertFalse($type->matches(['type' => 'a', 'value' => 'text']));
        static::assertTrue($type->matches(['type' => 'b', 'value' => 'text']));
        static::assertTrue($type->matches(['type' => 'a', 'value' => 1]));
    }
}
