<?php

declare(strict_types=1);

namespace Psl\DNS\Record;

use Psl\DateTime\Duration;

/**
 * A DNAME record mapping a subtree to another domain name (RFC 6672).
 *
 * @api
 */
final class DNAMERecord implements RecordInterface
{
    /**
     * {@inheritDoc}
     */
    public RecordType $kind {
        get => RecordType::DNAME;
    }

    /**
     * @param string $name The domain whose descendants this record redirects.
     * @param Duration $duration The time-to-live for this record.
     * @param string $target The replacement domain for names below this owner.
     */
    public function __construct(
        public readonly string $name,
        public readonly Duration $duration,
        public readonly string $target,
    ) {}
}
