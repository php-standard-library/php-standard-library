<?php

declare(strict_types=1);

namespace Psl\Process\Internal;

use Psl\Process\Signal;

use function defined;

use const PHP_OS_FAMILY;
use const SIGUSR1;
use const SIGUSR2;

/**
 * Translates between {@see Signal} cases and the signal numbers of the current platform.
 *
 * The backing values of {@see Signal} are the signal numbers of Linux on most architectures
 * (x86 and ARM among them). POSIX fixes the numbers of SIGHUP (1), SIGINT (2), SIGQUIT (3),
 * SIGKILL (9), SIGALRM (14) and SIGTERM (15), but not those of SIGUSR1 and SIGUSR2: they are
 * 10 and 12 on most Linux architectures, 30 and 31 on macOS and the BSDs, and 16 and 17 on
 * Solaris. The SIGUSR1 and SIGUSR2 constants of ext-pcntl are used when available, and are
 * exact. Otherwise the numbers are derived from the operating system family, which is a
 * best-effort guess: Linux on MIPS and PA-RISC (16 and 17) or on Alpha and SPARC (30 and 31)
 * is not told apart from other Linux architectures.
 *
 * @see https://pubs.opengroup.org/onlinepubs/9799919799/utilities/kill.html
 *
 * @internal
 */
final class NativeSignal
{
    /**
     * Returns the platform's number for the given signal.
     */
    public static function toNumber(Signal $signal): int
    {
        return match ($signal) {
            Signal::User1 => defined('SIGUSR1')
                ? SIGUSR1
                : match (PHP_OS_FAMILY) {
                    'Darwin', 'BSD' => 30,
                    'Solaris' => 16,
                    default => 10,
                },
            Signal::User2 => defined('SIGUSR2')
                ? SIGUSR2
                : match (PHP_OS_FAMILY) {
                    'Darwin', 'BSD' => 31,
                    'Solaris' => 17,
                    default => 12,
                },
            default => $signal->value,
        };
    }

    /**
     * Returns the signal with the given platform number, or null if it is not a known signal.
     */
    public static function fromNumber(int $number): null|Signal
    {
        foreach (Signal::cases() as $signal) {
            if (self::toNumber($signal) === $number) {
                return $signal;
            }
        }

        return null;
    }
}
