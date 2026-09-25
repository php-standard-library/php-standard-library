# Type

## Introduction

The `Type` component provides a set of functions to ensure that a given value is of a specific type **at runtime**. It implements the [Parse, Don't Validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/) pattern -- turning unstructured input into well-typed, trusted data.

## Usage

```php
use Psl\Type;

function get_untrusted_input(): mixed
{
    return '<some string>';
}

// Coerce: convert a string-like value into a non-empty-string
$trustedInput = Type\non_empty_string()->coerce(get_untrusted_input());

// Assert: verify it is already a non-empty-string (no conversion)
$trustedInput = Type\non_empty_string()->assert(get_untrusted_input());

// Match: check without throwing
$isTrustworthy = Type\non_empty_string()->matches(get_untrusted_input());
```

## Core Operations

Every type provided by this component is an instance of `Type\TypeInterface<Tv>`, which provides three operations:

- **`matches(mixed $value): bool`** -- Checks if the value already satisfies the type. No conversion, no exceptions.
- **`assert(mixed $value): Tv`** -- Asserts the value is already of the correct type. Throws `AssertException` if not. No conversion is performed.
- **`coerce(mixed $value): Tv`** -- Attempts to convert the value into the target type. Throws `CoercionException` if conversion is impossible.

The key distinction is between `assert` (strict -- value must already match) and `coerce` (flexible -- will attempt safe conversions like `"42"` to `42`).

## Static Analysis

Your static analyzer fully understands the types provided by this component, but requires a plugin:

- **Mago**: enable `psl` plugin in your `mago.toml`:

```toml
[analyzer]
plugins = ["psl"]
...
```

- **Psalm**: see [php-standard-library/psalm-plugin](https://github.com/php-standard-library/psalm-plugin)
- **PHPStan**: see [php-standard-library/phpstan-extension](https://github.com/php-standard-library/phpstan-extension)

## Building Types

### Scalar Types

The component provides types for all PHP scalar values: `string()`, `int()`, `float()`, `bool()`, `null()`, and `num()`. Each has well-defined coercion rules:

```php
use Psl\Type;

// int() coerces from int, numeric strings, and floats with .00 decimal
Type\int()->coerce('42'); // 42
Type\int()->coerce(42.0); // 42

// string() coerces from string, int, and Stringable objects
Type\string()->coerce(42); // '42'

// bool() coerces from bool, 0/1 (int), and '0'/'1' (string)
Type\bool()->coerce(1); // true
```

Sized integer types enforce range constraints: `i8()`, `i16()`, `i32()`, `i64()`, `u8()`, `u16()`, `u32()`, `uint()`, `positive_int()`. Float variants: `f32()`, `f64()`. All use the same coercion rules as their base type while guarding the value range.

### Compound Types

#### shape -- Structured Arrays

`shape()` is the most powerful type for validating complex data structures. It supports nesting, optional fields, and produces detailed error paths:

```php
use Psl\Type;

$shape = Type\shape([
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
    'pagination' => Type\optional(Type\shape([
        'currentPage' => Type\uint(),
        'totalPages' => Type\uint(),
        'perPage' => Type\uint(),
        'totalRows' => Type\uint(),
    ])),
]);

$untrustedData = [
    'name' => 'Alice',
    'articles' => [
        [
            'title' => 'Hello World',
            'content' => 'My first article.',
            'likes' => 5,
            'comments' => [
                ['user' => 'Bob', 'comment' => 'Great post!'],
            ],
        ],
    ],
    'pagination' => [
        'currentPage' => 1,
        'totalPages' => 10,
        'perPage' => 20,
        'totalRows' => 200,
    ],
];

$validData = $shape->coerce($untrustedData);
```

When validation fails, you get precise error paths:

> Expected "array{...}", got "int" **at path "articles.0.comments.0.user"**.

#### vec -- Ordered Lists

```php
use Psl\Type;

$vec = Type\vec(Type\shape([
    'user' => Type\string(),
    'comment' => Type\string(),
]));

$vec->assert([
    ['user' => 'john', 'comment' => 'hello'],
    ['user' => 'jane', 'comment' => 'world'],
]);
```

Use `non_empty_vec()` to additionally require at least one element.

#### dict -- Key-Value Dictionaries

```php
use Psl\Type;

$dict = Type\dict(Type\string(), Type\shape([
    'title' => Type\string(),
    'content' => Type\string(),
]));
```

Use `non_empty_dict()` to require at least one entry.

### Nullability and Optionality

- **`nullable(TypeInterface $inner)`** -- Allows `null` alongside the inner type
- **`optional(TypeInterface $inner)`** -- Marks a field as optional within a `shape()` (the key may be absent entirely)
- **`nullish(TypeInterface $inner)`** -- Combines both: the key may be absent, but defaults to `null` instead of being omitted
- **`nonnull()`** -- Accepts any value except `null`

```php
use Psl\Type;

$shape = Type\shape([
    'name' => Type\string(),
    'nickname' => Type\nullable(Type\string()), // present but may be null
    'bio' => Type\optional(Type\string()), // key may be missing entirely
    'avatar' => Type\nullish(Type\string()), // key may be missing, defaults to null
]);
```

### Union and Intersection

```php
use Psl\Type;

interface Loggable
{
    public function log(): string;
}

interface Exportable
{
    public function export(): string;
}

// Value must satisfy either type
$stringOrInt = Type\union(Type\string(), Type\int());

// Value must satisfy both types
$loggableAndExportable = Type\intersection(Type\instance_of(Loggable::class), Type\instance_of(Exportable::class));
```

Coercion tries each type in order. For unions, the first successful coercion wins. Intersections coerce through one type then assert against the other.

### Object and Enum Types

```php
use Psl\Type;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum Color
{
    case Red;
    case Green;
    case Blue;
}

interface Renderable
{
    public function render(): string;
}

class HtmlRenderer implements Renderable
{
    public function render(): string
    {
        return '<p>Hello</p>';
    }
}

// Validate class instances
$value = new DateTimeImmutable();
Type\instance_of(DateTimeImmutable::class)->assert($value);

// Backed enums -- coerce from the backing value
Type\backed_enum(Status::class)->coerce('active');

// Unit enums -- only accept enum instances directly
Type\unit_enum(Color::class)->assert(Color::Red);

// Class strings
Type\class_string(Renderable::class)->assert(HtmlRenderer::class);
```

### Literal Values

```php
use Psl\Type;

Type\literal_scalar('hello')->assert('hello');
Type\literal_scalar(42)->assert(42);
```

### Collection Types

The component also provides types for PSL collection objects: `vector()`, `mutable_vector()`, `map()`, `mutable_map()`, `set()`, `mutable_set()`.

## Type Conversion with `converted()`

The `converted()` type enables custom conversion pipelines. It takes a source type, a target type, and a converter function. This is especially powerful for building reusable type-safe parsers for value objects:

```php
use Psl\Type;
use Psl\Type\TypeInterface;

$dateTimeType = Type\converted(
    Type\string(),
    Type\instance_of(DateTimeImmutable::class),
    static function (string $value): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date) {
            throw new RuntimeException('Invalid date format');
        }

        return $date;
    },
);

$date = $dateTimeType->coerce('2024-01-15 10:30:00');
// DateTimeImmutable object

$dateTimeType->assert($date);
// Works -- assert checks the output type (DateTimeImmutable), not the input

// Building reusable type-safe parsers for value objects:

final class Person
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
    ) {}

    /** @return TypeInterface<self> */
    public static function type(): TypeInterface
    {
        return Type\converted(
            Type\shape([
                'firstName' => Type\string(),
                'lastName' => Type\string(),
            ]),
            Type\instance_of(Person::class),
            static fn(array $data): Person => new Person($data['firstName'], $data['lastName']),
        );
    }
}

// Now composable with other types:
$shape = Type\shape([
    'person' => Person::type(),
    'role' => Type\string(),
]);
```

When conversion fails, error messages indicate which stage failed:

> Could not coerce "int" to type "string" **at path "coerce_input(int): string"**

> Could not coerce "string" to type "class-string<stdClass>" **at path "coerce_output(string): class-string<stdClass>"**

## JSON Decoding with `json_decoded()`

The `json_decoded()` type transparently handles fields that may contain JSON-encoded strings. If the value already matches the inner type, it passes through; if it's a string, it's JSON-decoded and coerced through the inner type. This is especially useful in shapes where database columns store JSON:

```php
use Psl\Type;

$shape = Type\shape([
    'name' => Type\string(),
    'metadata' => Type\json_decoded(Type\shape([
        'role' => Type\string(),
        'active' => Type\bool(),
    ])),
]);

// Works with JSON strings (e.g. from a database column)
$fromDb = $shape->coerce([
    'name' => 'Alice',
    'metadata' => '{"role": "admin", "active": true}',
]);

// Also works with already-decoded arrays
$fromArray = $shape->coerce([
    'name' => 'Alice',
    'metadata' => ['role' => 'admin', 'active' => true],
]);
```

## Strict Mode with `always_assert()`

By default, `coerce()` attempts type conversion. Use `always_assert()` to create a type that rejects any value not already matching, even during coercion:

```php
use Psl\Type;
use Psl\Type\Exception\CoercionException;

$integer = Type\int();
$strictInteger = Type\always_assert(Type\int());

$integer->coerce('1'); // 1 (coerced from string)

try {
    $strictInteger->coerce('1'); // CoercionException!
} catch (CoercionException $e) {
    echo $e->getMessage() . "\n";
}
```

## Error Paths

One of the most valuable features of the Type component is detailed error reporting. When validation fails on nested structures, the error message includes the exact path to the failing value:

```php
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Type\Exception\CoercionException;

$type = Type\dict(Type\string(), Type\shape([
    'title' => Type\string(),
    'content' => Type\string(),
]));

// If key 123 (int) is used instead of a string key:
try {
    $type->assert([
        123 => ['title' => 'Hello', 'content' => 'World'],
    ]);
} catch (AssertException $e) {
    echo $e->getMessage() . "\n";
}

// If 'title' is an array instead of string:
try {
    $type->coerce([
        'foo' => ['title' => ['nested'], 'content' => 'World'],
    ]);
} catch (CoercionException $e) {
    echo $e->getMessage() . "\n";
}
```

This makes debugging validation failures in deeply nested data structures straightforward.

See [src/Psl/Type/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/type/src/Psl/Type/) for the full API.
