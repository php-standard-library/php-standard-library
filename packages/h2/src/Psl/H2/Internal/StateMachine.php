<?php

declare(strict_types=1);

namespace Psl\H2\Internal;

use Psl\DateTime\Duration;
use Psl\H2\ErrorCode;
use Psl\H2\Event\AltSvcReceived;
use Psl\H2\Event\DataReceived;
use Psl\H2\Event\EventInterface;
use Psl\H2\Event\GoAwayReceived;
use Psl\H2\Event\HeadersReceived;
use Psl\H2\Event\OriginReceived;
use Psl\H2\Event\PingReceived;
use Psl\H2\Event\PriorityUpdateReceived;
use Psl\H2\Event\PushPromiseReceived;
use Psl\H2\Event\SettingsReceived;
use Psl\H2\Event\StreamClosed;
use Psl\H2\Event\StreamReset;
use Psl\H2\Event\WindowUpdated;
use Psl\H2\Exception\FlowControlException;
use Psl\H2\Exception\FrameDecodingException;
use Psl\H2\Exception\ProtocolException;
use Psl\H2\Exception\StreamException;
use Psl\H2\Frame\AltSvcFrame;
use Psl\H2\Frame\ContinuationFrame;
use Psl\H2\Frame\FrameType;
use Psl\H2\Frame\GoAwayFrame;
use Psl\H2\Frame\HeadersFrame;
use Psl\H2\Frame\OriginFrame;
use Psl\H2\Frame\PingFrame;
use Psl\H2\Frame\PriorityFrame;
use Psl\H2\Frame\PriorityUpdateFrame;
use Psl\H2\Frame\PushPromiseFrame;
use Psl\H2\Frame\RawFrame;
use Psl\H2\Frame\RstStreamFrame;
use Psl\H2\Frame\SettingsFrame;
use Psl\H2\Frame\WindowUpdateFrame;
use Psl\H2\RateLimiter;
use Psl\H2\Setting;
use Psl\H2\StreamState;
use Psl\HPACK\Decoder;
use Psl\HPACK\Encoder;
use Psl\HPACK\Exception\ExceptionInterface as HPACKException;
use Psl\HPACK\Header;

use function array_shift;
use function ctype_digit;
use function hrtime;
use function max;
use function min;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

use const PHP_INT_MAX;
use const Psl\H2\DEFAULT_HEADER_TABLE_SIZE;
use const Psl\H2\DEFAULT_INITIAL_WINDOW_SIZE;
use const Psl\H2\DEFAULT_MAX_FRAME_SIZE;
use const Psl\H2\MAX_FRAME_SIZE_UPPER_BOUND;
use const Psl\H2\MAX_STREAM_ID;
use const Psl\H2\MAX_WINDOW_SIZE;

/**
 * Processes HTTP/2 frames and manages stream/connection state.
 *
 * Handles frame encoding/decoding, HPACK compression, stream lifecycle,
 * flow control, and settings negotiation. Does not perform I/O directly;
 * use {@see \Psl\H2\Connection} for a complete I/O-integrated API.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-properties
 * @mago-expect lint:excessive-nesting
 */
final class StateMachine
{
    private const int MAX_ENCODER_TABLE_SIZE = 1_048_576;

    /**
     * @var SettingsRegistry Local and remote HTTP/2 settings.
     */
    private SettingsRegistry $settings;

    /**
     * @var StreamTable Registry of active streams.
     */
    private StreamTable $streams;

    /**
     * @var FlowController Connection and stream flow control windows.
     */
    private FlowController $flowController;

    /**
     * @var HeaderBlockAssembler Reassembles CONTINUATION frames into complete header blocks.
     */
    private HeaderBlockAssembler $assembler;

    /**
     * @var bool Whether a header block is currently being assembled across CONTINUATION frames.
     */
    private bool $assembling = false;

    /**
     * @var bool Whether the header block being assembled is trailing headers.
     */
    private bool $assemblingTrailing = false;

    /** Whether to refuse the stream after decoding its field block. */
    private bool $refusingStream = false;

    /** Stream error to report after decoding the current field block. */
    private null|StreamException|ProtocolException $pendingHeaderError = null;

    /** @var array<int, positive-int> Connection window credit owed after DATA stream errors. */
    private array $pendingReceiveWindowUpdates = [];

    /**
     * @var Encoder HPACK encoder for outgoing headers.
     */
    private Encoder $hpackEncoder;

    /**
     * @var Decoder HPACK decoder for incoming headers.
     */
    private Decoder $hpackDecoder;

    /**
     * @var null|RateLimiter Optional rate limiter for incoming frames.
     */
    private null|RateLimiter $rateLimiter;

    private RateLimiter $errorResetLimiter;

    /**
     * @var int<0, max> Maximum frame payload size we accept from the remote peer.
     */
    private int $localMaxFrameSize;

    /** @var non-negative-int Last acknowledged local frame-size limit. */
    private int $acknowledgedLocalMaxFrameSize = DEFAULT_MAX_FRAME_SIZE;

    /** @var list<non-negative-int> Effective frame-size limits for SETTINGS awaiting ACK, in send order. */
    private array $pendingLocalMaxFrameSizes = [];

    /** @var non-negative-int Last acknowledged local header-table limit. */
    private int $acknowledgedLocalHeaderTableSize = DEFAULT_HEADER_TABLE_SIZE;

    /** @var list<non-negative-int> Effective header-table limits awaiting SETTINGS ACKs. */
    private array $pendingLocalHeaderTableSizes = [];

    /**
     * Local stream limits awaiting SETTINGS ACKs, in send order.
     *
     * Each entry holds [MAX_CONCURRENT_STREAMS, INITIAL_WINDOW_SIZE] as sent. They reach
     * the stream table when the peer acknowledges that SETTINGS frame (RFC 9113 Section 6.5.3).
     *
     * @var list<array{int<0, max>, int<0, max>}>
     */
    private array $pendingLocalStreamSettings = [];

    /** @var int<0, max> Last acknowledged local INITIAL_WINDOW_SIZE. */
    private int $acknowledgedLocalInitialWindowSize;

    /**
     * @var int<0, max> Maximum frame payload size the remote peer accepts from us.
     */
    private int $remoteMaxFrameSize;

    /**
     * @var int<1, max>
     */
    private int $nextStreamIdentifier;

    /**
     * @var int<0, max> Highest local stream ID actually opened (not just reserved).
     */
    private int $lastLocalStreamId = 0;

    /**
     * @var int<0, max>
     */
    private int $lastPeerStreamId = 0;

    /**
     * Whether a GOAWAY frame has been sent or received, indicating the connection is shutting down.
     */
    public private(set) bool $shutdown = false;

    /** Last peer stream accepted by our GOAWAY, if sent. */
    private null|int $sentGoAwayLastStreamId = null;

    /**
     * Create a new HTTP/2 state machine.
     *
     * @param bool $isClient Whether this endpoint acts as a client or server.
     * @param array<positive-int, non-negative-int> $localSettings Local settings overrides (setting ID => value).
     * @param null|RateLimiter $rateLimiter Optional rate limiter for incoming frames.
     * @param int $maxHeaderBlockSize Maximum accumulated header block size in bytes (0 = unlimited).
     */
    public function __construct(
        private readonly bool $isClient,
        array $localSettings = [],
        null|RateLimiter $rateLimiter = null,
        int $maxHeaderBlockSize = 0,
        private readonly null|BDPEstimator $bdpEstimator = null,
    ) {
        $this->settings = new SettingsRegistry($localSettings);
        $localMaxConcurrent = $this->settings->localValue(Setting::MaxConcurrentStreams);
        $localInitialWindow = $this->settings->localValue(Setting::InitialWindowSize);
        $this->acknowledgedLocalInitialWindowSize = $localInitialWindow;
        $this->streams = new StreamTable(
            PHP_INT_MAX,
            DEFAULT_INITIAL_WINDOW_SIZE,
            $localInitialWindow,
            $localMaxConcurrent,
            $this->isClient,
        );
        $this->flowController = new FlowController();
        $this->assembler = new HeaderBlockAssembler($maxHeaderBlockSize);
        $this->rateLimiter = $rateLimiter;
        $this->errorResetLimiter = $rateLimiter ?? new RateLimiter([
            RateLimiter::STREAM_ERROR_RESET => [100, Duration::seconds(10)],
        ]);
        $this->localMaxFrameSize = $this->settings->localValue(Setting::MaxFrameSize);
        $this->remoteMaxFrameSize = $this->settings->remoteValue(Setting::MaxFrameSize);
        $maxHeaderListSize = $this->settings->localValue(Setting::MaxHeaderListSize);
        $headerTableSize = $this->settings->localValue(Setting::HeaderTableSize);
        $this->hpackEncoder = new Encoder(DEFAULT_HEADER_TABLE_SIZE, $maxHeaderListSize);
        if ($headerTableSize < DEFAULT_HEADER_TABLE_SIZE) {
            $this->hpackEncoder->resize($headerTableSize);
        }

        $this->hpackDecoder = new Decoder(DEFAULT_HEADER_TABLE_SIZE, $maxHeaderListSize);
        $this->hpackDecoder->resize(max(DEFAULT_HEADER_TABLE_SIZE, $headerTableSize));
        $this->nextStreamIdentifier = $this->isClient ? 1 : 2;
    }

    /**
     * Generate the initial SETTINGS frame to send as part of the connection preface.
     *
     * @return list<RawFrame> A single-element list containing the SETTINGS frame.
     */
    public function initialize(): array
    {
        $this->pendingLocalMaxFrameSizes[] = $this->settings->localValue(Setting::MaxFrameSize);
        $this->pendingLocalHeaderTableSizes[] = $this->settings->localValue(Setting::HeaderTableSize);
        $this->pendingLocalStreamSettings[] = [
            $this->settings->localValue(Setting::MaxConcurrentStreams),
            $this->settings->localValue(Setting::InitialWindowSize),
        ];
        $overrides = $this->settings->localOverrides();
        $frames = [new SettingsFrame($overrides, false)->toRaw()];

        if ($this->isClient && $this->bdpEstimator !== null) {
            $increment = $this->bdpEstimator->getInitialConnectionWindowIncrement();
            if ($increment > 0) {
                $frames[] = new RawFrame(FrameType::WindowUpdate->value, 0, 0, pack('N', $increment));
                $this->flowController->applyConnectionReceiveWindowUpdate($increment);
            }
        }

        return $frames;
    }

    /**
     * Process a received frame and return any response frames and events.
     *
     * Validates the frame, updates connection and stream state, and produces
     * any protocol-required response frames (e.g. SETTINGS ACK, WINDOW_UPDATE).
     *
     * @param RawFrame $rawFrame The raw frame read from the transport.
     *
     * @throws ProtocolException For connection-level protocol violations.
     * @throws StreamException For stream-level errors.
     * @throws FrameDecodingException For malformed frame data.
     * @throws FlowControlException For flow control violations.
     *
     * @return array{list<RawFrame|string>, list<EventInterface>} A tuple of [response frames to send, events to dispatch].
     */
    public function receive(RawFrame $rawFrame): array
    {
        if ($this->assembling) {
            $expectedStream = $this->assembler->activeStreamId();
            if ($rawFrame->type !== 9 || $rawFrame->streamId !== $expectedStream) {
                throw ProtocolException::forHeaderBlockInterrupted($expectedStream ?? 0, $rawFrame->type);
            }
        }

        $type = $rawFrame->type;
        $streamId = $rawFrame->streamId;

        $payloadLength = strlen($rawFrame->payload);
        $this->validateFrameSize($payloadLength);

        if ($streamId !== 0) {
            if (
                $this->ignorePeerStreamAfterGoAway($streamId)
                && ($type === 2 || $type === 3 || $type === 8 || $type === 0xa)
            ) {
                return [[], []];
            }

            if ($type === 2 && $payloadLength !== 5) {
                if ($this->getStreamState($streamId) === StreamState::Idle) {
                    throw ProtocolException::forFrameSizeError(5, $payloadLength);
                }

                throw StreamException::forFrameSizeError($streamId, 5, $payloadLength);
            }

            if ($type === 4 || $type === 6 || $type === 7 || $type === 0xc || $type === 0x10) {
                throw ProtocolException::forConnectionError(
                    'Frame type ' . $type . ' must be on stream 0, received on stream ' . $streamId,
                );
            }

            if (($type === 3 || $type === 8) && !$this->isStreamKnown($streamId)) {
                throw ProtocolException::forConnectionError(
                    'Received frame type ' . $type . ' on idle stream ' . $streamId,
                );
            }
        } else if ($type === 2) {
            throw ProtocolException::forConnectionError('PRIORITY frame must not be on stream 0');
        }

        if ($type === 9 && !$this->assembling) {
            throw ProtocolException::forConnectionError(
                'CONTINUATION frame received without preceding HEADERS or PUSH_PROMISE',
            );
        }

        return match ($type) {
            0 => $this->receiveDataRaw($rawFrame),
            1 => $this->receiveHeaders(HeadersFrame::fromRaw($rawFrame)),
            8 => $this->receiveWindowUpdateRaw($rawFrame),
            9 => $this->receiveContinuation(ContinuationFrame::fromRaw($rawFrame)),
            4 => $this->receiveSettingsRateLimited(SettingsFrame::fromRaw($rawFrame)),
            6 => $this->receivePingRateLimited(PingFrame::fromRaw($rawFrame)),
            7 => $this->receiveGoAway(GoAwayFrame::fromRaw($rawFrame)),
            3 => $this->receiveRstStreamRateLimited(RstStreamFrame::fromRaw($rawFrame)),
            5 => $this->receivePushPromise(PushPromiseFrame::fromRaw($rawFrame)),
            2 => $this->receivePriority(PriorityFrame::fromRaw($rawFrame)),
            0xa => $this->receiveAltSvc(AltSvcFrame::fromRaw($rawFrame)),
            0xc => $this->receiveOrigin(OriginFrame::fromRaw($rawFrame)),
            0x10 => $this->receivePriorityUpdate(PriorityUpdateFrame::fromRaw($rawFrame)),
            default => [[], []],
        };
    }

    /**
     * Reject oversized frames before reading their payload.
     *
     * @throws ProtocolException
     */
    public function validateFrameSize(int $length): void
    {
        if ($length > $this->localMaxFrameSize) {
            throw ProtocolException::forFrameSizeError($this->localMaxFrameSize, $length);
        }
    }

    /**
     * Encode headers directly to wire bytes.
     *
     * Returns the complete binary representation of HEADERS (and CONTINUATION)
     * frames, avoiding intermediate RawFrame allocations.
     *
     * @param int $streamId The stream to send headers on.
     * @param list<Header> $headers The headers to encode.
     * @param bool $endStream Whether to also end the stream.
     *
     * @return string Wire-ready binary data.
     *
     * @throws FlowControlException If max concurrent streams exceeded.
     * @throws StreamException If the stream state is invalid.
     * @throws ProtocolException If the header list exceeds the maximum allowed size.
     */
    public function sendHeadersEncoded(int $streamId, array $headers, bool $endStream = false): string
    {
        $this->prepareHeaderStream($streamId);

        $normalized = self::lowercaseHeaders($headers, $streamId);

        try {
            $encoded = $this->hpackEncoder->encode($normalized);
        } catch (HPACKException $e) {
            throw ProtocolException::forConnectionError($e->getMessage(), $e);
        }

        $frames = $this->buildHeaderFramesEncoded($streamId, $encoded, $endStream);
        $stream = $this->streams->get($streamId);
        if ($this->isClient && $stream !== null) {
            foreach ($normalized as $header) {
                if ($header->name !== ':method') {
                    continue;
                }

                $stream->requestMethod = $header->value;
                break;
            }
        }

        return $frames;
    }

    /**
     * Encode a response's :status and headers directly to wire bytes.
     *
     * Combines the :status pseudo-header with the provided headers in a single
     * encoding pass, producing wire-ready HEADERS (and CONTINUATION) frames.
     *
     * @param int $streamId The stream to send the response on.
     * @param string $status The HTTP status code (e.g. "200", "404").
     * @param iterable<Header> $headers Response headers.
     * @param bool $endStream Whether to also end the stream (no body will follow).
     *
     * @return string Wire-ready binary data.
     *
     * @throws FlowControlException If max concurrent streams exceeded.
     * @throws StreamException If the stream state is invalid.
     * @throws ProtocolException If the header list exceeds the maximum allowed size.
     */
    public function sendResponseHeadersEncoded(
        int $streamId,
        string $status,
        iterable $headers,
        bool $endStream = false,
    ): string {
        $this->prepareHeaderStream($streamId);

        try {
            $encoded = $this->hpackEncoder->encodeWithStatus($status, self::lowercaseHeaders($headers, $streamId));
        } catch (HPACKException $e) {
            throw ProtocolException::forConnectionError($e->getMessage(), $e);
        }

        return $this->buildHeaderFramesEncoded($streamId, $encoded, $endStream);
    }

    /**
     * Check stream state before changing the HPACK encoder.
     *
     * @throws ProtocolException
     * @throws StreamException
     * @throws FlowControlException
     */
    private function prepareHeaderStream(int $streamId): void
    {
        $this->requirePositiveStreamId($streamId);
        $stream = $this->streams->get($streamId);
        if ($stream === null) {
            if ($this->shutdown) {
                throw ProtocolException::forConnectionError('Cannot open new stream after GOAWAY');
            }

            $isPeerInitiated = ($streamId % 2) !== ($this->nextStreamIdentifier % 2);
            if ($isPeerInitiated && $streamId <= $this->lastPeerStreamId) {
                throw StreamException::forInvalidState($streamId, 'open or half-closed (remote)', 'closed');
            }

            $this->streams->checkCanOpen($streamId);
        } elseif ($stream->state === StreamState::ReservedLocal) {
            $this->streams->checkCanOpen($streamId);
        } elseif ($stream->state !== StreamState::Open && $stream->state !== StreamState::HalfClosedRemote) {
            throw StreamException::forInvalidState(
                $streamId,
                'open, half-closed (remote), or reserved (local)',
                $stream->state->name,
            );
        }
    }

    private function buildHeaderFramesEncoded(int $streamId, string $encoded, bool $endStream): string
    {
        $stream = $this->streams->get($streamId);
        if ($stream === null) {
            $stream = $this->streams->open($streamId);
            if (($streamId % 2) === ($this->nextStreamIdentifier % 2)) {
                $this->lastLocalStreamId = max($this->lastLocalStreamId, $streamId);
            }
        } elseif ($stream->state === StreamState::ReservedLocal) {
            $stream = $this->streams->open($streamId);
            $stream->state = StreamState::HalfClosedRemote;
        }

        $maxSize = $this->remoteMaxFrameSize;
        $encodedLen = strlen($encoded);
        $maskedStreamId = $streamId & 0x7FFF_FFFF;

        if ($encodedLen <= $maxSize) {
            $flags = ($endStream ? 0x01 : 0x00) | 0x04;
            $result = pack('NCN', ($encodedLen << 8) | 0x01, $flags, $maskedStreamId) . $encoded;
        } else {
            $flags = $endStream ? 0x01 : 0x00;
            $result = pack('NCN', ($maxSize << 8) | 0x01, $flags, $maskedStreamId) . substr($encoded, 0, $maxSize);

            $offset = $maxSize;
            while (($encodedLen - $offset) > $maxSize) {
                $result .=
                    pack('NCN', ($maxSize << 8) | 0x09, 0x00, $maskedStreamId) . substr($encoded, $offset, $maxSize);
                $offset += $maxSize;
            }

            $remainingLen = $encodedLen - $offset;
            $result .= pack('NCN', ($remainingLen << 8) | 0x09, 0x04, $maskedStreamId) . substr($encoded, $offset);
        }

        if ($endStream) {
            if ($stream->state === StreamState::Open) {
                $this->streams->markHalfClosed($streamId, StreamState::HalfClosedLocal);
            } elseif ($stream->state === StreamState::HalfClosedRemote) {
                $this->streams->close($streamId);
            }
        }

        return $result;
    }

    /**
     * Encode a DATA frame for the given stream.
     *
     * Consumes flow control window for the data size and transitions
     * the stream state if ending.
     *
     * @param int $streamId The stream to send data on.
     * @param string $data The payload bytes to send.
     * @param bool $endStream Whether this is the final data frame on the stream.
     *
     * @throws ProtocolException If the stream ID is invalid.
     * @throws StreamException If the stream state is invalid.
     * @throws FlowControlException If the flow control window is exhausted.
     *
     * @return list<RawFrame>
     */
    public function sendData(int $streamId, string $data, bool $endStream = false): array
    {
        $this->requirePositiveStreamId($streamId);

        $stream = $this->streams->get($streamId);
        if (
            $stream === null
            || $stream->state !== StreamState::Open && $stream->state !== StreamState::HalfClosedRemote
        ) {
            throw StreamException::forInvalidState(
                $streamId,
                'open or half-closed (remote)',
                $stream?->state->name ?? 'idle',
            );
        }

        $size = strlen($data);
        if ($size > 0) {
            $this->flowController->consumeSendWindow($stream, $size);
        }

        $frames = [];
        $offset = 0;
        do {
            $length = min($size - $offset, $this->remoteMaxFrameSize);
            $last = ($offset + $length) === $size;
            $frames[] = new RawFrame(
                FrameType::Data->value,
                $endStream && $last ? 0x01 : 0x00,
                $streamId,
                substr($data, $offset, $length),
            );
            $offset += $length;
        } while ($offset < $size);

        if ($endStream) {
            if ($stream->state === StreamState::Open) {
                $this->streams->markHalfClosed($streamId, StreamState::HalfClosedLocal);
            } else {
                $this->streams->close($streamId);
            }
        }

        return $frames;
    }

    /**
     * Encode DATA frames directly to wire bytes.
     *
     * Functionally identical to {@see sendData()} but returns the binary
     * frame representation directly, avoiding RawFrame allocation.
     *
     * @param int $streamId The stream to send data on.
     * @param string $data The payload bytes to send.
     * @param bool $endStream Whether this is the final data frame on the stream.
     *
     * @throws ProtocolException If the stream ID is invalid.
     * @throws StreamException If the stream state is invalid.
     * @throws FlowControlException If the flow control window is exhausted.
     *
     * @return string Wire-ready binary data.
     */
    public function sendDataEncoded(int $streamId, string $data, bool $endStream = false): string
    {
        $this->requirePositiveStreamId($streamId);

        $stream = $this->streams->get($streamId);
        if (
            $stream === null
            || $stream->state !== StreamState::Open && $stream->state !== StreamState::HalfClosedRemote
        ) {
            throw StreamException::forInvalidState(
                $streamId,
                'open or half-closed (remote)',
                $stream?->state->name ?? 'idle',
            );
        }

        $size = strlen($data);
        if ($size > 0) {
            $this->flowController->consumeSendWindow($stream, $size);
        }

        if ($endStream) {
            if ($stream->state === StreamState::Open) {
                $this->streams->markHalfClosed($streamId, StreamState::HalfClosedLocal);
            } else {
                $this->streams->close($streamId);
            }
        }

        $encoded = '';
        $offset = 0;
        do {
            $length = min($size - $offset, $this->remoteMaxFrameSize);
            $last = ($offset + $length) === $size;
            $encoded .=
                pack('NCN', $length << 8, $endStream && $last ? 0x01 : 0x00, $streamId & 0x7FFF_FFFF)
                . substr($data, $offset, $length);
            $offset += $length;
        } while ($offset < $size);

        return $encoded;
    }

    /**
     * Encode a RST_STREAM frame to abruptly terminate a stream.
     *
     * Closes the stream immediately and produces a RST_STREAM frame
     * to notify the remote peer.
     *
     * @param int $streamId The stream to reset.
     * @param ErrorCode $errorCode The reason for resetting the stream.
     *
     * @throws ProtocolException If the stream ID is invalid.
     *
     * @return list<RawFrame>
     */
    public function resetStream(int $streamId, ErrorCode $errorCode): array
    {
        $this->requirePositiveStreamId($streamId);

        $pendingWindowUpdate = $this->pendingReceiveWindowUpdates[$streamId] ?? 0;
        unset($this->pendingReceiveWindowUpdates[$streamId]);
        $this->streams->close($streamId);

        $frames = [new RstStreamFrame($streamId, $errorCode)->toRaw()];
        if ($pendingWindowUpdate > 0) {
            $frames = [...$frames, ...$this->windowUpdate(0, $pendingWindowUpdate)];
        }

        return $frames;
    }

    /**
     * Count resets caused by peer errors before releasing the stream.
     *
     * @return list<RawFrame>
     *
     * @throws ProtocolException If the peer exceeds the reset limit.
     */
    public function resetStreamForError(int $streamId, ErrorCode $errorCode): array
    {
        $this->errorResetLimiter->record(RateLimiter::STREAM_ERROR_RESET);

        return $this->resetStream($streamId, $errorCode);
    }

    /**
     * @throws ProtocolException If the opaque data is empty.
     *
     * @return list<RawFrame>
     */
    public function ping(string $opaqueData): array
    {
        if ($opaqueData === '') {
            throw ProtocolException::forConnectionError('PING opaque data must not be empty');
        }

        $length = strlen($opaqueData);
        /** @var non-empty-string $padded */
        $padded = match (true) {
            $length < 8 => $opaqueData . str_repeat("\x00", 8 - $length),
            $length > 8 => substr($opaqueData, 0, 8),
            default => $opaqueData,
        };

        $this->bdpEstimator?->onPingSent((int) hrtime(true) / 1e9);

        return [new PingFrame($padded, false)->toRaw()];
    }

    /**
     * Encode a GOAWAY frame to initiate connection shutdown.
     *
     * Marks the connection as shutting down and produces a GOAWAY frame
     * containing the last peer-initiated stream ID that was processed.
     *
     * @param ErrorCode $errorCode The reason for closing the connection.
     * @param string $debugData Optional diagnostic data for the remote peer.
     *
     * @return list<RawFrame>
     */
    public function goAway(ErrorCode $errorCode, string $debugData = '', null|int $lastStreamId = null): array
    {
        $this->shutdown = true;

        /** @var non-negative-int $streamId */
        $streamId = min($lastStreamId ?? $this->lastPeerStreamId, $this->sentGoAwayLastStreamId ?? MAX_STREAM_ID);
        $this->sentGoAwayLastStreamId = $streamId;

        return [new GoAwayFrame($streamId, $errorCode->value, $debugData)->toRaw()];
    }

    /**
     * Return the highest stream ID initiated by the remote peer.
     *
     * @return int<0, max>
     */
    public function lastPeerStreamId(): int
    {
        return $this->lastPeerStreamId;
    }

    /**
     * Get the current state of a stream.
     *
     * Returns {@see StreamState::Idle} for streams that have never been opened.
     *
     * @param positive-int $streamId
     */
    public function getStreamState(int $streamId): StreamState
    {
        $entry = $this->streams->get($streamId);
        if ($entry !== null) {
            return $entry->state;
        }

        // Stream not in table. Determine if it was once open (now closed)
        // or has never been used (idle).
        // Client streams are odd, server streams are even.
        $isLocalStream = (($streamId % 2) === 1) === $this->isClient;

        if ($isLocalStream) {
            // We initiated this stream - it's closed if we actually opened it.
            if ($streamId <= $this->lastLocalStreamId) {
                return StreamState::Closed;
            }
        } else {
            // Peer initiated this stream - it's closed if they opened it.
            if ($streamId <= $this->lastPeerStreamId) {
                return StreamState::Closed;
            }
        }

        return StreamState::Idle;
    }

    /**
     * Get the number of streams currently in an active state.
     *
     * @return int<0, max>
     */
    public function activeStreamCount(): int
    {
        return $this->streams->activeCount();
    }

    /**
     * Create a SETTINGS frame for sending mid-connection.
     *
     * Updates the local settings and returns the frame to send.
     *
     * @param array<positive-int, non-negative-int> $settings Setting identifiers mapped to values.
     *
     * @return list<RawFrame>
     */
    public function sendSettings(array $settings): array
    {
        $this->settings->updateLocal($settings);
        $this->pendingLocalStreamSettings[] = [
            $this->settings->localValue(Setting::MaxConcurrentStreams),
            $this->settings->localValue(Setting::InitialWindowSize),
        ];
        $this->pendingLocalMaxFrameSizes[] = $this->settings->localValue(Setting::MaxFrameSize);
        $this->localMaxFrameSize = max([$this->acknowledgedLocalMaxFrameSize, ...$this->pendingLocalMaxFrameSizes]);
        $this->pendingLocalHeaderTableSizes[] = $this->settings->localValue(Setting::HeaderTableSize);
        $this->hpackDecoder->resize(max([
            $this->acknowledgedLocalHeaderTableSize,
            ...$this->pendingLocalHeaderTableSizes,
        ]));

        return [new SettingsFrame($settings, false)->toRaw()];
    }

    /**
     * Create a PRIORITY frame.
     *
     * @param positive-int $streamId
     * @param int<0, max> $streamDependency
     * @param int<1, 256> $weight
     *
     * @throws ProtocolException If the stream ID is invalid.
     *
     * @return list<RawFrame>
     */
    public function sendPriority(int $streamId, int $streamDependency, int $weight, bool $exclusive): array
    {
        $this->requirePositiveStreamId($streamId);

        return [new PriorityFrame($streamId, $streamDependency, $weight, $exclusive)->toRaw()];
    }

    /**
     * Create a PRIORITY_UPDATE frame (RFC 9218).
     *
     * @param positive-int $prioritizedStreamId
     * @param string $fieldValue Structured Fields priority value.
     *
     * @throws ProtocolException If the stream ID is invalid.
     *
     * @return list<RawFrame>
     */
    public function sendPriorityUpdate(int $prioritizedStreamId, string $fieldValue): array
    {
        $this->requirePositiveStreamId($prioritizedStreamId);

        return [new PriorityUpdateFrame($prioritizedStreamId, $fieldValue)->toRaw()];
    }

    /**
     * Create an ALTSVC frame (RFC 7838).
     *
     * @param int<0, max> $streamId 0 for explicit origin, non-zero for stream's origin.
     * @param string $origin The origin (required when streamId is 0, empty otherwise).
     * @param string $fieldValue The Alt-Svc field value.
     *
     * @return list<RawFrame>
     */
    public function sendAltSvc(int $streamId, string $origin, string $fieldValue): array
    {
        return [new AltSvcFrame($streamId, $origin, $fieldValue)->toRaw()];
    }

    /**
     * Create an ORIGIN frame (RFC 8336).
     *
     * @param list<non-empty-string> $origins The origins the server is authoritative for.
     *
     * @return list<RawFrame>
     */
    public function sendOrigin(array $origins): array
    {
        return [new OriginFrame($origins)->toRaw()];
    }

    /**
     * Encode a WINDOW_UPDATE frame to increase the flow control window.
     *
     * Can target the connection-level window (stream ID 0) or a specific stream.
     *
     * @param int $streamId The stream to update, or 0 for the connection-level window.
     * @param int $increment The number of bytes to add to the window (1 to 2^31-1).
     *
     * @throws ProtocolException If the stream ID or increment is out of range.
     * @throws FlowControlException If the window would overflow.
     *
     * @return list<RawFrame>
     */
    public function windowUpdate(int $streamId, int $increment): array
    {
        if ($streamId < 0 || $streamId > MAX_STREAM_ID) {
            throw ProtocolException::forInvalidStreamId($streamId, 'stream ID must be between 0 and ' . MAX_STREAM_ID);
        }

        if ($increment < 1 || $increment > MAX_WINDOW_SIZE) {
            throw ProtocolException::forConnectionError('WINDOW_UPDATE increment must be between 1 and '
            . MAX_WINDOW_SIZE);
        }

        if ($streamId === 0) {
            $this->flowController->applyConnectionReceiveWindowUpdate($increment);
        } else {
            $stream = $this->streams->get($streamId);
            if ($stream !== null) {
                $newWindow = $stream->receiveWindow + $increment;
                if ($newWindow > MAX_WINDOW_SIZE) {
                    throw FlowControlException::forWindowOverflow($streamId, $stream->receiveWindow, $increment);
                }

                $stream->receiveWindow = $newWindow;
            }
        }

        return [new WindowUpdateFrame($streamId, $increment)->toRaw()];
    }

    /**
     * Get the maximum DATA frame payload size the peer will accept.
     *
     * @return int<0, max> Maximum frame payload size in bytes.
     */
    public function getRemoteMaxFrameSize(): int
    {
        return $this->remoteMaxFrameSize;
    }

    /**
     * Get the available flow control send window for a stream.
     *
     * Returns the minimum of the stream-level and connection-level send windows.
     * If the stream does not exist, returns only the connection-level window.
     *
     * @param int $streamId The stream to check.
     *
     * @return int Available bytes that can be sent.
     */
    public function availableSendWindow(int $streamId): int
    {
        $stream = $this->streams->get($streamId);
        if ($stream === null) {
            return $this->flowController->connectionSendWindow();
        }

        return $this->flowController->availableSendWindow($stream);
    }

    /**
     * Allocate and return the next available stream ID for this endpoint.
     *
     * Clients get odd IDs (1, 3, 5, ...), servers get even IDs (2, 4, 6, ...).
     * Each call increments the internal counter by 2.
     *
     * @return int<1, max> The next stream ID.
     */
    public function nextStreamId(): int
    {
        $id = $this->nextStreamIdentifier;
        $this->nextStreamIdentifier += 2;

        return $id;
    }

    /**
     * Encode PUSH_PROMISE and CONTINUATION frames for server push.
     *
     * Reserves the promised stream and produces the frames needed to notify
     * the client of the pushed resource.
     *
     * @param int<0, max> $associatedStreamId The client-initiated stream that triggered the push.
     * @param int<0, max> $promisedStreamId The server-assigned stream ID for the pushed resource.
     * @param list<Header> $headers Request headers describing the promised resource.
     *
     * @return list<RawFrame>
     *
     * @throws ProtocolException If push is disabled by the remote peer, or the header list exceeds the maximum allowed size.
     * @throws StreamException If the associated stream is not in a valid state.
     */
    public function sendPushPromise(int $associatedStreamId, int $promisedStreamId, array $headers): array
    {
        $this->requirePositiveStreamId($associatedStreamId);
        $this->requirePositiveStreamId($promisedStreamId);

        $maxSize = $this->remoteMaxFrameSize;
        $firstMaxPayload = $maxSize - 4;
        if ($firstMaxPayload < 1) {
            throw ProtocolException::forConnectionError('MAX_FRAME_SIZE too small for PUSH_PROMISE');
        }

        $associated = $this->streams->get($associatedStreamId);
        if (
            $associated === null
            || $associated->state !== StreamState::Open && $associated->state !== StreamState::HalfClosedRemote
        ) {
            throw StreamException::forInvalidState(
                $associatedStreamId,
                'open or half-closed (remote)',
                $associated?->state->name ?? 'idle',
            );
        }

        if ($this->settings->remoteValue(Setting::EnablePush) === 0) {
            throw ProtocolException::forConnectionError('Remote peer has disabled PUSH');
        }

        $normalized = self::lowercaseHeaders($headers, $promisedStreamId);

        try {
            $encoded = $this->hpackEncoder->encode($normalized);
        } catch (HPACKException $e) {
            throw ProtocolException::forConnectionError($e->getMessage(), $e);
        }

        $frames = [];

        $encodedLen = strlen($encoded);
        if ($encodedLen <= $firstMaxPayload) {
            $frames[] = new PushPromiseFrame($associatedStreamId, $promisedStreamId, $encoded, true)->toRaw();
        } else {
            $firstChunk = substr($encoded, 0, $firstMaxPayload);
            $frames[] = new PushPromiseFrame($associatedStreamId, $promisedStreamId, $firstChunk, false)->toRaw();

            $offset = $firstMaxPayload;
            while (($encodedLen - $offset) > $maxSize) {
                $chunk = substr($encoded, $offset, $maxSize);
                $frames[] = new ContinuationFrame($associatedStreamId, $chunk, false)->toRaw();
                $offset += $maxSize;
            }

            $frames[] = new ContinuationFrame($associatedStreamId, substr($encoded, $offset), true)->toRaw();
        }

        $promised = $this->streams->getOrCreate($promisedStreamId);
        $promised->state = StreamState::ReservedLocal;
        $this->lastLocalStreamId = max($this->lastLocalStreamId, $promisedStreamId);

        return $frames;
    }

    /**
     * @throws ProtocolException If the stream ID is out of the valid 31-bit range.
     *
     * @psalm-assert =int<1, max> $streamId
     */
    private function isStreamKnown(int $streamId): bool
    {
        if ($this->streams->get($streamId) !== null) {
            return true;
        }

        if ($streamId <= $this->lastPeerStreamId) {
            return true;
        }

        return false;
    }

    /**
     * @psalm-assert =positive-int $streamId
     *
     * @throws ProtocolException If the stream ID is not a positive integer within range.
     */
    private function requirePositiveStreamId(int $streamId): void
    {
        if ($streamId < 1 || $streamId > MAX_STREAM_ID) {
            throw ProtocolException::forInvalidStreamId($streamId, 'stream ID must be between 1 and ' . MAX_STREAM_ID);
        }
    }

    /**
     * @psalm-assert =positive-int $streamId
     *
     * @throws ProtocolException If stream ID has wrong parity or is not monotonically increasing.
     */
    private function validatePeerStreamId(int $streamId): void
    {
        $isEven = ($streamId % 2) === 0;

        if ($this->isClient && !$isEven) {
            throw ProtocolException::forInvalidStreamId($streamId, 'expected even (server-initiated) stream ID');
        }

        if (!$this->isClient && $isEven) {
            throw ProtocolException::forInvalidStreamId($streamId, 'expected odd (client-initiated) stream ID');
        }

        if ($streamId <= $this->lastPeerStreamId) {
            throw ProtocolException::forInvalidStreamId(
                $streamId,
                'must be greater than last peer stream ID ' . $this->lastPeerStreamId,
            );
        }
    }

    /**
     * @throws ProtocolException If a setting value is out of range.
     * @throws FlowControlException If a window size change causes overflow.
     *
     * @return array{list<RawFrame>, list<SettingsReceived>}
     */
    private function receiveSettings(SettingsFrame $frame): array
    {
        if ($frame->ack) {
            if ($this->pendingLocalMaxFrameSizes !== []) {
                $this->acknowledgedLocalMaxFrameSize = array_shift($this->pendingLocalMaxFrameSizes);
                $this->localMaxFrameSize = max([
                    $this->acknowledgedLocalMaxFrameSize,
                    ...$this->pendingLocalMaxFrameSizes,
                ]);
            }

            if ($this->pendingLocalHeaderTableSizes !== []) {
                $this->acknowledgedLocalHeaderTableSize = array_shift($this->pendingLocalHeaderTableSizes);
                $this->hpackDecoder->resize(max([
                    $this->acknowledgedLocalHeaderTableSize,
                    ...$this->pendingLocalHeaderTableSizes,
                ]));
            }

            if ($this->pendingLocalStreamSettings !== []) {
                [$maxConcurrent, $initialWindow] = array_shift($this->pendingLocalStreamSettings);
                $this->streams->setPeerMaxConcurrent($maxConcurrent);
                if ($initialWindow !== $this->acknowledgedLocalInitialWindowSize) {
                    $this->streams->adjustReceiveWindows($initialWindow - $this->acknowledgedLocalInitialWindowSize);
                    $this->streams->setInitialReceiveWindow($initialWindow);
                    $this->acknowledgedLocalInitialWindowSize = $initialWindow;
                }
            }

            if ($this->pendingLocalMaxFrameSizes === []) {
                $this->settings->markLocalAcknowledged();
            }

            return [[], [new SettingsReceived([])]];
        }

        foreach ($frame->settingPairs as [$id, $value]) {
            $this->validateSetting($id, $value);
            $oldInitialWindowSize = $this->settings->remoteValue(Setting::InitialWindowSize);
            $this->settings->applyRemote([$id => $value]);

            if ($id === Setting::InitialWindowSize->value) {
                $this->streams->adjustSendWindows($value - $oldInitialWindowSize);
                $this->streams->setInitialSendWindow($value);
            } elseif ($id === Setting::MaxConcurrentStreams->value) {
                $this->streams->setMaxConcurrent($value);
            } elseif ($id === Setting::HeaderTableSize->value) {
                $this->hpackEncoder->resize(min($value, self::MAX_ENCODER_TABLE_SIZE));
            } elseif ($id === Setting::MaxHeaderListSize->value) {
                $this->hpackEncoder->setMaxHeaderListSize($value);
            }
        }

        $this->remoteMaxFrameSize = $this->settings->remoteValue(Setting::MaxFrameSize);

        $ack = new RawFrame(FrameType::Settings->value, 0x01, 0, '');

        return [[$ack], [new SettingsReceived($frame->settings)]];
    }

    /**
     * @throws ProtocolException
     */
    private function validateSetting(int $id, int $value): void
    {
        $setting = Setting::tryFrom($id);
        if ($setting === null) {
            return;
        }

        match ($setting) {
            Setting::EnablePush => $value !== 0 && ($this->isClient || $value !== 1)
                ? throw ProtocolException::forSettingsValueOutOfRange('ENABLE_PUSH', $value)
                : null,
            Setting::InitialWindowSize => $value > MAX_WINDOW_SIZE
                ? throw FlowControlException::forWindowOverflow(0, 0, $value)
                : null,
            Setting::MaxFrameSize => $value < DEFAULT_MAX_FRAME_SIZE || $value > MAX_FRAME_SIZE_UPPER_BOUND
                ? throw ProtocolException::forSettingsValueOutOfRange('MAX_FRAME_SIZE', $value)
                : null,
            Setting::EnableConnectProtocol => $value !== 0 && $value !== 1
                ? throw ProtocolException::forSettingsValueOutOfRange('ENABLE_CONNECT_PROTOCOL', $value)
                : null,
            default => null,
        };
    }

    /** @param positive-int $streamId */
    private function ignoreHeaders(int $streamId): bool
    {
        return (
            $this->getStreamState($streamId) === StreamState::Closed
            || $this->ignorePeerStreamAfterGoAway($streamId)
        );
    }

    /** @param positive-int $streamId */
    private function ignorePeerStreamAfterGoAway(int $streamId): bool
    {
        return (
            $this->sentGoAwayLastStreamId !== null
            && $streamId > $this->sentGoAwayLastStreamId
            && (($streamId % 2) === 0) === $this->isClient
        );
    }

    /**
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function receiveHeaders(HeadersFrame $frame): array
    {
        // RFC 9113 §5.1 permits minimal processing for all closed streams.
        if ($this->ignoreHeaders($frame->streamId)) {
            if ($frame->endHeaders) {
                return $this->completeHeaders($frame->streamId, $frame->headerBlockFragment, $frame->endStream);
            }

            $this->assembler->startHeaders($frame->streamId, $frame->headerBlockFragment, $frame->endStream);
            $this->assembling = true;

            return [[], []];
        }

        $error = $frame->streamDependency === $frame->streamId
            ? StreamException::forInvalidState($frame->streamId, 'not self-dependent', 'depends on itself')
            : null;

        $stream = $this->streams->getOrCreate($frame->streamId);

        $error = match ($stream->state) {
            StreamState::HalfClosedRemote => StreamException::forStreamClosed($frame->streamId),
            StreamState::ReservedLocal => ProtocolException::forConnectionError(
                'HEADERS received on a reserved local stream',
            ),
            default => $error,
        };

        $isNewStream = $stream->state === StreamState::Idle;

        if ($isNewStream) {
            $this->validatePeerStreamId($frame->streamId);

            if ($this->shutdown && $frame->streamId > $this->lastPeerStreamId) {
                throw ProtocolException::forConnectionError(
                    'New stream ' . $frame->streamId . ' received after GOAWAY',
                );
            }

            $this->refusingStream = !$this->streams->canAcceptPeerStream();
            if (!$this->refusingStream) {
                $stream->state = StreamState::Open;
                $this->streams->incrementActive($frame->streamId);
            }

            $this->lastPeerStreamId = max($this->lastPeerStreamId, $frame->streamId);
        }

        $isTrailing = $stream->receivedHeaders;

        if ($isTrailing && !$frame->endStream) {
            $error ??= StreamException::forInvalidState($frame->streamId, 'half-closed (remote)', $stream->state->name);
        }

        $this->pendingHeaderError = $error;
        $stream->receivedHeaders = true;

        if ($frame->endHeaders) {
            return $this->completeHeaders(
                $frame->streamId,
                $frame->headerBlockFragment,
                $frame->endStream,
                $isTrailing,
            );
        }

        $this->assembler->startHeaders($frame->streamId, $frame->headerBlockFragment, $frame->endStream);
        $this->assembling = true;
        $this->assemblingTrailing = $isTrailing;

        return [[], []];
    }

    /**
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function receiveContinuation(ContinuationFrame $frame): array
    {
        $this->assembler->append($frame->headerBlockFragment);

        if ($frame->endHeaders) {
            [$buffer, $endStream, $isPushPromise, $promisedStreamId] = $this->assembler->complete();
            $this->assembling = false;

            if ($isPushPromise) {
                if ($promisedStreamId < 1 || $promisedStreamId > MAX_STREAM_ID) {
                    throw ProtocolException::forInvalidStreamId(
                        $promisedStreamId,
                        'stream ID must be between 1 and ' . MAX_STREAM_ID,
                    );
                }

                return $this->completePushPromise($frame->streamId, $promisedStreamId, $buffer);
            }

            $isTrailing = $this->assemblingTrailing;
            $this->assemblingTrailing = false;

            return $this->completeHeaders($frame->streamId, $buffer, $endStream, $isTrailing);
        }

        return [[], []];
    }

    /**
     * @param positive-int $streamId
     *
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function completeHeaders(
        int $streamId,
        string $headerBlock,
        bool $endStream,
        bool $isTrailing = false,
    ): array {
        try {
            $headers = $this->hpackDecoder->decode($headerBlock);
        } catch (HPACKException $e) {
            throw ProtocolException::forCompressionError($e);
        }

        if ($this->ignoreHeaders($streamId)) {
            $this->pendingHeaderError = null;
            $this->refusingStream = false;

            return [[], []];
        }

        $error = $this->pendingHeaderError;
        $this->pendingHeaderError = null;
        if ($this->refusingStream) {
            $this->refusingStream = false;

            return [$this->resetStreamForError($streamId, ErrorCode::RefusedStream), []];
        }

        if ($error !== null) {
            throw $error;
        }

        HeaderValidator::validate($headers, $this->isClient, $isTrailing, $streamId);

        if ($this->isClient && !$isTrailing && self::isInformationalResponse($headers)) {
            $stream = $this->streams->get($streamId);
            if ($stream !== null) {
                $stream->receivedHeaders = false;
            }
        }

        $events = [new HeadersReceived($streamId, $headers, $endStream)];

        $stream = $this->streams->get($streamId);
        if ($stream !== null) {
            if ($stream->state === StreamState::ReservedRemote) {
                if (!$this->streams->canAcceptPeerStream()) {
                    return [$this->resetStreamForError($streamId, ErrorCode::RefusedStream), []];
                }

                $this->streams->incrementActive($streamId);
                $stream->state = StreamState::HalfClosedLocal;
            }

            if (!$isTrailing) {
                $contentLength = self::resolveContentLength($streamId, $headers);

                // RFC 9113 §8.1.1 excludes responses defined as having no content.
                if (
                    $contentLength !== null
                    && (!$this->isClient || self::responseHasContent($headers, $stream->requestMethod))
                ) {
                    $stream->declaredContentLength = (int) $contentLength;
                }
            }

            if ($endStream) {
                if (
                    $stream->declaredContentLength !== null
                    && $stream->declaredContentLength !== $stream->receivedDataLength
                ) {
                    throw StreamException::forContentLengthMismatch(
                        $streamId,
                        $stream->declaredContentLength,
                        $stream->receivedDataLength,
                    );
                }

                if ($stream->state === StreamState::Open) {
                    $this->streams->markHalfClosed($streamId, StreamState::HalfClosedRemote);
                } elseif ($stream->state === StreamState::HalfClosedLocal) {
                    $this->streams->close($streamId);
                    $this->bdpEstimator?->removeStream($streamId);
                    $events[] = new StreamClosed($streamId);
                }
            }
        }

        return [[], $events];
    }

    /**
     * Resolve the content-length field, rejecting malformed and conflicting values.
     *
     * Repeated field lines are equivalent to a single comma-separated value
     * (RFC 9110 §5.3), so duplicate content-length fields that disagree make the
     * message invalid (RFC 9110 §8.6). Identical duplicates collapse to one value.
     *
     * @param positive-int $streamId
     * @param list<Header> $headers
     *
     * @throws StreamException If a value is malformed, or conflicts with an earlier one.
     */
    private static function resolveContentLength(int $streamId, array $headers): null|string
    {
        $resolved = null;
        foreach ($headers as $header) {
            if ($header->name !== 'content-length') {
                continue;
            }

            if ($header->value === '' || !ctype_digit($header->value)) {
                throw StreamException::forMalformedContentLength($streamId, $header->value);
            }

            if ($resolved !== null && $resolved !== $header->value) {
                throw StreamException::forConflictingContentLength($streamId, $resolved, $header->value);
            }

            $resolved = $header->value;
        }

        return $resolved;
    }

    /**
     * @param list<Header> $headers
     */
    private static function responseHasContent(array $headers, null|string $requestMethod): bool
    {
        if ($requestMethod === 'HEAD') {
            return false;
        }

        foreach ($headers as $header) {
            if ($header->name !== ':status') {
                continue;
            }

            $status = (int) $header->value;

            return (
                $status >= 200
                && $status !== 204
                && $status !== 304
                && ($requestMethod !== 'CONNECT' || $status >= 300)
            );
        }

        return true;
    }

    /**
     * @param list<Header> $headers
     */
    private static function isInformationalResponse(array $headers): bool
    {
        foreach ($headers as $header) {
            if ($header->name !== ':status') {
                continue;
            }

            $status = (int) $header->value;
            return $status >= 100 && $status < 200;
        }

        return false;
    }

    /**
     * @throws StreamException If the stream is not in a valid state for receiving data.
     * @throws FlowControlException If the receive window is exhausted.
     * @throws ProtocolException If the empty DATA frame rate limit is exceeded.
     *
     * @return array{list<RawFrame|string>, list<EventInterface>}
     */
    private function receiveDataRaw(RawFrame $rawFrame): array
    {
        $streamId = $rawFrame->streamId;
        if ($streamId === 0) {
            throw FrameDecodingException::forStreamIdRequired($rawFrame->type);
        }

        $payload = $rawFrame->payload;
        $endStream = ($rawFrame->flags & 0x01) !== 0;
        $payloadLength = strlen($payload);

        if (($rawFrame->flags & 0x08) !== 0) {
            if ($payloadLength < 1) {
                throw ProtocolException::forFrameSizeError(1, $payloadLength);
            }

            $padLength = ord($payload[0]);
            if ($padLength >= $payloadLength) {
                throw FrameDecodingException::forInvalidPaddingLength($padLength, $payloadLength);
            }

            $payload = substr($payload, 1, $payloadLength - 1 - $padLength);
        }

        if ($this->ignorePeerStreamAfterGoAway($streamId)) {
            if ($payloadLength === 0) {
                return [[], []];
            }

            $this->flowController->consumeConnectionReceiveWindow($payloadLength);

            return [$this->windowUpdate(0, $payloadLength), []];
        }

        $stream = $this->streams->get($streamId);
        if (($stream->state ?? $this->getStreamState($streamId)) === StreamState::Idle) {
            throw ProtocolException::forConnectionError('DATA frame received on idle stream ' . $streamId);
        }

        if (
            $stream === null
            || $stream->state !== StreamState::Open && $stream->state !== StreamState::HalfClosedLocal
        ) {
            if ($payloadLength > 0) {
                $this->flowController->consumeConnectionReceiveWindow($payloadLength);
                $this->pendingReceiveWindowUpdates[$streamId] =
                    ($this->pendingReceiveWindowUpdates[$streamId] ?? 0) + $payloadLength;
            }

            throw StreamException::forInvalidState(
                $streamId,
                'open or half-closed (local)',
                $stream?->state->name ?? 'closed',
                ErrorCode::StreamClosed,
            );
        }

        $dataLength = strlen($payload);
        if ($payloadLength > 0) {
            $this->flowController->consumeReceiveWindow($stream, $payloadLength);
            $this->pendingReceiveWindowUpdates[$streamId] = $payloadLength;
        }

        if ($dataLength === 0 && !$endStream) {
            $this->rateLimiter?->record(RateLimiter::EMPTY_DATA_FRAME);
        }

        // RFC 9113 §8.1.1: validate received DATA against declared content-length.
        if ($stream->declaredContentLength !== null) {
            $stream->receivedDataLength += $dataLength;
            if ($stream->receivedDataLength > $stream->declaredContentLength) {
                throw StreamException::forContentLengthMismatch(
                    $streamId,
                    $stream->declaredContentLength,
                    $stream->receivedDataLength,
                );
            }

            if ($endStream && $stream->receivedDataLength !== $stream->declaredContentLength) {
                throw StreamException::forContentLengthMismatch(
                    $streamId,
                    $stream->declaredContentLength,
                    $stream->receivedDataLength,
                );
            }
        }

        unset($this->pendingReceiveWindowUpdates[$streamId]);
        $events = [new DataReceived($streamId, $payload, $endStream)];
        $responseFrames = [];

        if ($payloadLength > 0) {
            if ($this->bdpEstimator !== null) {
                $updates = $this->bdpEstimator->recordDataReceived($streamId, $payloadLength);
                foreach ($updates as [$updateStreamId, $increment]) {
                    $responseFrames[] = pack(
                        'NCNN',
                        (4 << 8) | FrameType::WindowUpdate->value,
                        0,
                        $updateStreamId & 0x7FFF_FFFF,
                        $increment & 0x7FFF_FFFF,
                    );
                    if ($updateStreamId === 0) {
                        $this->flowController->applyConnectionReceiveWindowUpdate($increment);
                    } else {
                        $updateStream = $this->streams->get($updateStreamId);
                        if ($updateStream !== null) {
                            $updateStream->receiveWindow += $increment;
                        }
                    }
                }
            } else {
                $increment = $payloadLength & 0x7FFF_FFFF;
                $responseFrames[] =
                    pack('NCNN', (4 << 8) | FrameType::WindowUpdate->value, 0, 0, $increment)
                    . pack('NCNN', (4 << 8) | FrameType::WindowUpdate->value, 0, $streamId & 0x7FFF_FFFF, $increment);
                $this->flowController->applyConnectionReceiveWindowUpdate($payloadLength);
                $stream->receiveWindow += $payloadLength;
            }
        }

        if ($endStream) {
            if ($stream->state === StreamState::Open) {
                $this->streams->markHalfClosed($streamId, StreamState::HalfClosedRemote);
            } else {
                $this->streams->close($streamId);
                $this->bdpEstimator?->removeStream($streamId);
                $events[] = new StreamClosed($streamId);
            }
        }

        return [$responseFrames, $events];
    }

    /**
     * @return array{list<RawFrame>, list{}}
     *
     * @throws StreamException If the stream depends on itself.
     */
    private function receivePriority(PriorityFrame $frame): array
    {
        $state = $this->getStreamState($frame->streamId);
        if (
            $frame->streamDependency === $frame->streamId
            && $state !== StreamState::Idle
            && $state !== StreamState::Closed
        ) {
            throw StreamException::forInvalidState($frame->streamId, 'not self-dependent', 'depends on itself');
        }

        return [[], []];
    }

    /**
     * @return array{list<RawFrame>, list<PriorityUpdateReceived>}
     */
    private function receivePriorityUpdate(PriorityUpdateFrame $frame): array
    {
        return [[], [new PriorityUpdateReceived($frame->prioritizedStreamId, $frame->fieldValue)]];
    }

    /**
     * @return array{list<RawFrame>, list<AltSvcReceived>}
     */
    private function receiveAltSvc(AltSvcFrame $frame): array
    {
        return [[], [new AltSvcReceived($frame->streamId, $frame->origin, $frame->fieldValue)]];
    }

    /**
     * @return array{list<RawFrame>, list<OriginReceived>}
     */
    private function receiveOrigin(OriginFrame $frame): array
    {
        return [[], [new OriginReceived($frame->origins)]];
    }

    /**
     * @throws FlowControlException If the window update causes overflow.
     * @throws FrameDecodingException If the frame is malformed.
     *
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function receiveWindowUpdateRaw(RawFrame $rawFrame): array
    {
        if (strlen($rawFrame->payload) !== 4) {
            throw ProtocolException::forFrameSizeError(4, strlen($rawFrame->payload));
        }

        if ($rawFrame->streamId !== 0 && $this->getStreamState($rawFrame->streamId) === StreamState::Closed) {
            return [[], []];
        }

        $increment = unpack('N', $rawFrame->payload, 0)[1] & 0x7FFF_FFFF;

        if ($increment === 0) {
            throw FrameDecodingException::forInvalidWindowUpdateIncrement(0);
        }

        $streamId = $rawFrame->streamId;
        if ($streamId === 0) {
            $this->flowController->applyConnectionWindowUpdate($increment);
        } else {
            $stream = $this->streams->get($streamId);
            if ($stream !== null) {
                try {
                    $this->flowController->applyStreamWindowUpdate($stream, $streamId, $increment);
                } catch (FlowControlException) {
                    // RFC 9113 Section 6.9.1: a stream-level overflow is a stream error.
                    // Throwing lets the connection send RST_STREAM and tell the caller.
                    throw StreamException::forWindowOverflow($streamId, $stream->sendWindow, $increment);
                }
            }
        }

        return [[], [new WindowUpdated($streamId, $increment)]];
    }

    /**
     * @return array{list<RawFrame>, list<EventInterface>}
     *
     * @throws FlowControlException
     * @throws ProtocolException
     */
    private function receiveSettingsRateLimited(SettingsFrame $frame): array
    {
        $this->rateLimiter?->record(FrameType::Settings->value);

        return $this->receiveSettings($frame);
    }

    /**
     * @throws ProtocolException If rate limit exceeded.
     * @throws FlowControlException If the BDP window update causes overflow.
     *
     * @return array{list<RawFrame|string>, list<EventInterface>}
     */
    private function receivePingRateLimited(PingFrame $frame): array
    {
        $this->rateLimiter?->record(FrameType::Ping->value);

        return $this->receivePing($frame);
    }

    /**
     * @return array{list<RawFrame>, list<EventInterface>}
     *
     * @throws ProtocolException
     */
    private function receiveRstStreamRateLimited(RstStreamFrame $frame): array
    {
        $this->rateLimiter?->record(FrameType::RstStream->value);

        return $this->receiveRstStream($frame);
    }

    /**
     * @throws FlowControlException If the BDP window update causes overflow.
     *
     * @return array{list<RawFrame|string>, list<EventInterface>}
     */
    private function receivePing(PingFrame $frame): array
    {
        if ($frame->ack) {
            $responseFrames = [];
            if ($this->bdpEstimator !== null) {
                $windowGrowth = $this->bdpEstimator->onPingAck((int) hrtime(true) / 1e9);
                if ($windowGrowth !== null && $windowGrowth > 0) {
                    $responseFrames[] = pack(
                        'NCNN',
                        (4 << 8) | FrameType::WindowUpdate->value,
                        0,
                        0,
                        $windowGrowth & 0x7FFF_FFFF,
                    );
                    $this->flowController->applyConnectionReceiveWindowUpdate($windowGrowth);
                }
            }

            return [$responseFrames, [new PingReceived($frame->opaqueData, true)]];
        }

        // PING ACK: 8-byte opaque data, type=6, flags=0x01 (ACK), stream=0
        $ack = pack('NCN', (8 << 8) | FrameType::Ping->value, 0x01, 0) . $frame->opaqueData;

        return [[$ack], [new PingReceived($frame->opaqueData, false)]];
    }

    /**
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function receiveGoAway(GoAwayFrame $frame): array
    {
        $this->shutdown = true;
        $errorCode = ErrorCode::tryFrom($frame->errorCode) ?? ErrorCode::InternalError;

        return [[], [new GoAwayReceived($frame->lastStreamId, $errorCode, $frame->debugData)]];
    }

    /**
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function receiveRstStream(RstStreamFrame $frame): array
    {
        $stream = $this->streams->get($frame->streamId);
        if ($stream === null) {
            return [[], []];
        }

        /** @var positive-int $streamId */
        $streamId = $frame->streamId;
        $this->streams->close($streamId);
        $this->bdpEstimator?->removeStream($streamId);

        return [[], [new StreamReset($streamId, $frame->errorCode), new StreamClosed($streamId)]];
    }

    /**
     * @throws ProtocolException If push promises are disabled.
     *
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function receivePushPromise(PushPromiseFrame $frame): array
    {
        if (!$this->isClient) {
            throw ProtocolException::forConnectionError('PUSH_PROMISE received by server is a protocol error');
        }

        if ($this->settings->localValue(Setting::EnablePush) === 0) {
            throw ProtocolException::forConnectionError('PUSH_PROMISE received but ENABLE_PUSH is 0');
        }

        $associatedState = $this->getStreamState($frame->streamId);
        if (
            ($frame->streamId % 2) !== 1
            || $associatedState !== StreamState::Open
            && $associatedState !== StreamState::HalfClosedLocal
            && $associatedState !== StreamState::Closed
        ) {
            throw ProtocolException::forConnectionError('PUSH_PROMISE received on an invalid associated stream');
        }

        if (!$this->ignorePeerStreamAfterGoAway($frame->promisedStreamId)) {
            $this->validatePeerStreamId($frame->promisedStreamId);
            $this->lastPeerStreamId = $frame->promisedStreamId;
        }

        if ($frame->endHeaders) {
            return $this->completePushPromise($frame->streamId, $frame->promisedStreamId, $frame->headerBlockFragment);
        }

        $this->assembler->startPushPromise($frame->streamId, $frame->promisedStreamId, $frame->headerBlockFragment);
        $this->assembling = true;

        return [[], []];
    }

    /**
     * @param positive-int $streamId
     * @param positive-int $promisedStreamId
     *
     * @return array{list<RawFrame>, list<EventInterface>}
     */
    private function completePushPromise(int $streamId, int $promisedStreamId, string $headerBlock): array
    {
        try {
            $headers = $this->hpackDecoder->decode($headerBlock);
        } catch (HPACKException $e) {
            throw ProtocolException::forCompressionError($e);
        }

        if ($this->ignorePeerStreamAfterGoAway($promisedStreamId)) {
            return [[], []];
        }

        // RFC 9113 §5.1: a promise can arrive after we reset its associated stream.
        if ($this->getStreamState($streamId) === StreamState::Closed) {
            return [$this->resetStream($promisedStreamId, ErrorCode::Cancel), []];
        }

        HeaderValidator::validate($headers, false, false, $promisedStreamId);
        $authority = null;
        foreach ($headers as $header) {
            if ($header->name === ':method' && $header->value !== 'GET' && $header->value !== 'HEAD') {
                throw StreamException::forMalformedMessage(
                    $promisedStreamId,
                    'Promised method must be safe and cacheable',
                );
            }

            if ($header->name === ':authority') {
                $authority = $header->value;
            }
        }

        if ($authority === null || $authority === '') {
            throw StreamException::forMalformedMessage($promisedStreamId, 'Promised request must include :authority');
        }

        $contentLength = self::resolveContentLength($promisedStreamId, $headers);
        if ($contentLength !== null && trim($contentLength, '0') !== '') {
            throw StreamException::forMalformedMessage($promisedStreamId, 'Promised request must not include content');
        }

        $promised = $this->streams->getOrCreate($promisedStreamId);
        $promised->state = StreamState::ReservedRemote;
        foreach ($headers as $header) {
            if ($header->name !== ':method') {
                continue;
            }

            $promised->requestMethod = $header->value;
            break;
        }

        return [[], [new PushPromiseReceived($streamId, $promisedStreamId, $headers)]];
    }

    /**
     * @param iterable<Header> $headers
     *
     * @return list<Header>
     */
    private static function lowercaseHeaders(iterable $headers, int $streamId): array
    {
        $result = [];
        foreach ($headers as $header) {
            HeaderValidator::validateConnectionSpecificField($header, $streamId);
            HeaderValidator::validateFieldValue($header, $streamId);
            $lower = strtolower($header->name);
            $result[] = $lower === $header->name ? $header : new Header($lower, $header->value, $header->sensitive);
        }

        return $result;
    }
}
