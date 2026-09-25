# OS

The `OS` component provides type-safe operating system detection. Instead of comparing raw strings against `PHP_OS_FAMILY`, you work with the `OperatingSystemFamily` enum and convenience functions.

## Usage

### Detecting the OS Family

```php
use Psl\IO;
use Psl\OS;

$family = OS\family();
IO\write_line('OS Family: %s', $family->name);
```

### Convenience Checks

Quick boolean checks for the most common platforms:

```php
use Psl\IO;
use Psl\OS;

if (OS\is_windows()) {
    IO\write_line('Running on Windows.');
}

if (OS\is_darwin()) {
    IO\write_line('Running on macOS.');
}

if (!OS\is_windows() && !OS\is_darwin()) {
    IO\write_line('Running on Linux or another platform.');
}
```

### The OperatingSystemFamily Enum

The enum covers six families: `Windows`, `BSD`, `Darwin`, `Solaris`, `Linux`, and `Unknown`. You can match against it for exhaustive platform handling:

```php
use Psl\IO;
use Psl\OS\OperatingSystemFamily;

$result = match (OperatingSystemFamily::default()) {
    OperatingSystemFamily::Windows => 'Configured for Windows',
    OperatingSystemFamily::Darwin => 'Configured for macOS',
    OperatingSystemFamily::Linux => 'Configured for Linux',
    OperatingSystemFamily::BSD => 'Configured for BSD',
    default => 'Using default configuration',
};

IO\write_line('%s', $result);
```

`OperatingSystemFamily::default()` returns the family for the current runtime, making it useful as a dependency injection default.

See [src/Psl/OS/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/os/src/Psl/OS/) for the full API.
