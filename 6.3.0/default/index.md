# Default

The `Default` component introduces a uniform approach for classes within PSL to provide and utilize default instances.

It encapsulates the `DefaultInterface`, establishing a contract that enables implementing classes to offer a standardized method for instantiating themselves with default values or settings. This pattern is beneficial when an object needs sensible defaults but also allows customization through further configuration.

## Usage

```php
use Psl\Default\DefaultInterface;

enum TransportMethod implements DefaultInterface
{
    case Air;
    case Water;
    case Land;

    public static function default(): static
    {
        return self::Land;
    }
}

// Obtaining a default instance of TransportMethod
$transportMethod = TransportMethod::default();
```

Classes and enums that implement `DefaultInterface` provide a static `default()` method returning a new instance initialized with default values:

```php
use Psl\Default;

final class Example implements Default\DefaultInterface
{
    public static function default(): static
    {
        // Return an instance with default configuration.
        return new self();
    }
}

$example = Example::default();
```

Implementing `DefaultInterface` signals that a class supports this standardized mechanism for obtaining default instances, ensuring consistency across PSL.

See [src/Psl/Default/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/default/src/Psl/Default/) for the full API.
