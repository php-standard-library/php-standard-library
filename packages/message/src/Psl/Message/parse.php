<?php

declare(strict_types=1);

namespace Psl\Message;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\Exception\CancelledException;
use Psl\Async\NullCancellationToken;
use Psl\IO;
use Psl\Message\Exception\ParsingException;
use Psl\MIME\Headers;
use Psl\MIME\MultiPart\Parser;
use Psl\MIME\Part\Part;

use function is_string;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Parse an RFC 5322 message from a string or a readable handle.
 *
 * Reads headers line by line from the input, then wraps the remaining
 * stream as the message body. The body is returned as a raw {@see Part}
 * whose content type reflects the message's Content-Type header.
 *
 * For multipart messages, the body stream can be further decomposed
 * using {@see Parser}.
 *
 * @throws ParsingException If the input is not a valid RFC 5322 message.
 * @throws CancelledException If the cancellation token is cancelled before we finish parsing the message.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5322
 *
 * @api
 */
function parse(
    string|IO\ReadHandleInterface $input,
    CancellationTokenInterface $cancellation = new NullCancellationToken(),
): Message {
    if (is_string($input)) {
        $input = new IO\MemoryHandle($input);
    }

    $reader = new IO\Reader($input);
    $headerPairs = [];
    $currentName = null;
    $currentValue = '';

    $cancellation->throwIfCancelled();
    while (!$reader->reachedEndOfDataSource()) {
        $line = $reader->readLine($cancellation);
        if ($line === null) {
            break;
        }

        $line = trim($line, "\r");

        if ($line === '') {
            break;
        }

        if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
            $currentValue .= ' ' . trim($line, " \t");
            continue;
        }

        if ($currentName !== null) {
            $headerPairs[] = [$currentName, $currentValue];
        }

        $colonPos = strpos($line, ':');
        if ($colonPos === false || $colonPos === 0) {
            $currentName = null;
            continue;
        }

        $currentName = substr($line, 0, $colonPos);
        $currentValue = trim(substr($line, $colonPos + 1), " \t");
    }

    if ($currentName !== null) {
        $headerPairs[] = [$currentName, $currentValue];
    }

    if ($headerPairs === []) {
        throw ParsingException::forMalformedMessage('no headers found');
    }

    $headers = Headers::fromPairs($headerPairs);

    $bodyHeaderPairs = [];
    foreach ($headerPairs as [$name, $value]) {
        $lower = strtolower($name);
        if ($lower === 'content-type' || $lower === 'content-transfer-encoding') {
            $bodyHeaderPairs[] = [$name, $value];
        }
    }

    $body = new Part(Headers::fromPairs($bodyHeaderPairs), $reader);

    return new Message($headers, $body);
}
