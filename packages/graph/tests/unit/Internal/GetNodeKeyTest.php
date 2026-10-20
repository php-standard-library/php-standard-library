<?php

declare(strict_types=1);

namespace Psl\Graph\Tests\Unit\Internal;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Psl\Graph;
use Psl\Graph\Internal;
use stdClass;

use function hex2bin;
use function ini_get;
use function ini_set;
use function restore_error_handler;
use function set_error_handler;
use function unpack;

use const NAN;

final class GetNodeKeyTest extends TestCase
{
    public function testObject(): void
    {
        $obj = new stdClass();
        $key = Internal\get_node_key($obj);

        static::assertStringStartsWith('o:', $key);
    }

    public function testArray(): void
    {
        $key = Internal\get_node_key(['a', 'b']);

        static::assertStringStartsWith('a:', $key);
    }

    public function testResource(): void
    {
        $key = Internal\get_node_key(STDIN);

        static::assertStringStartsWith('r:', $key);
    }

    public function testBoolTrue(): void
    {
        static::assertSame('b:1', Internal\get_node_key(true));
    }

    public function testBoolFalse(): void
    {
        static::assertSame('b:0', Internal\get_node_key(false));
    }

    public function testInt(): void
    {
        static::assertSame('i:42', Internal\get_node_key(42));
    }

    public function testFloat(): void
    {
        static::assertSame('f:40091eb851eb851f', Internal\get_node_key(3.14));
        static::assertSame(Internal\get_node_key(0.1 + 0.2), Internal\get_node_key(0.1 + 0.2));
    }

    public function testDistinctFloatsHaveDistinctKeys(): void
    {
        static::assertNotSame(Internal\get_node_key(0.1 + 0.2), Internal\get_node_key(0.3));
        static::assertNotSame(Internal\get_node_key(1.0), Internal\get_node_key(1.000_000_000_000_01));
        static::assertNotSame(Internal\get_node_key(1.0), Internal\get_node_key(1));
    }

    public function testNanFloatDoesNotWarn(): void
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            $key = Internal\get_node_key(NAN);
        } finally {
            restore_error_handler();
        }

        static::assertSame('f:nan', $key);
        // every NAN shares one key, whatever its sign and payload bits.
        static::assertSame($key, Internal\get_node_key(unpack('E', hex2bin('fff8000000000001'))[1]));
    }

    /**
     * @mago-expect lint:no-ini-set(4)
     */
    public function testFloatKeysDoNotDependOnIniSettings(): void
    {
        $precision = ini_get('precision');
        $serializePrecision = ini_get('serialize_precision');
        ini_set('precision', '5');
        ini_set('serialize_precision', '5');

        try {
            static::assertNotSame(Internal\get_node_key(0.1 + 0.2), Internal\get_node_key(0.3));
            static::assertNotSame(Internal\get_node_key(1.0), Internal\get_node_key(1.000_01));
        } finally {
            ini_set('precision', (string) $precision);
            ini_set('serialize_precision', (string) $serializePrecision);
        }
    }

    public function testDistinctFloatNodes(): void
    {
        $graph = Graph\add_node(Graph\add_node(Graph\directed(), 0.1 + 0.2), 0.3);

        static::assertSame([0.1 + 0.2, 0.3], $graph->getNodes());
    }

    public function testString(): void
    {
        static::assertSame('s:hello', Internal\get_node_key('hello'));
    }

    public function testNull(): void
    {
        static::assertSame('n:null', Internal\get_node_key(null));
    }
}
