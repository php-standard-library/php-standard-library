<?php

declare(strict_types=1);

namespace Psl\DNS;

/**
 * DNS response codes (RCODEs) as defined in RFC 1035, RFC 2136, and RFC 6891.
 *
 * @api
 */
enum ResponseCode: string
{
    /**
     * The query completed successfully with no errors.
     */
    case NoError = 'NOERROR';

    /**
     * The nameserver was unable to interpret the query due to a format error.
     */
    case FormatError = 'FORMERR';

    /**
     * The nameserver encountered an internal failure while processing the query.
     */
    case ServerFailure = 'SERVFAIL';

    /**
     * The queried domain name does not exist (NXDOMAIN).
     */
    case NonExistentDomain = 'NXDOMAIN';

    /**
     * The nameserver does not support the requested query type.
     */
    case NotImplemented = 'NOTIMP';

    /**
     * The nameserver refused to perform the requested operation.
     */
    case ServerRefused = 'REFUSED';

    /**
     * A name exists when it should not (RFC 2136 dynamic update).
     */
    case DomainShouldNotExist = 'YXDOMAIN';

    /**
     * An RRset exists when it should not (RFC 2136 dynamic update).
     */
    case RecordSetShouldNotExist = 'YXRRSET';

    /**
     * An RRset does not exist when it should (RFC 2136 dynamic update).
     */
    case RecordSetShouldExist = 'NXRRSET';

    /**
     * The server is not authoritative for the zone (RFC 2136 dynamic update).
     */
    case NotAuthoritative = 'NOTAUTH';

    /**
     * A name is not contained in the zone (RFC 2136 dynamic update).
     */
    case NameNotInZone = 'NOTZONE';

    /**
     * The EDNS version is not supported by the server (RFC 6891).
     */
    case BadVersion = 'BADVERS';

    /**
     * The server cookie is missing or invalid (RFC 7873).
     */
    case BadCookie = 'BADCOOKIE';

    /**
     * Whether this response code indicates a successful query.
     */
    public function isSuccess(): bool
    {
        return $this === self::NoError;
    }

    /**
     * Whether this response code indicates any error condition.
     */
    public function isError(): bool
    {
        return $this !== self::NoError;
    }

    /**
     * Whether this response code indicates a server-side error.
     */
    public function isServerError(): bool
    {
        return $this === self::ServerFailure || $this === self::ServerRefused;
    }

    /**
     * Whether this response code indicates the domain does not exist.
     */
    public function isNameError(): bool
    {
        return $this === self::NonExistentDomain;
    }
}
