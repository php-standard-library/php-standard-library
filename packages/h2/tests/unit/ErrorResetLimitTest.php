<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\DateTime\Duration;
use Psl\H2\Configuration;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\RateLimiter;
use Psl\H2\ServerConnection;
use Psl\H2\Setting;
use Psl\H2\Tests\Fixture\ChunkedHandle;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

use const Psl\H2\MAX_WINDOW_SIZE;

final class ErrorResetLimitTest extends TestCase
{
    public function testDefaultLimitStopsThe101stErrorReset(): void
    {
        $this->assertLimited('malformed', 100, new Configuration());
    }

    #[DataProvider('causes')]
    public function testConfiguredLimitCountsEachPeerErrorReset(string $cause): void
    {
        $this->assertLimited(
            $cause,
            2,
            new Configuration(
                settings: $cause === 'refused' ? [Setting::MaxConcurrentStreams->value => 0] : [],
                rateLimiter: new RateLimiter([RateLimiter::STREAM_ERROR_RESET => [2, Duration::seconds(10)]]),
            ),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function causes(): iterable
    {
        yield 'malformed headers' => ['malformed'];
        yield 'stream window overflow' => ['overflow'];
    }

    private function assertLimited(string $cause, int $limit, Configuration $configuration): void
    {
        $encoder = new Encoder();
        $input = '';
        for ($i = 0; $i <= $limit; $i++) {
            $stream = 1 + (2 * $i);
            $headers = $cause === 'malformed'
                ? [new Header(':method', 'GET')]
                : [
                    new Header(':method', 'GET'),
                    new Header(':scheme', 'https'),
                    new Header(':path', '/'),
                ];
            $input .= Frame\encode(new HeadersFrame($stream, $encoder->encode($headers), false, true)->toRaw());
            if ($cause === 'overflow') {
                $input .= Frame\encode(new WindowUpdateFrame($stream, MAX_WINDOW_SIZE)->toRaw());
            }
        }

        $handle = new ChunkedHandle($input);
        $connection = new ServerConnection($handle, $configuration);
        for ($i = 0; $i <= $limit; $i++) {
            try {
                $connection->readEvent();
                if ($cause === 'overflow') {
                    $connection->readEvent();
                }
            } catch (StreamException) {
                static::assertLessThan($limit, $i);
            } catch (ProtocolException $error) {
                static::assertSame($limit, $i);
                static::assertSame(ErrorCode::EnhanceYourCalm, $error->errorCode);
                static::assertFalse($connection->isConnected());
                $wire = $handle->written;
                $last = null;
                while ($wire !== '') {
                    [$last, $consumed] = Frame\decode($wire);
                    $wire = substr($wire, $consumed);
                }

                static::assertNotNull($last);
                static::assertSame(ErrorCode::EnhanceYourCalm->value, GoAwayFrame::fromRaw($last)->errorCode);
                return;
            }
        }

        static::fail('Expected the reset rate limit to end the connection.');
    }
}
