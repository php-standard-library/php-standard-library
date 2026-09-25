# CIDR

The `CIDR` component provides utilities for working with CIDR (Classless Inter-Domain Routing) notation. It supports both IPv4 and IPv6 addresses.

IPv4 addresses are internally normalized to IPv4-mapped IPv6 for unified comparison, so mixed-family matching works transparently.

## Usage

```php
use Psl\CIDR;

$block = new CIDR\Block('192.168.1.0/24');
$block->contains('192.168.1.100'); // true
$block->contains('192.168.2.1'); // false
```

`Block::contains()` accepts either a string or an `IP\Address` object:

```php
use Psl\CIDR;
use Psl\IP\Address;

$block = new CIDR\Block('192.168.1.0/24');

// Using a string
$block->contains('192.168.1.100'); // true

// Using an Address object
$addr = Address::parse('192.168.1.100');
$block->contains($addr); // true
```

## Examples

### IPv4

```php
use Psl\CIDR;

$private = new CIDR\Block('10.0.0.0/8');
$private->contains('10.1.2.3'); // true
$private->contains('172.16.0.1'); // false
```

### IPv6

```php
use Psl\CIDR;

$loopback = new CIDR\Block('::1/128');
$loopback->contains('::1'); // true
```

### Single Host

```php
use Psl\CIDR;

$exact = new CIDR\Block('192.168.1.1/32');
$exact->contains('192.168.1.1'); // true
$exact->contains('192.168.1.2'); // false
```

See [src/Psl/CIDR/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/cidr/src/Psl/CIDR/) for the full API.
