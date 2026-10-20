<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\DateTime\DateTime;
use Psl\DateTime\Duration;
use Psl\SMTP\Client\SendConfiguration;
use Psl\SMTP\DeliverBy;
use Psl\SMTP\DeliverByMode;
use Psl\SMTP\Priority;

// Per-send parameters control MAIL FROM extensions
$config = new SendConfiguration()
    // DSN: request delivery status notifications (RFC 3461)
    ->withDsnReturn('FULL')
    ->withDsnEnvelopeId('msg-001')
    ->withDsnNotify('SUCCESS,FAILURE')
    // Require TLS for the entire delivery chain (RFC 8689).
    // Sending requires TLS and throws if the server lacks REQUIRETLS.
    ->withRequireTls(true)
    // Message priority (RFC 6710 / STANAG 4406)
    ->withPriority(Priority::Urgent)
    // Delivery deadline: return if not delivered within 2 hours (RFC 2852)
    ->withDeliverBy(new DeliverBy(Duration::hours(2), DeliverByMode::Return))
    // Deferred delivery: hold for 30 minutes (RFC 4865)
    ->withFutureRelease(Duration::minutes(30));

// Or hold until a specific time
$config = $config->withFutureRelease(DateTime::now()->plusHours(6));
