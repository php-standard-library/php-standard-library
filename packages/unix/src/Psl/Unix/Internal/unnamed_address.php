<?php

declare(strict_types=1);

namespace Psl\Unix\Internal;

use Psl\Network;

/**
 * The address that stands for an unnamed Unix domain socket, one not bound to any name, as a client
 * usually is.
 *
 * `Network\Address` has no kind for an unnamed socket, so the name `<anonymous>`, which the listener
 * has always reported for its peers, stands for it.
 *
 * @internal
 *
 * @pure
 */
function unnamed_address(): Network\Address
{
    return Network\Address::unix('<anonymous>');
}
