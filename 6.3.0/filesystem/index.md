# Filesystem

The `Filesystem` component provides type-safe functions for common file system operations. It replaces PHP's procedural file functions with proper error handling through exceptions, clear parameter types, and consistent behavior.

Unlike the `File` component (which deals with reading and writing file contents through handles), `Filesystem` focuses on managing files and directories themselves: checking existence, creating, copying, deleting, and inspecting metadata.

## Checking Existence and Type

```php
use Psl\Filesystem;
use Psl\IO;

// Create temp directory and file for demonstration
$dir = sys_get_temp_dir() . '/psl-fs-check-' . uniqid();
Filesystem\create_directory($dir);
$file = $dir . '/test.txt';
Filesystem\create_file($file);
$link = $dir . '/test-link';
Filesystem\create_symbolic_link($file, $link);

IO\write_line('exists(file):       %s', Filesystem\exists($file) ? 'true' : 'false');
IO\write_line('is_file(file):      %s', Filesystem\is_file($file) ? 'true' : 'false');
IO\write_line('is_directory(dir):  %s', Filesystem\is_directory($dir) ? 'true' : 'false');
IO\write_line('is_symbolic_link:   %s', Filesystem\is_symbolic_link($link) ? 'true' : 'false');
IO\write_line('is_readable(file):  %s', Filesystem\is_readable($file) ? 'true' : 'false');
IO\write_line('is_writable(file):  %s', Filesystem\is_writable($file) ? 'true' : 'false');

Filesystem\delete_file($link);
Filesystem\delete_file($file);
Filesystem\delete_directory($dir);
```

## Creating Files and Directories

```php
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

// Create a temporary file
$tmp = Filesystem\create_temporary_file();
IO\write_line('Temp file: %s', $tmp);

$tmp2 = Filesystem\create_temporary_file($base, prefix: 'app_');
IO\write_line('Prefixed temp file: %s', Filesystem\get_basename($tmp2));

// Clean up
Filesystem\delete_file($tmp);
Filesystem\delete_file($tmp2);
Filesystem\delete_directory($base, recursive: true);
```

## Copying and Deleting

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$base = sys_get_temp_dir() . '/psl-fs-copy-' . uniqid();
Filesystem\create_directory($base);

$src = $base . '/source.txt';
$dst = $base . '/dest.txt';
File\write($src, 'hello world');

// Copy a file (preserves executable bits)
Filesystem\copy($src, $dst);
IO\write_line('Copied: %s', File\read($dst));

// Copy with overwrite
File\write($src, 'updated content');
Filesystem\copy($src, $dst, overwrite: true);
IO\write_line('Overwritten: %s', File\read($dst));

// Delete a file
Filesystem\delete_file($dst);
IO\write_line('File deleted: %s', !Filesystem\exists($dst) ? 'yes' : 'no');

// Delete a directory (empty, or recursive)
$subDir = $base . '/subdir';
Filesystem\create_directory($subDir);
Filesystem\delete_directory($subDir);

// Delete a directory with contents recursively
Filesystem\delete_directory($base, recursive: true);
IO\write_line('Directory deleted: %s', !Filesystem\exists($base) ? 'yes' : 'no');
```

## Metadata and Permissions

```php
use Psl\File;
use Psl\Filesystem;
use Psl\IO;

$tmp = Filesystem\create_temporary_file(prefix: 'psl_meta_');
File\write($tmp, 'some content here');

$bytes = Filesystem\file_size($tmp);
$mtime = Filesystem\get_modification_time($tmp);
$perms = Filesystem\get_permissions($tmp);
$owner = Filesystem\get_owner($tmp);

IO\write_line('Size: %d bytes', $bytes);
IO\write_line('Modified: %s', date('Y-m-d H:i:s', $mtime));
IO\write_line('Permissions: %o', $perms);
IO\write_line('Owner UID: %d', $owner);

Filesystem\change_permissions($tmp, 0o644);
IO\write_line('New permissions: %o', Filesystem\get_permissions($tmp));

Filesystem\delete_file($tmp);
```

## Symbolic Links

```php
use Psl\Filesystem;
use Psl\IO;

$base = sys_get_temp_dir() . '/psl-fs-links-' . uniqid();
Filesystem\create_directory($base);

$target = $base . '/target.txt';
$symlink = $base . '/symlink.txt';
$hardlink = $base . '/hardlink.txt';

Filesystem\create_file($target);

Filesystem\create_symbolic_link($target, $symlink);
Filesystem\create_hard_link($target, $hardlink);

$resolved = Filesystem\read_symbolic_link($symlink);
IO\write_line('Symlink points to: %s', $resolved);
IO\write_line('Is symbolic link: %s', Filesystem\is_symbolic_link($symlink) ? 'true' : 'false');
IO\write_line('Hard link exists: %s', Filesystem\is_file($hardlink) ? 'true' : 'false');

Filesystem\delete_directory($base, recursive: true);
```

## Path Operations

```php
use Psl\Filesystem;
use Psl\IO;

$base = sys_get_temp_dir() . '/psl-fs-paths-' . uniqid();
Filesystem\create_directory($base . '/subdir', 0o755);
$file = $base . '/subdir/file.txt';
Filesystem\create_file($file);

// Resolve to absolute path (returns null if path does not exist)
$real = Filesystem\canonicalize($base . '/subdir/../subdir/file.txt');
IO\write_line('Canonical: %s', $real ?? 'null');

IO\write_line('Basename: %s', Filesystem\get_basename($file)); // "file.txt"
IO\write_line('Without ext: %s', Filesystem\get_basename($file, '.txt')); // "file"
IO\write_line('Directory: %s', Filesystem\get_directory($file));
IO\write_line('Extension: %s', Filesystem\get_extension($file) ?? 'null'); // "txt"

$noExt = $base . '/subdir/Makefile';
Filesystem\create_file($noExt);
IO\write_line('No extension: %s', Filesystem\get_extension($noExt) ?? 'null'); // null

Filesystem\delete_directory($base, recursive: true);
```

## Reading Directory Contents

```php
use Psl\Filesystem;
use Psl\IO;

$dir = sys_get_temp_dir() . '/psl-fs-readdir-' . uniqid();
Filesystem\create_directory($dir);
Filesystem\create_file($dir . '/alpha.txt');
Filesystem\create_file($dir . '/beta.txt');
Filesystem\create_directory($dir . '/subdir');

$entries = Filesystem\read_directory($dir);
// Returns a list of absolute path strings (excludes . and ..)
foreach ($entries as $entry) {
    IO\write_line('  %s', Filesystem\get_basename($entry));
}

Filesystem\delete_directory($dir, recursive: true);
```

## Error Handling

All operations throw specific exceptions on failure:

```php
use Psl\Filesystem;
use Psl\IO;

$missing = sys_get_temp_dir() . '/psl-fs-missing-' . uniqid() . '.txt';

try {
    Filesystem\delete_file($missing);
} catch (Filesystem\Exception\NotFoundException) {
    IO\write_line('Caught NotFoundException: file does not exist');
} catch (Filesystem\Exception\NotFileException) {
    IO\write_line('Caught NotFileException: path exists but is not a file');
}
```

See [src/Psl/Filesystem/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/filesystem/src/Psl/Filesystem/) for the full API.
