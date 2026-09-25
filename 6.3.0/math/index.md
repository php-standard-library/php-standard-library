# Math

The `Math` component provides mathematical functions with strict typing and predictable error handling. Instead of PHP's loose math functions that silently return `false` or emit warnings, PSL's `Math` functions throw typed exceptions on invalid inputs and preserve numeric types through generics.

## Constants

```php
use Psl\Math;

Math\PI; // 3.141592653589793...
Math\E; // 2.718281828459045...
Math\INFINITY; // INF
Math\NAN; // NAN
Math\INT64_MAX; // 9_223_372_036_854_775_807
Math\INT32_MAX; // 2_147_483_647
```

## Usage

### Basic Arithmetic

```php
use Psl\Math;

Math\abs(-5); // 5 (preserves int|float type)
Math\ceil(4.2); // 5.0
Math\floor(4.8); // 4.0
Math\round(3.456, 2); // 3.46
Math\sqrt(16.0); // 4.0
Math\div(7, 2); // 3 (integer division, throws on division by zero)
```

### Clamping and Aggregation

```php
use Psl\Math;

Math\clamp(15, 0, 10); // 10
Math\clamp(-5, 0, 10); // 0

Math\sum([1, 2, 3, 4]); // 10 (int)
Math\sum_floats([1.5, 2.5]); // 4.0 (float)
Math\mean([2, 4, 6]); // 4.0
Math\median([1, 3, 5, 7]); // 4.0
Math\mean([]); // null (empty list)
```

### Min, Max, and Comparisons

```php
use Psl\Math;

// From a list (returns null if empty)
Math\max([3, 1, 4, 1, 5]); // 5
Math\min([3, 1, 4, 1, 5]); // 1

// From variadic arguments (requires at least two)
Math\maxva(3, 1, 4, 1, 5); // 5
Math\minva(3, 1, 4, 1, 5); // 1

// By a custom comparison function
$users = [['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]];
Math\max_by($users, fn($u) => $u['age']); // ['name' => 'Alice', 'age' => 30]
Math\min_by($users, fn($u) => $u['age']); // ['name' => 'Bob', 'age' => 25]
```

### Trigonometry

All trigonometric functions work in radians:

```php
use Psl\Math;

Math\sin(Math\PI / 2); // 1.0
Math\cos(0.0); // 1.0
Math\tan(Math\PI / 4); // ~1.0
Math\asin(1.0); // ~PI/2
Math\atan2(1.0, 1.0); // ~PI/4 (two-argument arctangent)
```

### Logarithms and Exponentiation

```php
use Psl\Math;

Math\log(Math\E); // 1.0 (natural logarithm)
Math\log(100.0, 10.0); // 2.0 (log base 10)
Math\exp(1.0); // ~E (e^1)

// Throws on invalid input instead of returning -INF or NAN
try {
    Math\log(-1.0);
} catch (Math\Exception\InvalidArgumentException $e) {
    echo $e->getMessage() . "\n";
}
```

### Base Conversion

```php
use Psl\Math;

Math\to_base(255, 16); // 'ff'
Math\from_base('ff', 16); // 255
Math\base_convert('ff', 16, 2); // '11111111'
```

See [src/Psl/Math/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/math/src/Psl/Math/) for the full API.
