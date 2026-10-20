<?php

declare(strict_types=1);

namespace Psl\DNSSEC;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Exception;

/**
 * Resolves and validates the DNSSEC chain of trust for a given zone.
 *
 * Implementations walk the chain from the root trust anchor down to the
 * target zone, verifying DS to DNSKEY links at each level.
 *
 * Users may implement this interface to add caching, logging, or other
 * behavior around chain resolution.
 *
 * @api
 */
interface TrustChainResolverInterface
{
    /**
     * Get validated DNSKEY records for a zone or a name within it.
     * Walk the chain of trust to find the zone that owns the name.
     *
     * Returns a {@see TrustChainResult} with one of three statuses:
     * - Secure: validated keys available via {@see TrustChainResult::$keys}
     * - Insecure: proven unsigned via DS non-existence
     * - Bogus: validation failed, reason in {@see TrustChainResult::$failure}
     *
     * @throws Exception\RuntimeException If a DNS query fails due to a transport or encoding error.
     * @throws Exception\InvalidArgumentException If the query name or options are invalid.
     * @throws Exception\ProtocolException If a response is malformed or violates the DNS protocol.
     */
    public function resolve(
        string $zone,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): TrustChainResult;
}
