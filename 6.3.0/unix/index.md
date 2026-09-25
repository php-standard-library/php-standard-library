# Unix

The `Unix` component provides a non-blocking API for Unix domain socket connections, built on top of PSL's IO and Network abstractions.

Unix domain sockets provide local inter-process communication without the overhead of TCP's network stack. Not available on Windows.

## Usage

```php
use Psl\Async;
use Psl\Env;
use Psl\Filesystem;
use Psl\Unix;

$path = Filesystem\create_temporary_file(Env\temp_dir(), 'psl-unix-echo-sock-');

$listener = Unix\listen($path);

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $data = $connection->readAll();
        $connection->writeAll("echo: {$data}");
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($path): void {
        $client = Unix\connect($path);
        $client->writeAll('hello');
        $client->shutdown();
        $_ = $client->readAll();
        $client->close();
    },
]);

@unlink($path);
```

## Configuration

`Unix\listen()` accepts a `ListenConfiguration` to control backlog (default 512) and idle connections (default 256). Configuration objects are immutable with `with*` builder methods.

## Examples

### Echo Server

```php
use Psl\Async;
use Psl\Env;
use Psl\Filesystem;
use Psl\Unix;

$path = Filesystem\create_temporary_file(Env\temp_dir(), 'psl-unix-server-');

$listener = Unix\listen($path);

Async\concurrently([
    'server' => static function () use ($listener, $path): void {
        echo "Listening on {$path}\n";

        // Accept one connection then shut down
        $connection = $listener->accept();
        Async\run(static function () use ($connection): void {
            $data = $connection->readAll();
            $connection->writeAll($data);
            $connection->close();
        })->await();

        $listener->close();
    },
    'client' => static function () use ($path): void {
        $client = Unix\connect($path);
        $client->writeAll('hello from client');
        $client->shutdown();
        $response = $client->readAll();
        echo "Got: {$response}\n";
        $client->close();
    },
]);

@unlink($path);
```

### Client with Cancellation

```php
use Psl\Async;
use Psl\DateTime\Duration;
use Psl\Env;
use Psl\Filesystem;
use Psl\Unix;

$path = Filesystem\create_temporary_file(Env\temp_dir(), 'psl-unix-timeout-sock-');

$listener = Unix\listen($path);

Async\concurrently([
    'server' => static function () use ($listener): void {
        $connection = $listener->accept();
        $data = $connection->readAll();
        $connection->writeAll("reply: {$data}");
        $connection->close();
        $listener->close();
    },
    'client' => static function () use ($path): void {
        $client = Unix\connect($path, new Async\TimeoutCancellationToken(Duration::seconds(5)));
        $client->writeAll('ping');
        $client->shutdown();
        $response = $client->readAll();
        $client->close();
    },
]);

@unlink($path);
```

### Concurrent Connections

```php
use Psl\Async;
use Psl\Env;
use Psl\Filesystem;
use Psl\Unix;

$path = Filesystem\create_temporary_file(Env\temp_dir(), 'psl-unix-concurrent-');

$listener = Unix\listen($path);

Async\concurrently([
    'server' => static function () use ($listener): void {
        // Accept multiple clients concurrently
        for ($i = 0; $i < 3; $i++) {
            $connection = $listener->accept();
            Async\run(static function () use ($connection): void {
                $request = $connection->readAll();
                $connection->writeAll("handled: {$request}");
                $connection->close();
            })->ignore();
        }

        $listener->close();
    },
    'clients' => static function () use ($path): void {
        // Spawn 3 clients concurrently
        Async\concurrently([
            static function () use ($path): void {
                $client = Unix\connect($path);
                $client->writeAll('request-1');
                $client->shutdown();
                $client->readAll();
                $client->close();
            },
            static function () use ($path): void {
                $client = Unix\connect($path);
                $client->writeAll('request-2');
                $client->shutdown();
                $client->readAll();
                $client->close();
            },
            static function () use ($path): void {
                $client = Unix\connect($path);
                $client->writeAll('request-3');
                $client->shutdown();
                $client->readAll();
                $client->close();
            },
        ]);
    },
]);

@unlink($path);
```

See [src/Psl/Unix/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/unix/src/Psl/Unix/) for the full API.
