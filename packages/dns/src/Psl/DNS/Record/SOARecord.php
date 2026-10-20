<?php

declare(strict_types=1);

namespace Psl\DNS\Record;

use Psl\DateTime\Duration;

/**
 * A SOA record containing authoritative information about a DNS zone (RFC 1035).
 *
 * @api
 */
final class SOARecord implements RecordInterface
{
    /**
     * {@inheritDoc}
     */
    public RecordType $kind {
        get => RecordType::SOA;
    }

    /**
     * @param string $name The domain name of the zone this record belongs to.
     * @param Duration $duration The time-to-live for this record.
     * @param string $masterName The primary nameserver for the zone.
     * @param string $responsibleName The email address of the zone administrator (in DNS format).
     * @param int $serial The version number of the zone file.
     * @param Duration $refresh The interval before the zone should be refreshed.
     * @param Duration $retry The interval before a failed refresh should be retried.
     * @param Duration $expire The upper limit on the interval before the zone is no longer authoritative.
     * @param Duration $minimumTtl The TTL limit for negative answers (RFC 2308).
     */
    public function __construct(
        public readonly string $name,
        public readonly Duration $duration,
        public readonly string $masterName,
        public readonly string $responsibleName,
        public readonly int $serial,
        public readonly Duration $refresh,
        public readonly Duration $retry,
        public readonly Duration $expire,
        public readonly Duration $minimumTtl,
    ) {}
}
