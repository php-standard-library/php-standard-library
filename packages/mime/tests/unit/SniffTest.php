<?php

declare(strict_types=1);

namespace Psl\MIME\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psl\IO;
use Psl\MIME\Sniff;
use Psl\Str;

final class SniffTest extends TestCase
{
    public function testFromStringPng(): void
    {
        $png =
            "\x89PNG\r\n\x1a\n"
            . "\x00\x00\x00\x0dIHDR"
            . "\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02"
            . "\x00\x00\x00\x90\x77\x53\xde\x00";
        $type = Sniff\from_string($png);

        static::assertSame('image/png', $type->essence());
    }

    public function testFromStringGif87a(): void
    {
        $gif = 'GIF87a' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($gif);

        static::assertSame('image/gif', $type->essence());
    }

    public function testFromStringGif89a(): void
    {
        $gif = 'GIF89a' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($gif);

        static::assertSame('image/gif', $type->essence());
    }

    public function testFromStringJpeg(): void
    {
        $jpeg = "\xff\xd8\xff\xe0" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($jpeg);

        static::assertSame('image/jpeg', $type->essence());
    }

    public function testFromStringBmp(): void
    {
        $bmp = 'BM' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($bmp);

        static::assertSame('image/bmp', $type->essence());
    }

    public function testFromStringWebp(): void
    {
        $webp = "RIFF\x00\x00\x00\x00WEBP" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($webp);

        static::assertSame('image/webp', $type->essence());
    }

    public function testFromStringWav(): void
    {
        $wav = "RIFF\x00\x00\x00\x00WAVE" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($wav);

        static::assertSame('audio/wav', $type->essence());
    }

    public function testFromStringTiffLE(): void
    {
        $tiff = "II\x2a\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($tiff);

        static::assertSame('image/tiff', $type->essence());
    }

    public function testFromStringTiffBE(): void
    {
        $tiff = "MM\x00\x2a" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($tiff);

        static::assertSame('image/tiff', $type->essence());
    }

    public function testFromStringMp3Id3(): void
    {
        $mp3 = 'ID3' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringOgg(): void
    {
        $ogg = 'OggS' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($ogg);

        static::assertSame('audio/ogg', $type->essence());
    }

    public function testFromStringFlac(): void
    {
        $flac = 'fLaC' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($flac);

        static::assertSame('audio/flac', $type->essence());
    }

    public function testFromStringZip(): void
    {
        $zip = "PK\x03\x04" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($zip);

        static::assertSame('application/zip', $type->essence());
    }

    public function testFromStringGzip(): void
    {
        $gz = "\x1f\x8b" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($gz);

        static::assertSame('application/gzip', $type->essence());
    }

    public function testFromStringBzip2(): void
    {
        $bz2 = 'BZh' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($bz2);

        static::assertSame('application/x-bzip2', $type->essence());
    }

    public function testFromStringXz(): void
    {
        $xz = "\xfd\x37\x7a\x58\x5a\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($xz);

        static::assertSame('application/x-xz', $type->essence());
    }

    public function testFromStringZstd(): void
    {
        $zstd = "\x28\xb5\x2f\xfd" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($zstd);

        static::assertSame('application/zstd', $type->essence());
    }

    public function testFromString7z(): void
    {
        $sz = "\x37\x7a\xbc\xaf\x27\x1c" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($sz);

        static::assertSame('application/x-7z-compressed', $type->essence());
    }

    public function testFromStringPdf(): void
    {
        $pdf = '%PDF-1.4' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($pdf);

        static::assertSame('application/pdf', $type->essence());
    }

    public function testFromStringElf(): void
    {
        $elf = "\x7fELF" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($elf);

        static::assertSame('application/x-elf', $type->essence());
    }

    public function testFromStringSqlite(): void
    {
        $sqlite = "SQLite format 3\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($sqlite);

        static::assertSame('application/x-sqlite3', $type->essence());
    }

    public function testFromStringWasm(): void
    {
        $wasm = "\x00asm" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($wasm);

        static::assertSame('application/wasm', $type->essence());
    }

    public function testFromStringFtypMp4(): void
    {
        $mp4 = "\x00\x00\x00\x1cftypisom" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp4);

        static::assertSame('video/mp4', $type->essence());
    }

    public function testFromStringFtypQuicktime(): void
    {
        $qt = "\x00\x00\x00\x1cftypqt  " . Str\repeat("\x00", 100);
        $type = Sniff\from_string($qt);

        static::assertSame('video/quicktime', $type->essence());
    }

    public function testFromStringPlainText(): void
    {
        $type = Sniff\from_string('Hello, this is plain text content.');

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromStringHtml(): void
    {
        $type = Sniff\from_string('<!DOCTYPE html><html><body>Hello</body></html>');

        static::assertSame('text/html', $type->essence());
    }

    public function testFromStringHtmlTag(): void
    {
        $type = Sniff\from_string('<html><body>Hello</body></html>');

        static::assertSame('text/html', $type->essence());
    }

    public function testFromStringXml(): void
    {
        $type = Sniff\from_string('<?xml version="1.0"?><root/>');

        static::assertSame('application/xml', $type->essence());
    }

    public function testFromStringSvg(): void
    {
        $type = Sniff\from_string('<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        static::assertSame('image/svg+xml', $type->essence());
    }

    public function testFromStringJson(): void
    {
        $type = Sniff\from_string('{"key": "value"}');

        static::assertSame('application/json', $type->essence());
    }

    public function testFromStringJsonArray(): void
    {
        $type = Sniff\from_string('[1, 2, 3]');

        static::assertSame('application/json', $type->essence());
    }

    public function testFromStringShebang(): void
    {
        $type = Sniff\from_string("#!/bin/bash\necho hello");

        static::assertSame('text/x-script', $type->essence());
    }

    public function testFromStringEmpty(): void
    {
        $type = Sniff\from_string('');

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringBinary(): void
    {
        $binary = Str\repeat("\x00\x01\x02\x03\x04\x05\x06\x07", 64);
        $type = Sniff\from_string($binary);

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringWoff(): void
    {
        $woff = 'wOFF' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($woff);

        static::assertSame('font/woff', $type->essence());
    }

    public function testFromStringWoff2(): void
    {
        $woff2 = 'wOF2' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($woff2);

        static::assertSame('font/woff2', $type->essence());
    }

    public function testFromStringRar(): void
    {
        $rar = "Rar!\x1a\x07" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($rar);

        static::assertSame('application/vnd.rar', $type->essence());
    }

    public function testFromStringMp3Frame(): void
    {
        $mp3 = "\xff\xfb\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringIco(): void
    {
        $ico = "\x00\x00\x01\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($ico);

        static::assertSame('image/x-icon', $type->essence());
    }

    public function testFromStringWebm(): void
    {
        $webm = "\x1a\x45\xdf\xa3" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($webm);

        static::assertSame('video/webm', $type->essence());
    }

    public function testFromStringWhitespaceBeforeHtml(): void
    {
        $type = Sniff\from_string("   \n  <!DOCTYPE html><html></html>");

        static::assertSame('text/html', $type->essence());
    }

    public function testFromStringWhitespaceBeforeJson(): void
    {
        $type = Sniff\from_string("  \n  {\"a\": 1}");

        static::assertSame('application/json', $type->essence());
    }

    public function testFromStringFormFeedBeforeHtml(): void
    {
        $type = Sniff\from_string("\t\r\n\x0C <!DOCTYPE html><html></html>");

        static::assertSame('text/html', $type->essence());
    }

    public function testFromStringVerticalTabIsNotSkippedAsWhitespace(): void
    {
        $type = Sniff\from_string("\x0B<!DOCTYPE html><html></html>");

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromStringXmlSvg(): void
    {
        $type = Sniff\from_string('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>');

        static::assertSame('image/svg+xml', $type->essence());
    }

    public function testFromStringRiffUnknown(): void
    {
        $riff = "RIFF\x00\x00\x00\x00ABCD" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($riff);

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringFtypM4a(): void
    {
        $m4a = "\x00\x00\x00\x1cftypM4A " . Str\repeat("\x00", 100);
        $type = Sniff\from_string($m4a);

        static::assertSame('audio/mp4', $type->essence());
    }

    public function testFromStringFtypUnknownBrand(): void
    {
        $mp4 = "\x00\x00\x00\x1cftypXXXX" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp4);

        static::assertSame('video/mp4', $type->essence());
    }

    public function testFromStringOtf(): void
    {
        $otf = 'OTTO' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($otf);

        static::assertSame('font/otf', $type->essence());
    }

    public function testFromStringMsOffice(): void
    {
        $doc = "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($doc);

        static::assertSame('application/vnd.ms-office', $type->essence());
    }

    public function testFromHandleSniffsPng(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . Str\repeat("\x00", 100);
        $handle = new IO\MemoryHandle($png);

        $type = Sniff\from_handle($handle);

        static::assertSame('image/png', $type->essence());
    }

    public function testFromHandlePreservesPosition(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . Str\repeat("\x00", 100);
        $handle = new IO\MemoryHandle($png);
        $handle->seek(10);

        Sniff\from_handle($handle);

        static::assertSame(10, $handle->tell());
    }

    public function testFromHandleAtStart(): void
    {
        $json = '{"key": "value"}';
        $handle = new IO\MemoryHandle($json);

        $type = Sniff\from_handle($handle);

        static::assertSame('application/json', $type->essence());
        static::assertSame(0, $handle->tell());
    }

    public function testFromHandleEmptyReturnsOctetStream(): void
    {
        $handle = new IO\MemoryHandle('');

        $type = Sniff\from_handle($handle);

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringRiffTooShort(): void
    {
        $riff = "RIFF\x00\x00\x00\x00";
        $type = Sniff\from_string($riff);

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringFtypTooShort(): void
    {
        $ftyp = "\x00\x00\x00\x08ftyp";
        $type = Sniff\from_string($ftyp);

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringAvi(): void
    {
        $avi = "RIFF\x00\x00\x00\x00AVI " . Str\repeat("\x00", 100);
        $type = Sniff\from_string($avi);

        static::assertSame('video/x-msvideo', $type->essence());
    }

    public function testFromStringFtypM4b(): void
    {
        $m4b = "\x00\x00\x00\x1cftypM4B " . Str\repeat("\x00", 100);
        $type = Sniff\from_string($m4b);

        static::assertSame('audio/mp4', $type->essence());
    }

    public function testFromStringMp3FrameF3(): void
    {
        $mp3 = "\xff\xf3\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringMp3FrameF2(): void
    {
        $mp3 = "\xff\xf2\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringMp3FrameMaskedE2(): void
    {
        $mp3 = "\xff\xe2\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringMp3FrameMaskedE3(): void
    {
        $mp3 = "\xff\xe3\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringMp3FrameMaskedEA(): void
    {
        $mp3 = "\xff\xea\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringMp3FrameMaskedEB(): void
    {
        $mp3 = "\xff\xeb\x90\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($mp3);

        static::assertSame('audio/mpeg', $type->essence());
    }

    public function testFromStringMp3FrameMaskedMismatch(): void
    {
        $data = "\xff\xc2\x00\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($data);

        static::assertSame('application/octet-stream', $type->essence());
    }

    public function testFromStringZipVariant0506(): void
    {
        $zip = "PK\x05\x06" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($zip);

        static::assertSame('application/zip', $type->essence());
    }

    public function testFromStringZipVariant0708(): void
    {
        $zip = "PK\x07\x08" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($zip);

        static::assertSame('application/zip', $type->essence());
    }

    public function testFromStringCursorIco(): void
    {
        $cur = "\x00\x00\x02\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($cur);

        static::assertSame('image/x-icon', $type->essence());
    }

    public function testFromStringMachOFeedface(): void
    {
        $macho = "\xfe\xed\xfa\xce" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($macho);

        static::assertSame('application/x-mach-binary', $type->essence());
    }

    public function testFromStringMachOCffaedfe(): void
    {
        $macho = "\xcf\xfa\xed\xfe" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($macho);

        static::assertSame('application/x-mach-binary', $type->essence());
    }

    public function testFromStringMachOCafebabe(): void
    {
        $macho = "\xca\xfe\xba\xbe" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($macho);

        static::assertSame('application/x-mach-binary', $type->essence());
    }

    public function testFromStringDosExe(): void
    {
        $exe = 'MZ' . Str\repeat("\x00", 100);
        $type = Sniff\from_string($exe);

        static::assertSame('application/x-dosexec', $type->essence());
    }

    public function testFromStringSfnt(): void
    {
        $sfnt = "\x00\x01\x00\x00\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($sfnt);

        static::assertSame('font/sfnt', $type->essence());
    }

    public function testFromStringType1Font(): void
    {
        $type1 = "\x01\x00\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($type1);

        static::assertSame('application/x-font-type1', $type->essence());
    }

    public function testFromStringOpenXmlMatchesZip(): void
    {
        $ooxml = "\x50\x4b\x03\x04\x14\x00\x06\x00" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($ooxml);

        static::assertSame('application/zip', $type->essence());
    }

    public function testFromStringUtf16LeBom(): void
    {
        $content = "\xFF\xFEH\x00e\x00l\x00l\x00o\x00";
        $type = Sniff\from_string($content);

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromStringInvalidJson(): void
    {
        $type = Sniff\from_string('{not valid json at all}');

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromStringJsonNull(): void
    {
        $type = Sniff\from_string('null');

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromStringPlainAsciiNoSpecialPrefix(): void
    {
        $type = Sniff\from_string('Just some regular text content without any special markers.');

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromHandleAtNonZeroOffset(): void
    {
        $content = Str\repeat('x', 100) . "\x89PNG\r\n\x1a\n";
        $handle = new IO\MemoryHandle($content);
        $handle->seek(50);

        $type = Sniff\from_handle($handle);

        static::assertSame('text/plain', $type->essence());
        static::assertSame(50, $handle->tell());
    }

    public function testFromStringFtypDash(): void
    {
        $dash = "\x00\x00\x00\x1cftypdash" . Str\repeat("\x00", 100);
        $type = Sniff\from_string($dash);

        static::assertSame('video/mp4', $type->essence());
    }

    public function testFromStringFtypM4v(): void
    {
        $m4v = "\x00\x00\x00\x1cftypM4V " . Str\repeat("\x00", 100);
        $type = Sniff\from_string($m4v);

        static::assertSame('video/mp4', $type->essence());
    }

    public function testFromStringJsonWithLeadingBracket(): void
    {
        $type = Sniff\from_string('["valid", "json", "array"]');

        static::assertSame('application/json', $type->essence());
    }

    public function testFromStringInvalidJsonArray(): void
    {
        $type = Sniff\from_string('[not valid json]');

        static::assertSame('text/plain', $type->essence());
    }

    public function testFromStringHtmlCaseInsensitive(): void
    {
        $type = Sniff\from_string('<!DOCTYPE HTML><HTML><BODY></BODY></HTML>');

        static::assertSame('text/html', $type->essence());
    }

    public function testFromStringSvgWithXmlDecl(): void
    {
        $type = Sniff\from_string('<?xml version="1.0"?><svg viewBox="0 0 100 100"></svg>');

        static::assertSame('image/svg+xml', $type->essence());
    }

    public function testFromStringShortContent(): void
    {
        $type = Sniff\from_string('a');

        static::assertSame('text/plain', $type->essence());
    }
}
