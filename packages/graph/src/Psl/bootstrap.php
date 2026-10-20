<?php

declare(strict_types=1);

(static function (): void {
    $functions = [
        'Psl\Graph\Internal\build_path' => __DIR__ . '/Graph/Internal/build_path.php',
        'Psl\Graph\Internal\get_node_key' => __DIR__ . '/Graph/Internal/get_node_key.php',
        'Psl\Graph\add_edge' => __DIR__ . '/Graph/add_edge.php',
        'Psl\Graph\add_node' => __DIR__ . '/Graph/add_node.php',
        'Psl\Graph\bfs' => __DIR__ . '/Graph/bfs.php',
        'Psl\Graph\dfs' => __DIR__ . '/Graph/dfs.php',
        'Psl\Graph\directed' => __DIR__ . '/Graph/directed.php',
        'Psl\Graph\has_cycle' => __DIR__ . '/Graph/has_cycle.php',
        'Psl\Graph\has_path' => __DIR__ . '/Graph/has_path.php',
        'Psl\Graph\neighbors' => __DIR__ . '/Graph/neighbors.php',
        'Psl\Graph\nodes' => __DIR__ . '/Graph/nodes.php',
        'Psl\Graph\shortest_path' => __DIR__ . '/Graph/shortest_path.php',
        'Psl\Graph\shortest_path_by' => __DIR__ . '/Graph/shortest_path_by.php',
        'Psl\Graph\topological_sort' => __DIR__ . '/Graph/topological_sort.php',
        'Psl\Graph\undirected' => __DIR__ . '/Graph/undirected.php',
    ];

    foreach ($functions as $function => $path) {
        if (function_exists($function)) {
            continue;
        }

        require_once $path;
    }
})();
