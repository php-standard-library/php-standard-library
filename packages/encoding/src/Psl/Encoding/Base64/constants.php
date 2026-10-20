<?php

declare(strict_types=1);

namespace Psl\Encoding\Base64;

// @codeCoverageIgnoreStart

/**
 * Number of raw bytes per {@see Variant::Mime} base64-encoded line (57 bytes encode to 76 characters).
 *
 * @api
 */
const CHUNK_SIZE = 57;

/**
 * Line ending used between the lines of {@see Variant::Mime} base64-encoded output.
 *
 * @api
 */
const LINE_ENDING = "\r\n";

// @codeCoverageIgnoreEnd
