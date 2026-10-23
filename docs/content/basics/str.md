# Str

The `Str` component provides Unicode-aware string functions that replace PHP's `mb_*` and standard string functions with a consistent, safe, and predictable API.

All functions work with UTF-8 by default and handle multibyte characters correctly. No more juggling `strlen` vs `mb_strlen` or forgetting encoding parameters -- `Str` makes the right behavior the default.

## The Three-Tier Model

PSL organizes string operations into three namespaces based on what a "character" means:

- `Str` (default): Unicode codepoints via `mb_*`. Use this for most work. A character is one Unicode codepoint.
- `Str\Byte`: Raw bytes via standard PHP functions. Faster when you know content is ASCII-only. A character is one byte.
- `Str\Grapheme`: Grapheme clusters via `grapheme_*`. Handles combined characters like accented letters and emoji. A character is one visual unit.

The difference matters for non-ASCII text:

@example('basics/str-three-tiers.php')

**Rule of thumb**: Use `Str\` by default. Use `Str\Byte\` for ASCII-only performance. Use `Str\Grapheme\` when you need to count or split by what users see on screen.

## Usage

### Searching and Matching

@example('basics/str-searching.php')

### Extracting Substrings

@example('basics/str-extracting.php')

### Transforming

@example('basics/str-transforming.php')

### Splitting and Joining

@example('basics/str-splitting.php')

### Trimming and Padding

@example('basics/str-trimming.php')

### Measuring, Formatting, and More

@example('basics/str-measuring.php')

## Byte-Level Operations

When working with ASCII-only content, `Str\Byte\` functions are faster since they skip multibyte handling:

@example('basics/str-byte.php')

## Grapheme-Level Operations

When you need to handle combined characters correctly (emoji sequences, accented characters), use `Str\Grapheme\`:

@example('basics/str-grapheme.php')

## Deprecations

`Str\metaphone()` is deprecated because the underlying PHP `metaphone()` function is deprecated as of PHP 8.6. Use a userland phonetic matching library instead. The wrapper remains available and continues to call PHP's native function, so it does not suppress PHP 8.6 deprecation notices.

See `src/Psl/Str/` for the full API.
