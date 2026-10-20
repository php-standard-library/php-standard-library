# Crypto

The `Crypto` component provides a comprehensive cryptography toolkit built on libsodium and OpenSSL. It covers symmetric encryption, asymmetric encryption, digital signatures, AEAD, key exchange, and key derivation -- all through a type-safe API with dedicated key types that prevent misuse.

## Symmetric Encryption

`Crypto\Symmetric` provides authenticated encryption using XChaCha20-Poly1305. A single shared key encrypts and decrypts. Nonces are managed automatically, so you never have to generate or track them:

@example('security/crypto-symmetric-seal.php')

### Additional Authenticated Data

You can bind extra context to the ciphertext with additional authenticated data (AAD). The AAD is not encrypted, but decryption will fail if it does not match exactly:

@example('security/crypto-symmetric-aad.php')

This is useful for tying ciphertext to a specific user, session, or record without including that metadata in the encrypted payload.

### Stream Encryption

For large data or file encryption, the `StreamEncryptor` class provides streaming encryption. Data is processed in chunks so memory usage stays constant regardless of input size:

@example('security/crypto-symmetric-stream.php')

The stream format uses XChaCha20-Poly1305 secretstream, which authenticates each chunk and detects reordering or truncation. Encryption fills each chunk across short reads and marks the final chunk only at EOF. The source must reach EOF before encryption can finish. Chunk sizes must be between 1 byte and `Symmetric\MAX_CHUNK_BYTES` (16 MiB).

## Asymmetric Encryption

`Crypto\Asymmetric` provides public-key encryption using X25519. Each party has a key pair consisting of a secret key and a public key.

### Sealed Boxes (Anonymous Sender)

Sealed boxes let you encrypt a message for a recipient using only their public key. The sender remains anonymous -- the recipient cannot determine who sent the message:

@example('security/crypto-asymmetric-seal.php')

Use sealed boxes when the sender's identity does not need to be verified, such as anonymous feedback or one-way secret submission.

### Authenticated Encryption

When both parties have key pairs, `Asymmetric\encrypt()` provides authenticated encryption. The recipient can verify the message came from the expected sender:

@example('security/crypto-asymmetric-encrypt.php')

A random nonce is generated and prepended to the ciphertext automatically, so each message is unique even if the plaintext is the same.

### Encryptor Class

The `Encryptor` class implements `Crypto\EncryptorInterface` for sealed-box encryption, useful for dependency injection:

@example('security/crypto-asymmetric-encryptor.php')

## Digital Signatures

`Crypto\Signing` provides Ed25519 digital signatures. A signature proves that a message was created by the holder of a specific secret key and has not been modified. Unlike HMAC, the verifier only needs the public key, so the signing key is never shared.

### Signing and Verifying

`Signing\sign()` creates a detached signature, and `Signing\verify()` checks it against the original message:

@example('security/crypto-signing-basic.php')

If any byte of the message changes, verification fails. The signature does not reveal the message contents.

### Signer and Verifier Classes

The `Signer` and `Verifier` classes wrap the signing and verification logic. They are useful for dependency injection, letting you separate the signing side (which holds the secret key) from the verification side (which only needs the public key):

@example('security/crypto-signing-class.php')

## AEAD Encryption

`Crypto\Aead` provides low-level authenticated encryption with associated data. Unlike `Crypto\Symmetric`, you manage the nonce yourself. This gives you full control but requires care: reusing a nonce with the same key is catastrophic for security.

> **Use `Crypto\Symmetric` instead** unless you have a specific reason to manage nonces manually. The symmetric API handles nonces automatically and is harder to misuse.

@example('security/crypto-aead-encrypt.php')

The `Aead\Algorithm` enum supports three constructions:

| Algorithm | Nonce Size | Notes |
|-----------|-----------|-------|
| `XChaCha20Poly1305` | 24 bytes | Recommended. Large nonce makes accidental reuse unlikely. |
| `ChaCha20Poly1305` | 8 bytes | IETF standard. Smaller nonce requires careful management. |
| `Aes256Gcm` | 12 bytes | Hardware-accelerated on supported CPUs. Not available everywhere. |

## Key Exchange

`Crypto\KeyExchange` provides X25519 Diffie-Hellman key agreement. Two parties can each combine their own secret key with the other's public key to arrive at the same shared secret, without ever transmitting the secret itself:

@example('security/crypto-key-exchange.php')

The shared secret can then be used as input keying material for key derivation to produce session keys.

## Key Derivation

PSL provides two key derivation mechanisms: `Crypto\Kdf` for deriving subkeys from a master key, and `Crypto\Hkdf` for deriving keys from arbitrary input keying material using the HMAC-based extract-and-expand pattern (RFC 5869).

### KDF: Subkey Derivation

`Kdf\derive()` produces deterministic subkeys from a master key. Each subkey is identified by a numeric ID and an 8-byte context string:

@example('security/crypto-kdf-derive.php')

This is useful when you need multiple keys for different purposes (encryption, signing, tokens) but want to store and manage only a single master key.

### HKDF: Extract and Expand

`Hkdf\derive()` implements the full HKDF construction from RFC 5869. It takes raw input keying material (such as a shared secret from key exchange) and produces cryptographically strong key material:

@example('security/crypto-hkdf-derive.php')

The two-step API (`Hkdf\extract()` + `Hkdf\expand()`) is useful when you need to derive multiple keys from the same source material, as you only pay the extraction cost once.

See `src/Psl/Crypto/` for the full API.
