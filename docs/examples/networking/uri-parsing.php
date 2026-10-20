<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\IO;
use Psl\URI;

$uri = URI\parse('https://Example.COM:443/foo/../bar?q=1#frag');

// "https"
IO\write_line('%s', $uri->scheme ?? '<unknown>');
// "example.com" - lowercased
IO\write_line('%s', $uri->authority?->host?->toString() ?? '<unknown>');
// 443
IO\write_line('%d', $uri->authority->port ?? 0);
// "/bar" - dot segments removed
IO\write_line('%s', $uri->path);
// "q=1"
IO\write_line('%s', $uri->query ?? '<unknown>');
// "frag"
IO\write_line('%s', $uri->fragment ?? '<unknown>');

// "https://example.com:443/bar?q=1#frag"
IO\write_line('%s', $uri->toString());

// Prefix a path segment that contains a colon with "./".
IO\write_line('%s', URI\parse('./:segment')->path); // "./:segment"
