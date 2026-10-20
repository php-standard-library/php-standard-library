<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\MIME\SMIME;

// Signing requires a certificate and private key in PEM format.
// Verification can optionally check the certificate chain against trusted CAs.
// Encryption requires the recipient's certificate; decryption requires their private key.
// Verification uses temporary certificate and signer metadata files, then removes them.
// Plaintext stays in memory. Chain verification needs explicit trust anchors.

/** @var string $certPem */
/** @var string $keyPem */
/** @var string $caCertPem */
/** @var string $recipientCertPem */
/** @var string $recipientKeyPem */

// Sign and verify
$signer = new SMIME\Signer($certPem, $keyPem);
$signed = $signer->sign('Hello');

$verifier = new SMIME\Verifier([$caCertPem]);
$result = $verifier->verify($signed, verifyCertificateChain: true);
$result->valid; // true
$result->content; // "Hello"
$result->digestAlgorithm; // DigestAlgorithm::Sha256

// Encrypt and decrypt
$encryptor = new SMIME\Encryptor([$recipientCertPem], SMIME\CipherAlgorithm::Aes256Cbc);
$encrypted = $encryptor->encrypt('Secret');

$decryptor = new SMIME\Decryptor($recipientKeyPem);
$decrypted = $decryptor->decrypt($encrypted); // "Secret"
