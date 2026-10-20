# CIDR

The `CIDR` component provides utilities for working with CIDR (Classless Inter-Domain Routing) notation. It supports both IPv4 and IPv6 addresses.

Containment requires the same address family. IPv6 blocks do not contain IPv4 addresses, and IPv4 blocks do not contain IPv4-mapped IPv6 addresses. Callers that need mapped matching must convert the candidate address first.

The constructor accepts host bits and masks them to the network prefix. For example, `192.168.1.5/24` denotes the same block as `192.168.1.0/24`.

## Usage

@example('networking/cidr-usage.php')

`Block::contains()` accepts either a string or an `IP\Address` object:

@example('networking/cidr-address.php')

## Examples

### IPv4

@example('networking/cidr-ipv4.php')

### IPv6

@example('networking/cidr-ipv6.php')

### Single Host

@example('networking/cidr-single-host.php')

See `src/Psl/CIDR/` for the full API.
