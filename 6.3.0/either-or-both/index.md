# EitherOrBoth

`EitherOrBoth` is a three-variant disjoint union: `Left`, `Right`, or `Both`. It models a value that may be present on either of two sides, or on both simultaneously.

Unlike `Either` (which carries a loose "one vs the other" convention where `Right` is the happy path), `EitherOrBoth` treats all three variants as equal citizens. No side is privileged. The canonical use case is a three-way diff between two collections -- insert / delete / update events -- but the type is useful anywhere two partially-overlapping sources describe the same domain.

## Design

- **`Left<TLeft>`** -- only the left value is present
- **`Right<TRight>`** -- only the right value is present
- **`Both<TLeft, TRight>`** -- both sides are present simultaneously
- **`EitherOrBoth<TLeft, TRight>`** -- the common interface all three implement

Inspired by Rust's [`itertools::EitherOrBoth`](https://docs.rs/itertools/latest/itertools/enum.EitherOrBoth.html) and Haskell's `Data.These`.

## When to Use

Reach for `EitherOrBoth` any time two collections, streams, or sources describe the same domain with potential partial overlap:

- **Three-way diff / synchronization** -- the canonical producer. Pair with `Psl\Iter\merge_join_by` or `merge_join_by_key` to emit insert / delete / update events.
- **Layered configuration merge** -- defaults vs overrides. `Left` = key only in defaults, `Right` = key only in overrides, `Both(default, override)` = key in both (inspect the conflict before resolving).
- **Multi-source enrichment** -- a primary record paired with optional data from a cache or secondary store.
- **Dual-validation** -- two parallel validators that can both fail, where the handling differs.
- **Snapshot comparison** -- expected vs actual, current vs desired, old-format vs new-format.
- **Full outer join at the language level** -- any time a SQL `FULL OUTER JOIN` is the mental model.

## Usage

### Creating EitherOrBoth Values

Use the concrete classes or the `left()` / `right()` / `both()` free functions:

```php
use Psl\EitherOrBoth;

// Via constructors
$onlyNew = new EitherOrBoth\Left('new-record');
$onlyOld = new EitherOrBoth\Right('old-record');
$conflict = new EitherOrBoth\Both('new-version', 'old-version');

// Via free functions
$onlyNew = EitherOrBoth\left('new-record');
$onlyOld = EitherOrBoth\right('old-record');
$conflict = EitherOrBoth\both('new-version', 'old-version');
```

### Predicates: `is*` vs `has*`

`EitherOrBoth` has two tiers of predicates. The exclusive `isLeft()` / `isRight()` / `isBoth()` each return true for exactly one variant. The inclusive `hasLeft()` / `hasRight()` return true whenever the corresponding side is present -- so `hasLeft()` is true for both `Left` and `Both`.

```php
use Psl\EitherOrBoth;

$both = new EitherOrBoth\Both('l', 'r');

// Exclusive predicates: true for exactly one variant
$both->isLeft(); // false
$both->isRight(); // false
$both->isBoth(); // true

// Inclusive predicates: true whenever the side is present
$both->hasLeft(); // true -- Both has a left side
$both->hasRight(); // true -- Both has a right side

$leftOnly = new EitherOrBoth\Left('l');
$leftOnly->isLeft(); // true
$leftOnly->hasLeft(); // true
$leftOnly->hasRight(); // false
```

### Extracting Values

```php
use Psl\EitherOrBoth;

$both = new EitherOrBoth\Both('hello', 'world');

// Direct access -- throws if the side is missing
$both->getLeft(); // 'hello'
$both->getRight(); // 'world'

$left = new EitherOrBoth\Left('only-left');
$left->getLeft(); // 'only-left'
// $left->getRight();            // throws MissingRightException

// Safe access via Option
$left->unwrapLeft(); // Some('only-left')
$left->unwrapRight(); // None

// Option methods cover the "or default" variations without duplicate surface on EitherOrBoth:
$left->unwrapRight()->unwrapOr('fallback'); // 'fallback'
$left->unwrapLeft()->unwrapOrElse(static fn(): string => 'computed'); // 'only-left'
```

### Transforming Values

`mapLeft()` and `mapRight()` each transform one side, leaving the other untouched. On `Both`, only the addressed side changes. `mapAny()` applies two closures, one per side. `map()` applies the same closure to whichever side(s) are present -- useful when both sides have the same type:

```php
use Psl\EitherOrBoth;
use Psl\Str;

$both = new EitherOrBoth\Both('hello', 'world');

// mapLeft / mapRight: transform one side, leave the other untouched
$both->mapLeft(Str\uppercase(...)); // Both('HELLO', 'world')
$both->mapRight(Str\uppercase(...)); // Both('hello', 'WORLD')

// mapAny: two closures, one per side. On Both, both run.
$both->mapAny(Str\uppercase(...), Str\length(...)); // Both('HELLO', 5)

// map: same closure applied to whichever side(s) are present.
// On Both, the closure runs twice.
$both->map(Str\uppercase(...)); // Both('HELLO', 'WORLD')

// mapLeft on a Right is a no-op and returns the same instance:
$right = new EitherOrBoth\Right('unchanged');
$right->mapLeft(Str\uppercase(...)); // still Right('unchanged'), same object
```

### Pattern Matching with proceed()

`proceed()` takes three closures -- one per variant -- and dispatches to exactly one. Arguments are positional: `left`, `right`, then `both` (no happy-path convention applies here, since the three variants are equal citizens):

```php
use Psl\EitherOrBoth;

/** @var EitherOrBoth\EitherOrBoth<string, string> $event */
$event = new EitherOrBoth\Both('new-value', 'old-value');

$description = $event->proceed(
    left: static fn(string $new): string => "insert: {$new}",
    right: static fn(string $old): string => "delete: {$old}",
    both: static fn(string $new, string $old): string => "update: {$old} -> {$new}",
);

// 'update: old-value -> new-value'
```

### Swapping Sides

`swap()` flips Left into Right and vice versa. On `Both(l, r)` it produces `Both(r, l)`:

```php
use Psl\EitherOrBoth;

$left = new EitherOrBoth\Left('hello');
$swappedLeft = $left->swap(); // Right('hello')

$right = new EitherOrBoth\Right('world');
$swappedRight = $right->swap(); // Left('world')

$both = new EitherOrBoth\Both('l', 'r');
$swappedBoth = $both->swap(); // Both('r', 'l')
```

### Side Effects with apply()

`apply()` runs a single side-effect closure on whichever side(s) are present and returns the value unchanged. On `Both` it runs twice, once per side -- same invocation shape as `map()`. Useful for logging inside a pipeline:

```php
use Psl\EitherOrBoth;
use Psl\IO;

// Single-closure apply: runs on whichever side(s) are present.
// On Left/Right the closure is called once; on Both it runs twice (once per side).
$event = new EitherOrBoth\Both('new', 'old');

$event->apply(static fn(string $v): mixed => IO\write_error_line('value: %s', $v));

// Logs:
//   value: new
//   value: old
// and returns the Both unchanged.
```

### Composing with iter producers

`EitherOrBoth` is the element type of `Psl\Iter\merge_join_by` (sorted inputs, O(1) memory on first traversal) and `Psl\Iter\merge_join_by_key` (keyed inputs, O(|right|) memory). Both return a rewindable `Iter\Iterator`. The four most common composition patterns -- side effects, pure mapping, filtering before dispatch, and per-side transformation -- all fall out naturally:

```php
use Psl\EitherOrBoth;
use Psl\Iter;
use Psl\Vec;

/** @var list<array{id: int, name: string}> $incoming */
$incoming = [['id' => 1, 'name' => 'Ada'], ['id' => 3, 'name' => 'Grace']];

/** @var list<array{id: int, name: string}> $current */
$current = [['id' => 1, 'name' => 'Ada (old)'], ['id' => 2, 'name' => 'Linus']];

// 1. Side effects (DB sync). `Iter\apply` is a drop-in for a foreach loop when
// you do not need the loop variable after the iteration finishes.
$ops = [];
Iter\apply(
    Iter\merge_join_by_key(
        $incoming,
        $current,
        /** @param array{id: int, name: string} $r */
        static fn(array $r): int => $r['id'],
    ),
    /** @param EitherOrBoth\EitherOrBoth<array{id: int, name: string}, array{id: int, name: string}> $event */
    static fn(EitherOrBoth\EitherOrBoth $event): mixed => $event->proceed(
        /** @param array{id: int, name: string} $new */
        left: static fn(array $new) => $ops[] = ['insert', $new['id']],
        /** @param array{id: int, name: string} $old */
        right: static fn(array $old) => $ops[] = ['delete', $old['id']],
        /**
         * @param array{id: int, name: string} $new
         * @param array{id: int, name: string} $old
         */
        both: static fn(array $new, array $old) => $ops[] = ['update', $new['id'], $old['id']],
    ),
);
// $ops: [['update', 1, 1], ['delete', 2], ['insert', 3]]

// 2. Pure mapping (build a changelog line for each event)
$changelog = Vec\map(
    Iter\merge_join_by_key(
        $incoming,
        $current,
        /** @param array{id: int, name: string} $r */
        static fn(array $r): int => $r['id'],
    ),
    /** @param EitherOrBoth\EitherOrBoth<array{id: int, name: string}, array{id: int, name: string}> $e */
    static fn(EitherOrBoth\EitherOrBoth $e): string => $e->proceed(
        /** @param array{id: int, name: string} $r */
        left: static fn(array $r): string => "+ {$r['id']} {$r['name']}",
        /** @param array{id: int, name: string} $r */
        right: static fn(array $r): string => "- {$r['id']} {$r['name']}",
        /**
         * @param array{id: int, name: string} $n
         * @param array{id: int, name: string} $o
         */
        both: static fn(array $n, array $o): string => "~ {$n['id']} {$o['name']} -> {$n['name']}",
    ),
);
// ['~ 1 Ada (old) -> Ada', '- 2 Linus', '+ 3 Grace']

// 3. Filtering before applying (only care about deletes)
$deletes = Vec\filter(
    Vec\values(Iter\merge_join_by_key(
        $incoming,
        $current,
        /** @param array{id: int, name: string} $r */
        static fn(array $r): int => $r['id'],
    )),
    static fn(EitherOrBoth\EitherOrBoth $e): bool => $e->isRight(),
);

// 4. Mapping each side independently (hydrate old + new differently)
$hydrated = Vec\map(
    Iter\merge_join_by_key(
        $incoming,
        $current,
        /** @param array{id: int, name: string} $r */
        static fn(array $r): int => $r['id'],
    ),
    /** @param EitherOrBoth\EitherOrBoth<array{id: int, name: string}, array{id: int, name: string}> $e */
    static fn(EitherOrBoth\EitherOrBoth $e): EitherOrBoth\EitherOrBoth => $e->mapAny(
        /** @param array{id: int, name: string} $new */
        static fn(array $new): string => "NEW:{$new['name']}",
        /** @param array{id: int, name: string} $old */
        static fn(array $old): string => "OLD:{$old['name']}",
    ),
);
```

## When to Use EitherOrBoth vs Either

- **Either** -- two mutually exclusive outcomes; one is the primary/success path, the other is secondary/error.
- **EitherOrBoth** -- two sides that may independently be present, absent, or both present. No privileged side.

If "both present" is a meaningful state in your domain, use `EitherOrBoth`. If it cannot occur, use `Either`.

See [src/Psl/EitherOrBoth/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/either-or-both/src/Psl/EitherOrBoth/) for the full API.
