# Str

The `Str` component provides Unicode-aware string functions that replace PHP's `mb_*` and standard string functions with a consistent, safe, and predictable API.

All functions work with UTF-8 by default and handle multibyte characters correctly. No more juggling `strlen` vs `mb_strlen` or forgetting encoding parameters -- `Str` makes the right behavior the default.

## The Three-Tier Model

PSL organizes string operations into three namespaces based on what a "character" means:

- `Str` (default): Unicode codepoints via `mb_*`. Use this for most work. A character is one Unicode codepoint.
- `Str\Byte`: Raw bytes via standard PHP functions. Faster when you know content is ASCII-only. A character is one byte.
- `Str\Grapheme`: Grapheme clusters via `grapheme_*`. Handles combined characters like accented letters and emoji. A character is one visual unit.

The difference matters for non-ASCII text:

```php
use Psl\Str;

$emoji = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // family emoji

Str\Byte\length($emoji); // 18 (raw bytes)
Str\length($emoji); // 5  (Unicode codepoints)
Str\Grapheme\length($emoji); // 1  (one visual character)
```

**Rule of thumb**: Use `Str\` by default. Use `Str\Byte\` for ASCII-only performance. Use `Str\Grapheme\` when you need to count or split by what users see on screen.

## Usage

### Searching and Matching

```php
use Psl\Str;

Str\contains('Hello, World', 'World'); // true
Str\contains('Hello, World', 'world'); // false (case-sensitive)
Str\contains_ci('Hello, World', 'world'); // true  (case-insensitive)

Str\starts_with('Hello, World', 'Hello'); // true
Str\ends_with('Hello, World', 'World'); // true

// Find position of a substring (returns null if not found)
Str\search('hello', 'l'); // 2
Str\search_last('hello', 'l'); // 3
```

### Extracting Substrings

```php
use Psl\Str;

Str\slice('Hello, World', 7); // 'World'
Str\slice('Hello, World', 0, 5); // 'Hello'

Str\before('user@example.com', '@'); // 'user'
Str\after('user@example.com', '@'); // 'example.com'

Str\chunk('Hello', 2); // ['He', 'll', 'o']
```

### Transforming

```php
use Psl\Str;

Str\uppercase('hello'); // 'HELLO'
Str\lowercase('HELLO'); // 'hello'
Str\capitalize('hello world'); // 'Hello world'
Str\capitalize_words('hello world'); // 'Hello World'

Str\replace('hello world', 'world', 'PHP'); // 'hello PHP'
Str\replace_every('aabbcc', ['aa' => '1', 'cc' => '3']); // '1bb3'

Str\reverse('Hello'); // 'olleH'
Str\repeat('ab', 3); // 'ababab'
```

### Splitting and Joining

```php
use Psl\Str;

Str\split('one,two,three', ','); // ['one', 'two', 'three']
Str\split('one,two,three', ',', 2); // ['one', 'two,three']

Str\join(['one', 'two', 'three'], ', '); // 'one, two, three'
```

### Trimming and Padding

```php
use Psl\Str;

Str\trim('  hello  '); // 'hello'
Str\trim_left('  hello  '); // 'hello  '
Str\trim_right('  hello  '); // '  hello'

Str\pad_left('42', 5, '0'); // '00042'
Str\pad_right('hi', 10, '.'); // 'hi........'
```

### Measuring, Formatting, and More

```php
use Psl\Str;

Str\length('Hello'); // 5
Str\length('مرحبا'); // 5 (Arabic characters, 5 codepoints)
Str\is_empty(''); // true
Str\width('你好'); // 4 (CJK characters count as 2)

Str\format('Hello, %s! You have %d messages.', 'Alice', 5);
// 'Hello, Alice! You have 5 messages.'

Str\concat('Hello', ', ', 'World'); // 'Hello, World'
Str\truncate('Hello, World', 0, 8, '...'); // 'Hello...'

Str\strip_prefix('HelloWorld', 'Hello'); // 'World'
Str\strip_suffix('HelloWorld', 'World'); // 'Hello'
```

## Byte-Level Operations

When working with ASCII-only content, `Str\Byte\` functions are faster since they skip multibyte handling:

```php
use Psl\Str;

// All the same functions, operating on raw bytes
Str\Byte\length('Hello'); // 5
Str\Byte\contains('Hello', 'ell'); // true
Str\Byte\uppercase('hello'); // 'HELLO'
Str\Byte\slice('Hello', 1, 3); // 'ell'
Str\Byte\reverse('Hello'); // 'olleH'
Str\Byte\rot13('Hello'); // 'Uryyb'
```

## Grapheme-Level Operations

When you need to handle combined characters correctly (emoji sequences, accented characters), use `Str\Grapheme\`:

```php
use Psl\Str;

$text = "cafe\u{0301}"; // 'cafe' + combining accent = 'caf' + visual 'é'

Str\length($text); // 5 (codepoints)
Str\Grapheme\length($text); // 4 (visual characters)

Str\Grapheme\slice($text, 0, 3); // 'caf' (3 visual characters)
```

See [src/Psl/Str/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/str/src/Psl/Str/) for the full API.
