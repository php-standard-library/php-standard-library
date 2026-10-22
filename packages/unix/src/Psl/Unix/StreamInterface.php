<?php

declare(strict_types=1);

namespace Psl\Unix;

use Psl\Network;

/**
 * A connected Unix domain socket stream.
 *
 * Its local and peer addresses are the names the two sockets are bound to. A socket that is not bound to any name,
 * as a client usually is, has the unnamed address `unix://<anonymous>`.
 *
 * @api
 */
interface StreamInterface extends Network\StreamInterface {}
