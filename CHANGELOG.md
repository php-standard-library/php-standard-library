# Changelog

## 6.4.0

Unreleased.

### security

- fix(dns): require the question of a `UDPResolver` or `TCPResolver` response to match the query's name, type and class (ignoring case), and check the UDP transaction ID before parsing any record, so an answer to a different query is rejected with `ProtocolException` instead of being accepted; a mismatched TCP reply also closes the pooled connection
- fix(dnssec): require the signer of a DNSKEY RRset to match a trusted DS record or root trust anchor, and a valid RRSIG on every DS and denial-proof RRset used to build the chain, so a DNSKEY RRset can no longer authenticate its own replacement key and unsigned DS or NSEC records next to unrelated signatures are rejected
- fix(dnssec): require the RRSIG signer to equal the DNSKEY owner, signed owners to lie at or below the signer's zone, and every RRset owned by the query name to carry a matching signature, so a signature from another zone no longer validates an answer and an insecure zone only skips validation for names it contains. DNSKEY records passed to `StaticTrustChainResolver` must now be owned by the zone name.
- fix(dnssec): validate every answer RRset, each query and alias RRset against the keys of its own zone, instead of letting unsigned records pass next to a signed one, strip unrelated answer RRsets, and reject alias loops and unbounded DNAME expansion. The DNS decoder now rejects records of classes other than IN (except OPT), and the new `DNS\Record\DNAMERecord` and `RecordType::DNAME` let DNAME substitutions be verified.
- fix(dnssec): authenticate every NSEC, NSEC3 and SOA RRset of the authority section with its own signature instead of letting unsigned proof records borrow unrelated RRSIGs, and stop a parent zone's signature from denying names below a secure child zone
- fix(dnssec): mark a delegation insecure only when the DS denial proves an unsigned zone cut, not for any proof that a DS record is absent, and require DS and denial proofs to be signed by the deepest validated zone
- fix(dnssec): require a complete NSEC3 Opt-Out proof, the closest provable encloser and an Opt-Out record covering its next closer name, before accepting that a delegation has no DS, instead of any covering Opt-Out record
- fix(dnssec): require a signed denial proof for the final target of a CNAME or DNAME chain before accepting an NXDOMAIN answer, and reject NXDOMAIN when that target is present in the answer
- fix(dnssec): require signed NSEC or NSEC3 proof that wildcard expansion applies to the queried name instead of trusting a valid wildcard signature alone, and clear the AD flag for NSEC3 Opt-Out
- fix(filesystem): create temporary files exclusively and with `0600` permissions in `create_temporary_file()`, retrying on name collisions, so a file created by someone else between choosing the name and creating it can no longer be reused
- fix(h2): check every frame, including connection-level frames, against `SETTINGS_MAX_FRAME_SIZE` as soon as its 9-byte header arrives and close with `FRAME_SIZE_ERROR` before reading the payload, so a peer can no longer make a connection buffer an oversized frame
- fix(http-client): validate HTTP/1 requests before writing them: the method and field names must be tokens, header and trailer values must not contain CR, LF or NUL, `Transfer-Encoding` must be a single `chunked` and cannot be combined with `Content-Length`, and `Content-Length` must be a single valid value that matches the streamed body, or `RequestException` is thrown
- fix(http-client): bound and harden HTTP/1 response parsing: reject malformed lengths and fields, bare CR, LF or NUL in header and trailer values, bad chunk CRLF and unsupported upgrades, cap chunk metadata at `maxResponseHeaderSize`, interim responses at 16 and CONNECT response headers at 8192 bytes, and never reuse a connection after a response that combines `Transfer-Encoding` with `Content-Length`, is close-delimited, carries `close` in any `Connection` field, or uses `Transfer-Encoding` over HTTP/1.0
- fix(http-client): key pooled and pending connections by origin together with the SNI, TLS, proxy, SOCKS and Unix socket settings, the protocol versions and the HTTP/2 settings, so a request no longer reuses a connection opened with different TLS or proxy settings; the key is hashed, so the pool keeps no plain-text proxy credentials
- fix(http-client): extend the `DeniedDestinationsMiddleware::forPrivateNetworkRanges()` preset with the special-purpose ranges it missed (current network, shared, IETF protocol assignments, benchmarking, IPv4 and IPv6 multicast, `240.0.0.0/4` with the broadcast address, documentation, NAT64, 6to4, Teredo, discard-only, site-local and IPv4-compatible), and match IPv4-mapped and bracketed IPv6 peers against IPv4 entries. The middleware checks the resolved peer address, so behind an HTTP or SOCKS proxy it sees the proxy, not the target. A connection over a Unix socket, which has no peer IP address, now skips the check instead of failing with `IP\Exception\InvalidArgumentException`.
- fix(message): stop `serialize()` from writing the `Bcc` and `Resent-Bcc` fields, which exposed blind recipients to everyone who received the message; the message and its SMTP envelope still keep them
- fix(message, mime): reject CR, LF and NUL bytes in constructed header field names and values, mailbox parts, message ids and MIME parameters so they can no longer inject header fields, and escape mailbox display names and comments. Parsers stay lenient and skip received fields with unsafe bytes, `AddressList::parse()` and `Group::parse()` gain an optional `$lenient` flag, and constructing a `Mailbox` now rejects invalid dot-atoms.
- fix(mime): verify the S/MIME signer certificate named by the first `SignerInfo` against explicit trust anchors in `SMIME\Verifier`, checking its chain, validity, purpose and CA constraints, so an unknown self-signed certificate no longer establishes trust. An empty trust list now fails verification, intermediate certificates need CA `basicConstraints` and a current validity period, the signer certificate needs the S/MIME signing purpose, and verification needs a writable `sys_get_temp_dir()` within `open_basedir`.
- fix(mime): bound the size of each multipart part header section with the new `MultiPart\Parser` constructor argument `$maxHeaderSize` (16 KiB by default, 0 for unlimited), so a body that never ends its part headers can no longer make the parser buffer the whole input in memory; a part header section now ends at its first blank line, so LF-only part headers are no longer split at a CRLF blank line inside a later body
- fix(mime): skip the multipart preamble in constant memory instead of buffering it, so a huge preamble or a body without any boundary no longer exhausts memory
- fix(mime): percent-encode CR, LF and `"` in form-data field names, as browsers do, so a field name taken from user input can no longer inject header lines or fake parts into `MultiPart\Form` output
- fix(smtp): refuse a send with `requireTls` unless the transport uses TLS and the server advertises `REQUIRETLS`, instead of silently sending without it, and keep the EHLO capabilities of a pooled connection when it is reused
- fix(tls): give the handshake in `TLS\Listener::accept()` its own deadline, 10 seconds by default and configurable with the new `$handshakeTimeout` constructor argument (a `Psl\DateTime\Duration`), so a peer that connects and sends nothing can no longer block the listener forever; a failed or timed-out handshake throws `HandshakeFailedException` and closes the connection

### fixes

- fix(ansi): make `Ansi\strip()` and `Ansi\contains()` recognize every ECMA-48 escape sequence (CSI with private markers, sub-parameters, intermediate or non-letter final bytes, DCS, SOS, PM and APC strings, SS2 and SS3, and two-byte escapes such as `\e7`) instead of only simple CSI and OSC sequences, so `strip()` now removes more than before
- fix(async): stop `Semaphore` and `KeyedSemaphore` from running more operations than their limit by handing a released slot directly to the next pending operation; admission is now first in, first out, so a caller that releases a slot and calls `waitFor()` again queues behind pending operations
- fix(async): make a fiber (or {main}) hold one `KeyedSemaphore` slot per key however deep its re-entrant calls (an operation calling `waitFor()` again for its own key) go: a re-entrant call runs at once on that slot instead of taking a free one, so other callers for the key may start while it runs, up to the limit, and it no longer takes the count above the limit, which let unrelated callers in beyond it and made `waitForPending()` return at once. `getOngoingOperations()` and `getTotalOngoingOperations()` count each fiber once per key, not each re-entrant call, and `getOngoingOperations()` never exceeds the limit.
- fix(async): accept generators in `Async\all()`, `Async\any()` and `Async\first()`, which failed because they traverse their input more than once; non-array iterables are now consumed once up front, and `all()` awaits every awaitable even when keys repeat
- fix(async): remove the awaitable's callback when a cancellation token ends an `Awaitable::await()` call, instead of keeping it, with the wait's suspension and token, until the awaitable completes, possibly never, so repeated cancelled waits on a pending awaitable no longer accumulate memory
- fix(cache): throw `Cache\Exception\InvalidArgumentException` from the `LocalStore` constructor for a max size below 1, instead of looping forever on the first write
- fix(cache): let a `LocalStore` that is no longer referenced be garbage collected, instead of the event loop keeping it, its entries and its cleanup timer alive until the process ends
- fix(cache): unreference the `LocalStore` cleanup timer, so an event loop with nothing left to do but sweep expiring entries now returns instead of waiting for them to expire
- fix(channel): stop blocked `receive()` and `send()` calls from hanging when they queue behind another waiter: a call no longer waits on a closed channel, and a receive or send that leaves messages or space behind wakes the next waiter, including when the waiter ahead was cancelled
- fix(channel): stop the cancellation of a blocked `receive()` or `send()` that a message, free space or `close()` already woke from raising Revolt's "Must call suspend() before calling throw()" `Error` in the code that cancelled the token; the call completes with the outcome it was woken for
- fix(channel): make a woken `receive()` or `send()` that finds its message or space already taken (by `tryReceive()`, for example) wait again instead of throwing `EmptyChannelException` or `FullChannelException`
- fix(cidr): restrict `CIDR\Block::contains()` to the block's own address family, so `::/0` no longer contains IPv4 addresses and IPv4 blocks no longer contain IPv4-mapped IPv6 addresses, and mask host bits when a block is built (`192.168.1.5/24` is `192.168.1.0/24`). Code that relied on cross-family matching must check both forms of an address itself, as `DeniedDestinationsMiddleware` now does, which keeps its IPv4-mapped deny entries matching.
- fix(collection): make `Vector::fromItems()`, `MutableVector::fromItems()`, `Set::fromItems()` and `MutableSet::fromItems()` ignore the keys of the given iterable, so elements are no longer dropped when keys repeat and keys that are not valid array keys no longer throw a `TypeError`
- fix(compression): drain the pending output of `CompressingWriteHandle` and `DecompressingWriteHandle` in linear time instead of copying the unwritten remainder after every partial write to the inner handle
- fix(compression): make a retried `flush()` on `CompressingWriteHandle` and `DecompressingWriteHandle` write only what the inner handle has not accepted yet, instead of finishing the stream again and rewriting everything with a second trailer; an inner write that accepts nothing now fails the flush with an IO `RuntimeException`
- fix(crypto): fill every chunk in `StreamEncryptor::copySealed()` until it is full or the source reaches its end, and emit `TAG_FINAL` only there, so a short read from a pipe or socket no longer ends the sealed stream early; chunk sizes outside `1..MAX_CHUNK_BYTES` now throw `InvalidArgumentException` before anything is written
- fix(crypto): write each sealed frame together with its length prefix, and the stream header with the chunk size, in a single write, so a destination that fails or is cancelled is never left with a prefix and no frame; the format is unchanged
- fix(date-time): make `FormatPattern::UnixTimestamp` format and parse whole seconds since the Unix epoch instead of the year, `FormatPattern::JulianDay` give the Julian day number instead of an ordinal date, and `FormatPattern::IsoWeekDate` give an ISO 8601 week date such as `2024-W03-1` for every locale. The values of `JulianDay` and `IsoWeekDate` change, so `FormatPattern::from('yyyy DDD')` and `FormatPattern::from('Y-ww-E')` no longer match; pass those patterns as strings for the old output.
- fix(date-time): keep the milliseconds ICU parses in `Timestamp::parse()`, `DateTime::parse()` and `fromString()`, which dropped the fraction, so `format()` and `parse()` round trips with `FormatPattern::Rfc3339`, `Iso8601` and `LogTimestamp` keep milliseconds and a time with a fraction before the epoch no longer parses one second late; digits past the third are still dropped
- fix(date-time): throw `DateTime\Exception\OverflowException`, or `UnderflowException` when negative, for a `Duration` whose total whole seconds fall outside -PHP_INT_MAX..PHP_INT_MAX, `Duration::seconds(PHP_INT_MIN)` included, instead of emitting warnings and returning a wrong value, and compute large durations exactly. `Timestamp::plus()`, `minus()` and `fromParts()`, and `since()`, throw the same exceptions instead of a `TypeError` when the seconds leave the int range, and `Duration::toStdlib()` no longer fails beyond 12 digits of seconds and keeps the fraction in the interval's `f`.
- fix(dict): make `Dict\count_values()` and `Dict\associate()` ignore the keys of non-array iterables, so values are no longer lost when keys repeat (and `associate()` compares the real lengths) and object keys no longer throw a `TypeError`
- fix(dns): map response codes 8, 9 and 10 to NXRRSET, NOTAUTH and NOTZONE as RFC 2136 assigns them, correct the backing value of `ResponseCode::RecordSetShouldNotExist` from `XRRSET` to `YXRRSET`, and add the `RecordSetShouldExist` and `BadCookie` cases, decoding BADCOOKIE from the header and OPT bits. Exhaustive `match` expressions over `ResponseCode` need the new cases.
- fix(dns): stop `FallbackResolver` from trying the next resolver after NXDOMAIN or an `InvalidArgumentException`; only SERVFAIL, REFUSED and transport or protocol failures fall through, so a `FallbackResolver` that put a static resolver first must route those names with `SplitHorizonResolver` instead
- fix(dns): support IPv6 nameservers in `UDPResolver`, binding an IPv6 socket and comparing reply addresses in packed form
- fix(dns): resolve a nameserver hostname once per query in `UDPResolver` and compare the reply source with that address, so replies from a nameserver configured by hostname are no longer rejected
- fix(dns): reject EDNS Client Subnet source and scope prefix lengths outside the address family's range with `InvalidArgumentException`, instead of emitting warnings or encoding a scope of 256 as 0
- fix(dns): reject truncated NAPTR records with `ProtocolException` instead of reading past the end of the packet with warnings and deprecations
- fix(dns): trim exactly the whitespace that the resolv.conf, hosts file and ipconfig parsers match, so form feeds and vertical tabs are handled the same way on every PHP version and NUL bytes are no longer stripped
- fix(dnssec): trust both IANA root keys, KSK-2017 (tag 20326) and KSK-2024 (tag 38696), each with its own SHA-256 digest, so a root DNSKEY RRset signed by either key validates
- fix(dnssec): reject malformed DNSKEY data from the network (RSA keys with an empty exponent or modulus, invalid Ed448 or ECDSA keys) without emitting PHP warnings or deprecations that could escape validation error handling
- fix(dnssec): encode TXT string lengths without `chr()` when building canonical RDATA, avoiding a PHP 8.5 deprecation for user-constructed `TXTRecord` strings longer than 255 bytes
- fix(encoding): make the Base64 encoding and decoding handles produce and accept exactly what `Base64\encode()` and `Base64\decode()` do for any chunking: non-`Mime` streams no longer contain CRLF, `Mime` streams no longer end with CRLF, short reads no longer insert padding mid-stream, and the decoding handles reject data after padding, and non-`Mime` ones whitespace, with `RangeException`. Streams the old handles wrapped with CRLF still decode with `Variant::Mime` for the standard alphabet, but `UrlSafe` and `DotSlash` ones must be unwrapped first.
- fix(encoding): reject a trailing line feed in `Base64\decode()` for the non-`Mime` variants with `RangeException`, instead of silently dropping it or reporting a padding error
- fix(encoding): make the QuotedPrintable encoding and decoding handles produce exactly what `QuotedPrintable\encode()` and `decode()` do for any chunking: the encoding handles take optional `$maxLineLength` and `$lineEnding` constructor arguments, a lone CR breaks the line, a trailing line break is kept, a CRLF split by `flush()` stays one line break, and decoding no longer rewrites hard line breaks to CRLF
- fix(encoding): make `tryRead()` and `tryWrite()` of the Base64, Hex and QuotedPrintable handles return without waiting on the inner handle, leaving unwritten output for the next `write()` or `flush()`, and pass the cancellation token of `write()` to every inner write, so a write can now be cancelled or time out. Concurrent `write()` calls on one write handle still never lose or repeat output, but when the inner handle takes one call's output in several parts, another call's output can now come between them, where a PSL stream handle used to keep each call's output together.
- fix(encoding): keep `Hex\DecodingReadHandle` reading until it has decoded a whole byte, so `read()` no longer returns an empty string before the end of the data and `readByte()` no longer throws when a read ends in the middle of a hex pair
- fix(encoding): return an empty string from `Hex\decode('')` instead of throwing `RangeException`, so `Hex\decode(Hex\encode(''))` round-trips
- fix(encoding): split the encoded-words of `EncodedWord\encode()` on character boundaries, so a multi-byte character is never split across two encoded-words, as RFC 2047 forbids
- fix(encoding): Q-encode in `EncodedWord\encode()` every character an RFC 2047 phrase does not allow, so display names written by `Message\Address\Mailbox::toString()` and `Group::toString()` no longer carry literal `,`, `<` or `>` that change how recipients parse the address list
- fix(encoding): keep an `=` that is not followed by two hex digits literal when `EncodedWord\decode()` decodes a Q-encoded word, instead of turning it into a NUL or other control byte
- fix(encoding): throw `ParsingException` from `EncodedWord\decode()` for a charset mbstring does not support instead of leaking a `ValueError`, and accept RFC 2231 language tags such as `=?US-ASCII*EN?Q?...?=`
- fix(encoding): drop only linear whitespace (SP, HTAB and CRLF) between adjacent encoded-words in `EncodedWord\decode()`, so NUL, vertical tab and form feed are kept, on every PHP version
- fix(env): throw the new `Env\Exception\RuntimeException` when `current_dir()` or `set_current_dir()` fails, including for a path containing a NUL byte, instead of an `InvariantViolationException` or a leaked `ValueError`. Both extend `Psl\Exception\RuntimeException`, but a catch of `InvariantViolationException` no longer matches.
- fix(file): back off in milliseconds while waiting for a contended lock, instead of falling into a busy loop after the first retry because the delay was built in nanoseconds
- fix(file): release the lock and close the handle when `File\read()` or `File\write()` fails or is cancelled, instead of leaking the file descriptor, and the lock, for the rest of the process
- fix(file): reject paths containing NUL bytes with the package's exceptions instead of leaking a `ValueError`: opening one for reading throws `NotFoundException`, and opening one for writing throws `InvalidArgumentException` before anything is created
- fix(filesystem): handle paths containing NUL bytes without leaking a `ValueError`: the predicates return false, `canonicalize()` returns null, functions on existing nodes throw `NotFoundException`, and functions that create nodes throw `InvalidArgumentException`
- fix(filesystem): validate the source in `copy()` before skipping an existing destination, so a missing or unreadable source throws `NotFoundException` or `NotReadableException` even when the destination exists and `$overwrite` is false
- fix(filesystem): strip PHP's "function(arguments): " prefix from the reason in `RuntimeException` messages, as intended, so they read `Failed to delete file "/tmp/foo": Permission denied.`
- fix(graph): support `null` nodes: `hasNode()` finds them, so adding an edge no longer discards a `null` node's existing edges, and `shortest_path()` and `shortest_path_by()` no longer cut a path at a `null` node
- fix(graph): key float nodes by their exact value, so distinct floats such as `0.1 + 0.2` and `0.3` are no longer merged into one node, `NAN` no longer triggers a warning, and `-0.0` and `0.0` are the same node, as `===` says
- fix(h2): close the connection with `FLOW_CONTROL_ERROR` instead of `PROTOCOL_ERROR` for flow-control failures, an initial window above 2^31-1 included, and make a connection window overflow throw from `readEvent()` and fail the waiting calls instead of returning an empty event list
- fix(h2): count DATA padding and the pad-length field against flow-control windows, while keeping padding out of delivered data and `content-length` checks
- fix(h2): split outbound DATA into frames no larger than the peer's `SETTINGS_MAX_FRAME_SIZE`, setting `END_STREAM` only on the last one
- fix(h2): report HPACK decoding failures in HEADERS and PUSH_PROMISE as a `COMPRESSION_ERROR` connection error
- fix(h2): validate response `content-length` against the received DATA on client connections, keeping the exceptions for `HEAD`, bodyless statuses and successful `CONNECT`, so a mismatched response now fails with `StreamException` and closes the connection with `PROTOCOL_ERROR` instead of being accepted
- fix(h2): apply the pairs of a `SETTINGS` frame in wire order, so a later duplicate can no longer hide an invalid earlier value; `SettingsFrame` gains `$settingPairs` to keep that order
- fix(h2): apply locally sent `MAX_CONCURRENT_STREAMS` and `INITIAL_WINDOW_SIZE` changes once the peer acknowledges them, adjusting the windows of open streams, instead of keeping the values given at construction
- fix(h2): track local `SETTINGS_MAX_FRAME_SIZE` changes through their acknowledgements, enforcing a reduction only once it is acknowledged and allowing an increase while it is pending
- fix(h2): keep HPACK tables within the negotiated limits: the encoder starts at the peer's default 4096-byte table and grows only after the peer advertises a larger one, and the decoder applies its advertised limits as the peer acknowledges them
- fix(h2): accept any 32-bit `SETTINGS_HEADER_TABLE_SIZE` from the peer, capping the encoder table at 1 MiB, instead of closing the connection for large values
- fix(h2): reject a `SETTINGS_ENABLE_PUSH` value other than 0 from servers with `PROTOCOL_ERROR`
- fix(hpack): leave `Encoder` state untouched when `encode()` or `encodeWithStatus()` rejects a header list that exceeds the size limit, so a retry produces a block the peer can decode; iterable input is read only once
- fix(hpack): keep the table size selected by the peer's encoder when `Decoder::resize()` changes the allowed limit, shrinking it only when it exceeds the new limit
- fix(html): substitute invalid code unit sequences with U+FFFD in `Html\encode()`, as `encode_special_characters()` does, instead of returning an empty string for the whole input
- fix(http-client): rewind seekable request bodies to their original position before following a redirect that keeps the body, resend a non-seekable body only if it was never read, and throw `RequestException` otherwise, instead of resending an empty or partial body
- fix(http-client): keep the method and body of non-POST requests on 301 and 302 redirects instead of rewriting them to GET
- fix(http-client): bound the wait for `100 Continue`, one second by default and configurable with the new `ClientConfiguration::$expectContinueTimeout`, never send the body once a final response has arrived, and stop reusing a connection whose final response came before the upload
- fix(http-client): keep tunnel bytes that arrive together with the proxy's CONNECT response headers, instead of dropping them before the TLS handshake through the tunnel reads them
- fix(http-client): only strip SP and HTAB around the HTTP/1 chunk size, so sizes such as `5\0` or `5\n` are rejected as malformed
- fix(http-client): reject HTTP/1 status codes below 100
- fix(http-client): discard an idle HTTP/1 connection whose probe read fails, instead of failing the request that was about to reuse it
- fix(http-client): keep closing the remaining pooled streams and HTTP/2 sessions in the `PooledConnector` destructor when one of them fails to close
- fix(http-client): clamp the `RetryClient` backoff, so a large attempt count or multiplier no longer overflows into a negative or nonsensical delay
- fix(io): reset the cached end-of-data flag when `seek()` succeeds on stream and memory handles, so `readAll()` after rewinding no longer stops after the first chunk
- fix(io): keep the bytes `Reader` has buffered readable after it observes the end of the inner handle, so `read()`, `readAll()`, `readLine()` and `readFixedSize()` no longer lose them after a failed `readFixedSize()`
- fix(io): keep waiting in `Reader::readByte()` when a read returns an empty string before the end of the data, instead of throwing
- fix(io): keep the bytes read so far buffered when `Reader::readUntil()` or `readUntilBounded()` is interrupted, so a `readLine()` that times out no longer loses the start of the line
- fix(io): stop `Reader::readUntil()` and `readUntilBounded()` from reading the inner handle again once the end of the data was observed, which on an interactive terminal waited for more input after Ctrl-D
- fix(io): honour the cancellation token in `Reader::readAll()`, whose end-of-data check between chunks blocked past the deadline, and keep the bytes it read buffered whenever it throws; `Reader::reachedEndOfDataSource()` gains an optional `$cancellation` argument
- fix(io): write large buffers in linear time in stream handles and `WriteHandleConvenienceMethodsTrait`, which copied the unwritten remainder after every partial write (writing 16 MiB to a Unix socket went from about 1.4 s to about 40 ms)
- fix(io): destroy and close stream handles that are no longer referenced, such as those from `IO\pipe()`, the `Close*StreamHandle` classes and `File` handles, which the event loop kept alive with their stream open until the end of the process
- fix(io): throw `IO\Exception\RuntimeException` instead of `InvariantViolationException` when `spool()`, `pipe()`, the standard stream handles or `File` handles cannot open their stream, and stop a path containing a `%` conversion from turning that failure into an `ArgumentCountError`. Both extend `Psl\Exception\RuntimeException`, but a catch of `InvariantViolationException` no longer matches.
- fix(io): drain the bytes `TeeWriteHandle` owes its second handle in linear time instead of copying the remainder on every call
- fix(io): make `close()` of `ConcatReadHandle`, `JoinedReadWriteHandle` and `TeeWriteHandle` close every handle they own even when one fails, rethrowing the first failure, close a handle passed twice only once, and do nothing when called again
- fix(io): stop handle destructors from throwing an IO `RuntimeException` when closing fails (other throwables still escape), and make a repeated `close()` of `FixedLengthReadHandle` or `TruncatedReadHandle` a no-op
- fix(ip): compare `Address` bytes in binary in `compare()`, which compared addresses whose bytes form numeric strings as numbers (for example reporting `32.32.49.50` and `48.48.49.50` as equal)
- fix(iter): stop `Iter\is_empty()` at the first element instead of consuming the whole iterable, so it returns for infinite generators and leaves a generator on its first element
- fix(iter): let `Iter\random()` pick values whose keys repeat in the iterable, and accept keys that are not valid array keys, instead of dropping those values or throwing a `TypeError`
- fix(math): throw `Math\Exception\OverflowException` when `Math\sum()` overflows instead of returning a meaningless value with a PHP warning; a sum whose intermediate overflows cancel out still returns the exact result
- fix(math): reject a digit equal to the base in `Math\from_base()` (`from_base('2', 2)` returned 2), and throw `OverflowException` instead of a `TypeError` when the last digit overflows
- fix(message): only trim folding whitespace when parsing header values, addresses, message ids and the `Date` header, instead of PHP's default trim set, which silently dropped NUL and vertical tab, and form feed on PHP 8.6 only
- fix(message): look for the group colon and member commas outside quoted strings in `Group::parse()`, and quote ASCII specials in `Group::toString()`, so groups with quoted names survive a round trip
- fix(message): ignore angle brackets inside quoted strings and quoted pairs when parsing a mailbox, so display names such as `"Team <Europe>"` and quoted local parts parse
- fix(message): keep comments intact, including the commas, quotes and brackets inside them, when splitting address lists and group members
- fix(mime): only strip whitespace around multipart part header fields and structured header values (`MediaType`, `MediaRange`, `MediaPreferences`, `Parameters`, `ContentDisposition`, `ContentId`), instead of also dropping NUL and vertical tab, and form feed on PHP 8.6 only
- fix(mime): only strip WSP in DKIM relaxed canonicalization, so a header value or body line ending in NUL, vertical tab or form feed hashes the way verifiers compute it
- fix(mime): skip only the WHATWG whitespace bytes (SP, HTAB, LF, FF and CR) before sniffing text, so a leading vertical tab or NUL byte is no longer skipped and a leading form feed is skipped on every PHP version
- fix(mime): hash the DKIM-Signature header field itself as RFC 6376 requires; until now no signature produced by `DKIM\Signer` could be verified by a conforming verifier
- fix(mime): hash repeated signed header fields from the bottom up in `DKIM\Signer`, so messages with several `Received` or other repeated fields verify
- fix(mime): canonicalize an empty or blank-only body to the empty string under relaxed DKIM body canonicalization, so its `bh=` matches what verifiers compute
- fix(mime): write the DKIM `l=` tag whenever the body was truncated to the configured maximum length, so signatures made with `withBodyMaxLength()` alone verify
- fix(mime): fold the DKIM-Signature header field to 78-character lines instead of writing a first line that could exceed the 998-character limit of RFC 5322
- fix(mime): end the header section at the first blank line when DKIM-signing, and keep header-only and header-less messages intact, instead of splitting LF messages inside their body or adding extra blank lines
- fix(mime): keep the line-ending style of a message signed by `DKIM\Signer`, so LF messages are no longer returned with mixed line endings
- fix(mime): keep the filename and undo the transfer encoding of parts added with `MultiPart\Form::addPart()`, so uploads built from `Part\Data` or `Part\Text` arrive as files instead of base64 text fields; CR, LF and `"` in the filename are percent-encoded
- fix(mime): sniff JSON documents larger than the 4 KiB sniff window as `application/json` instead of `text/plain`
- fix(mime): require a valid BMP info header after the `BM` signature, so text starting with "BM" is no longer sniffed as `image/bmp`
- fix(mime): only recognize a multipart boundary delimiter at the start of a line and followed by `--` or by padding and a line break, so a body containing the delimiter elsewhere is no longer cut short
- fix(mime): enforce the multipart `maxParts` limit as soon as another part starts, instead of first reading the whole extra part
- fix(network): read and discard up to 1 MiB of unread bytes when closing a TCP stream or a `Network\socket_pair()` stream, so the peer sees an orderly end of the stream instead of a reset that drops the last bytes written
- fix(process): reject a working directory containing NUL bytes with a `RuntimeException` in `Command` and `Shell\execute()` instead of leaking a `ValueError` on PHP 8.6
- fix(process): make `withClearedEnvironment()` start the child from an empty environment and `withoutEnvironmentVariable()` withhold inherited variables, as documented, instead of still passing the parent's environment
- fix(process): reject environment variables with an empty name, a `=` in the name or a NUL byte with a `RuntimeException` before starting the process, instead of giving the child a truncated or mangled environment
- fix(process): report the signal that terminated a child through `ExitStatus::hasBeenSignaled()` and `getTerminationSignal()`, which always reported false and null
- fix(process): send and decode `Signal::User1` and `Signal::User2` with the platform's numbers on macOS, the BSDs and Solaris, where they used to send other signals (`SIGBUS` and `SIGSYS` on macOS)
- fix(process): pass environment variables with an empty value or an integer-like name to the child, which `proc_open()` dropped or passed without a name. A list of `NAME=VALUE` strings, which the documented `array<string, string>` type does not allow, now gives the child variables named `0`, `1` and so on instead of working by accident.
- fix(process): let the child of a command that does not change its environment inherit the parent's environment unchanged under the cli and phpdbg SAPIs, including variables whose name contains a space, `.` or `[`
- fix(range): stop `BetweenRange` iteration at an inclusive `PHP_INT_MAX` upper bound, where it looped forever yielding a float, and yield nothing for the empty exclusive range at `PHP_INT_MIN`
- fix(smtp): only strip SP and HTAB from EHLO capability lines, so control characters are no longer silently removed and a capability followed by one, such as `STARTTLS\f`, is detected the same way on every PHP version
- fix(smtp): encode each non-ASCII domain label of envelope addresses as an `xn--` A-label instead of punycoding the whole domain without the prefix
- fix(smtp): refuse to send a non-ASCII local part to a server that does not advertise SMTPUTF8, checking the sender and every recipient before any envelope command
- fix(str): trim the same explicit ASCII whitespace set in `Str\Byte\trim()`, `trim_left()` and `trim_right()` on every PHP version, so form feed is trimmed by default before PHP 8.6 as well
- fix(str): mask the argument of `Str\Byte\chr()` into 0..255, avoiding the PHP 8.5 deprecation for negative values while returning the same bytes
- fix(str): compute the needle length with the given encoding in `Str\after()`, `after_ci()`, `after_last()` and `after_last_ci()`, which returned slices at the wrong offset for non-UTF-8 encodings
- fix(str): report the actual regex error from `Str\replace_ci()` for an invalid UTF-8 haystack instead of an unrelated earlier PHP error
- fix(str): make `Str\replace_every_ci()` match with Unicode case folding for UTF-8, like `replace_ci()`, and accept numeric-string keys for other encodings instead of throwing a `TypeError`. Invalid UTF-8 input now throws `Str\Exception\InvalidArgumentException` when a needle is found.
- fix(str): throw `Str\Exception\InvalidArgumentException` for a negative width in `Str\truncate()` and `Str\width_slice()` instead of passing it on to `mb_strimwidth()`, which deprecates it
- fix(str): accept numeric-string keys in `Str\replace_every()` for non-UTF-8 encodings instead of throwing a `TypeError`
- fix(str): throw `Str\Exception\OutOfBoundsException` for an out-of-range offset in `Str\width_slice()` instead of a raw `ValueError`
- fix(terminal): stop `EventParser` spinning at 100% CPU when a read ends in the middle of a UTF-8 character
- fix(terminal): clamp `Gauge`, `BarChart` and `Sparkline` values before casting them to int and treat NAN as 0, avoiding the PHP 8.5 "not representable as an int" warning
- fix(terminal): validate UTF-8 continuation bytes in `EventParser`, so a broken character no longer swallows the keys or escape sequences after it; ill-formed input is now dropped instead of producing `Key` events with invalid UTF-8
- fix(terminal): parse SS3 key sequences (F1 to F4, application cursor and keypad keys, rxvt Ctrl+arrows) instead of reporting them as Alt+O followed by a character
- fix(terminal): handle the kitty keyboard protocol: map F1 to F4, Home and End, modified F5 to F12, keypad keys, F13 to F35 and non-ASCII text keys, drop key releases instead of reporting them as presses, and ignore Caps Lock and Num Lock. Super, Hyper and Meta are now reported as `super+`, `hyper+` and `meta+` prefixes, and Ctrl+Space as `ctrl+space`.
- fix(terminal): report horizontal wheel scrolling as the new `MouseKind::ScrollLeft` and `MouseKind::ScrollRight` cases instead of `ScrollUp` and `ScrollDown`; an exhaustive `match` over `MouseKind` must handle them
- fix(terminal): scan control sequences as ECMA-48 defines them, so unknown sequences no longer swallow the keys typed after them, and map the rxvt modified keys and the Linux console F1 to F5
- fix(terminal): drop the events of mouse buttons 8 to 11 (back, forward and two more) instead of reporting them as left, middle and right button events
- fix(tls): keep the record OpenSSL committed during a short TLS write and send it before any later bytes, instead of silently corrupting the byte stream when a caller wrote other bytes after `tryWrite()` returned 0 or cancelled a `writeAll()`. `write()` and `writeAll()` are now serialized, and `shutdown()` throws while such a record is pending.
- fix(tls): check `ClientConfiguration::$peerFingerprints` against the SHA-256 fingerprint of the captured peer certificate after the handshake, since handing them to PHP made every pinned handshake fail, even with a matching pin
- fix(tls): destroy and close TLS streams that are no longer referenced, instead of keeping them and their socket open until the cycle collector runs
- fix(tls): write large buffers in linear time in `writeAll()` instead of copying the unwritten remainder after every write
- fix(tls): read and discard up to 1 MiB of unread bytes, such as TLS 1.3 session tickets, after sending close_notify on `close()`, so the peer sees an orderly end of the stream instead of a reset that drops the last bytes written
- fix(type): reject NAN, INF and out-of-range floats in `Type\int()`, `uint()`, `positive_int()` and `int_range()` coercion without the PHP 8.5 cast warning
- fix(type): reject NAN and infinite floats in `Type\numeric_string()` coercion instead of returning "NAN", "INF" or "-INF"
- fix(type): return the slow-path result when `Type\shape()` coercion falls back after an element fails, instead of a shape missing that element and every one after it
- fix(type): throw `InvariantViolationException` from `Type\int_range()` when the minimum is greater than the maximum, instead of building a type that matches nothing
- fix(type): stop `Type\iterable()->matches()` from consuming generators: it now returns false for a generator, unless both types are `mixed()`, and leaves it untouched, while `always_assert()` and `json_decoded()` validate generators through `assert()`
- fix(unix): reject socket paths containing a NUL byte, except Linux abstract names (which start with one), with `Network\Exception\InvalidArgumentException` instead of silently using the path truncated at the first NUL byte
- fix(unix): let `Unix\listen()` and `Socket::listen()` bind names in the Linux abstract namespace, which threw "Error retrieving local address."
- fix(unix): report Unix socket paths containing `:` as Unix addresses instead of truncated TCP addresses
- fix(uri): stop silently stripping NUL, vertical tab and form feed from URI template varspecs, which made the expansion depend on the PHP version
- fix(uri): reject relative references whose first path segment contains a colon, such as `://host`, as RFC 3986 requires
- fix(vec, dict): compare values with strict identity in `Vec\unique_scalar()` and `Dict\unique_scalar()` for arrays and other iterables alike, so `1`, `'1'`, `1.0` and `true` are distinct and no float is converted to a string

### deprecations

- deprecated(str): `Str\metaphone()` because the underlying PHP function is deprecated as of PHP 8.6. Use a userland phonetic matching library instead.

## 6.3.0

### features

* feat(type): add `numeric` type - [#793](https://github.com/php-standard-library/php-standard-library/pull/793) by @gsteel

### fixes

- fix(http-client): validate all `Transfer-Encoding` response fields and reject invalid or unsupported transfer codings instead of falling back to `Content-Length` - [#806](https://github.com/php-standard-library/php-standard-library/pull/806)
- fix(type): allow interface names in `Type\class_string()` when no class is specified - [#802](https://github.com/php-standard-library/php-standard-library/issues/802)

## 6.2.1

### security

- fix(h2): validate `content-length` header against received DATA on server connections, preventing HTTP/2 request smuggling on `Psl\H2\ServerConnection` (GHSA-pw9p-jvrm-f7rm)

## 6.2.0

### features

- feat(either-or-both): introduce `EitherOrBoth` component - a three-variant disjoint union (`Left` / `Right` / `Both`) for values that may be present on either or both of two sides, inspired by Rust's `itertools::EitherOrBoth` and Haskell's `Data.These`. Primary use case: three-way diff of two collections (insert / delete / update events). Secondary: layered config merge, multi-source enrichment, dual-validation, snapshot comparison. Full `map` / `mapLeft` / `mapRight` / `mapAny` / `swap` / `proceed` / `apply` / `containsLeft` / `containsRight` surface; `left()` / `right()` / `both()` free constructors.
- feat(iter): add `Iter\merge_join_by` and `Iter\merge_join_by_key` - full-outer-join stream producers that yield `EitherOrBoth` events as a rewindable `Iter\Iterator`. `merge_join_by` is a lazy two-cursor merge over sorted inputs (O(1) memory on first traversal, `Psl\Comparison\Order`-returning comparator, matching Rust's `itertools::merge_join_by`); `merge_join_by_key` is a hash-based variant for keyed inputs that do not need to be pre-sorted (O(|right|) memory).
- feat(io): add `IO\IterableReadHandle` - a streaming `ReadHandleInterface` that lazily consumes an `iterable<string>` without buffering the entire content in memory
- feat(io): add `IO\ConcatReadHandle` - reads from two handles in sequence, switching to the second when the first reaches EOF
- feat(io): add `IO\JoinedReadWriteHandle` - joins a `ReadHandleInterface` and `WriteHandleInterface` into a single read-write handle, delegating all operations to the respective underlying handle
- feat(io): add `IO\TeeWriteHandle` - writes to two handles simultaneously with backpressure buffering when the second handle is slower
- feat(io): add `IO\SinkWriteHandle` - a `/dev/null`-like write handle that discards all written data
- feat(io): add `IO\SinkReadHandle` - a read handle that is always at EOF, unlike `MemoryHandle('')` which only reports EOF after the first read
- feat(io): add `IO\SinkReadWriteHandle` - a sink that discards writes and always reports EOF on reads
- feat(io): add `IO\TruncatedReadHandle` - reads up to N bytes from an underlying handle, silently reporting EOF when the limit is reached
- feat(io): add `IO\BoundedReadHandle` - reads up to N bytes from an underlying handle, throwing `RuntimeException` if the underlying handle has more data than the limit allows
- feat(io): add `IO\FixedLengthReadHandle` - reads exactly N bytes from an underlying handle, throwing `RuntimeException` on premature EOF
- feat(io): add `IO\copy_chunked()` and `IO\copy_bidirectional_chunked()` - variants of `IO\copy()` and `IO\copy_bidirectional()` that accept a custom chunk size
- feat(http-client): add `SendConfiguration::$connectionTimeout` - per-request maximum duration for establishing a connection (TCP + TLS handshake), using a linked cancellation token
- feat(type): support `'true'`/`'false'` string literals in `Type\bool()` coercion - [#735](https://github.com/php-standard-library/php-standard-library/pull/735) by @verweto
- feat(mime): introduce `MIME` component - comprehensive MIME toolkit implementing RFC 2045-2049 and related standards
  - Media type parsing, validation, and content negotiation (`MediaType`, `MediaRange`, `MediaPreferences`) per RFC 2045, RFC 6838, RFC 9110
  - MIME part construction with automatic transfer encoding (`Part\Text`, `Part\Data`) per RFC 2045
  - Streaming multipart body construction and parsing (`MultiPart\Composite`, `MultiPart\Alternative`, `MultiPart\Related`, `MultiPart\Form`, `MultiPart\Parser`) per RFC 2046, RFC 2387, RFC 7578
  - Immutable header collection with RFC 5322 line folding (`Headers`)
  - Content-Disposition parsing with safe filename extraction (`ContentDisposition`) per RFC 2183
  - Content-ID parsing, generation, and `cid:` URI support (`ContentId`) per RFC 2392
  - RFC 2231 parameter encoding/decoding with continuations and charset conversion (`Parameters`)
  - Content sniffing from bytes and seekable handles (`Sniff\from_string`, `Sniff\from_handle`)
  - S/MIME signing, verification, encryption, and decryption (`SMIME\Signer`, `SMIME\Verifier`, `SMIME\Encryptor`, `SMIME\Decryptor`) per RFC 5652, RFC 8551
  - DKIM message signing with RSA-SHA256 and Ed25519-SHA256 (`DKIM\Signer`) per RFC 6376, RFC 8301, RFC 8463
- feat(message): introduce `Message` component - RFC 5322 internet message construction, parsing, and serialization
  - Typed header fields with fluent `with*()` methods (`Message`) per RFC 5322
  - Address methods accept `string|Mailbox|AddressList` for convenience
  - Message body as `PartInterface` from the MIME component per RFC 2045
  - Streaming `serialize()` and `parse()` accepting string or `ReadHandleInterface`
  - Reply, reply-all, and forward with automatic threading headers (In-Reply-To, References) per RFC 5322
  - SMTP envelope derivation (`Envelope`) per RFC 5321
  - RFC 5322 address parsing: `Mailbox`, `Group`, `AddressList` with RFC 2047 encoded-word support
- feat(smtp): introduce `SMTP` component - RFC 5321 SMTP client with connection pooling, TLS, and authentication
  - Low-level `Connection` implementing `Network\StreamInterface` for protocol-level SMTP operations
  - High-level `Transport` managing the full SMTP lifecycle: connect, EHLO/HELO, STARTTLS, AUTH, send, RSET
  - Connection pooling with automatic reuse across multiple sends via `TCP\SocketPool`
  - Implicit TLS (port 465) and STARTTLS upgrade (RFC 3207) with automatic detection
  - EHLO with HELO fallback per RFC 5321
  - SMTP pipelining (RFC 2920) for reduced round-trips
  - BDAT chunking (RFC 3030) to avoid dot-stuffing overhead on large messages
  - Enhanced status codes (RFC 3463) with structured `EnhancedStatusCode` parsing
  - DSN delivery status notifications (RFC 3461) via `SendConfiguration`
  - REQUIRETLS (RFC 8689) for end-to-end TLS enforcement
  - MT-PRIORITY (RFC 6710) message priority with STANAG 4406 levels
  - DELIVERBY (RFC 2852) delivery deadline specification
  - FUTURERELEASE (RFC 4865) deferred delivery via `Duration` or `DateTimeInterface`
  - BINARYMIME (RFC 3030), 8BITMIME (RFC 6152), SMTPUTF8 (RFC 6531) capability negotiation
  - Punycode IDN encoding for internationalized domain names in addresses
  - Partial recipient success with `DeliveryReport` for per-recipient rejection tracking
  - CRLF and null byte injection protection via `PossibleAttackException`
  - Five authentication mechanisms: PLAIN (RFC 4616), LOGIN, XOAUTH2, CRAM-MD5 (RFC 2195), SCRAM-SHA-256 (RFC 7677)
  - Immutable `TransportConfiguration` and `SendConfiguration` with fluent `with*()` builders
  - Configurable pipelining, chunking, chunk size, and partial success behavior
- feat(dns): introduce `DNS` component - async DNS resolution with full protocol support
  - `SystemResolver` mirrors OS DNS behavior, usable as a default parameter value
  - UDP and pooled TCP resolvers with automatic TCP fallback on truncation
  - DNS-over-TLS (DoT) via TLS client configuration on `TCPResolver`
  - `RacingResolver` races multiple nameservers concurrently for fastest response
  - `SplitHorizonResolver` routes queries by domain name for split-horizon DNS
  - `SearchDomainResolver` expands short names using search domain lists
  - `HostsFileResolver` checks the OS hosts file before network queries
  - `CachedResolver` decorator with TTL-aware caching via `Cache\StoreInterface`
  - `StaticResolver` for hardcoded records in tests and development
  - Cross-platform system configuration loading (Linux, macOS, Windows) via async process execution
  - EDNS0 support: DNS cookies, client subnet, padding, NSID, TCP keepalive, key tag, extended DNS error
  - 20+ record types: A, AAAA, NS, CNAME, MX, TXT, SRV, SOA, PTR, CAA, SSHFP, TLSA, SVCB, HTTPS, LOC, NAPTR, DS, DNSKEY, RRSIG, NSEC, NSEC3
  - DNS-over-HTTPS (DoH) via `HTTPSResolver` using the HTTP client (RFC 8484)
  - DNS name validation with null byte and label length enforcement
  - `ResponseCode` helper methods: `isSuccess()`, `isError()`, `isServerError()`, `isNameError()`
- feat(dnssec): introduce `DNSSEC` component - full DNSSEC validation chain
  - `SecureResolver` validates RRSIG signatures on every response
  - `TrustChainResolver` walks DS/DNSKEY chain from root to target zone
  - `CachedTrustChainResolver` caches trust chain results for performance
  - `StaticTrustChainResolver` for offline/air-gapped environments
  - NSEC and NSEC3 authenticated denial of existence proof validation
  - 7 signature algorithms: RSA/SHA-1, RSA/SHA-256, RSA/SHA-512, ECDSA P-256, ECDSA P-384, Ed25519, Ed448
  - 4 specific validation exceptions: `SignatureFailedException`, `BrokenTrustChainException`, `InvalidProofException`, `UnsignedResponseException`
- feat(http-message): introduce `HTTP Message` component - version-agnostic HTTP message abstractions
  - `Request` and `Response` immutable value objects with streaming body (`ReadHandleInterface`)
  - `FieldMap` ordered, case-insensitive header field collection with lazy index
  - `ProtocolVersion` enum covering HTTP/1.0, HTTP/1.1, HTTP/2, and HTTP/3
  - Trailers modelled as `Async\Awaitable<FieldMap>` for HTTP/2 and chunked HTTP/1.1
  - HTTP status code and method constants per RFC 9110
  - `reason_phrase()` function for HTTP/1.x status line serialization
  - Fluent `with*()` mutation methods on both Request and Response
  - `Transaction` groups the final response with informational (1xx) responses and server push exchanges
  - `Exchange` represents a pushed request/response pair for HTTP/2 server push
- feat(http-client): introduce `HTTP Client` component - async HTTP/1.1 and HTTP/2 client with connection pooling
  - `Client` with automatic protocol negotiation via ALPN (HTTP/2 preferred, HTTP/1.1 fallback)
  - `PooledConnector` with HTTP/1.x idle connection reuse and HTTP/2 session sharing across concurrent requests
  - HTTP/2 multiplexing with event-driven stream dispatch via `H2Multiplexer` and per-stream `H2Stream` state
  - Transparent reconnection on connection failure (GOAWAY, TCP reset) via pool-backed reconnect closures
  - `RedirectClient` decorator following 301/302/303/307/308 redirects with method rewriting per RFC 9110, cross-origin credential stripping, and auto-referrer
  - `RetryClient` decorator with configurable exponential backoff and jitter for transport-level failures
  - `SendConfiguration` for per-request overrides (body size limits, TLS, protocol versions, tunnel) merged with `ClientConfiguration` defaults
  - `DeniedDestinationsMiddleware` for SSRF protection against private IP ranges (RFC 1918, RFC 4193, loopback, link-local)
  - Connection-level middleware via `HandlerInterface` / `MiddlewareInterface` chain with access to peer address and TLS state
  - SOCKS5 proxy support via `ClientConfiguration::$proxy` using `Psl\Socks\Connector`
  - HTTP CONNECT tunnel support via `ClientConfiguration::$tunnel` with TLS and proxy authentication
  - `noTunneling` host bypass rules (exact match, domain suffix, wildcard)
  - H2 flow control with BDP auto-tuning for dynamic receive window sizing
  - Lazy, pull-based response body reading via `ResponseBodyHandle` implementing `ReadHandleInterface`
  - 104 integration tests against httpbun covering methods, redirects, auth, caching, cookies, concurrency, and streaming
- feat(h2): introduce unified `Configuration` replacing deprecated `ClientConfiguration` and `ServerConfiguration`
  - Both `ClientConnection` and `ServerConnection` now accept `Configuration` in addition to their legacy config types
  - `ClientConnection` now supports BDP auto-tuning when using `Configuration` with `maxReceiveWindowSize` set
- feat(tcp): add `bindTo` option to `ConnectConfiguration` for binding to a specific local address before connecting
- feat(tcp): add `bindTo` option to `ListenConfiguration` for binding to a specific local address before listening
- feat(tcp): add `withBindTo()` fluent builder method to both `ConnectConfiguration` and `ListenConfiguration`
- feat(tcp): `connect()` now respects `ConnectConfiguration::$bindTo` by setting the `socket.bindto` stream context option
- feat(type): Allow null argument to `Type\class_string` to assert or coerce bare `class-string`

### type system

- chore(types): annotate read-only template parameters with `@template-covariant` across the library, allowing values to flow into wider declarations:
    - `Async\Awaitable<T>`, `Promise\PromiseInterface<T>`
    - `Result\ResultInterface<T>`, `Result\Success<T>`, `Result\Failure<T, Te>`
    - `Option\Option<T>`
    - `Either\Either<TLeft, TRight>`, `Either\Left<TLeft>`, `Either\Right<TRight>`
    - `Tree\NodeInterface<T>`, `Tree\LeafNode<T>`, `Tree\TreeNode<T>`
- chore(collection): annotate immutable collection template parameters with `@template-covariant` (`CollectionInterface`, `AccessibleCollectionInterface`, `IndexAccessInterface`, `MapInterface`, `Map`, `SetInterface`, `Set`, `VectorInterface`, `Vector`); mutable collections remain invariant.
- chore(async): annotate `Sequence`, `KeyedSequence`, `Semaphore`, `KeyedSemaphore` template parameters with the correct variance; keys/inputs are `@template-contravariant` (write-position only) and outputs are `@template-covariant` (read-position only).
- chore(collection): relax `Vector::getIterator()` and `MutableVector::getIterator()` return type from `Iterator<int<0, max>, T>` to `Iterator<int, T>`.

### fixes

- fix(io): `IO\copy()` now flushes the writer after copying if it implements `BufferedWriteHandleInterface`, ensuring no data remains in an internal buffer
- fix(async): `State::subscribe()` and `State::invokeCallbacks()` no longer capture `$this` in queued closures, preventing delayed garbage collection of `Deferred`/`Awaitable` chains
- fix(uri): bare IPv6 addresses (e.g., `http://::1/path`) are now correctly parsed as `IPHost` instead of being misparsed as a registered name with a numeric port
- fix(h2): separate `maxConcurrent` (peer's limit on our streams) from `peerMaxConcurrent` (our limit on peer's streams) in `StreamTable`, preventing the client's own SETTINGS from limiting its outgoing streams
- fix(h2): `BDPEstimator` now produces an initial connection-level WINDOW_UPDATE during `initialize()` to bring the receive window from the RFC default (65535) up to `initialWindowSize`, preventing flow-control stalls when many concurrent streams receive data simultaneously
- fix(h2): `notifyWindowWaiters()` now copies the waiter list before iterating and properly removes satisfied waiters, preventing iteration corruption and memory leaks
- fix(io): `ResourceHandle` readable/writable callbacks now null out the suspension reference before calling `resume()`, preventing "Must call suspend() before calling throw()" errors during handle destruction
- fix(io): `ResourceHandle::doRead()` and `doWrite()` cancellation subscriptions now null out the suspension reference before throwing, preventing double-wake when cancellation and close race during PHP shutdown
- fix(encoding): RFC 2047 encoded-word encoder no longer embeds CRLF line folding in the encoded output; line folding is now the responsibility of the header serializer, fixing header/body separation issues in serialized messages

### deprecations

- deprecated(tcp): `Socket` class -- use `ConnectConfiguration::$bindTo` or `ListenConfiguration::$bindTo` instead. Will be removed in PSL 7.0.
- deprecated(h2): `ClientConfiguration` -- use `Configuration` instead. Will be removed in PSL 7.0.
- deprecated(h2): `ServerConfiguration` -- use `Configuration` instead. Will be removed in PSL 7.0.

### ci

- ci: add httpbun, microsocks, and tinyproxy services to unit-tests, code-coverage, mutation-tests, and package-tests workflows for HTTP client integration testing

## 6.1.1

### fixes

- fix(str): `Str\chr()` now throws `OutOfBoundsException` for invalid Unicode code points instead of silently returning an empty string
- fix(str): `Str\from_code_points()` now validates code points and throws `OutOfBoundsException` for out-of-range values, surrogates, and negative inputs instead of producing invalid UTF-8; implementation now delegates to `Str\chr()` for consistent behavior

### other

- chore(str): clarify `width()`, `truncate()`, and `width_slice()` PHPDoc to explicitly reference `mb_strwidth()`/`mb_strimwidth()` semantics
- chore: make all function calls explicit across the codebase, eliminating PHP namespace fallback resolution
- chore(h2): skip timer-sensitive rate limiter test on Windows

## 6.1.0

### features

- feat(io): introduce `Psl\IO\BufferedWriteHandleInterface`, extending `WriteHandleInterface` with `flush()` for handles that buffer data internally before writing to an underlying resource
- feat: introduce `Compression` component with streaming compression/decompression abstractions for IO handles. Provides `CompressorInterface`, `DecompressorInterface`, four handle decorators (`CompressingReadHandle`, `CompressingWriteHandle`, `DecompressingReadHandle`, `DecompressingWriteHandle`), and convenience functions `compress()` and `decompress()`
- feat: introduce `HPACK` component - RFC 7541 HPACK header compression for HTTP/2
- feat: introduce `H2` component - HTTP/2 binary framing protocol implementation
- feat: introduce `Cache` component - async-safe in-memory LRU cache with per-key atomicity via `KeyedSequence`, proactive TTL expiration via event loop

## 6.0.1

- fix(io): `Reader::readUntil()` and `Reader::readUntilBounded()` no longer treat empty reads from non-blocking streams as EOF, fixing `readLine()` returning the entire content instead of individual lines when used with non-blocking streams
- fix(docs): source links now correctly point to `packages/{name}/src/Psl/` instead of the non-existent top-level `src/Psl/` path
- internal: add `splitter audit` command to verify organization repository settings (wiki, issues, discussions, PRs, tag immutability).

## 6.0.0

### breaking changes

- **BC** - All `null|Duration $timeout` parameters across IO, Network, TCP, TLS, Unix, UDP, Socks, Process, and Shell components have been replaced with `CancellationTokenInterface $cancellation = new NullCancellationToken()`. This enables both timeout-based and signal-based cancellation of async operations.
- **BC** - Removed `Psl\IO\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - Removed `Psl\Network\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - Removed `Psl\Process\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - Removed `Psl\Shell\Exception\TimeoutException` - use `Psl\Async\Exception\CancelledException` instead.
- **BC** - `Psl\IO\CloseHandleInterface` now requires an `isClosed(): bool` method.
- **BC** - `Network\SocketInterface::getLocalAddress()` and `Network\StreamInterface::getPeerAddress()` no longer throw exceptions. Addresses are resolved at construction time and cached, making these O(1) property lookups with no syscall.
- **BC** - `BufferedReadHandleInterface::readLine()` now always splits on `"\n"` instead of `PHP_EOL`. Trailing `"\r"` is stripped, so both `"\n"` and `"\r\n"` line endings are handled consistently across all platforms. Use `readUntil(PHP_EOL)` for system-dependent behavior.
- **BC** - `Psl\TLS\ServerConfig` renamed to `Psl\TLS\ServerConfiguration`.
- **BC** - `Psl\TLS\ClientConfig` renamed to `Psl\TLS\ClientConfiguration`.
- **BC** - All variables and parameters across the codebase now use `$camelCase` naming instead of `$snake_case`.
- **BC** - `TCP\listen()`, `TCP\connect()`, `TCP\Socket::listen()`, `TCP\Socket::connect()` now accept configuration objects (`TCP\ListenConfiguration`, `TCP\ConnectConfiguration`) instead of individual parameters for socket options.
- **BC** - `Unix\listen()` and `Unix\Socket::listen()` now accept `Unix\ListenConfiguration` instead of individual parameters.
- **BC** - `UDP\Socket::bind()` now accepts `UDP\BindConfiguration` instead of individual parameters.
- **BC** - `TCP\Socket` setter/getter methods (`setReuseAddress`, `setReusePort`, `setNoDelay`, etc.) have been removed. Use configuration objects instead.
- **BC** - `TCP\Connector` constructor now accepts `TCP\ConnectConfiguration` instead of `bool $noDelay`.
- **BC** - `Socks\Connector` constructor changed from `(string $proxyHost, int $proxyPort, ?string $username, ?string $password, ConnectorInterface $connector)` to `(ConnectorInterface $connector, Socks\Configuration $configuration)`.
- **BC** - Renamed `ingoing` to `ongoing` across `Semaphore`, `Sequence`, `KeyedSemaphore`, and `KeyedSequence` (`hasIngoingOperations()` -> `hasOngoingOperations()`, `getIngoingOperations()` -> `getOngoingOperations()`, etc.).

### features

- feat(async): introduce `Psl\Async\CancellationTokenInterface` for cancelling async operations
- feat(async): introduce `Psl\Async\NullCancellationToken` - no-op token used as default parameter value
- feat(async): introduce `Psl\Async\SignalCancellationToken` - manually triggered cancellation via `cancel(?Throwable $cause)`
- feat(async): introduce `Psl\Async\TimeoutCancellationToken` - auto-cancels after a `Duration`, replacing the old `Duration $timeout` pattern
- feat(async): introduce `Psl\Async\LinkedCancellationToken` - cancelled when either of two inner tokens is cancelled, useful for combining a request-scoped token with an operation-specific timeout
- feat(async): introduce `Psl\Async\Exception\CancelledException` - thrown when a cancellation token is triggered; the cause (e.g., `TimeoutException`) is attached as `$previous`. Use `$e->getToken()` to identify which token triggered the cancellation.
- feat(async): `Async\sleep()` now accepts an optional `CancellationTokenInterface` parameter, allowing early wake-up on cancellation
- feat(async): `Awaitable::await()` now accepts an optional `CancellationTokenInterface` parameter
- feat(async): `Sequence::waitFor()` and `Sequence::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(async): `Semaphore::waitFor()` and `Semaphore::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(async): `KeyedSequence::waitFor()` and `KeyedSequence::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(async): `KeyedSemaphore::waitFor()` and `KeyedSemaphore::waitForPending()` now accept an optional `CancellationTokenInterface` parameter
- feat(channel): `SenderInterface::send()` and `ReceiverInterface::receive()` now accept an optional `CancellationTokenInterface` parameter
- feat(network): `ListenerInterface::accept()` now accepts an optional `CancellationTokenInterface` parameter
- feat(tcp): `TCP\ListenerInterface::accept()` now accepts an optional `CancellationTokenInterface` parameter
- feat(unix): `Unix\ListenerInterface::accept()` now accepts an optional `CancellationTokenInterface` parameter
- feat(tls): `TLS\Acceptor::accept()`, `TLS\LazyAcceptor::accept()`, `TLS\ClientHello::complete()`, and `TLS\Connector::connect()` now accept an optional `CancellationTokenInterface` parameter - cancellation propagates through the TLS handshake
- feat(tls): `TLS\TCPConnector::connect()` and `TLS\connect()` now pass the cancellation token through to the TLS handshake
- feat(async): introduce `Psl\Async\TaskGroup` for running closures concurrently and awaiting them all with `defer()` + `awaitAll()`
- feat(async): introduce `Psl\Async\WaitGroup`, a counter-based synchronization primitive with `add()`, `done()`, and `wait()`
- feat(encoding): introduce `Psl\Encoding\QuotedPrintable\encode()`, `decode()`, and `encode_line()` for RFC 2045 quoted-printable encoding with configurable line length and line ending
- feat(encoding): introduce `Psl\Encoding\EncodedWord\encode()` and `decode()` for RFC 2047 encoded-word encoding/decoding in MIME headers (B-encoding and Q-encoding with automatic selection)
- feat(tls): introduce `TLS\ListenerInterface` and `TLS\Listener`, wrapping any `Network\ListenerInterface` to perform TLS handshakes on accepted connections
- feat(encoding): add `Base64\Variant::Mime` for RFC 2045 MIME Base64 with 76-char line wrapping and CRLF, using constant-time encoding/decoding
- feat(encoding): introduce streaming IO handles for Base64 (`EncodingReadHandle`, `DecodingReadHandle`, `EncodingWriteHandle`, `DecodingWriteHandle`), QuotedPrintable (same 4), and Hex (same 4), bridging `Psl\IO` and `Psl\Encoding` for transparent encode/decode on read/write
- feat(io): introduce `Psl\IO\BufferedReadHandleInterface`, extending `ReadHandleInterface` with `readByte()`, `readLine()`, `readUntil()`, and `readUntilBounded()`
- feat(io): `Psl\IO\Reader` now implements `BufferedReadHandleInterface`
- feat(tcp): introduce `TCP\ListenConfiguration` and `TCP\ConnectConfiguration` with immutable `with*` builder methods
- feat(unix): introduce `Unix\ListenConfiguration` with immutable `with*` builder methods
- feat(udp): introduce `UDP\BindConfiguration` with immutable `with*` builder methods
- feat(socks): introduce `Socks\Configuration` with immutable `with*` builder methods for proxy host, port, and credentials
- feat(tcp): introduce `TCP\RestrictedListener`, wrapping a listener to restrict connections to a set of allowed `IP\Address` and `CIDR\Block` entries
- feat(network): introduce `Network\CompositeListener`, accepting connections from multiple listeners concurrently through a single `accept()` call
- feat: introduce `URI` component - RFC 3986 URI parsing, normalization, reference resolution, and RFC 6570 URI Template expansion (Levels 1–4), with RFC 5952 IPv6 canonical form and RFC 6874 zone identifiers
- feat: introduce `IRI` component - RFC 3987 Internationalized Resource Identifier parsing with Unicode support, RFC 3492 Punycode encoding/decoding, and RFC 5891/5892 IDNA 2008 domain name processing
- feat: introduce `URL` component - strict URL type with scheme and authority validation, default port stripping for known schemes, and URI/IRI conversion
- feat: introduce `Punycode` component - RFC 3492 Punycode encoding and decoding for internationalized domain names
- fix(tcp): `RetryConnector` backoff sleep now respects cancellation tokens, allowing retry loops to be cancelled during the delay
- fix(io, str): `IO\write()`, `IO\write_line()`, `IO\write_error()`, `IO\write_error_line()`, and `Str\format()` no longer pass the message through `sprintf`/`vsprintf` when no arguments are given, preventing format string errors when the message contains `%` characters

### migration guide

Replace `Duration` timeout parameters with `TimeoutCancellationToken`:

```php
// Before (5.x)
$data = $reader->read(timeout: Duration::seconds(5));

// After (6.0)
$data = $reader->read(cancellation: new Async\TimeoutCancellationToken(Duration::seconds(5)));
```

For manual cancellation (e.g., cancel all request IO when a client disconnects):

```php
$token = new Async\SignalCancellationToken();

// Pass to all request-scoped IO
$body = $reader->readAll(cancellation: $token);

// Cancel from elsewhere
$token->cancel();
```

## 5.5.0

### features

- feat(io): added `Reader::readUntilBounded(string $suffix, int $max_bytes, ?Duration $timeout)` method, which reads until a suffix is found, but throws `IO\Exception\OverflowException` if the content exceeds `$max_bytes` before the suffix is encountered - [#620](https://github.com/php-standard-library/php-standard-library/pull/620) - by @azjezz
- feat(io): added `IO\Exception\OverflowException` exception class - [#620](https://github.com/php-standard-library/php-standard-library/pull/620) - by @azjezz
- feat(type): add `Type\json_decoded()` type for transparent JSON string coercion - [#619](https://github.com/php-standard-library/php-standard-library/pull/619) by @veewee
- feat(type): add `Type\nullish()` type for optional-and-nullable shape fields - [#618](https://github.com/php-standard-library/php-standard-library/pull/618) by @veewee

## 5.4.0

### features

- feat(dict, vec): add filter_nonnull_by and map_nonnull - [#576](https://github.com/php-standard-library/php-standard-library/pull/576) by @Dima-369
* feat(tcp): add `backlog` parameter to `TCP\listen()` for configuring the pending connection queue size - [#617](https://github.com/php-standard-library/php-standard-library/pull/617) - by @azjezz
* feat(tcp): listener now drains the accept backlog in a loop for higher throughput - [#617](https://github.com/php-standard-library/php-standard-library/pull/617) - by @azjezz

### other

* chore: update dev dependencies, and re-format the codebase using latest mago version - [#616](https://github.com/php-standard-library/php-standard-library/pull/616) by @azjezz

## 5.3.0

### features

* feat(io): introduce `IO\spool()` for memory-backed handles that spill to disk

## 5.2.0

### features

* feat: introduce `IP` component with immutable, binary-backed `Address` value object and `Family` enum
* feat(cidr): `CIDR\Block::contains()` now accepts `string|IP\Address`

## 5.1.0

### features

* feat(tls): introduce `TLS\TCPConnector` for poolable TLS connections
* feat(tls): `TLS\StreamInterface` now extends `TCP\StreamInterface`, enabling TLS streams to be used with `TCP\SocketPoolInterface`

## 5.0.0

### breaking changes

* Dropped PHP 8.3 support; minimum is now PHP 8.4 - [#584](https://github.com/php-standard-library/php-standard-library/pull/584) by @azjezz
* Migrated to PHPUnit 13 - [#584](https://github.com/php-standard-library/php-standard-library/pull/584) by @azjezz
* Complete networking stack rewrite (`Network`, `TCP`, `Unix`) - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* `Psl\Shell` internals refactored; dead code removed - [#596](https://github.com/php-standard-library/php-standard-library/pull/596) by @azjezz
* `Psl\Env\temp_dir()` now always returns a canonicalized path - [#599](https://github.com/php-standard-library/php-standard-library/pull/599) by @azjezz

### features

* feat: introduce `Ansi` component - [#588](https://github.com/php-standard-library/php-standard-library/pull/588) by @azjezz
* feat: introduce `Terminal` component - [#589](https://github.com/php-standard-library/php-standard-library/pull/589) by @azjezz
* feat: introduce `Process` component - [#578](https://github.com/php-standard-library/php-standard-library/pull/578) by @azjezz
* feat: introduce `Binary` component - [#598](https://github.com/php-standard-library/php-standard-library/pull/598) by @azjezz
* feat: introduce `Interoperability` component - [#582](https://github.com/php-standard-library/php-standard-library/pull/582) by @azjezz
* feat: introduce `TLS` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat: introduce `UDP` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat: introduce `CIDR` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat: introduce `Socks` component - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat(network): connection pooling, retry logic, socket pairs - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat(datetime): add `Period`, `Interval`, `TemporalAmountInterface` - [#595](https://github.com/php-standard-library/php-standard-library/pull/595) by @azjezz
* feat(io): add `IO\copy()` and `IO\copy_bidirectional()` - [#585](https://github.com/php-standard-library/php-standard-library/pull/585) by @azjezz
* feat(vec): add `Vec\flatten()` - [#583](https://github.com/php-standard-library/php-standard-library/pull/583) by @azjezz
* feat: introduce `Crypto` component with symmetric/asymmetric encryption, signing, AEAD, KDF, HKDF, key exchange, and stream ciphers - [#607](https://github.com/php-standard-library/php-standard-library/pull/607) by @azjezz

### fixes, and improvements

* fix(vec): strict comparison in `range()` for float precision - [#581](https://github.com/php-standard-library/php-standard-library/pull/581) by @azjezz
* fix(filesystem): canonicalize temporary directory for `create_temporary_file` - [#580](https://github.com/php-standard-library/php-standard-library/pull/580), [#597](https://github.com/php-standard-library/php-standard-library/pull/597) by @azjezz

### other

* docs: documentation website at https://php-standard-library.dev/ - [#592](https://github.com/php-standard-library/php-standard-library/pull/592), [#594](https://github.com/php-standard-library/php-standard-library/pull/594) by @azjezz
* perf: performed optimizations across multiple components, which benchmarks showing up to 100% improvements in certain cases/functions.

## 4.3.0

### features

* feat: introduce `Either` type - [#572](https://github.com/php-standard-library/php-standard-library/pull/572) by @simPod
* feat(type): add `uuid` type - [#568](https://github.com/php-standard-library/php-standard-library/pull/568) by @gsteel

### fixes, and improvements

* fix(shell): terminate the process on timeout - [#574](https://github.com/php-standard-library/php-standard-library/pull/574) by @azjezz
* fix(io): correct PHPDoc return type annotation - [#571](https://github.com/php-standard-library/php-standard-library/pull/571) by @mitelg
* refactor(phpunit): resolve test case naming deprecations - [#573](https://github.com/php-standard-library/php-standard-library/pull/573) by @simPod

## 4.2.1

### fixes, and improvements

* fix(tree): explicit type precedence - [#566](https://github.com/php-standard-library/php-standard-library/pull/566) by @azjezz
* fix(iter): do not narrow down `seek($offset)` type - [#552](https://github.com/php-standard-library/php-standard-library/pull/552) by @azjezz
* fix(filesystem): release handles before changing permissions when copying files - [#550](https://github.com/php-standard-library/php-standard-library/pull/550) by @dragosprotung
* revert(option): revert [#475](https://github.com/php-standard-library/php-standard-library/pull/475) - [#560](https://github.com/php-standard-library/php-standard-library/pull/560) by @devnix

## 4.2.0

### other

* chore: add support for PHP 8.5 - [#549](https://github.com/php-standard-library/php-standard-library/pull/549) by @veewee

## 4.1.0

### features

* feat: add `Graph` component with directed and undirected graph support - [#547](https://github.com/php-standard-library/php-standard-library/pull/547) by @azjezz
* feat: add `Tree` component for hierarchical data structures - [#546](https://github.com/php-standard-library/php-standard-library/pull/546) by @azjezz
* feat(type): add reflection-based type functions for class members - [#543](https://github.com/php-standard-library/php-standard-library/pull/543) by @azjezz

### other

* chore: migrate from `make` to `just` - [#544](https://github.com/php-standard-library/php-standard-library/pull/544) by @azjezz

## 4.0.1

### fixes, and improvements

* refactor: remove redundant `@var` tags from constants - [#533](https://github.com/php-standard-library/php-standard-library/pull/533) by @azjezz

## 4.0.0

### breaking changes

* `Psl\Result\wrap()` no longer unwraps nested results - [#531](https://github.com/php-standard-library/php-standard-library/pull/531) by @azjezz
* `Psl\Collection\Map`, `Psl\Collection\MutableMap`, `Psl\Collection\Set`, and `Psl\Collection\MutableSet` now have a more natural JSON serialization - [#512](https://github.com/php-standard-library/php-standard-library/pull/512) by @josh-rai
* A large number of intersection interfaces in the `Psl\IO` and `Psl\File` namespaces have been removed to simplify the component's hierarchy - [#518](https://github.com/php-standard-library/php-standard-library/pull/518) by @azjezz
* `Psl\sequence()` function has been removed - [#519](https://github.com/php-standard-library/php-standard-library/pull/519) by @azjezz

### features

* feat(type): add `container` type - [#513](https://github.com/php-standard-library/php-standard-library/pull/513) by @azjezz
* feat(type): add `int_range` type - [#510](https://github.com/php-standard-library/php-standard-library/pull/510) by @george-steel
* feat(type): add `always_assert` type - [#522](https://github.com/php-standard-library/php-standard-library/pull/522) by @azjezz
* feat(iter): add `search_with_keys_opt` and `search_with_keys` functions - [#490](https://github.com/php-standard-library/php-standard-library/pull/490) by @simon-podlipsky

### fixes, and improvements

* refactor: improve type inference for non-empty lists - [#529](https://github.com/php-standard-library/php-standard-library/pull/529) by @azjezz
* refactor: improve type inference for `Iter` and `Regex` - [#528](https://github.com/php-standard-library/php-standard-library/pull/528) by @azjezz

### other

* chore: migrate from `psalm` to `mago` - [#527](https://github.com/php-standard-library/php-standard-library/pull/527) by @azjezz
* chore: replace psalm-specific tags by generic tags - [#531](https://github.com/php-standard-library/php-standard-library/pull/531) by @azjezz

## 2.7.0

### features

* feat(encoding): introduce `Base64\Variant` enum to support encoding/decoding different variants - [#408](https://github.com/php-standard-library/php-standard-library/pull/408) by @Gashmob

### fixes, and improvements

* fix(option): return `Option<never>` for `Option::none()` - [#415](https://github.com/php-standard-library/php-standard-library/pull/415) by @devnix
* fix(str): add invariant to avoid unexpected errors when parsing an invalid UTF8 string - [#410](https://github.com/php-standard-library/php-standard-library/pull/410) by @devnix

## 2.6.0

### features

* feat(type): introduce `Type\converted` function - [#405](https://github.com/php-standard-library/php-standard-library/pull/405) by @veewee
* feat(type): introduce `Type\numeric_string` function - [#406](https://github.com/php-standard-library/php-standard-library/pull/406) by @veewee

## 2.5.0

### features

* feat(result): introduce `Result\try_catch` function - [#403](https://github.com/php-standard-library/php-standard-library/pull/403) by @azjezz

### fixes, and improvements

* fix(file): improve consistency when creating files for write-mode - [#401](https://github.com/php-standard-library/php-standard-library/pull/401) by @veewee

## 2.4.1

### fixes, and improvements

* fix(type): un-deprecate `Psl\Type\positive_int` function - [#400](https://github.com/php-standard-library/php-standard-library/pull/400) by @dragosprotung

## 2.4.0

### features

* feat(range): introduced `Psl\Range` component - [#378](https://github.com/php-standard-library/php-standard-library/pull/378) by @azjezz
* feat(str): introduced `Psl\Str\range`, `Psl\Str\Byte\range`, and `Psl\Str\Grapheme\range` functions - [#385](https://github.com/php-standard-library/php-standard-library/pull/385) by @azjezz
* feat(type): introduced `Psl\Type\uint` function - [#393](https://github.com/php-standard-library/php-standard-library/pull/393) by @azjezz
* feat(type): introduced `Psl\Type\i8`, `Psl\Type\i16`, `Psl\Type\i32`, `Psl\Type\i64` functions - [#392](https://github.com/php-standard-library/php-standard-library/pull/392) by @azjezz
* feat(type): introduced `Psl\Type\u8`, `Psl\Type\u16`, `Psl\Type\u32` functions - [#395](https://github.com/php-standard-library/php-standard-library/pull/395) by @KennedyTedesco
* feat(type): introduced `Psl\Type\f32`, and `Psl\Type\f64` functions - [#396](https://github.com/php-standard-library/php-standard-library/pull/396) by @KennedyTedesco
* feat(type): introduced `Psl\Type\nonnull` function - [#392](https://github.com/php-standard-library/php-standard-library/pull/392) by @azjezz
* feat(option): improve options type declarations and add `andThen` method - [#398](https://github.com/php-standard-library/php-standard-library/pull/398) by @veewee

### fixes, and improvements

* fix(vec/dict): Return might be non-empty-list/non-empty-array for map functions - [#384](https://github.com/php-standard-library/php-standard-library/pull/384) by @dragosprotung

### other

* chore(async): add async component documentation - [#386](https://github.com/php-standard-library/php-standard-library/pull/386) by @azjezz

### deprecations

* deprecated `Psl\Type\positive_int` function, use `Psl\Type\uint` instead - by @azjezz

## 2.3.1

### fixes, and improvements

* fix(vec): `Vec\reproduce` and `Vec\range` return type is always non-empty-list - [#383](https://github.com/php-standard-library/php-standard-library/pull/383) by @dragosprotung

### other

* chore: update license copyright year - [#371](https://github.com/php-standard-library/php-standard-library/pull/371) by @azjezz

## 2.3.0

### other

* chore: support psalm v5 - [#369](https://github.com/php-standard-library/php-standard-library/pull/369) by @veewee


## 2.2.0

### features

* feat(option): introduce option component - [#356](https://github.com/php-standard-library/php-standard-library/pull/356) by @azjezz

## 2.1.0

### features

* introduced a new `Psl\Type\unit_enum` function - [@19d1230](https://github.com/php-standard-library/php-standard-library/commit/19d123074546cc3ebfca18ad666f100e7fad0658) by @azjezz
* introduced a new `Psl\Type\backed_enum` function - [@19d1230](https://github.com/php-standard-library/php-standard-library/commit/19d123074546cc3ebfca18ad666f100e7fad0658) by @azjezz
* introduced a new `Psl\Type\mixed_vec` function - [#362](https://github.com/php-standard-library/php-standard-library/pull/362) by @BackEndTea
* introduced a new `Psl\Type\mixed_dict` function - [#362](https://github.com/php-standard-library/php-standard-library/pull/362) by @BackEndTea

### fixes, and improvements

* improved `Psl\Type\vec` performance - [#364](https://github.com/php-standard-library/php-standard-library/pull/364) by @BackEndTea
* improved `Psl\Type\float`, and `Psl\Type\num` - [#367](https://github.com/php-standard-library/php-standard-library/pull/367) by @bcremer

### other

* updated `revolt-php/event-loop` to `1.0.0` - [@c7bf866](https://github.com/php-standard-library/php-standard-library/commit/c7bf866a362b9528934a758981da718408ec15d4) by @azjezz
* introduced scope-able loader - [#361](https://github.com/php-standard-library/php-standard-library/pull/361) by @veewee
* fixed wrong function names in examples - [#354](https://github.com/php-standard-library/php-standard-library/pull/354) by @jrmajor
* added reference to PHPStan integration in README.md - [#353](https://github.com/php-standard-library/php-standard-library/pull/353) by @ondrejmirtes

## 2.0.0

* **BC** - removed `Psl\Arr` component.
* **BC** - removed `Psl\Type\is_array`, `Psl\Type\is_arraykey`, `Psl\Type\is_bool`, `Psl\Type\is_callable`, `Psl\Type\is_float`, `Psl\Type\is_instanceof`, `Psl\Type\is_int`, `Psl\Type\is_iterable`, `Psl\Type\is_null`, `Psl\Type\is_numeric`, `Psl\Type\is_object`, `Psl\Type\is_resource`, `Psl\Type\is_scalar`, and `Psl\Type\is_string` functions ( use `TypeInterface::matches($value)` instead ).
* **BC** - removed `Psl\Iter\chain`, `Psl\Iter\chunk`, `Psl\Iter\chunk_with_keys`, `Psl\Iter\diff_by_key`, `Psl\Iter\drop`, `Psl\Iter\drop_while`, `Psl\Iter\enumerate`, `Psl\Iter\filter`, `Psl\Iter\filter_keys`, `Psl\Iter\filter_nulls`, `Psl\Iter\filter_with_key`, `Psl\Iter\flat_map`, `Psl\Iter\flatten`, `Psl\Iter\flip`, `Psl\Iter\from_entries`, `Psl\Iter\from_keys`, `Psl\Iter\keys`, `Psl\Iter\map`, `Psl\Iter\map_keys`, `Psl\Iter\map_with_key`, `Psl\Iter\merge`, `Psl\Iter\product`, `Psl\Iter\pull`, `Psl\Iter\pull_with_key`, `Psl\Iter\range`, `Psl\Iter\reductions`, `Psl\Iter\reindex`, `Psl\Iter\repeat`, `Psl\Iter\reproduce`, `Psl\Iter\reverse`, `Psl\Iter\slice`, `Psl\Iter\take`, `Psl\Iter\take_while`, `Psl\Iter\to_array`, `Psl\Iter\to_array_with_keys`, `Psl\Iter\values`, and `Psl\Iter\zip` functions.
* **BC** - signature of `Psl\Iter\reduce_keys` function changed from `reduce_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(?Ts, Tk): Ts) $function, Ts|null $initial = null): Ts|null` to `reduce_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(Ts, Tk): Ts) $function, Ts $initial): Ts`.
* **BC** - signature of `Psl\Iter\reduce_with_keys` function changed from `reduce_with_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(?Ts, Tk, Tv): Ts) $function, Ts|null $initial = null): Ts|null` to `reduce_with_keys<Tk, Tv, Ts>(iterable<Tk, Tv> $iterable, (callable(Ts, Tk, Tv): Ts) $function, Ts $initial): Ts`.
* **BC** - removed bundled psalm plugin `Psl\Integration\Psalm\Plugin`, use `php-standard-library/psalm-plugin` package instead.
* dropped support for PHP 8.0
* **BC** - signature of `Psl\Type\object` function changed from `object<T of object>(classname<T> $classname): TypeInterface<T>` to `object(): TypeInterface<object>` ( to preserve the old behavior, use `Psl\Type\instance_of` )
* introduced `Psl\Type\instance_of` function, with the signature of `instance_of<T of object>(classname<T> $classname): TypeInterface<T>`.
* introduced a new `Psl\Async` component.
* refactored `Psl\IO` handles API.
* introduced a new `Psl\File` component.
* refactor `Psl\Shell\execute` to use `Psl\IO` component.
* introduced a `Psl\IO\pipe(): (Psl\IO\CloseReadHandleInterface, Psl\IO\CloseWriteHandleInterface)` function to create a pair of handles, where writes to the WriteHandle can be read from the ReadHandle.
* **BC** - `$encoding` argument for `Psl\Str` functions now accepts `Psl\Str\Encoding` instead of `?string`.
* introduced a new `Psl\Runtime` component.
* introduced a new `Psl\Network` component.
* introduced a new `Psl\TCP` component.
* introduced a new `Psl\Unix` component.
* introduced a new `Psl\Channel` component.
* introduced a new `IO\write()` function.
* introduced a new `IO\write_line()` function.
* introduced a new `IO\write_error()` function.
* introduced a new `IO\write_error_line()` functions.
* introduced a new `Psl\Html\Encoding` enum.
* **BC** - `$encoding` argument for `Psl\Html` functions now accepts `Psl\Html\Encoding` instead of `?string`.
* **BC** - `Psl\Shell\escape_command` function has been removed, no replacement is available.
* introduced a new `Psl\Math\acos` function.
* introduced a new `Psl\Math\asin` function.
* introduced a new `Psl\Math\atan` function.
* introduced a new `Psl\Math\atan2` function.
* **BC** - The type of the $numbers argument of `Psl\Math\mean` has changed to `list<int|float>` instead of `iterable<int|float>`.
* **BC** - The type of the $numbers argument of `Psl\Math\median` has changed to `list<int|float>` instead of `iterable<int|float>`.
* introduced a new `Psl\Promise` component.
* **BC** - `Psl\Result\ResultInterface` now implements `Psl\Promise\PromiseInterface`
* **BC** - `Psl\Type\resource('curl')->toString()` now uses PHP built-in resource kind notation ( i.e: `resource (curl)` ) instead of generic notation ( i.e: `resource<curl>` )
* **BC** - `Psl\Str`, `Psl\Str\Byte`, and `Psl\Str\Grapheme` functions now throw `Psl\Str\Exception\OutOfBoundsException` instead of `Psl\Exception\InvaraintViolationsException` when `$offset` is out-of-bounds.
* **BC** - `Psl\Collection\IndexAccessInterface::at()` now throw `Psl\Collection\Exception\OutOfBoundsException` instead of `Psl\Exception\InvariantViolationException` if `$k` is out-of-bounds.
* **BC** - `Psl\Collection\AccessibleCollectionInterface::slice` signature has changed from `slice(int $start, int $length): static` to `slice(int $start, ?int $length = null): static`
* **BC** - All psl functions previously accepting `callable`, now accept only `Closure`.
* **BC** - `Psl\DataStructure\QueueInterface::dequeue`, and `Psl\DataStructure\StackInterface::pop` now throw `Psl\DataStructure\Exception\UnderflowException` instead of `Psl\Exception\InvariantViolationException` when the data structure is empty.
* **BC** - `Psl\Filesystem\write_file($file, $content)` function has been removed, use `Psl\File\write($file, $content);` instead. 
  > To preserve the same behavior as the old function, use `Psl\File\write($file, $content, Filesystem\is_file($file) ? File\WriteMode::TRUNCATE : File\WriteMode::OPEN_OR_CREATE)`.
* **BC** - `Psl\Filesystem\read_file($file, $offset, $length)` function has been removed, use `Psl\File\read($file, $offset, $length)` instead.
* **BC** - `Psl\Filesystem\append_file($file, $contents)` function has been removed, use `Psl\File\write($file, $contents, File\WriteMode::APPEND)` instead.
* **BC** - `Psl\Filesystem` functions no longer throw `Psl\Exception\InvariantViolationException`.

  New exceptions:
  - `Psl\Filesystem\Exception\NotReadableException` thrown when attempting to read from a non-readable node
  - `Psl\Filesystem\Exception\NotFileException` thrown when attempting a file operation on a non-file node.
  - `Psl\Filesystem\Exception\NotDirectoryException` thrown when attempting a directory operation on a non-directory node.
  - `Psl\Filesystem\Exception\NotSymbolicLinkException` thrown when attempting a symbolic link operation on a non-symbolic link node.
  - `Psl\Filesystem\Exception\NotFoundException` thrown when attempting an operation on a non-existing node.
* introduced `Psl\Hash\Algorithm` enum.
* introduced `Psl\Hash\Hmac\Algorithm` enum.
* **BC** - `Psl\Hash\hash`, and `Psl\Hash\Context::forAlgorithm` now take `Psl\Hash\Algorithm` as an algorithm, rather than a string.
* **BC** - `Psl\Hash\Hmac\hash`, and `Psl\Hash\Context::hmac` now take `Psl\Hash\Hmac\Algorithm` as an algorithm, rather than a string.
* **BC** - A new method `chunk(positive-int $size): CollectionInterface` has been added to `Psl\Collection\CollectionInterface`.
* introduced a new `Psl\OS` component.
* introduced `Psl\Password\Algorithm` enum
* **BC** - all constants of `Psl\Password` component has been removed.
* **BC** - function `Psl\Password\algorithms()` have been removed.
* **BC** - `Psl\Result\ResultInterface::getException()` method has been renamed to `Psl\Result\ResultInterface::getThrowable()`
* **BC** - `Psl\Result\wrap` function now catches all `Throwable`s instead of only `Exception`s
* introduced a new `Psl\Result\reflect` function
* **BC** - `Psl\Shell\escape_argument` function has been removed, `Shell\execute` arguments are now always escaped.
* **BC** - `$escape_arguments` argument of `Shell\execute` function has been removed.
* introduced a new `Psl\Shell\ErrorOutputBehavior` enum
* added a new `$error_output_behavior` argument to `Shell\execute` function, which can be used to return the command error output content, as well as the standard output content.
* introduced a new `Psl\Shell\unpack` function to unpack packed result of `Shell\execute` ( see `Psl\Shell\ErrorOutputBehavior::Packed` ).
* introduced a new `Psl\Shell\stream_unpack` function to unpack packed result of `Shell\execute` chunk by chunk, maintaing order ( see `Psl\Shell\ErrorOutputBehavior::Packed` ).
