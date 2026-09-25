# DateTime

The `DateTime` component provides immutable, timezone-aware date and time types. It replaces PHP's mutable `DateTime` class with a design where every operation returns a new instance, eliminating an entire class of mutation bugs.

The component is built around several core types: `DateTime` for calendar-aware dates, `Timestamp` for raw points in time, `Duration` for exact time spans, `Period` for calendar-based periods, and `Interval` for time ranges.

## Creating Dates

```php
use Psl\DateTime;
use Psl\IO;

$now = DateTime\DateTime::now();
IO\write_line('Now: %s', $now->toRfc3339());

$tokyo = DateTime\DateTime::now(DateTime\Timezone::AsiaTokyo);
IO\write_line('Tokyo: %s', $tokyo->toRfc3339());

// From individual components
$birthday = DateTime\DateTime::fromParts(DateTime\Timezone::AmericaNewYork, 1990, DateTime\Month::March, 15, 14, 30, 0);
IO\write_line('Birthday: %s', $birthday->toRfc3339());

// A specific time today
$meeting = DateTime\DateTime::todayAt(9, 0, timezone: DateTime\Timezone::EuropeLondon);
IO\write_line('Meeting: %s', $meeting->toRfc3339());
```

## Timestamps

A `Timestamp` represents a precise point in time as seconds and nanoseconds since the Unix epoch, independent of any timezone:

```php
use Psl\DateTime;
use Psl\IO;

$ts = DateTime\Timestamp::now();
IO\write_line('Seconds since epoch: %d', $ts->getSeconds());
IO\write_line('Nanoseconds: %d', $ts->getNanoseconds());

$specific = DateTime\Timestamp::fromParts(1_700_000_000, 500_000_000);

// Create from milliseconds or microseconds
$fromMs = DateTime\Timestamp::fromMilliseconds(1_700_000_000_500);
$fromUs = DateTime\Timestamp::fromMicroseconds(1_700_000_000_500_000);

$dt = DateTime\DateTime::fromTimestamp($ts, DateTime\Timezone::UTC);
IO\write_line('From timestamp: %s', $dt->toRfc3339());
```

Use `Timestamp::monotonic()` for duration measurements -- it uses a monotonic clock unaffected by system time adjustments.

## Duration and Arithmetic

`Duration` represents an exact time span (hours, minutes, seconds, nanoseconds). All values are normalized automatically:

```php
use Psl\DateTime;
use Psl\IO;

$twoHours = DateTime\Duration::hours(2);
$timeout = DateTime\Duration::seconds(30);
$precise = DateTime\Duration::milliseconds(1500); // normalizes to 1s 500ms

$now = DateTime\DateTime::now(DateTime\Timezone::UTC);

// Add or subtract durations from dates
$later = $now->plus(DateTime\Duration::hours(3));
$earlier = $now->minus(DateTime\Duration::hours(7 * 24));

// Convenience methods
$tomorrow = $now->plusDays(1);
$nextMonth = $now->plusMonths(1);
$lastYear = $now->minusYears(1);

IO\write_line('Now: %s', $now->toRfc3339());
IO\write_line('3 hours later: %s', $later->toRfc3339());
IO\write_line('7 days earlier: %s', $earlier->toRfc3339());
IO\write_line('Tomorrow: %s', $tomorrow->toRfc3339());
IO\write_line('Next month: %s', $nextMonth->toRfc3339());
IO\write_line('Last year: %s', $lastYear->toRfc3339());

// Measure elapsed time
$start = DateTime\Timestamp::now();
$end = DateTime\Timestamp::now();
$elapsed = $end->since($start); // returns Duration
IO\write_line('Elapsed: %f seconds', $elapsed->getTotalSeconds());

// ISO 8601 format
$duration = DateTime\Duration::fromParts(2, 30, 15);
IO\write_line('ISO 8601: %s', $duration->toIso8601()); // PT2H30M15S
$parsed = DateTime\Duration::fromIso8601('PT1H45M');
IO\write_line('Parsed: %s', $parsed->toString());
```

## Period

`Period` represents a calendar-based period (years, months, days). Unlike `Duration`, a period's actual time length depends on context - a month can be 28-31 days, and a day can be 23-25 hours due to DST. Months automatically normalize into years, but days are never normalized:

```php
use Psl\DateTime;
use Psl\IO;

// Create periods from calendar units
$oneYear = DateTime\Period::years(1);
$sixMonths = DateTime\Period::months(6);
$twoWeeks = DateTime\Period::weeks(2); // 14 days
$tenDays = DateTime\Period::days(10);

// Months automatically normalize into years
$period = DateTime\Period::months(14); // 1 year, 2 months
IO\write_line('14 months = %s', $period->toString());

// Days are never normalized (a day is not a fixed number of hours)
$longPeriod = DateTime\Period::days(365);
IO\write_line('365 days = %s', $longPeriod->toString());

// Combine parts
$mixed = DateTime\Period::fromParts(1, 6, 15);
IO\write_line('Mixed: %s', $mixed->toString());

// Arithmetic
$a = DateTime\Period::fromParts(1, 6, 0);
$b = DateTime\Period::fromParts(0, 8, 15);
IO\write_line('Sum: %s', $a->plus($b)->toString());
IO\write_line('Difference: %s', $a->minus($b)->toString());
IO\write_line('Inverted: %s', $a->invert()->toString());

// Calculate the period between two dates
$start = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2024, 3, 15);
$end = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 7, 20);
$between = DateTime\Period::between($start, $end);
IO\write_line('Between: %s', $between->toString()); // 1 year(s), 4 month(s), 5 day(s)

// ISO 8601 format
IO\write_line('ISO 8601: %s', $mixed->toIso8601()); // P1Y6M15D
$parsed = DateTime\Period::fromIso8601('P2Y3M10D');
IO\write_line('Parsed: %s', $parsed->toString());
```

## Interval

`Interval` represents a range between two points in time. It can check containment, overlap, and compute the exact duration between its endpoints:

```php
use Psl\DateTime;
use Psl\IO;

// An Interval is a range between two points in time
$start = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 1, 1);
$end = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 12, 31, 23, 59, 59);
$interval = DateTime\Interval::between($start, $end);

IO\write_line('Interval: %s', $interval->toString());

// Create from a start point and a duration
$hourInterval = DateTime\Interval::from(DateTime\Timestamp::now(), DateTime\Duration::hours(1));
IO\write_line('One hour from now: %s', $hourInterval->toString());

// Get the exact time duration between start and end
$duration = $interval->getDuration();
IO\write_line('Duration: %s', $duration->toString());

// Check if a point falls within the interval
$mid = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 6, 15);
$outside = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2026, 1, 1);

IO\write_line('Contains mid-year: %s', $interval->contains($mid) ? 'yes' : 'no');
IO\write_line('Contains next year: %s', $interval->contains($outside) ? 'yes' : 'no');

// Check if two intervals overlap and get their intersection
$q1 = DateTime\Interval::between(
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 1, 1),
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 3, 31),
);
$q2 = DateTime\Interval::between(
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 3, 1),
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 6, 30),
);

IO\write_line('Q1 overlaps Q2: %s', $q1->overlaps($q2) ? 'yes' : 'no');

$intersection = $q1->intersection($q2);
if (null !== $intersection) {
    IO\write_line('Intersection: %s', $intersection->toString());
}

// Check if one interval fully contains another
IO\write_line('Full year contains Q1: %s', $interval->containsInterval($q1) ? 'yes' : 'no');
IO\write_line('Q1 contains full year: %s', $q1->containsInterval($interval) ? 'yes' : 'no');

// Merge overlapping intervals
$merged = $q1->merge($q2);
IO\write_line('Merged Q1+Q2: %s', $merged->toString());

// Find the gap between non-overlapping intervals
$spring = DateTime\Interval::between(
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 3, 1),
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 5, 31),
);
$autumn = DateTime\Interval::between(
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 9, 1),
    DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 11, 30),
);

$gap = $spring->gap($autumn);
if (null !== $gap) {
    IO\write_line('Gap between spring and autumn: %s', $gap->toString());
}

// ISO 8601 format
IO\write_line('ISO 8601: %s', $interval->toIso8601());
$parsed = DateTime\Interval::fromIso8601('2025-01-01T00:00:00Z/2025-12-31T23:59:59Z');
IO\write_line('Parsed: %s', $parsed->toString());
```

## Convenience Methods

`DateTime` provides convenience methods for common boundary operations:

```php
use Psl\DateTime;
use Psl\IO;

$dt = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, 6, 15, 14, 30, 45);

// Start and end of day
$startOfDay = $dt->atStartOfDay();
IO\write_line('Start of day: %s', $startOfDay->toRfc3339()); // 2025-06-15T00:00:00+00:00

$endOfDay = $dt->atEndOfDay();
IO\write_line('End of day: %s', $endOfDay->toRfc3339()); // 2025-06-15T23:59:59.999999999+00:00

// Start and end of month
$startOfMonth = $dt->atStartOfMonth();
IO\write_line('Start of month: %s', $startOfMonth->toRfc3339()); // 2025-06-01T00:00:00+00:00

$endOfMonth = $dt->atEndOfMonth();
IO\write_line('End of month: %s', $endOfMonth->toRfc3339()); // 2025-06-30T23:59:59.999999999+00:00

// Start and end of year
$startOfYear = $dt->atStartOfYear();
IO\write_line('Start of year: %s', $startOfYear->toRfc3339()); // 2025-01-01T00:00:00+00:00

$endOfYear = $dt->atEndOfYear();
IO\write_line('End of year: %s', $endOfYear->toRfc3339()); // 2025-12-31T23:59:59.999999999+00:00

// Start and end of week (Monday-Sunday)
$startOfWeek = $dt->atStartOfWeek();
IO\write_line('Start of week: %s', $startOfWeek->toRfc3339()); // 2025-06-09T00:00:00+00:00

$endOfWeek = $dt->atEndOfWeek();
IO\write_line('End of week: %s', $endOfWeek->toRfc3339()); // 2025-06-15T23:59:59.999999999+00:00

// Useful for date range queries
$feb = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2024, 2, 15);
IO\write_line('Feb 2024 ends: day %d', $feb->atEndOfMonth()->getDay()); // 29 (leap year)
```

## Comparing and Inspecting

```php
use Psl\DateTime;
use Psl\IO;

$a = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, DateTime\Month::January, 1);
$b = DateTime\DateTime::fromParts(DateTime\Timezone::UTC, 2025, DateTime\Month::June, 15);

IO\write_line('a before b: %s', $a->before($b) ? 'yes' : 'no'); // true
IO\write_line('b after a: %s', $b->after($a) ? 'yes' : 'no'); // true
IO\write_line('a same time as a: %s', $a->atTheSameTime($a) ? 'yes' : 'no'); // true
IO\write_line('a between a and b: %s', $a->betweenTimeInclusive($a, $b) ? 'yes' : 'no'); // true

// Inspect components
IO\write_line('Year: %d', $a->getYear());
IO\write_line('Month: %s', $a->getMonthEnum()->name);
IO\write_line('Weekday: %s', $a->getWeekday()->name);
IO\write_line('Leap year: %s', $a->isLeapYear() ? 'yes' : 'no');
IO\write_line('DST: %s', $a->isDaylightSavingTime() ? 'yes' : 'no');
```

## Formatting and Parsing

```php
use Psl\DateTime;
use Psl\IO;

$dt = DateTime\DateTime::now(DateTime\Timezone::UTC);

// ICU pattern
IO\write_line('ICU: %s', $dt->format('yyyy-MM-dd HH:mm:ss'));

// Predefined patterns
IO\write_line('ISO 8601: %s', $dt->format(DateTime\FormatPattern::Iso8601));
IO\write_line('SQL: %s', $dt->format(DateTime\FormatPattern::SqlDateTime));

// Style-based (locale-aware)
IO\write_line('Long/Short: %s', $dt->toString(DateTime\DateStyle::Long, DateTime\TimeStyle::Short));

// RFC 3339 for APIs
IO\write_line('RFC 3339: %s', $dt->toRfc3339());

// Parsing
$parsed = DateTime\DateTime::parse('2025-03-15 12:00:00', 'yyyy-MM-dd HH:mm:ss', DateTime\Timezone::UTC);
IO\write_line('Parsed: %s', $parsed->toRfc3339());
```

## Timezone Conversion

```php
use Psl\DateTime;
use Psl\IO;

$utc = DateTime\DateTime::now(DateTime\Timezone::UTC);
$la = $utc->convertToTimezone(DateTime\Timezone::AmericaLosAngeles);

// Same moment, different local time
IO\write_line('UTC: %s', $utc->toRfc3339());
IO\write_line('LA:  %s', $la->toRfc3339());
IO\write_line('Same time: %s', $utc->atTheSameTime($la) ? 'yes' : 'no');
```

## Interoperability

`DateTime` converts to and from PHP's `DateTimeImmutable` and ICU's `IntlCalendar`:

```php
use Psl\DateTime;
use Psl\IO;

$native = new DateTimeImmutable('2025-03-15 12:00:00', new DateTimeZone('UTC'));
$dt = DateTime\DateTime::fromStdlib($native);
$back = $dt->toStdlib(); // DateTimeImmutable

IO\write_line('From stdlib: %s', $dt->toRfc3339());
IO\write_line('Back to stdlib: %s', $back->format('Y-m-d H:i:s T'));
```

See [src/Psl/DateTime/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/date-time/src/Psl/DateTime/) for the full API.
