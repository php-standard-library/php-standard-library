# Type

## Introduction

The `Type` component provides a set of functions to ensure that a given value is of a specific type **at runtime**. It implements the [Parse, Don't Validate](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/) pattern -- turning unstructured input into well-typed, trusted data.

## Usage

@example('types/type-usage.php')

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

@example('types/type-scalars.php')

Sized integer types enforce range constraints: `i8()`, `i16()`, `i32()`, `i64()`, `u8()`, `u16()`, `u32()`, `uint()`, `positive_int()`. Float variants: `f32()`, `f64()`. All use the same coercion rules as their base type while guarding the value range.

### Compound Types

#### shape -- Structured Arrays

`shape()` is the most powerful type for validating complex data structures. It supports nesting, optional fields, and produces detailed error paths:

@example('types/type-shape.php')

When validation fails, you get precise error paths:

> Expected "array{...}", got "int" **at path "articles.0.comments.0.user"**.

#### vec -- Ordered Lists

@example('types/type-vec.php')

Use `non_empty_vec()` to additionally require at least one element.

#### dict -- Key-Value Dictionaries

@example('types/type-dict.php')

Use `non_empty_dict()` to require at least one entry.

#### iterable -- Arrays and Traversables

`iterable()` accepts arrays and `Traversable` values whose keys and values match the given types. `assert()` and `coerce()` iterate the value and return a validated, re-iterable copy of it. Since a `Generator` cannot be rewound, `matches()` never iterates one: it returns `false` for a `Generator` unless both the key and value types are `mixed()`.

### Nullability and Optionality

- **`nullable(TypeInterface $inner)`** -- Allows `null` alongside the inner type
- **`optional(TypeInterface $inner)`** -- Marks a field as optional within a `shape()` (the key may be absent entirely)
- **`nullish(TypeInterface $inner)`** -- Combines both: the key may be absent, but defaults to `null` instead of being omitted
- **`nonnull()`** -- Accepts any value except `null`

@example('types/type-nullability.php')

### Union and Intersection

@example('types/type-union-intersection.php')

Coercion tries each type in order. For unions, the first successful coercion wins. Intersections coerce through one type then assert against the other.

### Object and Enum Types

@example('types/type-object-enum.php')

### Literal Values

@example('types/type-literal.php')

### Collection Types

The component also provides types for PSL collection objects: `vector()`, `mutable_vector()`, `map()`, `mutable_map()`, `set()`, `mutable_set()`.

## Type Conversion with `converted()`

The `converted()` type enables custom conversion pipelines. It takes a source type, a target type, and a converter function. This is especially powerful for building reusable type-safe parsers for value objects:

@example('types/type-converted.php')

When conversion fails, error messages indicate which stage failed:

> Could not coerce "int" to type "string" **at path "coerce_input(int): string"**

> Could not coerce "string" to type "class-string<stdClass>" **at path "coerce_output(string): class-string<stdClass>"**

## JSON Decoding with `json_decoded()`

The `json_decoded()` type transparently handles fields that may contain JSON-encoded strings. If the value already matches the inner type, it passes through; if it's a string, it's JSON-decoded and coerced through the inner type. This is especially useful in shapes where database columns store JSON:

@example('types/type-json-decoded.php')

## Strict Mode with `always_assert()`

By default, `coerce()` attempts type conversion. Use `always_assert()` to create a type that rejects any value not already matching, even during coercion:

@example('types/type-always-assert.php')

## Error Paths

One of the most valuable features of the Type component is detailed error reporting. When validation fails on nested structures, the error message includes the exact path to the failing value:

@example('types/type-error-paths.php')

This makes debugging validation failures in deeply nested data structures straightforward.

See `src/Psl/Type/` for the full API.
