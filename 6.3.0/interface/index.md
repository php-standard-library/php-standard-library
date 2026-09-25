# Interface

The `Interface` component provides type-safe wrappers around PHP's built-in interface reflection and existence checks. It replaces loose `interface_exists()` calls with a consistent, intention-revealing API.

## Usage

PSL separates existence checks into two distinct functions -- `exists()` (triggers autoloading) and `defined()` (checks only already-loaded definitions) -- so the intent is always clear.

```php
use Psl\Interface;
use Psl\IO;
use Psl\Trait;

IO\write_line('JsonSerializable exists: %s', Interface\exists(JsonSerializable::class) ? 'yes' : 'no');
IO\write_line('JsonSerializable defined: %s', Interface\defined(JsonSerializable::class) ? 'yes' : 'no');

// Check a non-existent trait
IO\write_line('NonExistentTrait exists: %s', Trait\exists('NonExistentTrait') ? 'yes' : 'no');
```

See [src/Psl/Interface/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/interface/src/Psl/Interface/) for the full API.
