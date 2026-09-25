# DataStructure

The `DataStructure` component provides three classic data structures: `Queue` (FIFO), `Stack` (LIFO), and `PriorityQueue`. They are generic, type-safe, and offer a consistent API with both safe and throwing access methods.

## Queue (FIFO)

A queue processes items in first-in, first-out order. Items are added to the back and removed from the front:

```php
use Psl\DataStructure;

$queue = new DataStructure\Queue();

$queue->enqueue('first');
$queue->enqueue('second');
$queue->enqueue('third');

$queue->count(); // 3

$queue->peek(); // "first" (does not remove)
$queue->pull(); // "first" (removes and returns)
$queue->dequeue(); // "second" (removes and returns, throws if empty)
```

Use `pull()` when an empty queue is a normal condition (it returns `null`). Use `dequeue()` when an empty queue is a bug (it throws `UnderflowException`).

## Stack (LIFO)

A stack processes items in last-in, first-out order. Items are added to and removed from the top:

```php
use Psl\DataStructure;

$stack = new DataStructure\Stack();

$stack->push('first');
$stack->push('second');
$stack->push('third');

$stack->count(); // 3

$stack->peek(); // "third" (does not remove)
$stack->pull(); // "third" (removes and returns)
$stack->pop(); // "second" (removes and returns, throws if empty)
```

Use `pull()` for safe access (returns `null` if empty) and `pop()` when emptiness is unexpected (throws `UnderflowException`).

## PriorityQueue

A priority queue dequeues items with the highest priority first. Items with equal priority are dequeued in FIFO order:

```php
use Psl\DataStructure;

$pq = new DataStructure\PriorityQueue();

$pq->enqueue('low-priority task', priority: 1);
$pq->enqueue('high-priority task', priority: 10);
$pq->enqueue('medium-priority task', priority: 5);
$pq->enqueue('another high-priority task', priority: 10);

$pq->dequeue(); // "high-priority task" (priority 10, enqueued first)
$pq->dequeue(); // "another high-priority task" (priority 10, enqueued second)
$pq->dequeue(); // "medium-priority task" (priority 5)
$pq->dequeue(); // "low-priority task" (priority 1)
```

If no priority is specified, items default to priority `0`.

## Common API

All three structures implement `Countable` and share a consistent pattern:

| Method | Queue | Stack | PriorityQueue |
|--------|-------|-------|---------------|
| Add item | `enqueue($item)` | `push($item)` | `enqueue($item, $priority)` |
| Peek at next | `peek()` | `peek()` | `peek()` |
| Remove (safe) | `pull()` | `pull()` | `pull()` |
| Remove (throws) | `dequeue()` | `pop()` | `dequeue()` |
| Count items | `count()` | `count()` | `count()` |

- `peek()` returns the next item without removing it, or `null` if empty
- `pull()` removes and returns the next item, or `null` if empty
- `dequeue()` / `pop()` removes and returns the next item, or throws `UnderflowException` if empty

See [src/Psl/DataStructure/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/data-structure/src/Psl/DataStructure/) for the full API.
