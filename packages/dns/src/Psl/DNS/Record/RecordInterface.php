<?php

declare(strict_types=1);

namespace Psl\DNS\Record;

use Psl\DateTime\Duration;

/**
 * Common interface for all DNS resource records.
 *
 * @api
 *
 * @inheritors ARecord|AaaaRecord|CnameRecord|DnameRecord|MxRecord|TxtRecord|SrvRecord|NsRecord|PtrRecord|SoaRecord|CaaRecord|NaptrRecord|SshfpRecord|TlsaRecord|OptRecord|DsRecord|DnskeyRecord|RrsigRecord|NsecRecord|Nsec3Record|Nsec3paramRecord|LocRecord|SvcbRecord|HttpsRecord
 */
interface RecordInterface
{
    /**
     * The DNS record type.
     */
    public RecordType $kind { get; }

    /**
     * The domain name this record belongs to.
     */
    public string $name { get; }

    /**
     * The time-to-live for this record.
     */
    public Duration $duration { get; }
}
