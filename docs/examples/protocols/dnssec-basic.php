<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\DNS;
use Psl\DNS\Record\RecordType;
use Psl\DNSSEC;

// Create a DNSSEC-validating resolver
$inner = new DNS\SystemResolver(dnssec: true);
$trustChain = new DNSSEC\TrustChainResolver($inner);
$resolver = new DNSSEC\SecureResolver($inner, $trustChain);

// Verify each answer, alias link, and NSEC/NSEC3/SOA proof RRset.
// Strip unrelated answer records.
$response = $resolver->query('example.com', RecordType::A);

// Throws SignatureFailedException, BrokenTrustChainException,
// InvalidProofException, or UnsignedResponseException on failure
