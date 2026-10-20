<?php

declare(strict_types=1);

namespace Psl\Process;

/**
 * A POSIX signal that can be sent to, or can terminate, a child process.
 *
 * The backing values are the signal numbers of Linux on most architectures (x86 and ARM
 * among them). Signal numbers are not the same on every platform (SIGUSR1 is 10 on x86 Linux
 * but 30 on macOS), so PSL translates each case to the current platform's number when
 * sending it, and translates the number reported for a terminated process back to a case.
 * Do not rely on {@see Signal::from()} or {@see Signal::tryFrom()} to decode a raw signal
 * number from the operating system.
 *
 * @api
 */
enum Signal: int
{
    case Hangup = 1;
    case Interrupt = 2;
    case Quit = 3;
    case Kill = 9;
    case User1 = 10;
    case User2 = 12;
    case Alarm = 14;
    case Terminate = 15;
}
