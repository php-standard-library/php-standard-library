<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\H2;
use Psl\H2\Frame;

$invalidPing = new Frame\RawFrame(Frame\FrameType::Ping->value, 0, 0, 'short');
try {
    Frame\PingFrame::fromRaw($invalidPing);
} catch (H2\Exception\ProtocolException $error) {
    // fromRaw() reports the same code that a connection sends in GOAWAY.
    assert($error->errorCode === H2\ErrorCode::FrameSizeError, 'Invalid payload sizes use FRAME_SIZE_ERROR.');
}
