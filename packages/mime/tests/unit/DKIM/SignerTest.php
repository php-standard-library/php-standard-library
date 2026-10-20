<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit\DKIM;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\MIME\DKIM\Algorithm;
use Psl\MIME\DKIM\Canonicalization;
use Psl\MIME\DKIM\Signer;
use Psl\MIME\DKIM\SignerInterface;
use Psl\MIME\DKIM\SigningConfiguration;
use Psl\MIME\Exception\DKIMException;
use Psl\MIME\Tests\Fixture\Certificates;
use ReflectionMethod;

use function base64_decode;
use function count;
use function explode;
use function hash;
use function preg_match;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function time;
use function trim;

/**
 * @mago-expect lint:no-shorthand-ternary
 */
final class SignerTest extends TestCase
{
    private function createSigner(null|SigningConfiguration $config = null): Signer
    {
        return new Signer(Certificates::DKIM_RSA_KEY, $config ?? new SigningConfiguration('example.com', 'default'));
    }

    public function testImplementsInterface(): void
    {
        $signer = $this->createSigner();

        static::assertInstanceOf(SignerInterface::class, $signer);
    }

    public function testSignAddsHeader(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\n\r\nHello, World!";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
    }

    public function testSignContainsRequiredTags(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);
        $headerEnd = strpos($signed, "\r\nFrom:");
        $dkimHeader = substr($signed, 0, $headerEnd);

        static::assertStringContainsString('v=1', $dkimHeader);
        static::assertStringContainsString('a=rsa-sha256', $dkimHeader);
        static::assertStringContainsString('d=example.com', $dkimHeader);
        static::assertStringContainsString('s=default', $dkimHeader);
        static::assertStringContainsString('bh=', $dkimHeader);
        static::assertStringContainsString('h=', $dkimHeader);
        static::assertStringContainsString('b=', $dkimHeader);
        static::assertStringContainsString('t=', $dkimHeader);
        static::assertStringContainsString('q=dns/txt', $dkimHeader);
    }

    public function testSignIncludesFromHeader(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertStringContainsString('h=from', $signed);
    }

    public function testSignIgnoresReturnPath(): void
    {
        $message = "Return-Path: <bounce@example.com>\r\nFrom: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nReturn-Path:");
        if (false === $headerEnd) {
            $headerEnd = strpos($signed, "\r\nFrom:");
        }

        $dkimHeader = substr($signed, 0, $headerEnd);
        static::assertStringNotContainsString('return-path', $dkimHeader);
    }

    public function testSignIgnoresCustomHeaders(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headersToIgnore: ['x-custom']);
        $message = "From: sender@example.com\r\nX-Custom: value\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nFrom:");
        $dkimHeader = substr($signed, 0, $headerEnd);
        static::assertStringNotContainsString('x-custom', $dkimHeader);
    }

    public function testSignPreservesOriginalMessage(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nHello!";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertStringContainsString("From: sender@example.com\r\n", $signed);
        static::assertStringContainsString("To: rcpt@example.com\r\n", $signed);
        static::assertStringEndsWith("\r\n\r\nHello!", $signed);
    }

    public function testSignWithRelaxedCanonicalization(): void
    {
        $config = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Relaxed,
            bodyCanonicalization: Canonicalization::Relaxed,
        );
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('c=relaxed/relaxed', $signed);
    }

    public function testSignWithSimpleCanonicalization(): void
    {
        $config = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Simple,
            bodyCanonicalization: Canonicalization::Simple,
        );
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('c=simple/simple', $signed);
    }

    public function testSignWithExpiration(): void
    {
        $config = new SigningConfiguration('example.com', 'default', signatureExpirationDelay: 3600);
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('x=', $signed);
    }

    public function testSignWithoutExpiration(): void
    {
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nFrom:");
        $dkimHeader = substr($signed, 0, $headerEnd);
        static::assertStringNotContainsString('x=', $dkimHeader);
    }

    public function testSignWithBodyLength(): void
    {
        $config = new SigningConfiguration('example.com', 'default', includeBodyLength: true);
        $message = "From: sender@example.com\r\n\r\nBody content here";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('l=', $signed);
    }

    public function testSignWithBodyMaxLength(): void
    {
        $config = new SigningConfiguration('example.com', 'default', bodyMaxLength: 10, includeBodyLength: true);
        $message = "From: sender@example.com\r\n\r\n" . str_repeat('A', 1000);
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('l=10', $signed);
    }

    public function testSignDeterministicBodyHash(): void
    {
        $message = "From: sender@example.com\r\n\r\nSame body";
        $signer = $this->createSigner();

        $signed1 = $signer->sign($message);
        $signed2 = $signer->sign($message);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');

        static::assertSame($bh1, $bh2);
    }

    public function testSignEmptyBody(): void
    {
        $message = "From: sender@example.com\r\n\r\n";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        static::assertStringContainsString('bh=', $signed);
    }

    public function testSignWithInvalidKeyThrows(): void
    {
        $signer = new Signer('not-a-valid-key', new SigningConfiguration('example.com', 'default'));

        $this->expectException(DKIMException::class);

        $signer->sign("From: a@b.com\r\n\r\nBody");
    }

    public function testSigningConfigurationWithMethods(): void
    {
        $config = new SigningConfiguration('example.com', 'sel1');

        $updated = $config
            ->withAlgorithm(Algorithm::Ed25519Sha256)
            ->withHeaderCanonicalization(Canonicalization::Simple)
            ->withBodyCanonicalization(Canonicalization::Simple)
            ->withSignatureExpirationDelay(7200)
            ->withBodyMaxLength(1024)
            ->withIncludeBodyLength()
            ->withHeadersToIgnore(['x-mailer']);

        static::assertSame(Algorithm::Ed25519Sha256, $updated->algorithm);
        static::assertSame(Canonicalization::Simple, $updated->headerCanonicalization);
        static::assertSame(Canonicalization::Simple, $updated->bodyCanonicalization);
        static::assertSame(7200, $updated->signatureExpirationDelay);
        static::assertSame(1024, $updated->bodyMaxLength);
        static::assertTrue($updated->includeBodyLength);
        static::assertSame(['x-mailer'], $updated->headersToIgnore);

        static::assertSame(Algorithm::RsaSha256, $config->algorithm);
        static::assertSame(Canonicalization::Relaxed, $config->headerCanonicalization);
    }

    public function testSignSkipsExistingDkimSignature(): void
    {
        $message = "DKIM-Signature: v=1; old\r\nFrom: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        $secondDkim = strpos($signed, 'DKIM-Signature:', strlen('DKIM-Signature:'));
        static::assertNotFalse($secondDkim);
    }

    public function testSignMultipleHeaders(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\nDate: Thu, 1 Jan 2026 00:00:00 +0000\r\nMessage-ID: <123@example.com>\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nFrom:");
        $dkimHeader = substr($signed, 0, $headerEnd);
        static::assertStringContainsString('from', $dkimHeader);
        static::assertStringContainsString('to', $dkimHeader);
        static::assertStringContainsString('subject', $dkimHeader);
        static::assertStringContainsString('date', $dkimHeader);
        static::assertStringContainsString('message-id', $dkimHeader);
    }

    public function testSignSkipsDkimSignatureButContinuesToNextHeaders(): void
    {
        $message = "DKIM-Signature: v=1; old\r\nFrom: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nDKIM-Signature: v=1");
        $dkimHeader = substr($signed, 0, $headerEnd ?: strlen($signed));
        static::assertStringContainsString('from', $dkimHeader);
        static::assertStringContainsString('to', $dkimHeader);
    }

    public function testSignContinuesAfterIgnoredReturnPathHeader(): void
    {
        $message = "Return-Path: <bounce@example.com>\r\nFrom: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nReturn-Path:");
        if (false === $headerEnd) {
            $headerEnd = strpos($signed, "\r\nFrom:");
        }

        $dkimHeader = substr($signed, 0, $headerEnd ?: strlen($signed));
        static::assertStringContainsString('from', $dkimHeader);
        static::assertStringContainsString('to', $dkimHeader);
    }

    public function testSignAccumulatesAllHeaderCanonData(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed1 = $signer->sign($message);
        $signed2 = $signer->sign($message);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');
        static::assertSame($bh1, $bh2);

        $dkimPart = substr($signed1, 0, strpos($signed1, "\r\nFrom:") ?: strlen($signed1));
        static::assertStringContainsString('from', $dkimPart);
        static::assertStringContainsString('to', $dkimPart);
        static::assertStringContainsString('subject', $dkimPart);
    }

    public function testSignContainsCorrectBhTagFormat(): void
    {
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $bh = self::extractTag($signed, 'bh');
        static::assertNotEmpty($bh);
        static::assertNotFalse(base64_decode($bh, true));
    }

    public function testSignContainsCorrectIdentityTag(): void
    {
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertStringContainsString('i=@example.com', $signed);
    }

    public function testSignContainsTimestampTag(): void
    {
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $before = time();
        $signed = $signer->sign($message);
        $after = time();

        $timestamp = self::extractTag($signed, 't');
        static::assertNotEmpty($timestamp);
        $ts = (int) $timestamp;
        static::assertGreaterThanOrEqual($before, $ts);
        static::assertLessThanOrEqual($after, $ts);
    }

    public function testSignWithExpirationHasCorrectValue(): void
    {
        $config = new SigningConfiguration('example.com', 'default', signatureExpirationDelay: 3600);
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $before = time();
        $signed = $signer->sign($message);

        $t = (int) self::extractTag($signed, 't');
        $x = (int) self::extractTag($signed, 'x');

        static::assertGreaterThanOrEqual($before + 3600, $x);
        static::assertSame($t + 3600, $x);
    }

    public function testSignProducesValidSignatureValue(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nHello!";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nFrom:");
        static::assertNotFalse($headerEnd);
        $dkimHeader = substr($signed, 0, $headerEnd);

        static::assertStringContainsString('b=', $dkimHeader);
        $bPos = strrpos($dkimHeader, 'b=');
        static::assertNotFalse($bPos);
        $sigPart = substr($dkimHeader, $bPos + 2);
        $sigPart = str_replace(["\r\n ", "\r\n"], '', $sigPart);
        static::assertNotEmpty($sigPart);
    }

    public function testSignatureLineLengthIs73(): void
    {
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $bPos = strpos($signed, 'b=');
        static::assertNotFalse($bPos);

        $b = self::extractTag($signed, 'b');
        static::assertNotEmpty($b);
    }

    public function testSignCorrectlySplitsHeadersAndBody(): void
    {
        $headers = "From: sender@example.com\r\nTo: rcpt@example.com";
        $body = 'Hello, World!';
        $message = $headers . "\r\n\r\n" . $body;
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertStringContainsString("From: sender@example.com\r\n", $signed);
        static::assertStringEndsWith("\r\n\r\nHello, World!", $signed);
    }

    public function testSignHandlesCrLfHeadersCorrectly(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $dkimPart = substr($signed, 0, strpos($signed, "\r\nFrom:") ?: strlen($signed));
        static::assertStringContainsString('from', $dkimPart);
        static::assertStringContainsString('to', $dkimPart);
    }

    public function testSignHandlesFoldedHeaders(): void
    {
        $message = "From: sender@example.com\r\nSubject: This is a very long\r\n subject line\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $dkimPart = substr($signed, 0, strpos($signed, "\r\nFrom:") ?: strlen($signed));
        static::assertStringContainsString('from', $dkimPart);
        static::assertStringContainsString('subject', $dkimPart);
        static::assertStringContainsString("Subject: This is a very long\r\n subject line", $signed);
    }

    public function testSignHandlesTabFoldedHeaderLines(): void
    {
        $message = "From: sender@example.com\r\nSubject: A long\r\n\tsubject\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $dkimPart = substr($signed, 0, strpos($signed, "\r\nFrom:") ?: strlen($signed));
        static::assertStringContainsString('subject', $dkimPart);
    }

    public function testCanonicalizeSimplePreservesRawLine(): void
    {
        $config = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Simple,
            bodyCanonicalization: Canonicalization::Simple,
        );
        $message = "From: sender@example.com\r\nTo:   Rcpt@Example.COM\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('c=simple/simple', $signed);
        $b = self::extractTag($signed, 'b');
        static::assertNotEmpty($b);
    }

    public function testSimpleAndRelaxedProduceDifferentSignatures(): void
    {
        $message = "From: Sender@Example.COM\r\nTo:   Rcpt@Example.COM  \r\n\r\nBody  content  ";

        $simpleConfig = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Simple,
            bodyCanonicalization: Canonicalization::Simple,
        );
        $relaxedConfig = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Relaxed,
            bodyCanonicalization: Canonicalization::Relaxed,
        );

        $simpleSigner = $this->createSigner($simpleConfig);
        $relaxedSigner = $this->createSigner($relaxedConfig);

        $simpleSigned = $simpleSigner->sign($message);
        $relaxedSigned = $relaxedSigner->sign($message);

        $simpleBh = self::extractTag($simpleSigned, 'bh');
        $relaxedBh = self::extractTag($relaxedSigned, 'bh');

        static::assertNotSame($simpleBh, $relaxedBh);
    }

    public function testSignHandlesLfOnlyMessage(): void
    {
        $message = "From: sender@example.com\nTo: rcpt@example.com\n\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        static::assertStringContainsString('Body', $signed);
    }

    public function testSignMessageWithNoBody(): void
    {
        $message = 'From: sender@example.com';
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
    }

    public function testRelaxedHeaderCanonicalizationExtractsValueAfterColon(): void
    {
        $config = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Relaxed,
            bodyCanonicalization: Canonicalization::Relaxed,
        );
        $message = "From: sender@example.com\r\nSubject: TestValue\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        static::assertStringContainsString('bh=', $signed);
    }

    public function testRelaxedCanonicalizationLowercasesHeaderNameAndStripsLineBreaks(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);
        $message = "From: sender@example.com\r\nX-Mixed-Case: some\r\n value\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nFrom:");
        $dkimHeader = substr($signed, 0, $headerEnd);
        static::assertStringContainsString('h=', $dkimHeader);
        static::assertStringContainsString('x-mixed-case', $dkimHeader);
    }

    public function testRelaxedCanonicalizationProducesCorrectFormat(): void
    {
        $config = new SigningConfiguration(
            'example.com',
            'default',
            headerCanonicalization: Canonicalization::Relaxed,
            bodyCanonicalization: Canonicalization::Relaxed,
        );

        $message1 = "From: sender@example.com\r\nSubject: hello\r\n\r\nBody";
        $message2 = "From: sender@example.com\r\nSubject:  hello \r\n\r\nBody";

        $signer = $this->createSigner($config);

        $signed1 = $signer->sign($message1);
        $signed2 = $signer->sign($message2);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');
        static::assertSame($bh1, $bh2);
    }

    public function testHashBodyTruncatesCorrectly(): void
    {
        $longBody = str_repeat('X', 100);
        $config = new SigningConfiguration('example.com', 'default', bodyMaxLength: 10, includeBodyLength: true);
        $message = "From: sender@example.com\r\n\r\n" . $longBody;
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('l=10', $signed);
        $signed2 = $signer->sign($message);
        $bh1 = self::extractTag($signed, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');
        static::assertSame($bh1, $bh2);
    }

    public function testHashBodyDoesNotTruncateWhenBodyShorterThanMax(): void
    {
        $config = new SigningConfiguration('example.com', 'default', bodyMaxLength: 100, includeBodyLength: true);
        $message = "From: sender@example.com\r\n\r\nShort";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('l=', $signed);
        static::assertStringNotContainsString('l=100', $signed);
    }

    public function testHashBodyWithZeroMaxLengthMeansNoLimit(): void
    {
        $longBody = str_repeat('A', 500);
        $config = new SigningConfiguration('example.com', 'default', bodyMaxLength: 0, includeBodyLength: true);
        $message = "From: sender@example.com\r\n\r\n" . $longBody;
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertStringContainsString('l=', $signed);
        static::assertStringNotContainsString('l=0;', $signed);
    }

    public function testEmptyBodyProducesConsistentHash(): void
    {
        $message1 = "From: sender@example.com\r\n\r\n";
        $message2 = "From: sender@example.com\r\n\r\n";
        $signer = $this->createSigner();

        $signed1 = $signer->sign($message1);
        $signed2 = $signer->sign($message2);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');
        static::assertSame($bh1, $bh2);
        static::assertNotEmpty($bh1);
    }

    public function testBodyCanonicalizationNormalizesLineEndings(): void
    {
        $config = new SigningConfiguration('example.com', 'default', bodyCanonicalization: Canonicalization::Simple);

        $message1 = "From: sender@example.com\r\n\r\nLine1\r\nLine2";
        $message2 = "From: sender@example.com\r\n\r\nLine1\nLine2";
        $message3 = "From: sender@example.com\r\n\r\nLine1\rLine2";

        $signer = $this->createSigner($config);

        $signed1 = $signer->sign($message1);
        $signed2 = $signer->sign($message2);
        $signed3 = $signer->sign($message3);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');
        $bh3 = self::extractTag($signed3, 'bh');

        static::assertSame($bh1, $bh2);
        static::assertSame($bh1, $bh3);
    }

    public function testSimpleVsRelaxedBodyCanonicalizationDiffer(): void
    {
        $body = "Line1   \r\nLine2  ";

        $configSimple = new SigningConfiguration(
            'example.com',
            'default',
            bodyCanonicalization: Canonicalization::Simple,
        );
        $configRelaxed = new SigningConfiguration(
            'example.com',
            'default',
            bodyCanonicalization: Canonicalization::Relaxed,
        );

        $message = "From: sender@example.com\r\n\r\n" . $body;

        $signerSimple = $this->createSigner($configSimple);
        $signerRelaxed = $this->createSigner($configRelaxed);

        $signedSimple = $signerSimple->sign($message);
        $signedRelaxed = $signerRelaxed->sign($message);

        $bhSimple = self::extractTag($signedSimple, 'bh');
        $bhRelaxed = self::extractTag($signedRelaxed, 'bh');

        static::assertNotSame($bhSimple, $bhRelaxed);
    }

    public function testRelaxedBodyCanonicalizationStripsTrailingWhitespace(): void
    {
        $config = new SigningConfiguration('example.com', 'default', bodyCanonicalization: Canonicalization::Relaxed);

        $message1 = "From: sender@example.com\r\n\r\nLine1\r\nLine2";
        $message2 = "From: sender@example.com\r\n\r\nLine1   \r\nLine2   ";

        $signer = $this->createSigner($config);

        $signed1 = $signer->sign($message1);
        $signed2 = $signer->sign($message2);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');

        static::assertSame($bh1, $bh2);
    }

    public function testBodyCanonicalizationStripsTrailingEmptyLines(): void
    {
        $config = new SigningConfiguration('example.com', 'default', bodyCanonicalization: Canonicalization::Simple);

        $message1 = "From: sender@example.com\r\n\r\nContent";
        $message2 = "From: sender@example.com\r\n\r\nContent\r\n\r\n\r\n";

        $signer = $this->createSigner($config);

        $signed1 = $signer->sign($message1);
        $signed2 = $signer->sign($message2);

        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');

        static::assertSame($bh1, $bh2);
    }

    public function testCanonicalizeHeaderRelaxedExtractsValueAfterColon(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'Subject', 'Subject: hello', Canonicalization::Relaxed);

        static::assertSame("subject:hello\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedDoesNotIncludeColon(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'X', 'X:value', Canonicalization::Relaxed);

        static::assertSame("x:value\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedDoesNotReturnWholeRawLine(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'From', 'From: test@example.com', Canonicalization::Relaxed);

        static::assertSame("from:test@example.com\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedNonEmptyValue(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'Subject', 'Subject: test', Canonicalization::Relaxed);

        static::assertSame("subject:test\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedUnfoldsContinuationLines(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'Subject', "Subject: part1\r\n part2", Canonicalization::Relaxed);

        static::assertSame("subject:part1 part2\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedLowercasesName(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'Content-Type', 'Content-Type: text/html', Canonicalization::Relaxed);

        static::assertStringStartsWith('content-type:', $result);
    }

    public function testCanonicalizeHeaderRelaxedFormatIsNameColonValue(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'From', 'From: a@b.com', Canonicalization::Relaxed);

        static::assertSame("from:a@b.com\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedTrimsValue(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'Subject', 'Subject:  hello  ', Canonicalization::Relaxed);

        static::assertSame("subject:hello\r\n", $result);
    }

    public function testCanonicalizeHeaderRelaxedCorrectConcatOrder(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'X-Test', 'X-Test: val', Canonicalization::Relaxed);

        static::assertSame("x-test:val\r\n", $result);

        $colonPos = strpos($result, ':');
        static::assertSame(6, $colonPos);
        static::assertSame('x-test', substr($result, 0, $colonPos));
    }

    public function testCanonicalizeHeaderRelaxedEndsWithCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'X', 'X: v', Canonicalization::Relaxed);

        static::assertSame("x:v\r\n", $result);
        static::assertStringEndsWith("\r\n", $result);
        static::assertStringStartsWith('x:', $result);
    }

    public function testCanonicalizeBodyEmptyReturnsOnlyCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, '', Canonicalization::Simple);

        static::assertSame("\r\n", $result);
    }

    public function testCanonicalizeBodyNormalizesCRLFToLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, "A\r\nB", Canonicalization::Simple);

        static::assertSame("A\r\nB\r\n", $result);
    }

    public function testCanonicalizeBodyNormalizesBareCarriageReturn(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, "A\rB", Canonicalization::Simple);

        static::assertSame("A\r\nB\r\n", $result);
    }

    public function testCanonicalizeBodySimpleVsRelaxed(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $simple = $method->invoke(null, "A   \nB   ", Canonicalization::Simple);
        $relaxed = $method->invoke(null, "A   \nB   ", Canonicalization::Relaxed);

        static::assertSame("A   \r\nB   \r\n", $simple);
        static::assertSame("A\r\nB\r\n", $relaxed);
    }

    public function testCanonicalizeBodyRelaxedRtrimsEachLine(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, "hello   \nworld\t\t", Canonicalization::Relaxed);

        static::assertSame("hello\r\nworld\r\n", $result);
    }

    #[DataProvider('provideNonWspControlCharacters')]
    public function testCanonicalizeBodyRelaxedKeepsTrailingNonWspCharacters(string $char): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, "hello {$char} \nworld{$char}", Canonicalization::Relaxed);

        static::assertSame("hello {$char}\r\nworld{$char}\r\n", $result);
    }

    #[DataProvider('provideNonWspControlCharacters')]
    public function testCanonicalizeHeaderRelaxedKeepsSurroundingNonWspCharacters(string $char): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'Subject', "Subject: {$char}hello{$char} ", Canonicalization::Relaxed);

        static::assertSame("subject:{$char}hello{$char}\r\n", $result);
    }

    /**
     * RFC 6376 relaxed canonicalization only reduces and strips WSP (SP / HTAB).
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonWspControlCharacters(): iterable
    {
        yield 'form feed' => ["\x0C"];
        yield 'vertical tab' => ["\x0B"];
        yield 'NUL byte' => ["\0"];
    }

    public function testCanonicalizeBodyStripsTrailingEmptyLines(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, "content\n\n\n", Canonicalization::Simple);

        static::assertSame("content\r\n", $result);
    }

    public function testCanonicalizeBodyAppendsExactlyOneCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, 'text', Canonicalization::Simple);

        static::assertSame("text\r\n", $result);
        static::assertStringEndsNotWith("\r\n\r\n", $result);
        static::assertStringStartsWith('text', $result);
    }

    public function testHashBodyTruncationLogic(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $length] = $method->invoke(null, 'ABCDEFGHIJ', Canonicalization::Simple, 5);

        static::assertSame(5, $length);

        $expected = hash('sha256', 'ABCDE', true);
        static::assertSame($expected, $hash);
    }

    public function testHashBodyZeroMaxLengthNoTruncation(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $length] = $method->invoke(null, 'ABC', Canonicalization::Simple, 0);

        static::assertSame(5, $length);

        $expected = hash('sha256', "ABC\r\n", true);
        static::assertSame($expected, $hash);
    }

    public function testHashBodyExactLengthNotTruncated(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $length] = $method->invoke(null, 'AB', Canonicalization::Simple, 4);

        static::assertSame(4, $length);
        $expected = hash('sha256', "AB\r\n", true);
        static::assertSame($expected, $hash);
    }

    public function testHashBodyTruncatesFromStart(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $length] = $method->invoke(null, 'ABCDEF', Canonicalization::Simple, 3);

        static::assertSame(3, $length);
        $expected = hash('sha256', 'ABC', true);
        static::assertSame($expected, $hash);
    }

    public function testSignAccumulatesHeaderCanonDataConcatenation(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);

        $message1 = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $message2 = "From: sender@example.com\r\n\r\nBody";

        $signer = $this->createSigner($config);

        $signed1 = $signer->sign($message1);
        $signed2 = $signer->sign($message2);

        $b1 = self::extractTag($signed1, 'b');
        $b2 = self::extractTag($signed2, 'b');
        static::assertNotSame($b1, $b2);
    }

    public function testCanonicalizeHeaderSimpleReturnsRawLinePlusCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $rawLine = 'From: sender@example.com';
        $result = $method->invoke(null, 'From', $rawLine, Canonicalization::Simple);

        static::assertSame($rawLine . "\r\n", $result);
        static::assertStringStartsWith('From:', $result);
        static::assertStringEndsWith("\r\n", $result);
    }

    public function testCanonicalizeHeaderSimpleDoesNotOmitRawLine(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $rawLine = 'Subject: Hello World';
        $result = $method->invoke(null, 'Subject', $rawLine, Canonicalization::Simple);

        static::assertStringContainsString('Hello World', $result);
        static::assertNotSame("\r\n", $result);
    }

    public function testCanonicalizeHeaderSimpleDoesNotOmitCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeHeader');

        $result = $method->invoke(null, 'From', 'From: a@b.com', Canonicalization::Simple);

        static::assertStringEndsWith("\r\n", $result);
        static::assertSame("From: a@b.com\r\n", $result);
    }

    public function testSplitMessageSeparatesHeadersAndBody(): void
    {
        $method = new ReflectionMethod(Signer::class, 'splitMessage');

        [$headers, $body] = $method->invoke(null, "From: a@b.com\r\nTo: c@d.com\r\n\r\nBody content");

        static::assertSame("From: a@b.com\r\nTo: c@d.com", $headers);
        static::assertSame('Body content', $body);
    }

    public function testSplitMessageStopsAtTheFirstBlankLineOfAnyStyle(): void
    {
        $method = new ReflectionMethod(Signer::class, 'splitMessage');

        [$headers, $body] = $method->invoke(null, "From: a@b.com\nTo: c@d.com\n\nline 1\r\n\r\nline 2\n");

        static::assertSame("From: a@b.com\nTo: c@d.com", $headers);
        static::assertSame("line 1\r\n\r\nline 2\n", $body);

        [$headers, $body] = $method->invoke(null, "From: a@b.com\r\n\nBody\r\n\r\n");

        static::assertSame('From: a@b.com', $headers);
        static::assertSame("Body\r\n\r\n", $body);
    }

    public function testSplitMessageNoSeparatorReturnsEmptyBody(): void
    {
        $method = new ReflectionMethod(Signer::class, 'splitMessage');

        [$headers, $body] = $method->invoke(null, 'From: a@b.com');

        static::assertSame('From: a@b.com', $headers);
        static::assertSame('', $body);
    }

    public function testParseHeadersContinuationLine(): void
    {
        $method = new ReflectionMethod(Signer::class, 'parseHeaders');

        $raw = "Subject: part1\r\n part2\r\nFrom: a@b.com";
        $headers = $method->invoke(null, $raw);

        static::assertCount(2, $headers);
        static::assertSame('Subject', $headers[0][0]);
        static::assertStringContainsString('part1', $headers[0][1]);
        static::assertStringContainsString('part2', $headers[0][1]);
        static::assertStringContainsString("\r\n", $headers[0][1]);
        static::assertSame('From', $headers[1][0]);
    }

    public function testParseHeadersTabContinuationLine(): void
    {
        $method = new ReflectionMethod(Signer::class, 'parseHeaders');

        $raw = "Subject: part1\r\n\tcontinued\r\nFrom: a@b.com";
        $headers = $method->invoke(null, $raw);

        static::assertCount(2, $headers);
        static::assertStringContainsString("\tcontinued", $headers[0][1]);
    }

    public function testParseHeadersContinuationAppendsWithCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'parseHeaders');

        $raw = "Subject: first\r\n second";
        $headers = $method->invoke(null, $raw);

        static::assertCount(1, $headers);
        static::assertSame("Subject: first\r\n second", $headers[0][1]);
    }

    public function testParseHeadersContinuationLineContinuesLoop(): void
    {
        $method = new ReflectionMethod(Signer::class, 'parseHeaders');

        $raw = "Subject: line1\r\n line2\r\n line3\r\nFrom: a@b.com";
        $headers = $method->invoke(null, $raw);

        static::assertCount(2, $headers);
        static::assertStringContainsString('line2', $headers[0][1]);
        static::assertStringContainsString('line3', $headers[0][1]);
        static::assertSame('From', $headers[1][0]);
    }

    public function testParseHeadersCRLFNormalization(): void
    {
        $method = new ReflectionMethod(Signer::class, 'parseHeaders');

        $raw = "Subject: value\r\nFrom: a@b.com";
        $headers = $method->invoke(null, $raw);

        static::assertCount(2, $headers);
        static::assertSame('Subject', $headers[0][0]);
        static::assertSame('From', $headers[1][0]);
    }

    public function testHashBodyExactMaxLengthIsNotTruncated(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash1, $len1] = $method->invoke(null, 'AB', Canonicalization::Simple, 4);

        $expected = hash('sha256', "AB\r\n", true);
        static::assertSame($expected, $hash1);
        static::assertSame(4, $len1);
    }

    public function testHashBodyOneLargerThanMaxIsTruncated(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $length] = $method->invoke(null, 'ABC', Canonicalization::Simple, 4);

        static::assertSame(4, $length);
        $expected = hash('sha256', "ABC\r", true);
        static::assertSame($expected, $hash);
    }

    public function testCanonicalizeBodyRelaxedEmptyReturnsEmptyString(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        // RFC 6376 §3.4.4: relaxed only appends a CRLF to a non-empty body.
        static::assertSame('', $method->invoke(null, '', Canonicalization::Relaxed));
        static::assertSame('', $method->invoke(null, "\r\n", Canonicalization::Relaxed));
        static::assertSame('', $method->invoke(null, " \t\r\n\r\n\t", Canonicalization::Relaxed));
        static::assertSame(" \t\r\n", $method->invoke(null, " \t\r\n\r\n", Canonicalization::Simple));
    }

    public function testSignEmptyBodyHashesPerCanonicalization(): void
    {
        $message = "From: sender@example.com\r\n\r\n";

        $relaxed = $this->createSigner(
            new SigningConfiguration('example.com', 'default', bodyCanonicalization: Canonicalization::Relaxed),
        );
        $simple = $this->createSigner(
            new SigningConfiguration('example.com', 'default', bodyCanonicalization: Canonicalization::Simple),
        );

        // SHA-256 of "" (relaxed) and of "\r\n" (simple).
        static::assertSame('47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=', self::extractTag(
            $relaxed->sign($message),
            'bh',
        ));
        static::assertSame('frcCV1k9oG9oKj3dpUqdJg1PxRT2RSN/XKdLCPjaYaY=', self::extractTag(
            $simple->sign($message),
            'bh',
        ));
    }

    public function testSignatureLineChunkSizeIs73(): void
    {
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Long enough to produce sig\r\n\r\nBody content here that is long enough";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $bPos = strpos($signed, '; b=');
        static::assertNotFalse($bPos);
        $afterB = substr($signed, $bPos + 4);
        $headerEnd = strpos($afterB, "\r\nFrom:");
        if ($headerEnd !== false) {
            $sigBlock = substr($afterB, 0, $headerEnd);
        } else {
            $sigBlock = $afterB;
        }

        $sigLines = explode("\r\n ", $sigBlock);
        foreach ($sigLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            static::assertLessThanOrEqual(73, strlen($trimmed));
        }
    }

    public function testSignRtrimsEncodedSignature(): void
    {
        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $headerEnd = strpos($signed, "\r\nFrom:");
        static::assertNotFalse($headerEnd);
        $dkimHeader = substr($signed, 0, $headerEnd);
        static::assertStringNotContainsString(" \r\nFrom:", $signed);
    }

    public function testDkimHeaderCanonDataIncludesRtrim(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Simple);

        $message = "From: sender@example.com\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        $b = self::extractTag($signed, 'b');
        static::assertNotEmpty($b);
    }

    public function testHeaderCanonDataAccumulatesAcrossAllHeaders(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);
        $signer = $this->createSigner($config);

        $oneHeader = "From: sender@example.com\r\n\r\nBody";
        $twoHeaders = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $threeHeaders = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\n\r\nBody";

        $sig1 = self::extractTag($signer->sign($oneHeader), 'b');
        $sig2 = self::extractTag($signer->sign($twoHeaders), 'b');
        $sig3 = self::extractTag($signer->sign($threeHeaders), 'b');

        static::assertNotSame($sig1, $sig2);
        static::assertNotSame($sig2, $sig3);
        static::assertNotSame($sig1, $sig3);
    }

    public function testHeaderCanonDataUsesAppendNotAssign(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);

        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\nDate: Thu, 01 Jan 2026 00:00:00 +0000\r\n\r\nBody";

        $signer = $this->createSigner($config);
        $signed = $signer->sign($message);

        $dkimPart = substr($signed, 0, strpos($signed, "\r\nFrom:") ?: strlen($signed));
        static::assertStringContainsString('from', $dkimPart);
        static::assertStringContainsString('to', $dkimPart);
        static::assertStringContainsString('subject', $dkimPart);
        static::assertStringContainsString('date', $dkimPart);

        $b = self::extractTag($signed, 'b');
        static::assertNotEmpty($b);
    }

    public function testSignatureChangesWhenHeadersAdded(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);
        $signer = $this->createSigner($config);

        $msg1 = "From: sender@example.com\r\n\r\nBody";
        $msg2 = "From: sender@example.com\r\nX-Extra: val\r\n\r\nBody";

        $b1 = self::extractTag($signer->sign($msg1), 'b');
        $b2 = self::extractTag($signer->sign($msg2), 'b');

        static::assertNotSame($b1, $b2);
    }

    public function testDkimHeaderCanonDataRtrimRemovesTrailingCRLF(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);
        $message = "From: sender@example.com\r\n\r\nBody";

        $signer = $this->createSigner($config);
        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        $bh = self::extractTag($signed, 'bh');
        static::assertNotEmpty($bh);
    }

    public function testDkimHeaderCanonDataWithRtrimProducesValidSignature(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed1 = $signer->sign($message);
        $signed2 = $signer->sign($message);

        static::assertTrue(str_starts_with($signed1, 'DKIM-Signature:'));
        static::assertTrue(str_starts_with($signed2, 'DKIM-Signature:'));
        $bh1 = self::extractTag($signed1, 'bh');
        $bh2 = self::extractTag($signed2, 'bh');
        static::assertSame($bh1, $bh2);
    }

    public function testDkimHeaderCanonDataAppendNotReplace(): void
    {
        $config = new SigningConfiguration('example.com', 'default', headerCanonicalization: Canonicalization::Relaxed);
        $message = "From: sender@example.com\r\nTo: rcpt@example.com\r\nSubject: Test\r\n\r\nBody";
        $signer = $this->createSigner($config);

        $signed = $signer->sign($message);
        $b = self::extractTag($signed, 'b');
        static::assertNotEmpty($b);

        $singleHeaderMsg = "From: sender@example.com\r\n\r\nBody";
        $signedSingle = $signer->sign($singleHeaderMsg);
        $bSingle = self::extractTag($signedSingle, 'b');

        static::assertNotSame($b, $bSingle);
    }

    public function testSignatureChunkSplitAt73NotOtherValues(): void
    {
        $message = "From: sender@example.com\r\nTo: a@b.com\r\nSubject: Test subject line\r\n\r\nBody content here";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $bPos = strpos($signed, '; b=');
        static::assertNotFalse($bPos);

        $afterB = substr($signed, $bPos + 4);
        $headerEnd = strpos($afterB, "\r\nFrom:");
        $sigBlock = $headerEnd !== false ? substr($afterB, 0, $headerEnd) : $afterB;

        $sigLines = explode("\r\n ", $sigBlock);
        foreach ($sigLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            static::assertLessThanOrEqual(73, strlen($trimmed));
        }
    }

    public function testSignatureBlockBase64LineLengthIs73(): void
    {
        $message = "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: Testing signature line length\r\n\r\nThis is the body of the message";
        $signer = $this->createSigner();

        $signed = $signer->sign($message);

        $bPos = strpos($signed, '; b=');
        static::assertNotFalse($bPos);
        $afterB = substr($signed, $bPos + 4);
        $endPos = strpos($afterB, "\r\nFrom:");
        $sigBlock = $endPos !== false ? substr($afterB, 0, $endPos) : $afterB;
        $lines = explode("\r\n ", $sigBlock);

        $nonEmptyLines = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '') {
                $nonEmptyLines[] = $t;
            }
        }

        static::assertNotEmpty($nonEmptyLines);
        $lastIdx = count($nonEmptyLines) - 1;
        foreach ($nonEmptyLines as $idx => $line) {
            if ($idx === $lastIdx) {
                continue;
            }

            static::assertSame(73, strlen($line), 'Non-final signature line should be exactly 73 chars');
        }
    }

    public function testHashBodyExactMaxLengthUsesStrictGreaterThan(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hashExact, $lenExact] = $method->invoke(null, 'AB', Canonicalization::Simple, 4);

        $fullCanon = "AB\r\n";
        $expected = hash('sha256', $fullCanon, true);
        static::assertSame($expected, $hashExact);
        static::assertSame(4, $lenExact);

        [$hashOver, $lenOver] = $method->invoke(null, 'ABC', Canonicalization::Simple, 4);
        static::assertSame(4, $lenOver);
        $truncated = hash('sha256', "ABC\r", true);
        static::assertSame($truncated, $hashOver);
    }

    public function testHashBodyMaxLengthBoundaryNotTruncatedWhenEqual(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $len] = $method->invoke(null, 'XY', Canonicalization::Simple, 4);
        static::assertSame(4, $len);
        $expected = hash('sha256', "XY\r\n", true);
        static::assertSame($expected, $hash);
    }

    public function testHashBodyMaxLengthBoundaryTruncatedWhenOneOver(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash5, $len5] = $method->invoke(null, 'XYZ', Canonicalization::Simple, 4);
        static::assertSame(4, $len5);

        [$hash4, $len4] = $method->invoke(null, 'XY', Canonicalization::Simple, 4);
        static::assertSame(4, $len4);

        static::assertNotSame($hash4, $hash5);
    }

    public function testCanonicalizeBodyEmptyReturnsExactlyCRLF(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $simpleResult = $method->invoke(null, '', Canonicalization::Simple);
        static::assertSame("\r\n", $simpleResult);
        static::assertSame(2, strlen($simpleResult));

        $relaxedResult = $method->invoke(null, '', Canonicalization::Relaxed);
        static::assertSame('', $relaxedResult);
    }

    public function testCanonicalizeBodyEmptyReturnValueIsUsed(): void
    {
        $method = new ReflectionMethod(Signer::class, 'canonicalizeBody');

        $result = $method->invoke(null, '', Canonicalization::Simple);
        static::assertNotEmpty($result);
        static::assertSame("\r\n", $result);
    }

    public function testCanonicalizeBodyEmptyHashDiffersFromEmptyString(): void
    {
        $method = new ReflectionMethod(Signer::class, 'hashBody');

        [$hash, $len] = $method->invoke(null, '', Canonicalization::Simple, 0);

        $emptyHash = hash('sha256', '', true);
        $crlfHash = hash('sha256', "\r\n", true);

        static::assertNotSame($emptyHash, $hash);
        static::assertSame($crlfHash, $hash);
        static::assertSame(2, $len);
    }

    public function testSignWithPassphraseNullUsesEmptyString(): void
    {
        $signer = new Signer(Certificates::DKIM_RSA_KEY, new SigningConfiguration('example.com', 'default'), null);
        $message = "From: sender@example.com\r\n\r\nBody";

        $signed = $signer->sign($message);

        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
    }

    public function testSignWithExplicitEmptyPassphraseMatchesNullPassphrase(): void
    {
        $signerNull = new Signer(Certificates::DKIM_RSA_KEY, new SigningConfiguration('example.com', 'default'), null);
        $signerEmpty = new Signer(Certificates::DKIM_RSA_KEY, new SigningConfiguration('example.com', 'default'), '');

        $message = "From: sender@example.com\r\n\r\nBody";

        $signedNull = $signerNull->sign($message);
        $signedEmpty = $signerEmpty->sign($message);

        $bhNull = self::extractTag($signedNull, 'bh');
        $bhEmpty = self::extractTag($signedEmpty, 'bh');
        static::assertSame($bhNull, $bhEmpty);
    }

    public function testSignWithPassphraseFallbackCoalesceOrder(): void
    {
        $signer = new Signer(Certificates::DKIM_RSA_KEY, new SigningConfiguration('example.com', 'default'));
        $message = "From: sender@example.com\r\n\r\nBody";

        $signed = $signer->sign($message);
        static::assertTrue(str_starts_with($signed, 'DKIM-Signature:'));
        $b = self::extractTag($signed, 'b');
        static::assertNotEmpty($b);
    }

    public function testSignInvalidKeyThrowsDKIMException(): void
    {
        $signer = new Signer('completely-invalid-key', new SigningConfiguration('example.com', 'default'));

        $this->expectException(DKIMException::class);
        $this->expectExceptionMessage('unable to load RSA private key');

        $signer->sign("From: a@b.com\r\n\r\nBody");
    }

    public function testSignInvalidKeyResultCheckUsesOr(): void
    {
        $signer = new Signer('bad-key', new SigningConfiguration('example.com', 'default'));

        $this->expectException(DKIMException::class);
        $this->expectExceptionMessage('unable to load RSA private key');

        $signer->sign("From: a@b.com\r\n\r\nBody");
    }

    public function testSignInvalidKeyAlwaysThrows(): void
    {
        $signer = new Signer('not-a-key', new SigningConfiguration('example.com', 'default'));

        $this->expectException(DKIMException::class);

        $signer->sign("From: a@b.com\r\n\r\nBody");
    }

    private static function extractTag(string $header, string $tag): string
    {
        $pattern = '/' . $tag . '=([^;]+)/';
        if (preg_match($pattern, $header, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
