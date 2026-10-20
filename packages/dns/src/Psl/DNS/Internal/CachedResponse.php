<?php

declare(strict_types=1);

namespace Psl\DNS\Internal;

use Psl\DateTime\Duration;
use Psl\DateTime\Timestamp;
use Psl\DNS\Record;
use Psl\DNS\Response;

use function array_map;
use function floor;
use function max;
use function min;

/**
 * @internal
 */
final readonly class CachedResponse
{
    public function __construct(
        private Response $response,
        private Timestamp $storedAt,
        private int $ttl,
    ) {}

    public function response(): Response
    {
        $elapsed = max(0.0, Timestamp::monotonic()->since($this->storedAt)->getTotalSeconds());
        $remaining = max(0.0, $this->ttl - $elapsed);
        $copy = static fn(Record\RecordInterface $record): Record\RecordInterface => self::copyRecord(
            $record,
            Duration::seconds((int) floor(max(0.0, min($record->duration->getTotalSeconds() - $elapsed, $remaining)))),
        );

        return new Response(
            $this->response->id,
            $this->response->code,
            array_map($copy, $this->response->answers),
            array_map($copy, $this->response->authority),
            array_map($copy, $this->response->additional),
            $this->response->authoritativeAnswer,
            $this->response->recursionDesired,
            $this->response->recursionAvailable,
            $this->response->authenticatedData,
            $this->response->checkingDisabled,
        );
    }

    private static function copyRecord(Record\RecordInterface $record, Duration $duration): Record\RecordInterface
    {
        return match (true) {
            // OPT carries flags in its TTL field, not a cache lifetime (RFC 6891).
            $record instanceof Record\OPTRecord => $record,
            $record instanceof Record\AAAARecord => new Record\AAAARecord($record->name, $duration, $record->address),
            $record instanceof Record\ARecord => new Record\ARecord($record->name, $duration, $record->address),
            $record instanceof Record\CAARecord => new Record\CAARecord(
                $record->name,
                $duration,
                $record->flags,
                $record->tag,
                $record->value,
            ),
            $record instanceof Record\CNAMERecord => new Record\CNAMERecord($record->name, $duration, $record->target),
            $record instanceof Record\DNAMERecord => new Record\DNAMERecord($record->name, $duration, $record->target),
            $record instanceof Record\DNSKEYRecord => new Record\DNSKEYRecord(
                $record->name,
                $duration,
                $record->flags,
                $record->protocol,
                $record->algorithm,
                $record->publicKey,
            ),
            $record instanceof Record\DSRecord => new Record\DSRecord(
                $record->name,
                $duration,
                $record->keyTag,
                $record->algorithm,
                $record->digestType,
                $record->digest,
            ),
            $record instanceof Record\HTTPSRecord => new Record\HTTPSRecord(
                $record->name,
                $duration,
                $record->priority,
                $record->target,
                $record->params,
            ),
            $record instanceof Record\LOCRecord => new Record\LOCRecord(
                $record->name,
                $duration,
                $record->version,
                $record->latitudeRaw,
                $record->longitudeRaw,
                $record->altitudeRaw,
                $record->sizeRaw,
                $record->horizontalPrecisionRaw,
                $record->verticalPrecisionRaw,
            ),
            $record instanceof Record\MXRecord => new Record\MXRecord(
                $record->name,
                $duration,
                $record->preference,
                $record->exchange,
            ),
            $record instanceof Record\NAPTRRecord => new Record\NAPTRRecord(
                $record->name,
                $duration,
                $record->order,
                $record->preference,
                $record->flags,
                $record->services,
                $record->regexp,
                $record->replacement,
            ),
            $record instanceof Record\NSEC3PARAMRecord => new Record\NSEC3PARAMRecord(
                $record->name,
                $duration,
                $record->hashAlgorithm,
                $record->flags,
                $record->iterations,
                $record->salt,
            ),
            $record instanceof Record\NSEC3Record => new Record\NSEC3Record(
                $record->name,
                $duration,
                $record->hashAlgorithm,
                $record->flags,
                $record->iterations,
                $record->salt,
                $record->nextHashedOwnerName,
                $record->types,
            ),
            $record instanceof Record\NSECRecord => new Record\NSECRecord(
                $record->name,
                $duration,
                $record->nextDomainName,
                $record->types,
            ),
            $record instanceof Record\NSRecord => new Record\NSRecord($record->name, $duration, $record->host),
            $record instanceof Record\PTRRecord => new Record\PTRRecord($record->name, $duration, $record->target),
            $record instanceof Record\RRSIGRecord => new Record\RRSIGRecord(
                $record->name,
                $duration,
                $record->typeCovered,
                $record->algorithm,
                $record->labels,
                $record->originalTtl,
                $record->expiration,
                $record->inception,
                $record->keyTag,
                $record->signer,
                $record->signature,
            ),
            $record instanceof Record\SOARecord => new Record\SOARecord(
                $record->name,
                $duration,
                $record->masterName,
                $record->responsibleName,
                $record->serial,
                $record->refresh,
                $record->retry,
                $record->expire,
                $record->minimumTtl,
            ),
            $record instanceof Record\SRVRecord => new Record\SRVRecord(
                $record->name,
                $duration,
                $record->priority,
                $record->weight,
                $record->port,
                $record->target,
            ),
            $record instanceof Record\SSHFPRecord => new Record\SSHFPRecord(
                $record->name,
                $duration,
                $record->algorithm,
                $record->fingerprintType,
                $record->fingerprint,
            ),
            $record instanceof Record\SVCBRecord => new Record\SVCBRecord(
                $record->name,
                $duration,
                $record->priority,
                $record->target,
                $record->params,
            ),
            $record instanceof Record\TLSARecord => new Record\TLSARecord(
                $record->name,
                $duration,
                $record->certificateUsage,
                $record->selector,
                $record->matchingType,
                $record->certificateAssociationData,
            ),
            $record instanceof Record\TXTRecord => new Record\TXTRecord($record->name, $duration, $record->strings),
        };
    }
}
