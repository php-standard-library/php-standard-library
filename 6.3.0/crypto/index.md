# Crypto

The `Crypto` component provides a comprehensive cryptography toolkit built on libsodium and OpenSSL. It covers symmetric encryption, asymmetric encryption, digital signatures, AEAD, key exchange, and key derivation -- all through a type-safe API with dedicated key types that prevent misuse.

## Symmetric Encryption

`Crypto\Symmetric` provides authenticated encryption using XChaCha20-Poly1305. A single shared key encrypts and decrypts. Nonces are managed automatically, so you never have to generate or track them:

```php
use Psl\Crypto\Symmetric;
use Psl\IO;

$key = Symmetric\generate_key();

$ciphertext = Symmetric\seal('Hello, World!', $key);
$plaintext = Symmetric\open($ciphertext, $key);

IO\write_line('Decrypted: %s', $plaintext);

// Decrypted: Hello, World!
```

### Additional Authenticated Data

You can bind extra context to the ciphertext with additional authenticated data (AAD). The AAD is not encrypted, but decryption will fail if it does not match exactly:

```php
use Psl\Crypto\Exception;
use Psl\Crypto\Symmetric;
use Psl\IO;

$key = Symmetric\generate_key();

// Additional authenticated data (AAD) is authenticated but not encrypted.
// Use it for metadata that must be verified alongside the ciphertext.
$ciphertext = Symmetric\seal('secret payload', $key, additionalData: 'user-id:42');

// Decryption succeeds only if the same AAD is provided
$plaintext = Symmetric\open($ciphertext, $key, additionalData: 'user-id:42');
IO\write_line('Decrypted: %s', $plaintext);

// Wrong or missing AAD causes decryption to fail
try {
    Symmetric\open($ciphertext, $key, additionalData: 'user-id:99');
} catch (Exception\DecryptionException) {
    IO\write_line('Decryption failed: AAD mismatch.');
}
```

This is useful for tying ciphertext to a specific user, session, or record without including that metadata in the encrypted payload.

### Stream Encryption

For large data or file encryption, the `StreamEncryptor` class provides streaming encryption. Data is processed in chunks so memory usage stays constant regardless of input size:

```php
use Psl\Crypto\Symmetric;
use Psl\IO;

$key = Symmetric\generate_key();
$streamEncryptor = new Symmetric\StreamEncryptor($key);

// Encrypt a stream in chunks (useful for large files)
$source = new IO\MemoryHandle('This is a large message that will be encrypted in chunks.');
$encrypted = new IO\MemoryHandle();

$streamEncryptor->copySealed($source, $encrypted, chunkSize: 4096);

// Decrypt the stream back
$encrypted->seek(0);
$decrypted = new IO\MemoryHandle();
$streamEncryptor->copyOpened($encrypted, $decrypted);

$decrypted->seek(0);
IO\write_line('Decrypted: %s', $decrypted->readAll());
```

The stream format uses XChaCha20-Poly1305 secretstream, which authenticates each chunk individually and detects reordering or truncation.

## Asymmetric Encryption

`Crypto\Asymmetric` provides public-key encryption using X25519. Each party has a key pair consisting of a secret key and a public key.

### Sealed Boxes (Anonymous Sender)

Sealed boxes let you encrypt a message for a recipient using only their public key. The sender remains anonymous -- the recipient cannot determine who sent the message:

```php
use Psl\Crypto\Asymmetric;
use Psl\IO;

$keyPair = Asymmetric\generate_key_pair();

// Sealed boxes: encrypt with just a public key (anonymous sender).
// Only the holder of the secret key can decrypt.
$ciphertext = Asymmetric\seal('Anonymous tip: the cake is a lie.', $keyPair->publicKey);
$plaintext = Asymmetric\open($ciphertext, $keyPair->secretKey, $keyPair->publicKey);

IO\write_line('Decrypted: %s', $plaintext);
```

Use sealed boxes when the sender's identity does not need to be verified, such as anonymous feedback or one-way secret submission.

### Authenticated Encryption

When both parties have key pairs, `Asymmetric\encrypt()` provides authenticated encryption. The recipient can verify the message came from the expected sender:

```php
use Psl\Crypto\Asymmetric;
use Psl\IO;

// Both parties generate key pairs
$alice = Asymmetric\generate_key_pair();
$bob = Asymmetric\generate_key_pair();

// Alice sends an authenticated message to Bob
$ciphertext = Asymmetric\encrypt(
    'Hello Bob, this is Alice.',
    senderSecretKey: $alice->secretKey,
    recipientPublicKey: $bob->publicKey,
);

// Bob decrypts and verifies it came from Alice
$plaintext = Asymmetric\decrypt($ciphertext, recipientSecretKey: $bob->secretKey, senderPublicKey: $alice->publicKey);

IO\write_line('From Alice: %s', $plaintext);
```

A random nonce is generated and prepended to the ciphertext automatically, so each message is unique even if the plaintext is the same.

### Encryptor Class

The `Encryptor` class implements `Crypto\EncryptorInterface` for sealed-box encryption, useful for dependency injection:

```php
use Psl\Crypto\Asymmetric;
use Psl\IO;

$keyPair = Asymmetric\generate_key_pair();

// The Encryptor class implements the EncryptorInterface for sealed-box encryption.
// Useful when you need an object to pass around or inject as a dependency.
$encryptor = new Asymmetric\Encryptor($keyPair->secretKey, $keyPair->publicKey);

$ciphertext = $encryptor->seal('Encrypt me');
$plaintext = $encryptor->open($ciphertext);

IO\write_line('Decrypted: %s', $plaintext);
```

## Digital Signatures

`Crypto\Signing` provides Ed25519 digital signatures. A signature proves that a message was created by the holder of a specific secret key and has not been modified. Unlike HMAC, the verifier only needs the public key, so the signing key is never shared.

### Signing and Verifying

`Signing\sign()` creates a detached signature, and `Signing\verify()` checks it against the original message:

```php
use Psl\Crypto\Signing;
use Psl\IO;

$keyPair = Signing\generate_key_pair();

$message = 'This message is authentic.';
$signature = Signing\sign($message, $keyPair->secretKey);

$valid = Signing\verify($signature, $message, $keyPair->publicKey);
IO\write_line('Signature valid: %s', $valid ? 'yes' : 'no');
// Signature valid: yes

$valid = Signing\verify($signature, 'tampered message', $keyPair->publicKey);
IO\write_line('Tampered valid: %s', $valid ? 'yes' : 'no');

// Tampered valid: no
```

If any byte of the message changes, verification fails. The signature does not reveal the message contents.

### Signer and Verifier Classes

The `Signer` and `Verifier` classes wrap the signing and verification logic. They are useful for dependency injection, letting you separate the signing side (which holds the secret key) from the verification side (which only needs the public key):

```php
use Psl\Crypto\Signing;
use Psl\IO;

$keyPair = Signing\generate_key_pair();

// The Signer and Verifier classes are useful for dependency injection.
$signer = new Signing\Signer($keyPair->secretKey);
$verifier = new Signing\Verifier($keyPair->publicKey);

$signature = $signer->sign('Important document contents');
$valid = $verifier->verify($signature, 'Important document contents');

IO\write_line('Valid: %s', $valid ? 'yes' : 'no');
```

## AEAD Encryption

`Crypto\Aead` provides low-level authenticated encryption with associated data. Unlike `Crypto\Symmetric`, you manage the nonce yourself. This gives you full control but requires care: reusing a nonce with the same key is catastrophic for security.

> **Use `Crypto\Symmetric` instead** unless you have a specific reason to manage nonces manually. The symmetric API handles nonces automatically and is harder to misuse.

```php
use Psl\Crypto\Aead;
use Psl\IO;
use Psl\SecureRandom;

$algorithm = Aead\Algorithm::XChaCha20Poly1305;
$key = Aead\generate_key($algorithm);

// AEAD requires a unique nonce for each message.
// Never reuse a nonce with the same key.
$nonce = SecureRandom\bytes(24);

$ciphertext = Aead\encrypt(
    'Sensitive data',
    $key,
    nonce: $nonce,
    additionalData: 'context-info',
    algorithm: $algorithm,
);

$plaintext = Aead\decrypt($ciphertext, $key, $nonce, 'context-info', $algorithm);
IO\write_line('Decrypted: %s', $plaintext);
```

The `Aead\Algorithm` enum supports three constructions:

| Algorithm | Nonce Size | Notes |
|-----------|-----------|-------|
| `XChaCha20Poly1305` | 24 bytes | Recommended. Large nonce makes accidental reuse unlikely. |
| `ChaCha20Poly1305` | 8 bytes | IETF standard. Smaller nonce requires careful management. |
| `Aes256Gcm` | 12 bytes | Hardware-accelerated on supported CPUs. Not available everywhere. |

## Key Exchange

`Crypto\KeyExchange` provides X25519 Diffie-Hellman key agreement. Two parties can each combine their own secret key with the other's public key to arrive at the same shared secret, without ever transmitting the secret itself:

```php
use Psl\Crypto\KeyExchange;
use Psl\IO;

// Both parties generate key pairs
$alice = KeyExchange\generate_key_pair();
$bob = KeyExchange\generate_key_pair();

// Each party computes a shared secret using their secret key and the other's public key
$aliceShared = KeyExchange\agree($alice->secretKey, $bob->publicKey);
$bobShared = KeyExchange\agree($bob->secretKey, $alice->publicKey);

// Both arrive at the same shared secret
IO\write_line('Secrets match: %s', $aliceShared->bytes === $bobShared->bytes ? 'yes' : 'no');

// Secrets match: yes
```

The shared secret can then be used as input keying material for key derivation to produce session keys.

## Key Derivation

PSL provides two key derivation mechanisms: `Crypto\Kdf` for deriving subkeys from a master key, and `Crypto\Hkdf` for deriving keys from arbitrary input keying material using the HMAC-based extract-and-expand pattern (RFC 5869).

### KDF: Subkey Derivation

`Kdf\derive()` produces deterministic subkeys from a master key. Each subkey is identified by a numeric ID and an 8-byte context string:

```php
use Psl\Crypto\Kdf;
use Psl\IO;
use Psl\Str\Byte;

$masterKey = Kdf\generate_key();

// Derive subkeys for different purposes using a context string (exactly 8 bytes)
// and a numeric subkey ID.
$encryptionKey = Kdf\derive($masterKey, subKeyId: 1, context: 'encryptn', length: 32);
$signingKey = Kdf\derive($masterKey, subKeyId: 2, context: 'encryptn', length: 32);

IO\write_line('Encryption key length: %d bytes', Byte\length($encryptionKey));
IO\write_line('Signing key length: %d bytes', Byte\length($signingKey));
IO\write_line('Keys are different: %s', $encryptionKey !== $signingKey ? 'yes' : 'no');
```

This is useful when you need multiple keys for different purposes (encryption, signing, tokens) but want to store and manage only a single master key.

### HKDF: Extract and Expand

`Hkdf\derive()` implements the full HKDF construction from RFC 5869. It takes raw input keying material (such as a shared secret from key exchange) and produces cryptographically strong key material:

```php
use Psl\Crypto\Hkdf;
use Psl\Hash\Hmac;
use Psl\IO;
use Psl\Str\Byte;

// HKDF (RFC 5869) derives keys from input keying material.
// Useful when you have a shared secret from key exchange and need session keys.
$inputKeyingMaterial = 'raw-shared-secret-from-key-exchange';

// One-step derivation (extract + expand combined)
$derivedKey = Hkdf\derive(
    inputKeyingMaterial: $inputKeyingMaterial,
    salt: 'optional-salt',
    info: 'session-encryption-key',
    length: 32,
    algorithm: Hmac\Algorithm::Sha256,
);

IO\write_line('Derived key length: %d bytes', Byte\length($derivedKey));

// Two-step derivation for deriving multiple keys from the same PRK
$prk = Hkdf\extract($inputKeyingMaterial, salt: 'my-salt');
$key1 = Hkdf\expand($prk, info: 'encryption', length: 32);
$key2 = Hkdf\expand($prk, info: 'authentication', length: 32);

IO\write_line('Keys are different: %s', $key1 !== $key2 ? 'yes' : 'no');
```

The two-step API (`Hkdf\extract()` + `Hkdf\expand()`) is useful when you need to derive multiple keys from the same source material, as you only pay the extraction cost once.

See [src/Psl/Crypto/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/crypto/src/Psl/Crypto/) for the full API.
