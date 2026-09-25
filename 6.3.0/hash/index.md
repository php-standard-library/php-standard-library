# Hash

The `Hash` component provides cryptographic and non-cryptographic hashing through a type-safe API. It wraps PHP's hash functions with an `Algorithm` enum that prevents typos and invalid algorithm names, and includes timing-safe comparison and HMAC support.

## Hashing Data

`Hash\hash()` computes a message digest for the given data and algorithm:

```php
use Psl\Hash;
use Psl\IO;

$digest = Hash\hash('Hello, World!', Hash\Algorithm::Sha256);
// 'dffd6021bb2bd5b0af676290809ec3a53191dd81c7f70a4b28688a362182986f'
IO\write_line('SHA-256: %s', $digest);

$md5 = Hash\hash('Hello, World!', Hash\Algorithm::Md5);
IO\write_line('MD5: %s', $md5);
```

The `Hash\Algorithm` enum covers all algorithms available in PHP, including SHA-1, SHA-256, SHA-512, SHA-3 variants, MD5, CRC32, xxHash, MurmurHash, and many more.

## Comparing Hashes Safely

Never compare hashes with `===`. String comparison leaks timing information that can be exploited to guess hashes byte-by-byte. Use `Hash\equals()` instead, which runs in constant time:

```php
use Psl\Hash;
use Psl\IO;

$data = 'sensitive payload';
$expected = Hash\hash($data, Hash\Algorithm::Sha256);

$userProvidedHash = $expected; // simulate correct hash

// Timing-safe comparison
if (Hash\equals($expected, $userProvidedHash)) {
    IO\write_line('Hashes match.');
} else {
    IO\write_line('Hashes do not match.');
}
```

## Incremental Hashing

For large data or streaming scenarios, use `Hash\Context` to feed data in chunks:

```php
use Psl\Hash;
use Psl\IO;

$chunkOne = 'Hello, ';
$chunkTwo = 'World';
$chunkThree = '!';

$digest = Hash\Context::forAlgorithm(Hash\Algorithm::Sha256)
    ->update($chunkOne)
    ->update($chunkTwo)
    ->update($chunkThree)
    ->finalize();

IO\write_line('Incremental SHA-256: %s', $digest);
```

The context is immutable -- each `update()` returns a new context, so you can safely branch from any intermediate state.

## HMAC

HMAC (Hash-based Message Authentication Code) combines a hash with a secret key to verify both data integrity and authenticity. Use it when you need to ensure data has not been tampered with and was produced by someone who holds the key.

```php
use Psl\Hash\Hmac;
use Psl\IO;

$message = 'Hello, HMAC!';
$secretKey = 'my-secret-key-for-signing';

$signature = Hmac\hash(data: $message, algorithm: Hmac\Algorithm::Sha256, key: $secretKey);

IO\write_line('HMAC-SHA256: %s', $signature);
```

A practical example -- signing and verifying an API request:

```php
use Psl\Hash;
use Psl\Hash\Hmac;
use Psl\IO;
use Psl\SecureRandom;

/**
 * Simulate an API secret key (in practice, this should be stored securely and not hardcoded)
 * For demonstration purposes, we generate a random 32-byte string to use as the API secret key.
 */
$apiSecret = SecureRandom\string(32);

// Sender: sign the payload
$payload = '{"action":"transfer","amount":100}';
$signature = Hmac\hash($payload, Hmac\Algorithm::Sha256, $apiSecret);
IO\write_line('Signature: %s', $signature);

// Receiver: verify the signature
$receivedSignature = $signature; // simulate receiving the same signature
$expected = Hmac\hash($payload, Hmac\Algorithm::Sha256, $apiSecret);
if (!Hash\equals($expected, $receivedSignature)) {
    throw new RuntimeException('Invalid signature -- request may have been tampered with.');
}

IO\write_line('Signature verified successfully.');
```

HMAC also supports incremental hashing through `Hash\Context::hmac()`:

```php
use Psl\Hash;
use Psl\IO;

$key = 'my-hmac-secret-key';
$headerBytes = 'Content-Type: application/json';
$bodyBytes = '{"user":"alice","action":"login"}';

$digest = Hash\Context::hmac(Hash\Hmac\Algorithm::Sha256, $key)
    ->update($headerBytes)
    ->update($bodyBytes)
    ->finalize();

IO\write_line('Incremental HMAC: %s', $digest);
```

See [src/Psl/Hash/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/hash/src/Psl/Hash/) for the full API.
