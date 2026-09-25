# PseudoRandom

The `PseudoRandom` component provides fast pseudo-random number generation using PHP's Mersenne Twister engine (`mt_rand`). It is appropriate for non-security contexts such as shuffling, sampling, simulations, or any case where speed matters more than cryptographic guarantees.

> **Important**: Do not use `PseudoRandom` for tokens, passwords, keys, or anything security-related. Use `SecureRandom` instead.

## Usage

### Random Integers

Generate a pseudo-random integer within a range:

```php
use Psl\IO;
use Psl\PseudoRandom;

$roll = PseudoRandom\int(1, 6);
IO\write_line('Dice roll: %d', $roll);

// Full 64-bit range by default
$value = PseudoRandom\int();
IO\write_line('Random value: %d', $value);
```

### Random Floats

Generate a pseudo-random float between 0.0 and 1.0:

```php
use Psl\IO;
use Psl\PseudoRandom;

$chance = PseudoRandom\float();
IO\write_line('Random chance: %f', $chance);

if ($chance < 0.3) {
    IO\write_line('Hit the 30%% probability branch!');
} else {
    IO\write_line('Missed the 30%% probability branch.');
}
```

## When to Use PseudoRandom vs SecureRandom

| Use case | Component |
|---|---|
| Dice rolls, shuffling, sampling | `PseudoRandom` |
| Simulation / Monte Carlo | `PseudoRandom` |
| API tokens, session IDs | `SecureRandom` |
| Passwords, encryption keys | `SecureRandom` |
| Nonces, CSRF tokens | `SecureRandom` |

See [src/Psl/PseudoRandom/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/pseudo-random/src/Psl/PseudoRandom/) for the full API.
