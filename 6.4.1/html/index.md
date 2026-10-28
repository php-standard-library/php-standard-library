# Html

The `Html` component provides functions for encoding, decoding, and stripping HTML entities. It wraps PHP's `htmlentities`, `htmlspecialchars`, and related functions with a cleaner API and an `Encoding` enum for character set selection.

## Usage

### Encoding

`encode()` converts all applicable characters to HTML entities, while `encode_special_characters()` only converts the special characters (`&`, `"`, `'`, `<`, `>`):

```php
use Psl\Html;

Html\encode('<p>"Hello" & welcome</p>');
// '&lt;p&gt;&quot;Hello&quot; &amp; welcome&lt;/p&gt;'

// encode_special_characters is lighter -- only converts &, ", ', <, >
Html\encode_special_characters('<script>alert("xss")</script>');

// '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
```

Use `encode_special_characters()` when building HTML output from user input -- it is the safer default for preventing XSS while keeping non-special characters readable.

### Avoiding Double Encoding

Both functions accept a `$double_encoding` parameter. Set it to `false` to preserve existing entities:

```php
use Psl\Html;

Html\encode('&amp; is an ampersand', doubleEncoding: false);

// '&amp; is an ampersand' (not double-encoded to '&amp;amp;')
```

### Decoding

```php
use Psl\Html;

Html\decode('&lt;p&gt;Hello&lt;/p&gt;');
// '<p>Hello</p>'

Html\decode_special_characters('&lt;p&gt;Hello&lt;/p&gt;');

// '<p>Hello</p>'
```

### Stripping Tags

Remove HTML and PHP tags from a string, optionally keeping specific tags:

```php
use Psl\Html;

Html\strip_tags('<p>Hello <b>World</b></p>');
// 'Hello World'

Html\strip_tags('<p>Hello <b>World</b></p>', ['b']);

// 'Hello <b>World</b>'
```

### Character Encoding

All encoding/decoding functions accept an `Encoding` enum to specify the character set. The default is UTF-8:

```php
use Psl\Html;

$html = '<p>"Hello" & welcome</p>';

Html\encode($html, encoding: Html\Encoding::Iso88591);
Html\decode('&lt;p&gt;Hello&lt;/p&gt;', Html\Encoding::ShiftJis);
```

The `Encoding` enum supports UTF-8, ISO-8859-1, ISO-8859-15, Windows-1251, Windows-1252, Big5, GB2312, Shift_JIS, EUC-JP, KOI8-R, and several other character sets.

Both `encode()` and `encode_special_characters()` replace byte sequences that are invalid in the selected encoding with the Unicode replacement character instead of returning an empty string: U+FFFD itself for UTF-8, and the `&#xFFFD;` character reference for the other encodings. `encode()` and `decode()` use the HTML 4.01 entity table, while `encode_special_characters()` and `decode_special_characters()` use the HTML5 one (so a single quote is written as `&apos;` rather than `&#039;`).

See [src/Psl/Html/](https://github.com/php-standard-library/php-standard-library/tree/6.4.1/packages/html/src/Psl/Html/) for the full API.
