# Password

The `Password` component provides secure password hashing and verification. It wraps PHP's `password_*` functions with a type-safe `Algorithm` enum and a clean functional API, making it easy to hash passwords with bcrypt or Argon2 and handle algorithm upgrades over time.

## Hashing a Password

```php
use Psl\IO;
use Psl\Password;

$plaintext = 'my-secret-password';

// Uses the default algorithm (currently bcrypt, may change in future PHP versions)
$hash = Password\hash($plaintext);
IO\write_line('Default hash: %s', $hash);

// Specify an algorithm explicitly
$bcryptHash = Password\hash($plaintext, Password\Algorithm::Bcrypt);
IO\write_line('Bcrypt hash: %s', $bcryptHash);
```

You can tune algorithm parameters through the `$options` array:

```php
use Psl\IO;
use Psl\Password;

$plaintext = 'my-secret-password';

// Bcrypt with a higher cost factor (default is 10)
$hash = Password\hash($plaintext, Password\Algorithm::Bcrypt, ['cost' => 14]);
IO\write_line('Bcrypt (cost 14): %s', $hash);
```

## Verifying a Password

`verify()` checks a plaintext password against a stored hash. The algorithm and salt are embedded in the hash itself, so you do not need to store them separately:

```php
use Psl\IO;
use Psl\Password;

$plaintext = 'my-secret-password';
$storedHash = Password\hash($plaintext, Password\Algorithm::Bcrypt);

if (Password\verify($plaintext, $storedHash)) {
    IO\write_line('Password is correct.');
} else {
    IO\write_line('Password is incorrect.');
}
```

## Checking If a Rehash Is Needed

When you upgrade your hashing algorithm or increase the cost factor, existing hashes remain valid but are no longer up to your current standard. `needs_rehash()` detects this so you can transparently upgrade hashes at login time:

```php
use Psl\IO;
use Psl\Password;

$plaintext = 'my-secret-password';
$storedHash = Password\hash($plaintext, Password\Algorithm::Bcrypt);

if (Password\verify($plaintext, $storedHash)) {
    IO\write_line('Password is correct.');

    // Check if the hash needs upgrading
    if (Password\needs_rehash($storedHash, Password\Algorithm::Bcrypt, ['cost' => 14])) {
        $newHash = Password\hash($plaintext, Password\Algorithm::Bcrypt, ['cost' => 14]);
        IO\write_line('Hash upgraded: %s', $newHash);
        // store $newHash in the database
    } else {
        IO\write_line('Hash is up to date.');
    }
}
```

This pattern lets you migrate from bcrypt to Argon2id (or increase cost parameters) gradually, without forcing all users to reset their passwords.

## Inspecting a Hash

`get_information()` returns the algorithm and options used to create a hash:

```php
use Psl\IO;
use Psl\Password;

$hash = Password\hash('my-secret-password', Password\Algorithm::Bcrypt);

$info = Password\get_information($hash);
IO\write_line('Algorithm: %s', $info['algorithm']->name);
IO\write_line('Cost: %d', $info['options']['cost'] ?? '<unknown>');
```

## Algorithm

The `Password\Algorithm` enum provides four cases:

- `Default` -- follows PHP's default, which may change over time to adopt stronger algorithms
- `Bcrypt` -- Blowfish-based, always produces 60-character hashes
- `Argon2i` -- memory-hard algorithm resistant to GPU attacks
- `Argon2id` -- hybrid of Argon2i and Argon2d, recommended for most applications

When using `Algorithm::Default`, store hashes in a column that can hold at least 255 characters, since the underlying algorithm (and hash length) may change in future PHP versions.

See [src/Psl/Password/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/password/src/Psl/Password/) for the full API.
