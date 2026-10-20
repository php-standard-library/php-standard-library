<?php

declare(strict_types=1);

namespace Psl\Type\Tests\Unit;

use ArrayIterator;
use ArrayObject;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psl\Collection;
use Psl\Iter;
use Psl\Str;
use Psl\Type;
use RuntimeException;

/**
 * @extends TypeTestCase<array>
 */
final class ShapeTypeTest extends TypeTestCase
{
    #[Override]
    public static function getType(): Type\TypeInterface
    {
        return Type\shape([
            'name' => Type\string(),
            'articles' => Type\vec(Type\shape([
                'title' => Type\string(),
                'content' => Type\string(),
                'likes' => Type\int(),
                'comments' => Type\optional(Type\vec(Type\shape([
                    'user' => Type\string(),
                    'comment' => Type\string(),
                ]))),
            ])),
        ]);
    }

    public function testWillConsiderUnknownIterableFieldsWhenCoercing(): void
    {
        static::assertSame(
            [
                'defined_key' => 'value',
                'additional_key' => 'value',
            ],
            Type\shape(['defined_key' => Type\mixed()], true)->coerce(new ArrayIterator([
                'defined_key' => 'value',
                'additional_key' => 'value',
            ])),
        );
    }

    #[Override]
    public static function getValidCoercions(): iterable
    {
        foreach (static::validCoercions() as $row) {
            yield $row;
            yield [
                new ArrayIterator((array) $row[0]),
                $row[1],
            ];
        }
    }

    /**
     * @return iterable<array{0: array, 1: T}>
     */
    private static function validCoercions(): iterable
    {
        yield [
            ['name' => 'saif', 'articles' => new Collection\Vector([])],
            ['name' => 'saif', 'articles' => []],
        ];

        yield [
            [
                'name' => 'saif',
                'articles' => new Collection\Vector([['title' => 'Foo', 'content' => 'Baz', 'likes' => 0]]),
            ],
            ['name' => 'saif', 'articles' => [['title' => 'Foo', 'content' => 'Baz', 'likes' => 0]]],
        ];

        yield [
            [
                'name' => 'saif',
                'articles' => new Collection\Vector([
                    ['title' => 'Foo', 'content' => 'Baz', 'likes' => 0],
                    [
                        'title' => 'Bar',
                        'content' => 'Qux',
                        'likes' => 0,
                        'comments' => [
                            ['user' => 'a', 'comment' => 'hello'],
                            ['user' => 'b', 'comment' => 'hey'],
                            ['user' => 'c', 'comment' => 'hi'],
                        ],
                    ],
                ]),
            ],
            [
                'name' => 'saif',
                'articles' => [
                    ['title' => 'Foo', 'content' => 'Baz', 'likes' => 0],
                    [
                        'title' => 'Bar',
                        'content' => 'Qux',
                        'likes' => 0,
                        'comments' => [
                            ['user' => 'a', 'comment' => 'hello'],
                            ['user' => 'b', 'comment' => 'hey'],
                            ['user' => 'c', 'comment' => 'hi'],
                        ],
                    ],
                ],
            ],
        ];

        yield [
            [
                'name' => 'saif',
                'articles' => new Collection\Vector([
                    ['title' => 'Foo', 'content' => 'Bar', 'likes' => 0],
                    ['title' => 'Baz', 'content' => 'Qux', 'likes' => 13],
                ]),
            ],
            [
                'name' => 'saif',
                'articles' => [
                    ['title' => 'Foo', 'content' => 'Bar', 'likes' => 0],
                    ['title' => 'Baz', 'content' => 'Qux', 'likes' => 13],
                ],
            ],
        ];

        yield 'stdClass containing a valid shape' => [
            (object) [
                'name' => 'saif',
                'articles' => new Collection\Vector([
                    ['title' => 'Foo', 'content' => 'Bar', 'likes' => 0, 'dislikes' => 5],
                    ['title' => 'Baz', 'content' => 'Qux', 'likes' => 13, 'dislikes' => 3],
                ]),
            ],
            [
                'name' => 'saif',
                'articles' => [
                    ['title' => 'Foo', 'content' => 'Bar', 'likes' => 0],
                    ['title' => 'Baz', 'content' => 'Qux', 'likes' => 13],
                ],
            ],
        ];

        yield [
            [
                'name' => 'saif',
                'articles' => new Collection\Vector([
                    ['title' => 'Foo', 'content' => 'Bar', 'likes' => 0, 'dislikes' => 5],
                    ['title' => 'Baz', 'content' => 'Qux', 'likes' => 13, 'dislikes' => 3],
                ]),
            ],
            [
                'name' => 'saif',
                'articles' => [
                    ['title' => 'Foo', 'content' => 'Bar', 'likes' => 0],
                    ['title' => 'Baz', 'content' => 'Qux', 'likes' => 13],
                ],
            ],
        ];
    }

    #[Override]
    public static function getInvalidCoercions(): iterable
    {
        yield [1.0];
        yield [1.23];
        yield [Type\bool()];
        yield [null];
        yield [false];
        yield [true];
        yield [STDIN];
        yield [['name' => 'saif', 'articles' => 5]];
        yield [['name' => 'saif', 'baz' => []]];
        yield [['name' => 'saif', 'baz' => []]];
        yield [[
            'name' => 'saif',
            'articles' => [
                ['title' => 'biz'], // missing 'content' and 'likes'
            ],
        ]];
        yield [[
            'name' => 'saif',
            'articles' => [
                ['title' => 'biz', 'content' => 'foo', 'upvotes' => 4], // 'likes' replaced by 'upvotes'
            ],
        ]];
        yield [(object) [
            'name' => 'saif',
            'articles' => [
                ['title' => 'biz', 'content' => 'foo', 'upvotes' => 4], // 'likes' replaced by 'upvotes'
            ],
        ]];
    }

    #[Override]
    public static function getToStringExamples(): iterable
    {
        yield [
            static::getType(),
            "array{'name': string, 'articles': vec<array{"
                . "'title': string, "
                . "'content': string, "
                . "'likes': int, "
                . "'comments'?: vec<array{'user': string, 'comment': string}>"
                . '}>}',
        ];
        yield [
            Type\shape([Type\int(), Type\string()]),
            'array{0: int, 1: string}',
        ];
    }

    /**
     * @param Collection\VectorInterface<mixed>|mixed $a
     * @param Collection\VectorInterface<mixed>|mixed $b
     */
    #[Override]
    protected static function equals(mixed $a, mixed $b): bool
    {
        $dict = Type\dict(Type\array_key(), Type\mixed());
        if (!$dict->matches($a) || !$dict->matches($b)) {
            return parent::equals($a, $b);
        }

        if (!Iter\contains_key($a, 'articles') || !Iter\contains_key($b, 'articles')) {
            return parent::equals($a, $b);
        }

        $vector = Type\instance_of(Collection\VectorInterface::class);
        if ($vector->matches($a['articles'])) {
            $a['articles'] = $a['articles']->toArray();
        }

        if ($vector->matches($b['articles'])) {
            $b['articles'] = $b['articles']->toArray();
        }

        return parent::equals($a, $b);
    }

    public static function provideAssertExceptionExpectations(): iterable
    {
        yield 'extra key' => [
            Type\shape(['name' => Type\string()]),
            [
                'name' => 'saif',
                'extra' => 123,
            ],
            'Expected "array{\'name\': string}", got "int" at path "extra".',
        ];
        yield 'missing key' => [
            Type\shape(['name' => Type\string()]),
            [],
            'Expected "array{\'name\': string}", got "null" at path "name".',
        ];
        yield 'invalid key' => [
            Type\shape(['name' => Type\string()]),
            ['name' => 123],
            'Expected "array{\'name\': string}", got "int" at path "name".',
        ];
        yield 'nested' => [
            Type\shape(['item' => Type\shape(['name' => Type\string()])]),
            ['item' => [
                'name' => 123,
            ]],
            'Expected "array{\'item\': array{\'name\': string}}", got "int" at path "item.name".',
        ];
    }

    public static function provideCoerceExceptionExpectations(): iterable
    {
        yield 'missing key' => [
            Type\shape(['name' => Type\string()]),
            [],
            'Could not coerce "null" to type "array{\'name\': string}" at path "name".',
        ];
        yield 'invalid key' => [
            Type\shape(['name' => Type\string()]),
            ['name' => new class() {}],
            'Could not coerce "class@anonymous" to type "array{\'name\': string}" at path "name".',
        ];
        yield 'invalid iterator first item' => [
            Type\shape(['id' => Type\int()]),
            (static function (): iterable {
                yield 'id' => Type\int()->coerce('nope');
            })(),
            'Could not coerce "string" to type "array{\'id\': int}" at path "first()".',
        ];
        yield 'invalid iterator second item' => [
            Type\shape(['id' => Type\int()]),
            (static function (): iterable {
                yield 'id' => 1;
                yield 'next' => Type\int()->coerce('nope');
            })(),
            'Could not coerce "string" to type "array{\'id\': int}" at path "id.next()".',
        ];
        yield 'iterator throwing exception' => [
            Type\shape(['id' => Type\int()]),
            (static function (): iterable {
                throw new RuntimeException('whoops');
                yield;
            })(),
            'Could not coerce "null" to type "array{\'id\': int}" at path "first()": whoops.',
        ];
        yield 'iterator yielding null key' => [
            Type\shape(['id' => Type\int()]),
            (static function (): iterable {
                yield null => 'nope';
            })(),
            'Could not coerce "null" to type "array{\'id\': int}" at path "id".',
        ];
        yield 'iterator yielding object key' => [
            Type\shape(['id' => Type\int()]),
            (static function (): iterable {
                yield new class() {} => 'nope';
            })(),
            'Could not coerce "null" to type "array{\'id\': int}" at path "id".',
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

    public function testMatchesReturnsFalseForUnknownFields(): void
    {
        $type = Type\shape(['name' => Type\string()]);

        static::assertFalse($type->matches(['name' => 'saif', 'extra' => 123]));
    }

    public function testNullishKeyAbsentDefaultsToNull(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'bio' => Type\nullish(Type\string()),
        ]);

        $result = $type->coerce(['name' => 'saif']);

        static::assertSame(['name' => 'saif', 'bio' => null], $result);
    }

    public function testNullishKeyAbsentDefaultsToNullViaAssert(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'bio' => Type\nullish(Type\string()),
        ]);

        $result = $type->assert(['name' => 'saif']);

        static::assertSame(['name' => 'saif', 'bio' => null], $result);
    }

    public function testNullishKeyPresentWithNull(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'bio' => Type\nullish(Type\string()),
        ]);

        $result = $type->coerce(['name' => 'saif', 'bio' => null]);

        static::assertSame(['name' => 'saif', 'bio' => null], $result);
    }

    public function testNullishKeyPresentWithValue(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'bio' => Type\nullish(Type\string()),
        ]);

        $result = $type->coerce(['name' => 'saif', 'bio' => 'hello']);

        static::assertSame(['name' => 'saif', 'bio' => 'hello'], $result);
    }

    public function testNullishKeyCoercesValue(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'count' => Type\nullish(Type\string()),
        ]);

        $result = $type->coerce(['name' => 'saif', 'count' => 123]);

        static::assertSame(['name' => 'saif', 'count' => '123'], $result);
    }

    public function testNullishKeyAbsentDefaultsToNullViaIterable(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'bio' => Type\nullish(Type\string()),
        ]);

        $result = $type->coerce(new ArrayIterator(['name' => 'saif']));

        static::assertSame(['name' => 'saif', 'bio' => null], $result);
    }

    public function testNullishCoercionFastPath(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'age' => Type\nullish(Type\int()),
        ], true);

        $result = $type->coerce(['name' => 'test']);
        static::assertSame('test', $result['name']);
        static::assertNull($result['age']);
    }

    public function testCoercionUsesTheSlowPathResultWhenTheFastPathFails(): void
    {
        $calls = new ArrayObject();
        // Fails the first time it is used (on the fast path), succeeds afterwards (on the slow path).
        $flaky = Type\converted(Type\mixed(), Type\int(), static function (mixed $_) use ($calls): int {
            $calls->append(true);
            if (1 === $calls->count()) {
                throw new RuntimeException('transient failure');
            }

            return 42;
        });

        $type = Type\shape([
            'a' => Type\int(),
            'b' => $flaky,
            'c' => Type\int(),
        ]);

        static::assertSame(['a' => 1, 'b' => 42, 'c' => 3], $type->coerce(['a' => 1, 'b' => 'x', 'c' => 3]));
        static::assertCount(2, $calls);
    }

    public function testNullishToString(): void
    {
        $type = Type\shape([
            'name' => Type\string(),
            'bio' => Type\nullish(Type\string()),
        ]);

        static::assertSame("array{'name': string, 'bio'?: ?string}", $type->toString());
    }
}
