# Runtime

The `Runtime` component provides introspection into the PHP runtime environment. It exposes version information, loaded extensions, the active SAPI, and build flags through simple function calls.

## Usage

### PHP Version

```php
use Psl\IO;
use Psl\Runtime;

$version = Runtime\get_version();
IO\write_line('PHP version: %s', $version);

$id = Runtime\get_version_id();
IO\write_line('Version ID: %d', $id);

$details = Runtime\get_version_details();
IO\write_line('Major: %d, Minor: %d, Release: %d', $details['major'], $details['minor'], $details['release']);
```

### Server API (SAPI)

Determine how PHP is being invoked:

```php
use Psl\IO;
use Psl\Runtime;

$sapi = Runtime\get_sapi();
IO\write_line('SAPI: %s', $sapi);
```

### Extensions

Check for loaded extensions or list them all:

```php
use Psl\IO;
use Psl\Runtime;

if (Runtime\has_extension('mbstring')) {
    IO\write_line('mbstring is available.');
}

$extensions = Runtime\get_extensions();
IO\write_line('Loaded extensions: %d', count($extensions));

$zendExtensions = Runtime\get_zend_extensions();
IO\write_line('Zend extensions: %d', count($zendExtensions));
```

### Zend Engine

```php
use Psl\IO;
use Psl\Runtime;

$zendVersion = Runtime\get_zend_version();
IO\write_line('Zend Engine version: %s', $zendVersion);
```

### Build Flags

```php
use Psl\IO;
use Psl\Runtime;

IO\write_line('Debug build: %s', Runtime\is_debug() ? 'yes' : 'no');
IO\write_line('Thread safe: %s', Runtime\is_thread_safe() ? 'yes' : 'no');
```

See [src/Psl/Runtime/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/runtime/src/Psl/Runtime/) for the full API.
