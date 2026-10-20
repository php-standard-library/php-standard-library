<?php

declare(strict_types=1);

namespace Psl\DNS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\DNS\ResponseCode;

final class ResponseCodeTest extends TestCase
{
    public function testAllCasesHaveExpectedValues(): void
    {
        static::assertSame('NOERROR', ResponseCode::NoError->value);
        static::assertSame('FORMERR', ResponseCode::FormatError->value);
        static::assertSame('SERVFAIL', ResponseCode::ServerFailure->value);
        static::assertSame('NXDOMAIN', ResponseCode::NonExistentDomain->value);
        static::assertSame('NOTIMP', ResponseCode::NotImplemented->value);
        static::assertSame('REFUSED', ResponseCode::ServerRefused->value);
        static::assertSame('YXDOMAIN', ResponseCode::DomainShouldNotExist->value);
        static::assertSame('YXRRSET', ResponseCode::RecordSetShouldNotExist->value);
        static::assertSame('NXRRSET', ResponseCode::RecordSetShouldExist->value);
        static::assertSame('NOTAUTH', ResponseCode::NotAuthoritative->value);
        static::assertSame('NOTZONE', ResponseCode::NameNotInZone->value);
        static::assertSame('BADVERS', ResponseCode::BadVersion->value);
        static::assertSame('BADCOOKIE', ResponseCode::BadCookie->value);
    }

    public function testNoErrorIsSuccess(): void
    {
        static::assertTrue(ResponseCode::NoError->isSuccess());
        static::assertFalse(ResponseCode::NoError->isError());
        static::assertFalse(ResponseCode::NoError->isServerError());
        static::assertFalse(ResponseCode::NoError->isNameError());
    }

    public function testServerFailureIsServerError(): void
    {
        static::assertFalse(ResponseCode::ServerFailure->isSuccess());
        static::assertTrue(ResponseCode::ServerFailure->isError());
        static::assertTrue(ResponseCode::ServerFailure->isServerError());
    }

    public function testServerRefusedIsServerError(): void
    {
        static::assertTrue(ResponseCode::ServerRefused->isError());
        static::assertTrue(ResponseCode::ServerRefused->isServerError());
    }

    public function testNonExistentDomainIsNameError(): void
    {
        static::assertTrue(ResponseCode::NonExistentDomain->isError());
        static::assertFalse(ResponseCode::NonExistentDomain->isServerError());
        static::assertTrue(ResponseCode::NonExistentDomain->isNameError());
    }

    public function testAllNonNoErrorAreErrors(): void
    {
        foreach (ResponseCode::cases() as $code) {
            if ($code === ResponseCode::NoError) {
                static::assertTrue($code->isSuccess());
                static::assertFalse($code->isError());
            } else {
                static::assertFalse($code->isSuccess());
                static::assertTrue($code->isError());
            }
        }
    }

    public function testOnlyServfailAndRefusedAreServerErrors(): void
    {
        foreach (ResponseCode::cases() as $code) {
            if ($code === ResponseCode::ServerFailure || $code === ResponseCode::ServerRefused) {
                static::assertTrue($code->isServerError());
            } else {
                static::assertFalse($code->isServerError());
            }
        }
    }

    public function testOnlyNxdomainIsNameError(): void
    {
        foreach (ResponseCode::cases() as $code) {
            if ($code === ResponseCode::NonExistentDomain) {
                static::assertTrue($code->isNameError());
            } else {
                static::assertFalse($code->isNameError());
            }
        }
    }
}
