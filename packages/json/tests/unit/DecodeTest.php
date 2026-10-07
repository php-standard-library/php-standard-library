<?php

declare(strict_types=1);

namespace Psl\Json;

use PHPUnit\Framework\TestCase;
use Psl\Json;

final class DecodeTest extends TestCase
{
    public function testDecode(): void
    {
        $actual = Json\decode('{
            "name": "php-standard-library/php-standard-library",
            "type": "library",
            "description": "PHP Standard Library.",
            "keywords": ["php", "std", "stdlib", "utility", "psl"],
            "license": "MIT"
        }');

        static::assertSame(
            [
                'name' => 'php-standard-library/php-standard-library',
                'type' => 'library',
                'description' => 'PHP Standard Library.',
                'keywords' => ['php', 'std', 'stdlib', 'utility', 'psl'],
                'license' => 'MIT',
            ],
            $actual,
        );
    }

    public function testDecodeThrowsForInvalidSyntax(): void
    {
        $this->expectException(Json\Exception\DecodeException::class);
        $this->expectExceptionMessage('The decoded property name is invalid');

        Json\decode('{"\u0000": 1}', false);
    }

    public function testDecodeMalformedUTF8(): void
    {
        $this->expectException(Json\Exception\DecodeException::class);
        $this->expectExceptionMessage('Malformed UTF-8 characters, possibly incorrectly encoded');

        Json\decode("\"\xC1\xBF\"");
    }
}
