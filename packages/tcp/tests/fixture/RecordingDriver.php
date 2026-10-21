<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Fixture;

use Closure;
use Override;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Suspension;

use function debug_backtrace;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 * An event loop driver that records the timers registered on it, and passes every call on to the driver it decorates.
 *
 * Each record names the method that registered the timer, the first caller outside the event loop. With
 * $fastForward, timers are passed on with no delay, so code that waits on a long timer runs to completion at once;
 * a negative delay is still passed on, for the driver to reject.
 */
final class RecordingDriver implements Driver
{
    /**
     * @var list<array{id: string, seconds: float, registeredBy: string}>
     */
    public array $timers = [];

    public function __construct(
        private readonly Driver $driver,
        private readonly bool $fastForward = false,
    ) {}

    #[Override]
    public function run(): void
    {
        $this->driver->run();
    }

    #[Override]
    public function stop(): void
    {
        $this->driver->stop();
    }

    #[Override]
    public function getSuspension(): Suspension
    {
        return $this->driver->getSuspension();
    }

    #[Override]
    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    #[Override]
    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }

    #[Override]
    public function defer(Closure $closure): string
    {
        return $this->driver->defer($closure);
    }

    #[Override]
    public function delay(float $delay, Closure $closure): string
    {
        $id = $this->driver->delay($this->fastForward && !($delay < 0.0) ? 0.0 : $delay, $closure);
        $this->record($id, $delay);

        return $id;
    }

    #[Override]
    public function repeat(float $interval, Closure $closure): string
    {
        $id = $this->driver->repeat($this->fastForward && !($interval < 0.0) ? 0.0 : $interval, $closure);
        $this->record($id, $interval);

        return $id;
    }

    #[Override]
    public function onReadable(mixed $stream, Closure $closure): string
    {
        return $this->driver->onReadable($stream, $closure);
    }

    #[Override]
    public function onWritable(mixed $stream, Closure $closure): string
    {
        return $this->driver->onWritable($stream, $closure);
    }

    #[Override]
    public function onSignal(int $signal, Closure $closure): string
    {
        return $this->driver->onSignal($signal, $closure);
    }

    #[Override]
    public function enable(string $callbackId): string
    {
        return $this->driver->enable($callbackId);
    }

    #[Override]
    public function cancel(string $callbackId): void
    {
        $this->driver->cancel($callbackId);
    }

    #[Override]
    public function disable(string $callbackId): string
    {
        return $this->driver->disable($callbackId);
    }

    #[Override]
    public function reference(string $callbackId): string
    {
        return $this->driver->reference($callbackId);
    }

    #[Override]
    public function unreference(string $callbackId): string
    {
        return $this->driver->unreference($callbackId);
    }

    #[Override]
    public function setErrorHandler(null|Closure $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    #[Override]
    public function getErrorHandler(): null|Closure
    {
        return $this->driver->getErrorHandler();
    }

    #[Override]
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    #[Override]
    public function getIdentifiers(): array
    {
        return $this->driver->getIdentifiers();
    }

    #[Override]
    public function getType(string $callbackId): CallbackType
    {
        return $this->driver->getType($callbackId);
    }

    #[Override]
    public function isEnabled(string $callbackId): bool
    {
        return $this->driver->isEnabled($callbackId);
    }

    #[Override]
    public function isReferenced(string $callbackId): bool
    {
        return $this->driver->isReferenced($callbackId);
    }

    #[Override]
    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    private function record(string $id, float $seconds): void
    {
        $registeredBy = '{main}';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? null;
            if ($class === self::class || $class === EventLoop::class) {
                continue;
            }

            $registeredBy = null === $class ? $frame['function'] : $class . '::' . $frame['function'];
            break;
        }

        $this->timers[] = ['id' => $id, 'seconds' => $seconds, 'registeredBy' => $registeredBy];
    }
}
