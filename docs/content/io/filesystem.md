# Filesystem

The `Filesystem` component provides type-safe functions for common file system operations. It replaces PHP's procedural file functions with proper error handling through exceptions, clear parameter types, and consistent behavior.

Unlike the `File` component (which deals with reading and writing file contents through handles), `Filesystem` focuses on managing files and directories themselves: checking existence, creating, copying, deleting, and inspecting metadata.

## Checking Existence and Type

@example('io/filesystem-exists.php')

## Creating Files and Directories

@example('io/filesystem-create.php')

## Copying and Deleting

@example('io/filesystem-copy-delete.php')

## Metadata and Permissions

@example('io/filesystem-metadata.php')

## Symbolic Links

@example('io/filesystem-symlinks.php')

## Path Operations

@example('io/filesystem-paths.php')

## Reading Directory Contents

@example('io/filesystem-read-directory.php')

## Error Handling

All operations throw specific exceptions on failure:

@example('io/filesystem-error-handling.php')

A path containing a NUL byte can never exist. Predicates such as `exists()` and `is_file()` return `false` for it, and `canonicalize()` returns `null`. Passing such a path where an existing node is expected, such as to `file_size()` or as the directory of `create_temporary_file()`, throws `NotFoundException`. Passing it as a node to create, such as the destination of `copy()` or the path given to `create_file()`, throws `InvalidArgumentException`.

See `src/Psl/Filesystem/` for the full API.
