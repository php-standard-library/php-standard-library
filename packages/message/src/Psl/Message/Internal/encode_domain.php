<?php

declare(strict_types=1);

namespace Psl\Message\Internal;

use Normalizer;
use Psl\Message\Exception\InvalidMailboxException;
use Psl\Punycode;

use function explode;
use function implode;
use function mb_strtolower;
use function preg_match;
use function strlen;

/**
 * @internal
 *
 * @throws InvalidMailboxException If an encoded domain label exceeds 63 bytes.
 */
function encode_domain(string $domain): string
{
    $labels = [];
    foreach (explode('.', $domain) as $label) {
        $label = Normalizer::normalize(mb_strtolower($label, 'UTF-8'), Normalizer::FORM_C);
        if ($label === false) {
            throw InvalidMailboxException::forInvalidDomain($domain);
        }

        $label = preg_match('/[\x80-\xff]/', $label) === 1 ? 'xn--' . Punycode\encode($label) : $label;
        if (strlen($label) > 63) {
            throw InvalidMailboxException::forInvalidDomain($domain);
        }

        $labels[] = $label;
    }

    return implode('.', $labels);
}
