<?php

declare(strict_types=1);

namespace Psl\Terminal\Event;

/**
 * @api
 */
enum MouseKind
{
    case Press;
    case Release;
    case Drag;
    case ScrollUp;
    case ScrollDown;
    case Move;
    case ScrollLeft;
    case ScrollRight;
}
