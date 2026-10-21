<?php

declare(strict_types=1);

namespace Psl\TLS\Exception;

use Psl\Network\Exception;

/**
 * Exception thrown when a TLS handshake fails.
 *
 * A handshake that failed because the peer's certificate was rejected throws the
 * {@see PeerVerificationException} subclass, which retry decorators do not retry.
 *
 * @api
 */
class HandshakeFailedException extends Exception\RuntimeException implements ExceptionInterface {}
