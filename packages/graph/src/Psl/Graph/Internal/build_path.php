<?php

declare(strict_types=1);

namespace Psl\Graph\Internal;

use function array_key_exists;
use function array_reverse;

/**
 * Rebuilds the path ending at $to by following parent links back to the start node.
 *
 * The start node is the only node of the path without an entry in $parent. Presence is
 * checked by key rather than by value, as `null` is a valid node.
 *
 * @template TNode
 *
 * @param array<string, TNode> $parent The parent of every reached node except the start node, by node key.
 * @param TNode $to
 *
 * @return list<TNode>
 *
 * @internal
 *
 * @pure
 */
function build_path(array $parent, mixed $to): array
{
    $path = [$to];
    $key = namespace\get_node_key($to);
    while (array_key_exists($key, $parent)) {
        $node = $parent[$key];
        $path[] = $node;
        $key = namespace\get_node_key($node);
    }

    return array_reverse($path);
}
