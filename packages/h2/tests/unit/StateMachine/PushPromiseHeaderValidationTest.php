<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Internal\StateMachine;
use Psl\HPACK\Encoder;
use Psl\HPACK\Header;

use function substr;

final class PushPromiseHeaderValidationTest extends TestCase
{
    /**
     * @param list<Header> $headers
     */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidPromisedRequests(array $headers, bool $continued): void
    {
        $sm = new StateMachine(true);
        $sm->initialize();
        $sm->sendHeadersEncoded(1, [
            new Header(':method', 'GET'),
            new Header(':scheme', 'https'),
            new Header(':path', '/'),
        ]);
        $block = new Encoder()->encode($headers);

        try {
            if ($continued) {
                $sm->receive(new PushPromiseFrame(1, 2, substr($block, 0, 1), false)->toRaw());
                $sm->receive(new ContinuationFrame(1, substr($block, 1), true)->toRaw());
            } else {
                $sm->receive(new PushPromiseFrame(1, 2, $block, true)->toRaw());
            }
        } catch (StreamException $exception) {
            static::assertSame(2, $exception->streamId);
            static::assertSame(ErrorCode::ProtocolError, $exception->errorCode);
            static::assertFalse($sm->shutdown);

            return;
        }

        static::fail('Expected the promised stream to fail validation.');
    }

    /**
     * @return iterable<string, array{list<Header>, bool}>
     */
    public static function invalidRequests(): iterable
    {
        foreach ([false, true] as $continued) {
            $suffix = $continued ? ' continued' : '';
            yield 'missing scheme' . $suffix => [[new Header(':method', 'GET'), new Header(':path', '/')], $continued];
            yield 'response pseudo-header' . $suffix => [[new Header(':status', '200')], $continued];
            yield 'connection field' . $suffix => [
                [
                    new Header(':method', 'GET'),
                    new Header(':scheme', 'https'),
                    new Header(':path', '/'),
                    new Header('connection', 'close'),
                ],
                $continued,
            ];
        }
    }
}
