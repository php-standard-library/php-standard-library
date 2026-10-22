<?php

declare(strict_types=1);

namespace Psl\TCP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Psl\TCP;
use Psl\TCP\Tests\Fixture\CallbackSnapshot;
use Psl\TCP\Tests\Fixture\SlowClosingListener;

use function sort;

final class CompositeListenerTest extends TestCase
{
    /**
     * How many accept() calls wait at once in the close test.
     */
    private const int WAITING_ACCEPTS = 3;

    /**
     * How long a test waits for something that should already have happened, in milliseconds.
     */
    private const int SETTLE_TIMEOUT_MILLISECONDS = 1000;

    public function testAcceptsFromMultipleListeners(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 18_300);
        $listener2 = TCP\listen('127.0.0.1', 18_301);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        Async\concurrently([
            'server' => static function () use ($composite): void {
                $stream1 = $composite->accept();
                $data1 = $stream1->read();
                $stream1->close();

                $stream2 = $composite->accept();
                $data2 = $stream2->read();
                $stream2->close();

                $values = [$data1, $data2];
                sort($values);
                static::assertSame(['from-listener-1', 'from-listener-2'], $values);

                $composite->close();
            },
            'clients' => static function (): void {
                $c1 = TCP\connect('127.0.0.1', 18_300);
                $c1->writeAll('from-listener-1');
                $c1->close();

                $c2 = TCP\connect('127.0.0.1', 18_301);
                $c2->writeAll('from-listener-2');
                $c2->close();
            },
        ]);
    }

    public function testGetLocalAddressReturnsFirst(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 18_302);
        $listener2 = TCP\listen('127.0.0.1', 18_303);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        $address = $composite->getLocalAddress();
        static::assertSame($listener1->getLocalAddress()->host, $address->host);
        static::assertSame($listener1->getLocalAddress()->port, $address->port);

        $composite->close();
    }

    public function testGetLocalAddresses(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 18_310);
        $listener2 = TCP\listen('127.0.0.1', 18_311);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        $addresses = $composite->getLocalAddresses();

        static::assertCount(2, $addresses);
        static::assertSame(18_310, $addresses[0]->port);
        static::assertSame(18_311, $addresses[1]->port);

        $composite->close();
    }

    public function testIsClosed(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 0);
        $listener2 = TCP\listen('127.0.0.1', 0);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        static::assertFalse($composite->isClosed());

        $composite->close();

        static::assertTrue($composite->isClosed());
    }

    public function testCloseClosesAllInnerListeners(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 0);
        $listener2 = TCP\listen('127.0.0.1', 0);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        $composite->close();

        static::assertTrue($listener1->isClosed());
        static::assertTrue($listener2->isClosed());
    }

    public function testAcceptWithCancellation(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 0);
        $listener2 = TCP\listen('127.0.0.1', 0);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

        try {
            Async\run(static function () use ($composite, $token): void {
                $composite->accept($token);
            })->await();

            static::fail('Expected CancelledException');
        } catch (Async\Exception\CancelledException) {
            static::addToAssertionCount(1);
        } finally {
            $composite->close();
        }
    }

    public function testAcceptOnClosedCompositeThrows(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 0);

        $composite = new Network\CompositeListener([$listener1]);
        $composite->close();

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        Async\run(static function () use ($composite): void {
            $composite->accept();
        })->await();
    }

    public function testCloseIsIdempotent(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 0);

        $composite = new Network\CompositeListener([$listener1]);

        $composite->close();
        $composite->close();

        static::assertTrue($composite->isClosed());
    }

    public function testSingleListener(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_304);
        $composite = new Network\CompositeListener([$listener]);

        Async\concurrently([
            'server' => static function () use ($composite): void {
                $stream = $composite->accept();
                $data = $stream->read();
                static::assertSame('single', $data);
                $stream->close();
                $composite->close();
            },
            'client' => static function (): void {
                $stream = TCP\connect('127.0.0.1', 18_304);
                $stream->writeAll('single');
                $stream->close();
            },
        ]);
    }

    public function testCloseStopsAcceptLoop(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_305);
        $composite = new Network\CompositeListener([$listener]);

        $composite->close();

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        Async\run(static function () use ($composite): void {
            $composite->accept();
        })->await();
    }

    public function testCloseClosesReceiver(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_306);
        $composite = new Network\CompositeListener([$listener]);

        $composite->close();

        try {
            Async\run(static function () use ($composite): void {
                $composite->accept();
            })->await();

            static::fail('Expected AlreadyStoppedException');
        } catch (Network\Exception\AlreadyStoppedException) {
            static::addToAssertionCount(1);
        }
    }

    public function testStopTokenCancelsAllLoops(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 18_307);
        $listener2 = TCP\listen('127.0.0.1', 18_308);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        $composite->close();

        static::assertTrue($listener1->isClosed());
        static::assertTrue($listener2->isClosed());
        static::assertTrue($composite->isClosed());
    }

    public function testAcceptReturnsStream(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_309);
        $composite = new Network\CompositeListener([$listener]);

        Async\concurrently([
            'server' => static function () use ($composite): void {
                $stream = $composite->accept();

                $data = $stream->read();
                static::assertSame('verify-return', $data);

                $stream->close();
                $composite->close();
            },
            'client' => static function (): void {
                $stream = TCP\connect('127.0.0.1', 18_309);
                $stream->writeAll('verify-return');
                $stream->close();
            },
        ]);
    }

    public function testWaitGroupDoneCalledOnListenerClose(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_312);
        $composite = new Network\CompositeListener([$listener]);

        $listener->close();

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        Async\run(static function () use ($composite): void {
            $composite->accept();
        })->await();
    }

    public function testCloseTwiceDoesNotDoubleCancel(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $composite = new Network\CompositeListener([$listener]);

        $composite->close();

        static::assertTrue($composite->isClosed());
        static::assertTrue($listener->isClosed());

        $composite->close();

        static::assertTrue($composite->isClosed());
    }

    public function testSenderClosedAfterAllLoopsEnd(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_313);
        $composite = new Network\CompositeListener([$listener]);

        Async\Scheduler::defer(static function () use ($listener): void {
            $listener->close();
        });

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        Async\run(static function () use ($composite): void {
            $composite->accept();
        })->await();
    }

    public function testStopTokenPreventsAcceptAfterClose(): void
    {
        $listener1 = TCP\listen('127.0.0.1', 18_314);
        $listener2 = TCP\listen('127.0.0.1', 18_315);

        $composite = new Network\CompositeListener([$listener1, $listener2]);

        Async\Scheduler::defer(static function () use ($composite): void {
            $composite->close();
        });

        $this->expectException(Network\Exception\AlreadyStoppedException::class);

        Async\run(static function () use ($composite): void {
            $composite->accept();
        })->await();
    }

    public function testReceiverCloseThrowsAlreadyStopped(): void
    {
        $listener = TCP\listen('127.0.0.1', 18_316);
        $composite = new Network\CompositeListener([$listener]);

        $composite->close();

        try {
            Async\run(static function () use ($composite): void {
                $composite->accept();
            })->await();

            static::fail('Expected AlreadyStoppedException');
        } catch (Network\Exception\AlreadyStoppedException $e) {
            static::assertSame('All listeners have been stopped.', $e->getMessage());
        }
    }

    public function testCloseImmediatelyClosesReceiver(): void
    {
        $listener = TCP\listen('127.0.0.1', 0);
        $composite = new Network\CompositeListener([$listener]);

        $composite->close();

        $threw = false;
        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(100));
        try {
            Async\run(static function () use ($composite, $token): void {
                $composite->accept($token);
            })->await();
        } catch (Network\Exception\AlreadyStoppedException) {
            $threw = true;
        } catch (Async\Exception\CancelledException) {
            // If we get here, receiver wasn't closed immediately
            static::fail('accept() should throw AlreadyStoppedException immediately, not wait for timeout.');
        }

        static::assertTrue($threw, 'Expected AlreadyStoppedException from accept() after close().');
    }

    public function testDoubleCloseDoesNotRepeatSideEffects(): void
    {
        $listener = TCP\listen();
        $composite = new Network\CompositeListener([$listener]);

        $composite->close();

        static::assertTrue($composite->isClosed());
        static::assertTrue($listener->isClosed());

        $composite->close();

        static::assertTrue($composite->isClosed());
    }

    public function testStopTokenCancelsAcceptLoopBeforeListenerClose(): void
    {
        $slow = new SlowClosingListener();
        $composite = new Network\CompositeListener([$slow]);

        Async\run(static function () use ($composite): void {
            Async\later();
            $composite->close();
        });

        $threw = false;
        try {
            Async\run(static function () use ($composite): void {
                $token = new Async\TimeoutCancellationToken(Duration::seconds(2));
                $composite->accept($token);
            })->await();
        } catch (Network\Exception\AlreadyStoppedException) {
            $threw = true;
        } catch (Async\Exception\CancelledException) {
            static::fail('Stop token should have cancelled the loop; accept() should not have timed out.');
        }

        static::assertTrue($threw);
    }

    /**
     * Closing a composite listener over live TCP listeners fails every waiting accept() call with
     * `AlreadyStoppedException`, and leaves no event loop callback behind.
     */
    public function testCloseFailsEveryWaitingCallOfLiveListeners(): void
    {
        // A warm-up cycle first, so that whatever the event loop registers once and keeps is in the baseline.
        $this->closeWhileCallsWait();
        $before = new CallbackSnapshot();

        $this->closeWhileCallsWait();

        $after = new CallbackSnapshot();
        static::assertSame([], $after->addedSince($before));
        static::assertSame([], $after->grownSince($before));
    }

    /**
     * Closing a composite listener over live TCP listeners while connections arrive leaves none of them open, wherever
     * each connection was when the composite listener closed, and leaves no event loop callback behind.
     */
    public function testCloseLeavesNoConnectionOpenWhileConnectionsArrive(): void
    {
        // A warm-up cycle first, so that whatever the event loop registers once and keeps is in the baseline.
        $this->closeWhileConnectionsArrive();
        $before = new CallbackSnapshot();

        $this->closeWhileConnectionsArrive();

        $after = new CallbackSnapshot();
        static::assertSame([], $after->addedSince($before));
        static::assertSame([], $after->grownSince($before));
    }

    /**
     * Close a composite listener over two live TCP listeners while accept() calls wait, and check that each of them
     * fails with `AlreadyStoppedException`.
     */
    private function closeWhileCallsWait(): void
    {
        $composite = new Network\CompositeListener([TCP\listen('127.0.0.1', 0), TCP\listen('127.0.0.1', 0)]);

        $calls = [];
        for ($i = 0; $i < self::WAITING_ACCEPTS; $i++) {
            $calls[] = Async\run($composite->accept(...));
        }

        Async\later();
        Async\later();

        $composite->close();

        foreach ($calls as $call) {
            try {
                $call->await(new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS)));

                static::fail('Expected AlreadyStoppedException to be thrown.');
            } catch (Network\Exception\AlreadyStoppedException) {
                static::addToAssertionCount(1);
            }
        }

        // One more turn of the event loop, so that it is quiescent again.
        Async\later();
    }

    /**
     * Close a composite listener over two live TCP listeners right after a client has connected to each, and check
     * that both connections end.
     *
     * One connection goes to the caller, which closes it. The inner accept of the other listener is still in flight
     * when the composite listener closes, and may or may not have taken the other connection into the backlog: either
     * way, that connection must be closed.
     */
    private function closeWhileConnectionsArrive(): void
    {
        $listeners = [TCP\listen('127.0.0.1', 0), TCP\listen('127.0.0.1', 0)];
        $composite = new Network\CompositeListener($listeners);

        $clients = [];
        foreach ($listeners as $listener) {
            $clients[] = TCP\connect('127.0.0.1', $listener->getLocalAddress()->port);
        }

        $accepted = $composite->accept();

        Async\later();

        $composite->close();
        $accepted->close();

        foreach ($clients as $client) {
            $this->assertConnectionEnds($client);
            $client->close();
        }

        // One more turn of the event loop, so that it is quiescent again.
        Async\later();
    }

    /**
     * Assert that the server end of the client's connection is closed: reading from the client ends, at EOF or with
     * a reset, instead of waiting.
     */
    private function assertConnectionEnds(TCP\StreamInterface $client): void
    {
        $token = new Async\TimeoutCancellationToken(Duration::milliseconds(self::SETTLE_TIMEOUT_MILLISECONDS));

        try {
            static::assertSame('', $client->readAll(null, $token));
        } catch (IO\Exception\RuntimeException) {
            // A reset ends the connection too.
            static::addToAssertionCount(1);
        }
    }
}
