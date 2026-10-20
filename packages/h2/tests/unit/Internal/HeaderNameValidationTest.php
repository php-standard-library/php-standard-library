<?php

declare(strict_types=1);

namespace Psl\H2\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\H2\Exception\StreamException;
use Psl\H2\Internal\HeaderValidator;
use Psl\HPACK\Decoder;

final class HeaderNameValidationTest extends TestCase
{
    public function testRejectsAnEmptyNameDecodedFromHpack(): void
    {
        $headers = new Decoder()->decode("\x88\x00\x00\x01x");

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('empty');

        HeaderValidator::validate($headers, true, false, 1);
    }
}
