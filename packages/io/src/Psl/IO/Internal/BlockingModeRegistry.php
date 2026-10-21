<?php

declare(strict_types=1);

namespace Psl\IO\Internal;

use function count;
use function fstat;
use function get_resource_id;
use function is_resource;
use function register_shutdown_function;
use function stream_get_meta_data;
use function stream_set_blocking;

use const PHP_OS_FAMILY;

/**
 * Puts back the blocking mode of streams that handles made nonblocking.
 *
 * `O_NONBLOCK` belongs to the open file description, which every descriptor duplicated from it shares, as do the
 * processes it was inherited from or passed to. A handle that makes the standard input nonblocking makes it
 * nonblocking for the shell that started the process too, and for every other process reading from it. A handle
 * that makes a stream it does not own nonblocking therefore joins this registry before it does, and leaves it when
 * it is released. So does a handle over a duplicate of a standard stream that PSL opened: it owns the duplicate, but
 * not the description behind it.
 *
 * Handles over one description share one entry, keyed by the device and inode `fstat()` reports. The entry records
 * the blocking mode the stream had when the first of them joined, and only the last one to leave puts that mode back,
 * on its own stream: while other handles of the entry are alive they still need it nonblocking. A handle that joins
 * a description another handle already made nonblocking joins the same entry and records nothing. At the end of an
 * orderly shutdown, every entry that still has members is put back, after the shutdown functions registered later,
 * and later releases do nothing.
 *
 * Two descriptions of one file, such as two `fopen()` calls on one FIFO, share a key, because PHP does not expose
 * which description a stream uses: wrapped at the same time, the one released last, or still alive at shutdown, gets
 * the mode recorded for the first one, and those released earlier stay nonblocking. Telling them apart would mean
 * changing the mode of a description other processes may share to see whether the other stream follows. Wrap at most
 * one description of a file at a time. On Windows, and wherever `fstat()` reports no inode, each stream is its own
 * key.
 *
 * Buffer sizes are not put back: PHP does not report them.
 *
 * @internal
 */
final class BlockingModeRegistry
{
    /**
     * @var array<string, array{blocking: bool, members: array<int, resource>}>
     */
    private static array $entries = [];

    /**
     * The key of each live membership.
     *
     * @var array<int, string>
     */
    private static array $keys = [];

    /**
     * Memberships joined for the next handle created over a stream, by the stream's resource id.
     *
     * @var array<int, int>
     */
    private static array $unclaimed = [];

    private static int $lastMembership = 0;

    private static bool $hooked = false;

    /**
     * Join the registry for `$stream`, before making it nonblocking.
     *
     * @param resource $stream
     *
     * @return int The membership, to pass to {@see leave()}.
     */
    public static function join(mixed $stream): int
    {
        $key = self::key($stream);
        if (!isset(self::$entries[$key])) {
            /** @mago-expect analysis:redundant-null-coalesce - `php://temp` streams report no blocking mode. */
            self::$entries[$key] = ['blocking' => stream_get_meta_data($stream)['blocked'] ?? true, 'members' => []];
            self::hook();
        }

        $membership = ++self::$lastMembership;
        self::$entries[$key]['members'][$membership] = $stream;
        self::$keys[$membership] = $key;

        return $membership;
    }

    /**
     * Join the registry for `$stream` on behalf of the next handle created over it, which takes the membership
     * with {@see claim()}.
     *
     * The handle owns the stream, so it would not join on its own; this is for duplicates of standard streams.
     *
     * @param resource $stream
     */
    public static function joinForNextHandle(mixed $stream): void
    {
        self::$unclaimed[get_resource_id($stream)] = self::join($stream);
    }

    /**
     * Take the membership {@see joinForNextHandle()} joined for `$stream`, if any.
     *
     * @param resource $stream
     */
    public static function claim(mixed $stream): null|int
    {
        $id = get_resource_id($stream);
        $membership = self::$unclaimed[$id] ?? null;
        unset(self::$unclaimed[$id]);

        return $membership;
    }

    /**
     * Leave the membership {@see joinForNextHandle()} joined for `$stream` if no handle claimed it.
     *
     * @param resource $stream
     */
    public static function abandon(mixed $stream): void
    {
        $membership = self::claim($stream);
        if (null !== $membership) {
            self::leave($membership);
        }
    }

    /**
     * Leave the registry.
     *
     * When this was the last membership of its entry, the stream it joined with gets the recorded blocking mode back.
     * Leaving twice, or after the shutdown restored the entry, does nothing.
     */
    public static function leave(int $membership): void
    {
        $key = self::$keys[$membership] ?? null;
        if (null === $key) {
            return;
        }

        unset(self::$keys[$membership]);
        $entry = self::$entries[$key];
        $stream = $entry['members'][$membership];
        unset($entry['members'][$membership]);
        if ([] !== $entry['members']) {
            self::$entries[$key] = $entry;

            return;
        }

        unset(self::$entries[$key]);
        if (is_resource($stream)) {
            // Nothing can be done about a failure here: the handle is being released.
            stream_set_blocking($stream, $entry['blocking']);
        }
    }

    /**
     * The number of live memberships.
     *
     * @return int<0, max>
     */
    public static function count(): int
    {
        return count(self::$keys);
    }

    /**
     * Put back the recorded blocking mode on every stream of every entry that still has members, and forget them.
     */
    public static function restoreAll(): void
    {
        $entries = self::$entries;
        self::$entries = [];
        self::$keys = [];
        self::$unclaimed = [];
        self::$hooked = false;

        foreach ($entries as $entry) {
            foreach ($entry['members'] as $stream) {
                if (!is_resource($stream)) {
                    continue;
                }

                stream_set_blocking($stream, $entry['blocking']);
            }
        }
    }

    /**
     * @param resource $stream
     */
    private static function key(mixed $stream): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $stat = @fstat($stream);
            if (false !== $stat && 0 !== $stat['ino']) {
                return $stat['dev'] . ':' . $stat['ino'];
            }
        }

        return '#' . get_resource_id($stream);
    }

    private static function hook(): void
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;
        register_shutdown_function(static function (): void {
            // Queue the restoration again, so that it runs after the shutdown functions registered after this one,
            // which may still use the handles.
            register_shutdown_function(static function (): void {
                self::restoreAll();
            });
        });
    }
}
