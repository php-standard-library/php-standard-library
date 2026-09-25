# Observer

The `Observer` component provides two interfaces for the classic Observer design pattern. A subject maintains a list of observers and notifies them when something changes. PSL's interfaces are generic, so observers receive the concrete subject type rather than a vague base class.

## Design

- **`SubjectInterface`** -- The object being watched. It can `subscribe()`, `unsubscribe()`, and `notify()` observers.
- **`ObserverInterface<T>`** -- The watcher. Its `update()` method receives the subject that triggered the notification, fully typed as `T`.

## Usage

The following example demonstrates a complete Subject/Observer implementation, including wiring them together:

```php
use Psl\IO;
use Psl\Observer;
use Psl\Vec;

final class Inventory implements Observer\SubjectInterface
{
    /** @var list<Observer\ObserverInterface<Inventory>> */
    private array $observers = [];
    private int $stock = 0;

    public function subscribe(Observer\ObserverInterface $observer): void
    {
        $this->observers[] = $observer;
    }

    public function unsubscribe(Observer\ObserverInterface $observer): void
    {
        $this->observers = Vec\filter($this->observers, static fn($o) => $o !== $observer);
    }

    public function notify(): void
    {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
    }

    public function restock(int $quantity): void
    {
        $this->stock += $quantity;
        $this->notify();
    }

    public function getStock(): int
    {
        return $this->stock;
    }
}

/** @implements Observer\ObserverInterface<Inventory> */
final class StockAlert implements Observer\ObserverInterface
{
    /** @param Inventory $subject */
    public function update(Observer\SubjectInterface $subject): void
    {
        if ($subject->getStock() > 100) {
            IO\write_line('Alert: stock is now %d (above threshold)', $subject->getStock());
        }
    }
}

// Wiring it together
$inventory = new Inventory();
$alert = new StockAlert();

$inventory->subscribe($alert);
$inventory->restock(150); // StockAlert::update() is called
$inventory->unsubscribe($alert);
$inventory->restock(50); // No alert -- observer was removed

IO\write_line('Final stock: %d', $inventory->getStock());
```

See [src/Psl/Observer/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/observer/src/Psl/Observer/) for the full API.
