<?php

declare(strict_types=1);

namespace Psl\DNSSEC;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Record\DNSKEYRecord;

use function rtrim;
use function strpos;
use function strtolower;
use function substr;

/**
 * Trust chain resolver backed by an in-memory map of pre-validated DNSKEY records.
 *
 * No network I/O or cryptographic verification is performed. Useful for testing
 * DNSSEC-aware code with deterministic, known-good key material.
 *
 * @api
 */
final readonly class StaticTrustChainResolver implements TrustChainResolverInterface
{
    /**
     * Map of lowercased zone names to validated DNSKEY records.
     *
     * @var array<string, list<DNSKEYRecord>>
     */
    private array $keys;

    /**
     * Set of lowercased zone names that are proven unsigned (insecure).
     *
     * @var array<string, true>
     */
    private array $insecureZones;

    /**
     * @param array<string, list<DNSKEYRecord>> $keys Map of zone names to validated DNSKEY records.
     * @param list<string> $insecureZones List of zone names that are proven unsigned (insecure).
     */
    public function __construct(array $keys, array $insecureZones = [])
    {
        $normalized = [];
        foreach ($keys as $zone => $dnskeys) {
            $normalized[rtrim(strtolower($zone), '.')] = $dnskeys;
        }

        $this->keys = $normalized;

        $normalizedInsecure = [];
        foreach ($insecureZones as $zone) {
            $normalizedInsecure[rtrim(strtolower($zone), '.')] = true;
        }

        $this->insecureZones = $normalizedInsecure;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(
        string $zone,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
    ): TrustChainResult {
        $normalizedZone = rtrim(strtolower($zone), '.');

        while (true) {
            if (isset($this->insecureZones[$normalizedZone])) {
                return new TrustChainResult(TrustChainStatus::Insecure, []);
            }

            if (isset($this->keys[$normalizedZone])) {
                return new TrustChainResult(TrustChainStatus::Secure, $this->keys[$normalizedZone]);
            }

            if ($normalizedZone === '') {
                return new TrustChainResult(TrustChainStatus::Bogus, [], ChainFailure::ChainBroken);
            }

            $dot = strpos($normalizedZone, '.');
            $normalizedZone = $dot === false ? '' : substr($normalizedZone, $dot + 1);
        }
    }
}
