<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Filesystem;
use Psl\IO;

$base = sys_get_temp_dir() . '/psl-fs-create-' . uniqid();

// Create a file (parent directories are created automatically)
$file = $base . '/nested/file.txt';
Filesystem\create_file($file);
IO\write_line('File created: %s', Filesystem\is_file($file) ? 'yes' : 'no');

// Create a directory (recursive by default)
$dir = $base . '/another/nested/dir';
Filesystem\create_directory($dir);
Filesystem\create_directory($dir, 0o755);
IO\write_line('Directory created: %s', Filesystem\is_directory($dir) ? 'yes' : 'no');

// Create a private temporary file without reusing an existing name.
$tmp = Filesystem\create_temporary_file();
IO\write_line('Temp file: %s', $tmp);

$tmp2 = Filesystem\create_temporary_file($base, prefix: 'app_');
IO\write_line('Prefixed temp file: %s', Filesystem\get_basename($tmp2));

// Clean up
Filesystem\delete_file($tmp);
Filesystem\delete_file($tmp2);
Filesystem\delete_directory($base, recursive: true);
