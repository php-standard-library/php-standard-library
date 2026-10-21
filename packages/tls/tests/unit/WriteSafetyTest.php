<?php

declare(strict_types=1);

namespace Psl\TLS\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\IO;
use Psl\Network;
use Psl\TCP;
use Psl\TLS;

use function count;
use function fclose;
use function fwrite;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function strspn;
use function substr;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

/**
 * A short TLS write leaves a committed record inside OpenSSL that must be sent, unchanged,
 * before any other bytes (SSL_write(3)). PHP reports it as 0 bytes written, so the stream
 * has to remember it itself.
 */
#[AllowMockObjectsWithoutExpectations]
final class WriteSafetyTest extends TestCase
{
    private const int RECORD = 16_384;

    /**
     * @var list<resource>
     */
    private array $resources = [];

    protected function tearDown(): void
    {
        foreach ($this->resources as $resource) {
            @fclose($resource);
        }

        $this->resources = [];
    }

    public function testZeroWriteKeepsTheRecordAndSendsItBeforeOtherBytes(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return $calls === ['AAA'] ? 0 : strlen($bytes);
        });
        $stream = $this->wrap($inner);

        // The record is reported as accepted although the socket took nothing.
        static::assertSame(3, $stream->tryWrite('AAA'));
        // It goes out first, then the new bytes.
        static::assertSame(3, $stream->tryWrite('BBB'));
        static::assertSame(['AAA', 'AAA', 'BBB'], $calls);
    }

    public function testPendingRecordIsBoundedToOneTlsRecord(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = strlen($bytes);

            return $calls === [20_000] ? 0 : strlen($bytes);
        });
        $stream = $this->wrap($inner);

        static::assertSame(self::RECORD, $stream->tryWrite(str_repeat('A', 20_000)));
        static::assertSame(1, $stream->tryWrite('B'));
        static::assertSame([20_000, self::RECORD, 1], $calls);
    }

    public function testPartialWriteKeepsTheUnwrittenHeadAsPending(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return $bytes === 'AAABBB' ? 3 : strlen($bytes);
        });
        $stream = $this->wrap($inner);

        static::assertSame(6, $stream->tryWrite('AAABBB'));
        static::assertSame(3, $stream->tryWrite('CCC'));
        static::assertSame(['AAABBB', 'BBB', 'CCC'], $calls);
    }

    public function testTryWriteReturnsZeroWhileTheRecordCannotBeSent(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return 0;
        });
        $stream = $this->wrap($inner);

        static::assertSame(3, $stream->tryWrite('AAA'));
        static::assertSame(0, $stream->tryWrite('BBB'));
        static::assertSame(0, $stream->tryWrite('BBB'));
        static::assertSame(['AAA', 'AAA', 'AAA'], $calls);
    }

    public function testEmptyWritesDoNothing(): void
    {
        $inner = $this->createInner(static fn(string $bytes): int => strlen($bytes));
        $inner->expects(static::never())->method('tryWrite');
        $stream = $this->wrap($inner);

        static::assertSame(0, $stream->tryWrite(''));
        static::assertSame(0, $stream->write(''));
        $stream->writeAll('');
    }

    public function testWriteSendsThePendingRecordBeforeReturning(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return $calls === ['AAA'] ? 0 : strlen($bytes);
        });
        $stream = $this->wrap($inner);

        static::assertSame(3, $stream->write('AAA'));
        // The retry happened inside write(); nothing is pending for the next write.
        static::assertSame(1, $stream->tryWrite('B'));
        static::assertSame(['AAA', 'AAA', 'B'], $calls);
    }

    public function testWriteAllSendsEveryByteInOrder(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return 2;
        });
        $stream = $this->wrap($inner);

        $stream->writeAll('ABCDEF');
        static::assertSame(['ABCDEF', 'CDEF', 'EF'], $calls);
    }

    public function testTryWriteYieldsToAnActiveWrite(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            // Two failed attempts make write() wait for writability before succeeding.
            return $calls === ['AAA'] || $calls === ['AAA', 'AAA'] ? 0 : strlen($bytes);
        });
        $stream = $this->wrap($inner);

        Async\concurrently([
            static fn(): int => $stream->write('AAA'),
            static function () use ($stream): void {
                static::assertSame(0, $stream->tryWrite('BBB'));
            },
        ]);

        static::assertSame(['AAA', 'AAA', 'AAA'], $calls);
        static::assertSame(3, $stream->tryWrite('BBB'));
    }

    public function testCancelledWriteKeepsTheRecordForTheNextWrite(): void
    {
        $calls = [];
        $sendable = false;
        $inner = $this->createInner(static function (string $bytes) use (&$calls, &$sendable): int {
            $calls[] = $bytes;

            return $sendable ? strlen($bytes) : 0;
        }, writable: false);
        $stream = $this->wrap($inner);

        try {
            $stream->writeAll('AAA', new Async\TimeoutCancellationToken(Duration::milliseconds(20)));
            static::fail('Expected the write to be cancelled.');
        } catch (Async\Exception\CancelledException) {
            static::assertFalse($stream->isClosed());
        }

        $sendable = true;
        $stream->writeAll('BBB');
        static::assertSame('AAA', $calls[0]);
        static::assertSame(['AAA', 'BBB'], [$calls[count($calls) - 2], $calls[count($calls) - 1]]);
    }

    public function testWriteFailsInsteadOfSpinningWhenAWritableSocketAcceptsNothing(): void
    {
        $calls = 0;
        $inner = $this->createInner(static function () use (&$calls): int {
            $calls++;

            return 0;
        });
        $stream = $this->wrap($inner);

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('accepted no data');

        try {
            $stream->write('AAA', new Async\TimeoutCancellationToken(Duration::seconds(5)));
        } finally {
            // The chunk itself, one flush attempt before waiting for writability, and one retry
            // after it: no busy loop until the deadline.
            static::assertSame(3, $calls);
        }
    }

    public function testLiveWriteToAPeerThatWentAwayFailsPromptly(): void
    {
        [$client, $server] = $this->connectPair();
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            $client->close();

            try {
                // Keep writing until the kernel notices the peer is gone; this must not spin until the deadline.
                for ($i = 0; $i < 64; $i++) {
                    $server->writeAll(str_repeat('x', 1_048_576), $deadline);
                }

                static::fail('Expected the write to fail once the peer closed the connection.');
            } catch (IO\Exception\RuntimeException $exception) {
                static::assertStringContainsString('accepted no data', $exception->getMessage());
            }
        } finally {
            $server->close();
        }
    }

    public function testShutdownRefusesToCutAPendingRecord(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return 0;
        });
        $inner->expects(static::never())->method('shutdown');
        $stream = $this->wrap($inner);

        static::assertSame(3, $stream->tryWrite('AAA'));

        $this->expectException(IO\Exception\RuntimeException::class);
        $this->expectExceptionMessage('TLS record is still pending');

        $stream->shutdown();
    }

    public function testShutdownSendsThePendingRecordFirst(): void
    {
        $calls = [];
        $inner = $this->createInner(static function (string $bytes) use (&$calls): int {
            $calls[] = $bytes;

            return $calls === ['AAA'] ? 0 : strlen($bytes);
        });
        $inner->expects(static::once())->method('shutdown');
        $stream = $this->wrap($inner);

        static::assertSame(3, $stream->tryWrite('AAA'));
        $stream->shutdown();
        static::assertSame(['AAA', 'AAA'], $calls);
    }

    public function testLiveTryWriteBackpressureThenOtherBytesKeepsTheStreamIntact(): void
    {
        [$client, $server] = $this->connectPair();
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(10));

        try {
            $accepted = $this->fillWriteBuffer($client);
            $second = str_repeat('B', 20_000);

            [, $received] = Async\concurrently([
                static fn() => $client->writeAll($second, $deadline),
                static fn(): string => $server->readFixedSize($accepted + strlen($second), $deadline),
            ]);

            static::assertSame($accepted, strspn($received, 'A'));
            static::assertSame($second, substr($received, $accepted));
        } finally {
            $client->close();
            $server->close();
        }
    }

    public function testLiveCancelledWriteAllThenOtherBytesKeepsTheStreamIntact(): void
    {
        [$client, $server] = $this->connectPair();
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(10));

        try {
            $accepted = $this->fillWriteBuffer($client);

            try {
                $client->writeAll(
                    str_repeat('A', 4 * 1_048_576),
                    new Async\TimeoutCancellationToken(Duration::milliseconds(20)),
                );
                static::fail('Expected the blocked write to be cancelled.');
            } catch (Async\Exception\CancelledException) {
                static::assertFalse($client->isClosed());
            }

            $second = str_repeat('B', 20_000);
            [, $received] = Async\concurrently([
                static function () use ($client, $second, $deadline): void {
                    $client->writeAll($second, $deadline);
                    $client->shutdown();
                },
                static fn(): string => $server->readAll(cancellation: $deadline),
            ]);

            $sentA = strspn($received, 'A');
            static::assertGreaterThanOrEqual($accepted, $sentA);
            static::assertSame($second, substr($received, $sentA));
        } finally {
            $client->close();
            $server->close();
        }
    }

    /**
     * @return array{TLS\StreamInterface, TLS\StreamInterface}
     */
    private function connectPair(): array
    {
        $listener = new TLS\Listener(
            TCP\listen('127.0.0.1', 0),
            TLS\ServerConfiguration::create(TLS\Certificate::create(
                __DIR__ . '/../../fixture/certs/server.crt',
                __DIR__ . '/../../fixture/certs/server.key',
            )),
        );
        $deadline = new Async\TimeoutCancellationToken(Duration::seconds(5));

        try {
            [$server, $client] = Async\concurrently([
                static fn(): TLS\StreamInterface => $listener->accept($deadline),
                static fn(): TLS\StreamInterface => TLS\connect(
                    '127.0.0.1',
                    $listener->getLocalAddress()->port,
                    TLS\ClientConfiguration::default()->withPeerVerification(false),
                    $deadline,
                ),
            ]);

            return [$client, $server];
        } finally {
            $listener->close();
        }
    }

    /**
     * Write 16 KiB chunks until the stream accepts nothing more.
     *
     * @return int<0, max> The number of bytes accepted.
     */
    private function fillWriteBuffer(TLS\StreamInterface $stream): int
    {
        $chunk = str_repeat('A', self::RECORD);
        $accepted = 0;
        while ($accepted < (64 * 1_048_576)) {
            $written = $stream->tryWrite($chunk);
            if ($written === 0) {
                return $accepted;
            }

            $accepted += $written;
        }

        static::fail('The TLS socket did not reach backpressure after 64 MiB.');
    }

    /**
     * @param (callable(string): int) $tryWrite
     * @param bool $writable Whether the underlying resource reports as writable.
     */
    private function createInner(callable $tryWrite, bool $writable = true): Network\StreamInterface&MockObject
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        static::assertNotFalse($pair);
        [$resource, $peer] = $pair;
        $this->resources[] = $resource;
        $this->resources[] = $peer;
        stream_set_blocking($resource, false);
        if (!$writable) {
            // Fill the kernel buffer so that the resource is not writable until the peer reads.
            do {
                $written = @fwrite($resource, str_repeat('x', 65_536));
            } while ($written !== false && $written > 0);
        }

        $inner = $this->createMock(Network\StreamInterface::class);
        $inner->method('getLocalAddress')->willReturn(Network\Address::tcp());
        $inner->method('getPeerAddress')->willReturn(Network\Address::tcp());
        $inner->method('getStream')->willReturn($resource);
        $inner->method('tryWrite')->willReturnCallback($tryWrite);
        $closed = false;
        $inner
            ->method('close')
            ->willReturnCallback(static function () use (&$closed): void {
                $closed = true;
            });
        $inner
            ->method('isClosed')
            ->willReturnCallback(static function () use (&$closed): bool {
                return $closed;
            });

        return $inner;
    }

    private function wrap(Network\StreamInterface $inner): TLS\StreamInterface
    {
        return new TLS\Internal\Stream(
            $inner,
            new TLS\ConnectionState(TLS\Version::Tls13, 'TLS_AES_256_GCM_SHA384', 256, 'TLSv1.3', null, null, null),
        );
    }
}
