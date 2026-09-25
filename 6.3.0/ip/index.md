# IP

The `IP` component provides an immutable, binary-backed value object for working with IPv4 and IPv6 addresses. It supports parsing, formatting, classification, comparison, and reverse DNS lookups.

`Address` implements `Stringable`, `Comparable`, and `Equable`.

## Usage

```php
use Psl\IP\Address;

$addr = Address::parse('192.168.1.10');
$addr->family; // Family::V4
$addr->toString(); // '192.168.1.10'
$addr->isPrivate(); // true
$addr->isGlobalUnicast(); // false
```

## Parsing

There are three ways to create an `Address`:

- **`Address::v4()`** -- parse a dotted-decimal IPv4 address.
- **`Address::v6()`** -- parse a colon-hex IPv6 address.
- **`Address::parse()`** -- auto-detect the family.

You can also create an address from raw binary bytes using `Address::fromBytes()`.

```php
use Psl\IP\Address;

// Parse with explicit family
$v4 = Address::v4('192.168.1.1');
$v6 = Address::v6('2001:db8::1');

// Auto-detect family
$auto = Address::parse('::1');
$auto->family; // Family::V6

// Create from raw binary bytes
$fromBytes = Address::fromBytes("\xc0\xa8\x01\x01");
$fromBytes->toString(); // '192.168.1.1'
```

## Classification

`Address` provides methods to classify addresses into well-known categories:

| Method | IPv4 Range | IPv6 Range |
|---|---|---|
| `isLoopback()` | `127.0.0.0/8` | `::1` |
| `isPrivate()` | `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | `fc00::/7` |
| `isLinkLocal()` | `169.254.0.0/16` | `fe80::/10` |
| `isMulticast()` | `224.0.0.0/4` | `ff00::/8` |
| `isUnspecified()` | `0.0.0.0` | `::` |
| `isDocumentation()` | `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24` | `2001:db8::/32` |
| `isGlobalUnicast()` | Everything else | Everything else |

```php
use Psl\IP\Address;

$loopback = Address::v4('127.0.0.1');
$loopback->isLoopback(); // true

$private = Address::v4('10.0.0.1');
$private->isPrivate(); // true

$public = Address::v4('8.8.8.8');
$public->isGlobalUnicast(); // true
$public->isPrivate(); // false

$doc = Address::v6('2001:db8::1');
$doc->isDocumentation(); // true
$doc->isGlobalUnicast(); // false
```

## Formatting

```php
use Psl\IP\Address;

$v6 = Address::v6('2001:db8::1');

// Compressed (RFC 5952)
$v6->toString(); // '2001:db8::1'

// Fully expanded
$v6->toExpandedString(); // '2001:0db8:0000:0000:0000:0000:0000:0001'

// Raw binary bytes
$v6->toBytes(); // 16-byte binary string

// Stringable
echo (string) $v6; // '2001:db8::1'
```

## Reverse DNS

```php
use Psl\IP\Address;

$v4 = Address::v4('192.168.1.10');
$v4->toArpaName(); // '10.1.168.192.in-addr.arpa'

$v6 = Address::v6('2001:db8::1');
$v6->toArpaName(); // '1.0.0.0.0.0.0.0...8.b.d.0.1.0.0.2.ip6.arpa'
```

## Comparison

`Address` implements `Comparable` and `Equable`, so addresses can be compared and sorted.

```php
use Psl\IP\Address;

$a = Address::v4('10.0.0.1');
$b = Address::v4('10.0.0.2');

$a->equals($b); // false
$a->compare($b); // Psl\Comparison\Order::Less
$b->compare($a); // Psl\Comparison\Order::Greater

$c = Address::v6('2001:db8::1');
$d = Address::v6('2001:0db8:0000:0000:0000:0000:0000:0001');
$c->equals($d); // true (same bytes, different notation)
```

## CIDR Integration

`Address` objects can be passed directly to `CIDR\Block::contains()`:

```php
use Psl\CIDR;
use Psl\IP\Address;

$block = new CIDR\Block('192.168.1.0/24');

// Pass a string
$block->contains('192.168.1.100'); // true

// Or pass an Address object
$addr = Address::v4('192.168.1.100');
$block->contains($addr); // true
```

## Family

The `Family` enum represents the IP address family, with values `V4` (4) and `V6` (16) corresponding to their byte sizes. It also supports conversion to and from IANA address family numbers (RFC 7871).

```php
use Psl\IP\Family;

$family = Family::V4;
$family->value; // 4 (byte size)
$family->ianaFamily(); // 1

$fromIana = Family::fromIanaFamily(2);
$fromIana === Family::V6; // true
```

See [src/Psl/IP/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/ip/src/Psl/IP/) for the full API.
