# Class

The `Class` component provides type-safe wrappers around PHP's built-in class reflection and existence checks. It replaces loose `class_exists()` calls with a consistent, intention-revealing API and adds helpers for inspecting class properties.

## Why Use This?

PHP's native `class_exists()` accepts a second argument to control autoloading, which is easy to forget or misuse. PSL separates that choice into two distinct functions -- `exists()` (triggers autoloading) and `defined()` (checks only already-loaded definitions) -- so the intent is always clear.

## Usage

### Checking Existence

```php
use Psl\Class;
use Psl\IO;

// Triggers autoloading if needed
IO\write_line('stdClass exists: %s', Class\exists(stdClass::class) ? 'yes' : 'no');

// Only checks already-loaded classes (no autoloading)
IO\write_line('stdClass defined: %s', Class\defined(stdClass::class) ? 'yes' : 'no');
```

### Inspecting Classes

```php
use Psl\Class;
use Psl\IO;

IO\write_line('stdClass is final: %s', Class\is_final(stdClass::class) ? 'yes' : 'no');
IO\write_line('stdClass is abstract: %s', Class\is_abstract(stdClass::class) ? 'yes' : 'no');
IO\write_line('stdClass is readonly: %s', Class\is_readonly(stdClass::class) ? 'yes' : 'no');
```

### Practical Example

Guard a factory method against invalid input:

```php
use Psl\Class;
use Psl\IO;

function create(string $class): object
{
    if (!Class\exists($class)) {
        throw new InvalidArgumentException($class . ' does not exist.');
    }

    if (Class\is_abstract($class)) {
        throw new InvalidArgumentException($class . ' is abstract and cannot be instantiated.');
    }

    // @mago-expect analysis:unknown-class-instantiation
    return new $class();
}

$obj = create(stdClass::class);
IO\write_line('Created: %s', get_class($obj));
```

See [src/Psl/Class/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/class/src/Psl/Class/) for the full API.
