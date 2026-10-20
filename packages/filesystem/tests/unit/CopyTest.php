<?php

declare(strict_types=1);

namespace Psl\Filesystem\Tests\Unit;

use Psl\File;
use Psl\Filesystem;
use Psl\Str;

final class CopyTest extends AbstractFilesystemTestCase
{
    protected string $function = 'copy';

    public function testCopy(): void
    {
        $textFile = Str\join([$this->directory, 'hello.txt'], Filesystem\SEPARATOR);
        $markdownFile = Str\join([$this->directory, 'hello.md'], Filesystem\SEPARATOR);

        File\write($textFile, 'Hello, World!');
        Filesystem\copy($textFile, $markdownFile);

        static::assertSame('Hello, World!', File\read($markdownFile));
    }

    public function testCopyOverwrite(): void
    {
        $textFile = Str\join([$this->directory, 'hello.txt'], Filesystem\SEPARATOR);
        $markdownFile = Str\join([$this->directory, 'hello.md'], Filesystem\SEPARATOR);

        File\write($textFile, 'Hello, World!');
        File\write($markdownFile, '# Hello, World!');
        Filesystem\copy($textFile, $markdownFile);

        static::assertSame('Hello, World!', File\read($textFile));
        static::assertSame('# Hello, World!', File\read($markdownFile));

        Filesystem\copy($textFile, $markdownFile, true);

        static::assertSame('Hello, World!', File\read($textFile));
        static::assertSame('Hello, World!', File\read($markdownFile));
    }

    public function testCopyThrowsForMissingSourceEvenIfDestinationExists(): void
    {
        $missingFile = Str\join([$this->directory, 'missing.txt'], Filesystem\SEPARATOR);
        $markdownFile = Str\join([$this->directory, 'hello.md'], Filesystem\SEPARATOR);

        File\write($markdownFile, '# Hello, World!');

        $this->expectException(Filesystem\Exception\NotFoundException::class);
        $this->expectExceptionMessage('File "' . $missingFile . '" is not found.');

        Filesystem\copy($missingFile, $markdownFile);
    }

    public function testCopyExecutableBits(): void
    {
        $shellFile = Str\join([$this->directory, 'hello.sh'], Filesystem\SEPARATOR);

        Filesystem\create_file($shellFile);
        Filesystem\change_permissions($shellFile, 0o557);

        static::assertTrue(Filesystem\is_executable($shellFile));

        $shellFileCopy = Str\join([$this->directory, 'hey.sh'], Filesystem\SEPARATOR);

        Filesystem\copy($shellFile, $shellFileCopy);

        static::assertTrue(Filesystem\is_executable($shellFileCopy));
    }
}
