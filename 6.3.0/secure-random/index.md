# SecureRandom

The `SecureRandom` component provides cryptographically secure random data generation. It wraps PHP's `random_bytes()` and `random_int()` with a consistent API and typed exceptions, making it suitable for security-sensitive tasks like token generation, password creation, and nonce generation.

## Usage

### Random Integers

Generate a secure random integer within a range:

```php
use Psl\IO;
use Psl\SecureRandom;

$id = SecureRandom\int(1, 1_000_000);
IO\write_line('Random ID: %d', $id);

// Full 64-bit range by default
$big = SecureRandom\int();
IO\write_line('Random big int: %d', $big);
```

### Random Floats

Generate a secure random float between 0.0 and 1.0:

```php
use Psl\IO;
use Psl\SecureRandom;

$probability = SecureRandom\float();
IO\write_line('Random float: %f', $probability);
```

### Random Bytes

Generate raw random bytes, useful for cryptographic keys or binary tokens:

```php
use Psl\IO;
use Psl\SecureRandom;

$key = SecureRandom\bytes(32); // 32 random bytes for an AES-256 key
IO\write_line('Key length: %d bytes', strlen($key));
IO\write_line('Key (hex): %s', bin2hex($key));

$iv = SecureRandom\bytes(16); // 16 random bytes for an IV
IO\write_line('IV (hex): %s', bin2hex($iv));
```

### Random Strings

Generate a random string from an alphabet. Defaults to alphanumeric characters:

```php
use Psl\IO;
use Psl\SecureRandom;

// 32-character alphanumeric token
$token = SecureRandom\string(32);
IO\write_line('Token: %s', $token);

// Hex string
$hex = SecureRandom\string(16, '0123456789abcdef');
IO\write_line('Hex: %s', $hex);

// Numeric code (e.g. for SMS verification)
$code = SecureRandom\string(6, '0123456789');
IO\write_line('Code: %s', $code);
```

## Error Handling

All functions throw `SecureRandom\Exception\InsufficientEntropyException` if the system cannot gather enough entropy, and `SecureRandom\Exception\InvalidArgumentException` for invalid arguments (such as `$min > $max` for `int()`).

See [src/Psl/SecureRandom/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/secure-random/src/Psl/SecureRandom/) for the full API.
