<?php

declare(strict_types=1);

namespace Psl\MIME\Sniff\Internal;

use function json_validate;
use function ltrim;
use function min;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Detect text-based MIME types by inspecting the leading bytes of the content.
 *
 * Returns null if the content appears to be binary (as determined by {@see is_binary()}).
 * Otherwise, examines up to 256 leading bytes (after skipping the WHATWG whitespace bytes
 * SP, HTAB, LF, FF and CR) to identify:
 * - `text/html` - content starting with `<!DOCTYPE html` or `<html`
 * - `image/svg+xml` - XML content containing `<svg`
 * - `application/xml` - content starting with `<?xml`
 * - `application/json` - content starting with `{` or `[` that passes JSON validation
 * - `text/x-script` - content starting with a shebang (`#!`)
 * - `text/plain` - fallback for all other non-binary content
 *
 * @internal
 */
function sniff_text(string $content): null|string
{
    if (namespace\is_binary($content)) {
        return null;
    }

    $trimmed = ltrim($content, " \t\n\x0C\r");
    $prefix = substr($trimmed, 0, min(strlen($trimmed), 256));
    $lower = strtolower($prefix);

    if (str_starts_with($lower, '<!doctype html') || str_starts_with($lower, '<html')) {
        return 'text/html';
    }

    if (str_starts_with($lower, '<?xml') || str_starts_with($lower, '<svg')) {
        if (str_contains($lower, '<svg')) {
            return 'image/svg+xml';
        }

        return 'application/xml';
    }

    if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
        $snippet = substr($trimmed, 0, min(strlen($trimmed), 4096));
        if (json_validate($snippet)) {
            return 'application/json';
        }
    }

    if (str_starts_with($trimmed, '#!')) {
        return 'text/x-script';
    }

    return 'text/plain';
}
