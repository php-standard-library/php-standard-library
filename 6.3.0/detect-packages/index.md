# Detect Packages

The `detect-packages` command scans PHP source directories for PSL namespace usage and reports which `php-standard-library/*` standalone packages are needed. This helps migrate from the monorepo to individual packages.

This tool is only available when using the monorepo (`php-standard-library/php-standard-library`).

## Usage

```bash
vendor/bin/psl detect-packages <path>... [--dev <path>...] [--composer]
```

### Options

| Option | Description |
|--------|-------------|
| `--src` | Mark following paths as `require` dependencies (default) |
| `--dev` | Mark following paths as `require-dev` dependencies |
| `--composer` | Output a JSON snippet for `composer.json` |

## Examples

### Basic scan

Scan a single source directory:

```bash
vendor/bin/psl detect-packages src/
```

```
Detected PSL packages:

  require:
    php-standard-library/dict
    php-standard-library/foundation
    php-standard-library/str
    php-standard-library/vec
```

### Separating require and require-dev

Scan source and test directories separately. Packages found in both are placed in `require` only:

```bash
vendor/bin/psl detect-packages src/ --dev tests/
```

```
Detected PSL packages:

  require:
    php-standard-library/dict
    php-standard-library/foundation
    php-standard-library/str
    php-standard-library/vec

  require-dev:
    php-standard-library/filesystem
    php-standard-library/os
```

### JSON output for composer.json

Use `--composer` to get output you can paste directly into your `composer.json`:

```bash
vendor/bin/psl detect-packages src/ --dev tests/ --composer
```

```json
{
    "require": {
        "php-standard-library/dict": "^6.1",
        "php-standard-library/str": "^6.1",
        "php-standard-library/vec": "^6.1"
    },
    "require-dev": {
        "php-standard-library/filesystem": "^6.1",
        "php-standard-library/os": "^6.1"
    }
}
```

### Multiple source paths

You can pass multiple directories. Bare paths default to `require`:

```bash
vendor/bin/psl detect-packages src/ lib/ --dev tests/
```

## How it works

The tool scans all `.php` files in the given directories and matches `Psl\<Namespace>` references using regex. Each namespace is mapped to its corresponding standalone package. The `foundation` package is always included when any PSL usage is detected, since all PSL packages depend on it.

Transitive dependencies are not resolved — only direct usage in your code is reported. Composer handles transitive dependencies automatically when you install the standalone packages.
