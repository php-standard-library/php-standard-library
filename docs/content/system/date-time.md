# DateTime

The `DateTime` component provides immutable, timezone-aware date and time types. It replaces PHP's mutable `DateTime` class with a design where every operation returns a new instance, eliminating an entire class of mutation bugs.

The component is built around several core types: `DateTime` for calendar-aware dates, `Timestamp` for raw points in time, `Duration` for exact time spans, `Period` for calendar-based periods, and `Interval` for time ranges.

## Creating Dates

@example('system/datetime-creation.php')

## Timestamps

A `Timestamp` represents a precise point in time as seconds and nanoseconds since the Unix epoch, independent of any timezone:

@example('system/datetime-timestamp.php')

Use `Timestamp::monotonic()` for duration measurements -- it uses a monotonic clock unaffected by system time adjustments.

## Duration and Arithmetic

`Duration` represents an exact time span (hours, minutes, seconds, nanoseconds). All values are normalized automatically:

@example('system/datetime-duration.php')

`plusDays()`, `plusWeeks()`, `plusMonths()`, `plusYears()` (and their `minus*()` counterparts) are calendar arithmetic: they move the date and keep the wall-clock time, even across daylight saving time changes. In `Europe/London`, `2024-03-30 12:00` plus one day is `2024-03-31 12:00`, only 23 hours later. Add a `Duration` when you need an exact amount of elapsed time.

## Period

`Period` represents a calendar-based period (years, months, days). Unlike `Duration`, a period's actual time length depends on context - a month can be 28-31 days, and a day can be 23-25 hours due to DST. Months automatically normalize into years, but days are never normalized:

@example('system/datetime-period.php')

## Interval

`Interval` represents a range between two points in time. It can check containment, overlap, and compute the exact duration between its endpoints:

@example('system/datetime-interval.php')

## Convenience Methods

`DateTime` provides convenience methods for common boundary operations:

@example('system/datetime-convenience.php')

## Comparing and Inspecting

@example('system/datetime-comparing.php')

## Formatting and Parsing

@example('system/datetime-formatting.php')

## Timezone Conversion

@example('system/datetime-timezone.php')

## Interoperability

`DateTime` converts to and from PHP's `DateTimeImmutable` and ICU's `IntlCalendar`:

@example('system/datetime-interop.php')

See `src/Psl/DateTime/` for the full API.
