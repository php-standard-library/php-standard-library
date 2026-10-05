<?php

declare(strict_types=1);

namespace Psl\DNSSEC;

use Psl\DateTime\Duration;
use Psl\DNS\DNSSEC\Algorithm;
use Psl\DNS\DNSSEC\DigestAlgorithm;
use Psl\DNS\Record\DSRecord;

/**
 * A trust anchor containing DS records used as the starting point for
 * DNSSEC chain-of-trust validation.
 *
 * The trust anchor represents the root of trust for DNSSEC. By default,
 * it contains the IANA root zone KSK DS record.
 *
 * @api
 */
final readonly class TrustAnchor
{
    /**
     * @param list<DSRecord> $anchors The DS records forming the trust anchor.
     */
    public function __construct(
        public array $anchors,
    ) {}

    /**
     * Create a trust anchor with the IANA root KSK.
     *
     * Key tag 20326, algorithm 8 (RSASHA256), digest type 2 (SHA-256).
     *
     * @see https://data.iana.org/root-anchors/root-anchors.xml
     */
    public static function root(): self
    {
        return new self([
            new DSRecord(
                '.',
                Duration::seconds(0),
                20_326,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                '683D2D0ACB8C9B712A1948B27F741219298D0A450D612C483AF444A4C0FB2B16',
            ),
        ]);
    }
}
