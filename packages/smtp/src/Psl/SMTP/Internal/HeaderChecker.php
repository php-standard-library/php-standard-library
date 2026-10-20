<?php

declare(strict_types=1);

namespace Psl\SMTP\Internal;

use Psl\Async\CancellationTokenInterface;
use Psl\IO;
use Psl\MIME\Exception\ExceptionInterface;
use Psl\MIME\MediaType;
use Psl\SMTP\Capability;
use Psl\SMTP\Exception\TransmissionException;

use function array_key_last;
use function array_slice;
use function count;
use function explode;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strlen;
use function strtolower;
use function trim;

/**
 * Checks raw MIME header bytes without treating body text as headers.
 *
 * @internal
 */
final class HeaderChecker
{
    public static function check(
        IO\ReadHandleInterface $body,
        MediaType $mediaType,
        null|string $encoding,
        CancellationTokenInterface $cancellation,
    ): void {
        if (self::isEncoded($encoding)) {
            return;
        }

        $boundary = $mediaType->parameters->get('boundary');
        $boundaries =
            $mediaType->type === 'multipart' && $boundary !== null
                ? [[$boundary, $mediaType->subtype === 'digest']]
                : [];
        $defaultMessage = false;
        $headers = self::isEmbeddedMessage($mediaType) ? '' : null;
        $line = '';
        $longBodyLine = false;

        while (!$body->reachedEndOfDataSource()) {
            $fragments = explode("\n", $body->read(8192, $cancellation));
            foreach ($fragments as $index => $fragment) {
                if ($headers !== null) {
                    if (preg_match('/[\x80-\xff]/', $fragment) === 1) {
                        throw TransmissionException::forUnsupportedCapability(Capability::SMTPUTF8);
                    }

                    $line .= $fragment;
                } elseif (!$longBodyLine) {
                    $line .= $fragment;
                    if (strlen($line) > 1000) {
                        $line = '';
                        $longBodyLine = true;
                    }
                }

                if ($index === array_key_last($fragments)) {
                    continue;
                }

                if ($longBodyLine) {
                    $longBodyLine = false;
                    continue;
                }

                $line = rtrim($line, "\r");
                if ($headers !== null && $line !== '') {
                    $headers .= $line . "\r\n";
                    $line = '';
                    continue;
                }

                if ($headers !== null) {
                    [$mediaType, $encoded] = self::parseMetadata($headers, $defaultMessage);
                    $defaultMessage = false;
                    $headers = !$encoded && self::isEmbeddedMessage($mediaType) ? '' : null;
                    $boundary = $mediaType->parameters->get('boundary');
                    if (!$encoded && $mediaType->type === 'multipart' && $boundary !== null) {
                        $boundaries[] = [$boundary, $mediaType->subtype === 'digest'];
                    }

                    $line = '';
                    continue;
                }

                $delimiter = rtrim($line, " \t");
                for ($depth = count($boundaries) - 1; $depth >= 0; $depth--) {
                    if ($delimiter === '--' . $boundaries[$depth][0]) {
                        $boundaries = array_slice($boundaries, 0, $depth + 1);
                        $headers = '';
                        $defaultMessage = $boundaries[$depth][1];
                        break;
                    }

                    if ($delimiter === '--' . $boundaries[$depth][0] . '--') {
                        $boundaries = array_slice($boundaries, 0, $depth);
                        break;
                    }
                }

                $line = '';
            }
        }
    }

    /** @return array{MediaType, bool} */
    private static function parseMetadata(string $headers, bool $defaultMessage): array
    {
        $headers = preg_replace('/\r\n[ \t]+/', ' ', $headers) ?? $headers;
        $matches = [];
        $encoded =
            preg_match('/^Content-Transfer-Encoding[ \t]*:([^\r\n]*)/mi', $headers, $matches) === 1
            && self::isEncoded($matches[1]);
        $mediaType = $defaultMessage ? new MediaType('message', 'rfc822') : new MediaType('text', 'plain');
        if (preg_match('/^Content-Type[ \t]*:([^\r\n]*)/mi', $headers, $matches) === 1) {
            try {
                $mediaType = MediaType::parse(trim($matches[1]));
            } catch (ExceptionInterface) {
                return [$mediaType, $encoded];
            }
        }

        return [$mediaType, $encoded];
    }

    private static function isEncoded(null|string $encoding): bool
    {
        $encoding = strtolower(trim($encoding ?? ''));

        return $encoding === 'base64' || $encoding === 'quoted-printable';
    }

    private static function isEmbeddedMessage(MediaType $mediaType): bool
    {
        return $mediaType->type === 'message' && ($mediaType->subtype === 'rfc822' || $mediaType->subtype === 'global');
    }
}
