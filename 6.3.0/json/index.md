# Json

The `Json` component provides JSON encoding and decoding that throws typed exceptions instead of returning `false` or requiring manual `json_last_error()` checks. It defaults to sensible flags (unescaped Unicode, unescaped slashes, preserved zero fractions) so encoded output is clean and predictable.

## Usage

### Encoding

```php
use Psl\Json;

Json\encode(['name' => 'Alice', 'score' => 9.0]);
// '{"name":"Alice","score":9.0}'

// Pretty-print for readability
Json\encode(['name' => 'Alice'], true);
// '{
//     "name": "Alice"
// }'

// Unicode and slashes are unescaped by default
Json\encode(['path' => '/home/user', 'greeting' => "\u{1F44B}"]);

// '{"path":"/home/user","greeting":"..."}' (actual wave emoji in output)
```

### Decoding

```php
use Psl\Json;

$_ = Json\decode('{"name":"Alice","age":30}');
// ['name' => 'Alice', 'age' => 30]

// Invalid JSON throws immediately
try {
    Json\decode('not json');
} catch (Json\Exception\DecodeException $e) {
    echo $e->getMessage() . "\n";
}
```

### Typed Decoding

`typed()` combines JSON decoding with PSL's `Type` system. The decoded data is validated and coerced into the expected shape in a single step -- no manual array key checks or type casting:

```php
use Psl\Json;
use Psl\Type;

// Decode and validate as a specific shape
$user = Json\typed('{"name":"Alice","age":30}', Type\shape([
    'name' => Type\string(),
    'age' => Type\int(),
]));
// $user['name'] is string, $user['age'] is int -- guaranteed

// If the JSON structure doesn't match, you get a clear exception
try {
    Json\typed('{"name":"Alice"}', Type\shape([
        'name' => Type\string(),
        'age' => Type\int(),
    ]));
} catch (Json\Exception\DecodeException $e) {
    echo $e->getMessage() . "\n";
}

// Works with any Type -- vectors, optionals, nested shapes
$ids = Json\typed('[1, 2, 3]', Type\vec(Type\int()));

// [1, 2, 3] as list<int>
```

## Why Use This Over json_encode/json_decode?

- **Exceptions by default**: No need to pass `JSON_THROW_ON_ERROR` or check `json_last_error()`
- **Clean defaults**: Unicode characters and slashes are not escaped, zero fractions are preserved
- **Type safety with `typed()`**: Validate the decoded structure against a `Type` in one call, eliminating manual `isset` / `is_array` checks
- **Consistent API**: Both encoding and decoding errors throw from the same `Json\Exception` hierarchy

See [src/Psl/Json/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/json/src/Psl/Json/) for the full API.
