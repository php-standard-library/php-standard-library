# Channel

The `Channel` component provides message-passing channels for async communication, inspired by Go's channels and Rust's `std::sync::mpsc`. A channel splits into a `SenderInterface` and a `ReceiverInterface`, giving you a clear separation between the producing and consuming sides.

Channels come in two flavors:

- **Bounded** -- Has a fixed capacity. Sending blocks when the channel is full.
- **Unbounded** -- Grows without limit. Sending never blocks due to capacity.

## Usage

### Creating Channels

Both factory functions return a `[ReceiverInterface, SenderInterface]` pair:

```php
use Psl\Channel;

// Bounded: holds at most 10 messages
[$receiver, $sender] = Channel\bounded(10);

// Unbounded: no capacity limit
[$receiver, $sender] = Channel\unbounded();
```

### Sending and Receiving

```php
use Psl\Channel;

[$receiver, $sender] = Channel\bounded(3);

$sender->send('hello');
$sender->send('world');

$receiver->receive(); // 'hello'
$receiver->receive(); // 'world'
```

`send()` waits for space if the channel is full. `receive()` waits for a message if the channel is empty. For non-blocking alternatives, use `trySend()` and `tryReceive()`, which throw immediately instead of waiting:

```php
use Psl\Channel;
use Psl\IO;

[$receiver, $sender] = Channel\bounded(1);

$sender->trySend('first');

try {
    $sender->trySend('second'); // throws FullChannelException -- channel is at capacity
} catch (Channel\Exception\FullChannelException) {
    IO\write_line('Channel is full, cannot send "second"');
}

$receiver->tryReceive(); // 'first'

try {
    $receiver->tryReceive(); // throws EmptyChannelException -- nothing left
} catch (Channel\Exception\EmptyChannelException) {
    IO\write_line('Channel is empty, cannot receive');
}
```

### Closing Channels

Closing a channel signals that no more messages will be sent. Messages already in the channel can still be received:

```php
use Psl\Channel;
use Psl\IO;

[$receiver, $sender] = Channel\bounded(10);

$sender->send('last message');
$sender->close();

$sender->isClosed(); // true
$receiver->receive(); // 'last message'

try {
    $receiver->receive(); // throws ClosedChannelException -- no more messages
} catch (Channel\Exception\ClosedChannelException) {
    IO\write_line('Channel is closed, cannot receive more messages');
}
```

### Inspecting Channel State

```php
use Psl\Channel;

[$receiver, $sender] = Channel\bounded(5);

$sender->getCapacity(); // 5
$sender->count(); // 0 (number of messages currently in the channel)
$sender->isEmpty(); // true
$sender->isFull(); // false
$sender->isClosed(); // false
```

For unbounded channels, `getCapacity()` returns `null` and `isFull()` always returns `false`.

### Producer-Consumer Pattern

```php
use Psl\Async;
use Psl\Channel;
use Psl\IO;

/**
 * @var Channel\ReceiverInterface<string> $receiver
 * @var Channel\SenderInterface<string> $sender
 */
[$receiver, $sender] = Channel\bounded(100);

// Producer
Async\run(function () use ($sender): void {
    foreach (['job-1', 'job-2', 'job-3'] as $job) {
        $sender->send($job);
    }

    $sender->close();
});

// Consumer
Async\run(function () use ($receiver): void {
    while (!$receiver->isClosed() || !$receiver->isEmpty()) {
        $job = $receiver->receive();
        IO\write_error_line('Processing: %s', $job);
    }
});

Async\later();
```

### Fan-Out: Multiple Consumers

Distribute work across several consumers by sharing the receiver:

```php
use Psl\Async;
use Psl\Channel;
use Psl\IO;

/**
 * @var Channel\ReceiverInterface<string> $receiver
 * @var Channel\SenderInterface<string> $sender
 */
[$receiver, $sender] = Channel\bounded(100);

// Spawn 3 worker consumers
for ($i = 0; $i < 3; $i++) {
    Async\run(function () use ($receiver, $i): void {
        while (!$receiver->isClosed() || !$receiver->isEmpty()) {
            $task = $receiver->receive();
            IO\write_error_line('Worker %d processing: %s', $i, $task);
        }
    });
}

// Feed tasks
$tasks = ['task-a', 'task-b', 'task-c', 'task-d', 'task-e'];
foreach ($tasks as $task) {
    $sender->send($task);
}

$sender->close();

Async\later();
```

### Cancellation

Both `send()` and `receive()` accept an optional `CancellationTokenInterface`. This is useful when you don't want to wait indefinitely for a message or for space in a bounded channel:

```php
use Psl\Async;
use Psl\Channel;
use Psl\DateTime\Duration;
use Psl\IO;

/** @var Channel\ReceiverInterface<string> $receiver */
/** @var Channel\SenderInterface<string> $sender */
[$receiver, $sender] = Channel\bounded(1);

// Producer sends one message then stops
Async\run(static function () use ($sender): void {
    $sender->send('hello');
    Async\sleep(Duration::seconds(5));
    $sender->send('never arrives');
})->ignore();

// Consumer receives with a timeout
$token = new Async\TimeoutCancellationToken(Duration::milliseconds(50));

$first = $receiver->receive($token);
IO\write_line('Received: %s', $first);

try {
    // This will be cancelled - no second message within 50ms
    $receiver->receive($token);
} catch (Async\Exception\CancelledException) {
    IO\write_line('Timed out waiting for next message');
}
```

`trySend()` and `tryReceive()` are non-blocking and don't need cancellation tokens -- they throw immediately if the channel is full or empty.

## When to Use Channel

- **Decoupling producers and consumers** -- The sender doesn't need to know who processes the messages
- **Backpressure** -- Bounded channels naturally slow down fast producers when consumers can't keep up
- **Async coordination** -- Pass data between concurrent tasks without shared mutable state

See [src/Psl/Channel/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/channel/src/Psl/Channel/) for the full API.
