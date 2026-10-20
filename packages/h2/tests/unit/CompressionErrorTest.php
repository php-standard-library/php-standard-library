<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ClientConnection;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\ServerConnection;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Exception\ExceptionInterface;
use Psl\HPACK\Header;

use function strlen;
use function substr;

final class CompressionErrorTest extends TestCase
{
    #[DataProvider('invalidFieldBlocks')]
    public function testInvalidFieldBlockClosesConnection(bool $push): void
    {
        $raw = $push
            ? new PushPromiseFrame(1, 2, "\x80", true)->toRaw()
            : new HeadersFrame(1, "\x80", false, true)->toRaw();
        $handle = new ChunkedHandle(Frame\encode($raw));
        if ($push) {
            $connection = new ClientConnection($handle);
            $connection->sendHeaders(1, [
                new Header(':method', 'GET'),
                new Header(':scheme', 'https'),
                new Header(':path', '/'),
            ]);
        } else {
            $connection = new ServerConnection($handle);
        }

        $offset = strlen($handle->written);
        try {
            $connection->readEvent();
            static::fail('Expected a compression error.');
        } catch (ProtocolException $error) {
            static::assertSame(ErrorCode::CompressionError, $error->errorCode);
            static::assertInstanceOf(ExceptionInterface::class, $error->getPrevious());
            static::assertFalse($connection->isConnected());
            [$frame] = Frame\decode(substr($handle->written, $offset));
            static::assertSame(ErrorCode::CompressionError->value, GoAwayFrame::fromRaw($frame)->errorCode);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function invalidFieldBlocks(): iterable
    {
        yield 'HEADERS' => [false];
        yield 'PUSH_PROMISE' => [true];
    }
}
