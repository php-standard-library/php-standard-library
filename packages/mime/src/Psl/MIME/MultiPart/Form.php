<?php

declare(strict_types=1);

namespace Psl\MIME\MultiPart;

use Psl\Encoding;
use Psl\IO;
use Psl\MIME\ContentDisposition;
use Psl\MIME\Exception\EntropyException;
use Psl\MIME\Exception\InvalidArgumentException;
use Psl\MIME\Exception\ParsingException;
use Psl\MIME\Headers;
use Psl\MIME\Internal;
use Psl\MIME\MediaType;
use Psl\MIME\Parameters;
use Psl\MIME\Part\Part;
use Psl\MIME\Part\PartInterface;
use Psl\MIME\TransferEncoding;

use function mb_strtolower;
use function strtr;
use function trim;

/**
 * Builder for multipart/form-data bodies per RFC 7578.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc7578
 *
 * @api
 */
final class Form implements MultiPartInterface
{
    /**
     * The boundary string used to delimit parts in the serialized body.
     *
     * @var non-empty-string
     */
    public readonly string $boundary;

    /**
     * The Content-Type media type for this multipart/form-data body, including the boundary parameter.
     */
    public readonly MediaType $mediaType;

    /**
     * The MIME headers for this multipart body, consisting of the Content-Type header.
     */
    public readonly Headers $headers;

    /**
     * The form field and file upload parts.
     *
     * @var list<PartInterface>
     */
    private array $parts = [];

    /**
     * @param null|non-empty-string $boundary
     *
     * @throws EntropyException If the system cannot generate a secure random boundary.
     */
    public function __construct(null|string $boundary = null)
    {
        $this->boundary = $boundary ?? Internal\generate_boundary();
        $this->mediaType = new MediaType('multipart', 'form-data', Parameters::fromPairs([[
            'boundary',
            $this->boundary,
        ]]));
        $this->headers = Headers::fromPairs([['Content-Type', $this->mediaType->toString()]]);
    }

    /**
     * Add a simple text field to the form.
     *
     * Creates a part with a Content-Disposition of "form-data" and the given
     * field name. The value is stored in memory as a {@see IO\MemoryHandle}.
     *
     * @param string $name The form field name, used in the Content-Disposition "name" parameter.
     * @param string $value The field value.
     */
    public function addField(string $name, string $value): void
    {
        $disposition = new ContentDisposition('form-data', Parameters::fromPairs([['name', $name]]));

        $this->parts[] = new Part(Headers::fromPairs([[
            'Content-Disposition',
            $disposition->toString(),
        ]]), new IO\MemoryHandle($value));
    }

    /**
     * Add a MIME part as a named form field.
     *
     * The part's Content-Disposition is replaced with a "form-data" disposition carrying the
     * given field name and, when the part's own disposition names a file, its "filename"
     * parameter (RFC 7578 §4.2). As in the HTML form-data encoding algorithm, CR, LF and the
     * double quote in the filename are percent-encoded (`%0D`, `%0A`, `%22`), so a filename can
     * never break out of the header line.
     *
     * RFC 7578 §4.7 deprecates Content-Transfer-Encoding in multipart/form-data (senders should
     * not use it, and servers ignore it), so the part's transfer encoding is undone: base64 and
     * quoted-printable bodies (such as those of {@see \Psl\MIME\Part\Data} and
     * {@see \Psl\MIME\Part\Text}) are decoded and sent as raw bytes, and the
     * Content-Transfer-Encoding header is dropped. A header naming an encoding this library does
     * not know is kept, since such a body cannot be decoded. When a part has more than one such
     * header, the last one decides, as in {@see Parser}.
     *
     * All other headers of the part are preserved.
     *
     * @param string $name The form field name, used in the Content-Disposition "name" parameter.
     * @param PartInterface $part The part to include.
     *
     * @link https://datatracker.ietf.org/doc/html/rfc7578#section-4.2
     * @link https://datatracker.ietf.org/doc/html/rfc7578#section-4.7
     * @link https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#multipart-form-data
     */
    public function addPart(string $name, PartInterface $part): void
    {
        $parameters = [['name', $name]];
        $filename = self::filename($part);
        if ($filename !== null) {
            $parameters[] = ['filename', self::escapeParameterValue($filename)];
        }

        $disposition = new ContentDisposition('form-data', Parameters::fromPairs($parameters));

        // As in Parser, the last Content-Transfer-Encoding header decides how the body is encoded.
        $encoding = null;
        foreach ($part->headers->pairs() as [$headerName, $headerValue]) {
            if (mb_strtolower($headerName) !== 'content-transfer-encoding') {
                continue;
            }

            $encoding = TransferEncoding::tryFrom(mb_strtolower(trim($headerValue, " \t")));
        }

        $headers = [['Content-Disposition', $disposition->toString()]];
        foreach ($part->headers->pairs() as [$headerName, $headerValue]) {
            $lowerName = mb_strtolower($headerName);
            if ($lowerName === 'content-disposition') {
                continue;
            }

            if ($lowerName === 'content-transfer-encoding' && $encoding !== null) {
                continue;
            }

            $headers[] = [$headerName, $headerValue];
        }

        $body = match ($encoding) {
            TransferEncoding::Base64 => new Encoding\Base64\DecodingReadHandle(
                $part->body(),
                Encoding\Base64\Variant::Mime,
            ),
            TransferEncoding::QuotedPrintable => new Encoding\QuotedPrintable\DecodingReadHandle($part->body()),
            default => $part->body(),
        };

        $this->parts[] = new Part(Headers::fromPairs($headers), $body);
    }

    /**
     * Return a readable stream that serializes all form fields and file parts,
     * separated by the MIME boundary delimiter.
     */
    public function body(): IO\ReadHandleInterface
    {
        return new Internal\MultiPartReadHandle($this->boundary, $this->parts);
    }

    /**
     * Escape a form-data disposition parameter value the way the HTML form-data encoding
     * algorithm does: CR, LF and the double quote are percent-encoded, so the value can neither
     * end the quoted string nor the header line.
     *
     * @link https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#multipart-form-data
     */
    private static function escapeParameterValue(string $value): string
    {
        return strtr($value, [
            "\r" => '%0D',
            "\n" => '%0A',
            '"' => '%22',
        ]);
    }

    /**
     * Return the "filename" parameter of the part's Content-Disposition, if it has a parseable one.
     */
    private static function filename(PartInterface $part): null|string
    {
        $value = $part->headers->get('content-disposition');
        if ($value === null) {
            return null;
        }

        try {
            return ContentDisposition::parse($value)->unsafeFilename();
        } catch (ParsingException|InvalidArgumentException) {
            return null;
        }
    }
}
