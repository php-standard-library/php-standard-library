# Env

The `Env` component provides functions for inspecting and modifying the process environment. It covers environment variables, the current working directory, temporary paths, command-line arguments, and `PATH` manipulation.

## Usage

### Environment Variables

Read, write, and remove environment variables for the current process:

@example('system/env-variables.php')

Keys containing `=` or the NUL character are rejected with an `InvariantViolationException`.

### Working Directory

@example('system/env-directories.php')

A directory containing the NUL character is rejected with an `InvariantViolationException`.

### System Paths

@example('system/env-system-paths.php')

### Command-Line Arguments and Executable Path

@example('system/env-args.php')

### PATH Manipulation

Split and join PATH-style strings using the platform's path separator (`:` on Unix, `;` on Windows):

@example('system/env-paths.php')

See `src/Psl/Env/` for the full API.
