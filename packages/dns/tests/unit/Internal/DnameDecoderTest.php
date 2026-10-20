<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\DNS\Exception\ProtocolException;
use Psl\DNS\Internal\Decoder;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\DNAMERecord;
use Psl\DNS\Record\RecordType;

use function pack;
use function strlen;

final class DnameDecoderTest extends TestCase
{
    public function testDecodesDnameTarget(): void
    {
        $target = Encoder::encodeName('other.example');
        $packet =
            pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0)
            . Encoder::encodeName('victim.example')
            . pack('nnNn', 39, 1, 300, strlen($target))
            . $target;

        $record = Decoder::decode($packet)->answers[0];
        static::assertInstanceOf(DNAMERecord::class, $record);
        static::assertSame(RecordType::DNAME, $record->kind);
        static::assertSame('victim.example', $record->name);
        static::assertSame('other.example', $record->target);
    }

    public function testRejectsRecordWhoseClassCannotBePreserved(): void
    {
        $packet =
            pack('nnnnnn', 1, 0x8000, 0, 1, 0, 0)
            . Encoder::encodeName('victim.example')
            . pack('nnNn', 1, 3, 300, 4)
            . "\xc0\x00\x02\x01";

        $this->expectException(ProtocolException::class);
        Decoder::decode($packet);
    }
}
