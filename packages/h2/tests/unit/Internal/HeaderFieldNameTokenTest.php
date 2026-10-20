<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\H2\ErrorCode;
use Psl\H2\Exception\StreamException;
use Psl\H2\Internal\HeaderValidator;
use Psl\HPACK\Header;

final class HeaderFieldNameTokenTest extends TestCase
{
    #[DataProvider('invalidFieldNames')]
    public function testRejectsNonTokenBytesAsAStreamError(string $name): void
    {
        try {
            HeaderValidator::validate([new Header(':status', '200'), new Header($name, 'value')], true, false, 7);
            static::fail('Expected a stream error for an invalid field name.');
        } catch (StreamException $error) {
            static::assertSame(7, $error->streamId);
            static::assertSame(ErrorCode::ProtocolError, $error->errorCode);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFieldNames(): iterable
    {
        yield 'space' => ['x name'];
        yield 'tab' => ["x\tname"];
        yield 'nul' => ["x\0name"];
        yield 'carriage return' => ["x\rname"];
        yield 'line feed' => ["x\nname"];
        yield 'control byte' => ["x\x1fname"];
        yield 'delete' => ["x\x7fname"];
        yield 'non-ASCII byte' => ["x\x80name"];
        yield 'Unicode' => ['x-é'];
        yield 'internal colon' => ['x:name'];
        yield 'slash' => ['x/name'];
        yield 'parentheses' => ['x(name)'];
        yield 'comma' => ['x,name'];
        yield 'equals' => ['x=name'];
        yield 'uppercase' => ['X-name'];
    }

    public function testAcceptsEveryLowercaseTokenCharacter(): void
    {
        HeaderValidator::validate(
            [
                new Header(':status', '200'),
                new Header("!#$%&'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyz", 'value'),
            ],
            true,
            false,
            1,
        );

        static::assertTrue(true);
    }
}
