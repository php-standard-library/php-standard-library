<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Fixture;

use Revolt\EventLoop;

use function array_diff;
use function array_values;
use function ksort;

/**
 * The callbacks registered with the event loop at one point in time: their identifiers, and how many there are of
 * each type and state.
 *
 * A test takes one snapshot after a warm-up cycle and another after the code under test has run, and checks that
 * the second holds no callback the first does not: zero net growth, measured through `EventLoop::getDriver()`. The
 * counts by type and state stand in for the driver's former `getInfo()`, which Revolt 1.x no longer has.
 */
final readonly class CallbackSnapshot
{
    /**
     * The identifiers of the registered callbacks.
     *
     * @var list<string>
     */
    public array $identifiers;

    /**
     * How many callbacks there are of each type and state, keyed like "Delay, enabled, referenced".
     *
     * @var array<string, int<1, max>>
     */
    public array $counts;

    /**
     * Take a snapshot of the callbacks registered with the event loop's driver now.
     */
    public function __construct()
    {
        $driver = EventLoop::getDriver();
        $identifiers = $driver->getIdentifiers();
        $counts = [];
        foreach ($identifiers as $identifier) {
            $kind =
                $driver->getType($identifier)->name
                . ($driver->isEnabled($identifier) ? ', enabled' : ', disabled')
                . ($driver->isReferenced($identifier) ? ', referenced' : ', unreferenced');

            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        ksort($counts);

        $this->identifiers = $identifiers;
        $this->counts = $counts;
    }

    /**
     * The identifiers of the callbacks in this snapshot that the earlier one does not hold.
     *
     * @return list<string>
     */
    public function addedSince(self $earlier): array
    {
        return array_values(array_diff($this->identifiers, $earlier->identifiers));
    }

    /**
     * The types and states of which this snapshot holds more callbacks than the earlier one, each with both counts.
     *
     * @return array<string, array{int<0, max>, int<1, max>}>
     */
    public function grownSince(self $earlier): array
    {
        $grown = [];
        foreach ($this->counts as $kind => $count) {
            $before = $earlier->counts[$kind] ?? 0;
            if ($count > $before) {
                $grown[$kind] = [$before, $count];
            }
        }

        return $grown;
    }
}
