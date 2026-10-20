<?php

declare(strict_types=1);

namespace Psl\Graph\Internal;

use function bin2hex;
use function get_resource_id;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_nan;
use function is_object;
use function is_resource;
use function is_string;
use function md5;
use function pack;
use function serialize;
use function spl_object_id;

/**
 * Generates a unique string key for a node of any type.
 *
 * @return non-empty-string
 *
 * @internal
 *
 * @pure
 */
function get_node_key(mixed $node): string
{
    if (is_object($node)) {
        return 'o:' . spl_object_id($node);
    }

    if (is_array($node)) {
        return 'a:' . md5(serialize($node));
    }

    if (is_resource($node)) {
        return 'r:' . get_resource_id($node);
    }

    if (is_bool($node)) {
        return $node ? 'b:1' : 'b:0';
    }

    if (is_int($node)) {
        return 'i:' . $node;
    }

    if (is_float($node)) {
        // Keyed by the exact bytes of the float: `(string) $node` rounds to the `precision` ini setting and
        // `var_export()` to `serialize_precision`, so distinct floats such as `0.1 + 0.2` and `0.3` could
        // share a key, and the cast emits a warning for `NAN` since PHP 8.5. Every `NAN` shares one key.
        return is_nan($node) ? 'f:nan' : 'f:' . bin2hex(pack('E', $node));
    }

    if (is_string($node)) {
        return 's:' . $node;
    }

    return 'n:null';
}
