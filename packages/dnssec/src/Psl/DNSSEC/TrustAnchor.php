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
 * it contains the IANA root zone KSK-2017 and KSK-2024 DS records.
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
     * Create a trust anchor with the IANA root KSK-2017 and KSK-2024.
     *
     * Key tags 20326 and 38696, algorithm 8 (RSASHA256), digest type 2 (SHA-256).
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
                'E06D44B80B8F1D39A95C0B0D7C65D08458E880409BBC683457104237C7F8EC8D',
            ),
            new DSRecord(
                '.',
                Duration::seconds(0),
                38_696,
                Algorithm::RSASHA256,
                DigestAlgorithm::SHA256,
                '683D2D0ACB8C9B712A1948B27F741219298D0A450D612C483AF444A4C0FB2B16',
            ),
        ]);
    }
}
