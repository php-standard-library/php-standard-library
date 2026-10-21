<?php

declare(strict_types=1);

namespace Psl\IO\Internal;

use Psl\IO\Exception;

use function error_get_last;
use function fopen;

/**
 * @throws Exception\RuntimeException If the resource cannot be opened.
 *
 * @return resource
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
function open_resource(string $uri, string $mode): mixed
{
    return namespace\suppress(
        /**
         * @return resource
         */
        static function () use ($uri, $mode): mixed {
            $resource = fopen($uri, $mode);
            if (false === $resource) {
                $error = error_get_last();

                throw new Exception\RuntimeException($error['message'] ?? 'Unable to open resource.');
            }

            return $resource;
        },
    );
}
