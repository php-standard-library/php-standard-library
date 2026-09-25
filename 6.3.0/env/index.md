# Env

The `Env` component provides functions for inspecting and modifying the process environment. It covers environment variables, the current working directory, temporary paths, command-line arguments, and `PATH` manipulation.

## Usage

### Environment Variables

Read, write, and remove environment variables for the current process:

```php
use Psl\Env;
use Psl\IO;

// Read -- returns null if not set
$home = Env\get_var('HOME');
IO\write_line('HOME: %s', $home ?? '(not set)');

// Write
Env\set_var('APP_ENV', 'production');
IO\write_line('APP_ENV: %s', Env\get_var('APP_ENV') ?? '(not set)');

// Remove
Env\remove_var('APP_ENV');
IO\write_line('APP_ENV after remove: %s', Env\get_var('APP_ENV') ?? '(not set)');

// Get all environment variables as an associative array
$allVars = Env\get_vars();
IO\write_line('Total env vars: %d', count($allVars));
```

Keys containing `=` or the NUL character are rejected with an `InvariantViolationException`.

### Working Directory

```php
use Psl\Env;
use Psl\IO;

$cwd = Env\current_dir();
IO\write_line('Current directory: %s', $cwd);
```

### System Paths

```php
use Psl\Env;
use Psl\IO;

$tmp = Env\temp_dir();
IO\write_line('Temp directory: %s', $tmp);
```

### Command-Line Arguments and Executable Path

```php
use Psl\Env;
use Psl\IO;

$args = Env\args();
IO\write_line('Arguments: %s', implode(', ', $args));

$binary = Env\current_exec();
IO\write_line('Executable: %s', $binary);
```

### PATH Manipulation

Split and join PATH-style strings using the platform's path separator (`:` on Unix, `;` on Windows):

```php
use Psl\Env;
use Psl\IO;

$dirs = Env\split_paths('/usr/local/bin:/usr/bin:/bin');
IO\write_line('Split paths: %s', implode(', ', $dirs));

$path = Env\join_paths('/usr/local/bin', '/usr/bin', '/bin');
IO\write_line('Joined path: %s', $path);
```

See [src/Psl/Env/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/env/src/Psl/Env/) for the full API.
