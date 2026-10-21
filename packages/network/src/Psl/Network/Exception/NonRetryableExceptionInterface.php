<?php

declare(strict_types=1);

namespace Psl\Network\Exception;

/**
 * Marks a failure that repeating the same operation cannot fix.
 *
 * Retry decorators (the TCP retry connector and the HTTP retry client) rethrow exceptions
 * implementing this interface unchanged instead of retrying. A rejected TLS peer certificate
 * is the first example: a certificate that failed chain, name or pin verification fails again
 * on the next handshake.
 *
 * @api
 */
interface NonRetryableExceptionInterface extends ExceptionInterface {}
