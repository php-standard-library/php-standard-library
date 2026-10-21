<?php

declare(strict_types=1);

namespace Psl\HTTP\Client\Tests\Unit\Internal\H2;

use PHPUnit\Framework\TestCase;
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\H2;
use Psl\HPACK\Header;
use Psl\HTTP\Client\ClientConfiguration;
use Psl\HTTP\Client\Connection\ConnectionMetadata;
use Psl\HTTP\Client\Exception\ProtocolException;
use Psl\HTTP\Client\H2ClientConfiguration;
use Psl\HTTP\Client\Internal\H2\H2Connection;
use Psl\HTTP\Client\Internal\H2\H2Session;
use Psl\HTTP\Client\Internal\H2\H2Stream;
use Psl\HTTP\Message\Request;
use Psl\IO;
use Psl\Network;
use Psl\URL;
use ReflectionProperty;

use function str_repeat;
use function strlen;

final class ResponseLimitTest extends TestCase
{
    public function testLimitIsEnforcedBeforeTheBodyIsRead(): void
    {
        $stream = new H2Stream(1, null, 4);
        $stream->handleHeaders([new Header(':status', '200')], false);
        $stream->handleData('1234', false);
        $stream->handleData('5', false);

        static::assertInstanceOf(ProtocolException::class, $stream->getError());
        static::assertSame('', new ReflectionProperty($stream, 'bodyBuffer')->getValue($stream));
        $this->expectException(ProtocolException::class);
        $stream->tryReadBody();
    }

    public function testLimitCountsConsumedBytes(): void
    {
        $stream = new H2Stream(1, null, 4);
        $stream->handleHeaders([new Header(':status', '200')], false);
        $stream->handleData('1234', false);
        static::assertSame('1234', $stream->tryReadBody());
        $stream->handleData('5', true);

        static::assertInstanceOf(ProtocolException::class, $stream->getError());
    }

    public function testZeroLimitMeansUnlimited(): void
    {
        $stream = new H2Stream(1, null, 0);
        $stream->handleHeaders([new Header(':status', '200')], false);
        $stream->handleData(str_repeat('x', 1_000_000), true);

        static::assertNull($stream->getError());
        static::assertSame(1_000_000, strlen($stream->tryReadBody()));
    }

    public function testMalformedStatusFailsTheStream(): void
    {
        foreach (['2000', '20', '0', 'abc', '099', ''] as $value) {
            $stream = new H2Stream(1);
            $stream->handleHeaders([new Header(':status', $value)], false);
            static::assertInstanceOf(
                ProtocolException::class,
                $stream->getError(),
                'Expected :status "' . $value . '" to fail the stream.',
            );
        }
    }

    public function testInterimResponsesAreCappedAtSixteen(): void
    {
        $stream = new H2Stream(1);
        for ($i = 0; $i < 16; $i++) {
            $stream->handleHeaders([new Header(':status', '103')], false);
        }

        static::assertNull($stream->getError());
        static::assertCount(16, $stream->getInformationalResponses());

        $stream->handleHeaders([new Header(':status', '103')], false);
        static::assertInstanceOf(ProtocolException::class, $stream->getError());
    }

    public function testOversizedUnreadResponseSendsCancel(): void
    {
        [$client, $server] = Network\socket_pair();
        $session = new H2Session($client, new H2ClientConfiguration());
        $connection = new H2Connection(
            $session,
            new ConnectionMetadata($client->getLocalAddress(), $client->getPeerAddress()),
        );
        $cancellation = new Async\TimeoutCancellationToken(Duration::seconds(1));
        $serverTask = Async\run(static function () use ($server, $cancellation): H2\ErrorCode {
            $h2 = new H2\ServerConnection($server);
            $h2->readClientPreface();
            $h2->initialize();
            while (true) {
                foreach ($h2->readEvent($cancellation) as $event) {
                    if ($event instanceof H2\Event\HeadersReceived) {
                        $h2->sendHeadersWithStatus($event->streamId, '200', []);
                        $h2->sendData($event->streamId, '12345');
                    } elseif ($event instanceof H2\Event\StreamReset) {
                        return $event->errorCode;
                    }
                }
            }
        });
        $serverTask->ignore();
        $body = null;

        try {
            $transaction = $connection->exchange(
                new Request(method: 'GET', url: URL\parse('http://example.com/')),
                new ClientConfiguration(maxResponseBodySize: 4),
                $cancellation,
            );
            $body = $transaction->response->body;
            static::assertSame(H2\ErrorCode::Cancel, $serverTask->await());
            static::assertFalse($session->isClosed());
            static::assertNotNull($body);
            $this->expectException(IO\Exception\RuntimeException::class);
            $body->read();
        } finally {
            if ($body instanceof IO\CloseHandleInterface) {
                $body->close();
            }

            $client->close();
            $server->close();
        }
    }
}
