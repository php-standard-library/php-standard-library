# Interoperability

The `Interoperability` component provides a set of interfaces for converting between PSL types and their PHP standard library (`stdlib`) or `intl` extension equivalents.

These interfaces establish a uniform contract for bidirectional conversion, allowing PSL types to interoperate seamlessly with native PHP types.

## Usage

```php
use Psl\DateTime\DateTime;
use Psl\DateTime\Timezone;
use Psl\IO;

// Convert a PSL DateTime to a PHP DateTimeImmutable
$psl = DateTime::fromParts(Timezone::AmericaNewYork, 2024, 6, 15, 14, 30, 45);
$stdlib = $psl->toStdlib();
IO\write_line('PSL to stdlib: %s', $stdlib->format('Y-m-d H:i:s T'));

// Convert back from a PHP DateTimeImmutable to a PSL DateTime
$back = DateTime::fromStdlib($stdlib);
IO\write_line('Back to PSL: %s', $back->toRfc3339());

// Convert a PSL Timezone to an IntlTimeZone
$intl = Timezone::AmericaNewYork->toIntl();
$id = $intl->getID();
IO\write_line('IntlTimeZone ID: %s', false === $id ? '<unknown>' : $id);

// Convert back from an IntlTimeZone to a PSL Timezone
$tz = Timezone::fromIntl($intl);
IO\write_line('Back to PSL Timezone: %s', $tz->value);
```

## Conversion Interfaces

The component defines four interfaces covering all conversion directions:

- **`ToStdlib`** -- convert a PSL type to its PHP standard library equivalent (e.g. PSL `DateTime` to `DateTimeImmutable`)
- **`FromStdlib`** -- create a PSL type from its PHP standard library equivalent (e.g. `DateTimeImmutable` to PSL `DateTime`)
- **`ToIntl`** -- convert a PSL type to its `intl` extension equivalent (e.g. PSL `Timezone` to `IntlTimeZone`)
- **`FromIntl`** -- create a PSL type from its `intl` extension equivalent (e.g. `IntlTimeZone` to PSL `Timezone`)

## Implementations

The following PSL types implement these interfaces:

| PSL Type | ToStdlib | FromStdlib | ToIntl | FromIntl |
|---|---|---|---|---|
| `DateTime\DateTime` | `DateTimeImmutable` | `DateTimeImmutable` | `IntlCalendar` | `IntlCalendar` |
| `DateTime\Timestamp` | `DateTimeImmutable` | `DateTimeImmutable` | - | - |
| `DateTime\Duration` | `DateInterval` | - | - | - |
| `DateTime\Timezone` | `DateTimeZone` | `DateTimeZone` | `IntlTimeZone` | `IntlTimeZone` |

See [src/Psl/Interoperability/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/interoperability/src/Psl/Interoperability/) for the full API.
