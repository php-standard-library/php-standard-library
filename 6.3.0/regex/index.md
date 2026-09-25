# Regex

The `Regex` component provides type-safe regular expression functions. PHP's `preg_*` functions return `false` on invalid patterns and use `null` for unmatched groups, making it easy to silently propagate errors. PSL's `Regex` functions throw `InvalidPatternException` for bad patterns and support typed capture groups so you know exactly what shape your matches will have.

## Usage

### Checking for Matches

```php
use Psl\Regex;

Regex\matches('hello@example.com', '/^[^@]+@[^@]+$/'); // true
Regex\matches('not-an-email', '/^[^@]+@[^@]+$/'); // false
Regex\matches('foo bar foo', '/foo/', 4); // true (from offset)
```

### Extracting Matches

`first_match()` returns the first match as an array, or `null` if nothing matched:

```php
use Psl\Regex;

$match = Regex\first_match('Order #12345', '/Order #(\d+)/');

// ['Order #12345', '12345']
```

### Typed Capture Groups

Pass a `Type` shape to get a well-typed result that validates the match structure at runtime:

```php
use Psl\Regex;
use Psl\Type;

$pattern = '/(?P<year>\d{4})-(?P<month>\d{2})-(?P<day>\d{2})/';

$match = Regex\first_match('Date: 2025-03-15', $pattern, Type\shape([
    0 => Type\string(),
    'year' => Type\string(),
    'month' => Type\string(),
    'day' => Type\string(),
]));

// $match['year'] === '2025', $match['month'] === '03', $match['day'] === '15'
```

The `capture_groups()` helper builds a shape type from a list of expected group keys:

```php
use Psl\Regex;

$match = Regex\first_match('Hello World', '/(Hello) (World)/', Regex\capture_groups([1, 2]));

// $match[0] === 'Hello World', $match[1] === 'Hello', $match[2] === 'World'
```

### Finding All Matches

`every_match()` returns all matches as a list, or `null` if none matched:

```php
use Psl\Regex;

$matches = Regex\every_match('prices: $10, $20, $30', '/\$(\d+)/');

// [['$10', '10'], ['$20', '20'], ['$30', '30']]
```

### Search and Replace

```php
use Psl\Regex;
use Psl\Str;

Regex\replace('hello world', '/world/', 'PHP');
// 'hello PHP'

// Dynamic replacement with a callback
Regex\replace_with('hello world', '/\b(\w)/', fn($m) => Str\uppercase($m[1]));
// 'Hello World'

// Replace multiple patterns at once
Regex\replace_every('foo bar baz', [
    '/foo/' => 'one',
    '/bar/' => 'two',
    '/baz/' => 'three',
]);

// 'one two three'
```

### Splitting

```php
use Psl\Regex;

Regex\split('one, two,  three', '/,\s*/');
// ['one', 'two', 'three']

Regex\split('a-b-c-d', '/-/', 2);

// ['a', 'b-c-d']
```

## Error Handling

All functions throw instead of returning error sentinels. An invalid pattern like `/unclosed(group` throws `Regex\Exception\InvalidPatternException` immediately rather than returning `false`.

See [src/Psl/Regex/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/regex/src/Psl/Regex/) for the full API.
