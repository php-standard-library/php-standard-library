<?php

declare(strict_types=1);

namespace Psl\Terminal\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Psl\Terminal\Event;
use Psl\Terminal\Internal\EventParser;

final class EventParserAdditionalTest extends TestCase
{
    private EventParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EventParser();
    }

    public function testBackspaceViaBs(): void
    {
        $events = $this->parser->feed("\x08");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('backspace'));
    }

    public function testCtrlSpace(): void
    {
        $events = $this->parser->feed("\x00");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+space'));
    }

    public function testCtrlA(): void
    {
        $events = $this->parser->feed("\x01");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+a'));
    }

    public function testCtrlZ(): void
    {
        $events = $this->parser->feed("\x1A");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+z'));
    }

    public function testAllPrintableAscii(): void
    {
        $events = $this->parser->feed(' ');
        static::assertCount(1, $events);
        static::assertSame(' ', $events[0]->char);

        $events = $this->parser->feed('~');
        static::assertCount(1, $events);
        static::assertSame('~', $events[0]->char);

        $events = $this->parser->feed('0');
        static::assertCount(1, $events);
        static::assertSame('0', $events[0]->char);

        $events = $this->parser->feed('Z');
        static::assertCount(1, $events);
        static::assertSame('Z', $events[0]->char);
    }

    public function testUtf8ThreeByteCharacter(): void
    {
        $events = $this->parser->feed("\xE2\x9C\x93");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame("\xE2\x9C\x93", $events[0]->char);
    }

    public function testUtf8FourByteCharacter(): void
    {
        $events = $this->parser->feed("\xF0\x9F\x98\x80");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame("\xF0\x9F\x98\x80", $events[0]->char);
    }

    public function testUtf8TwoByteFollowedByAscii(): void
    {
        $events = $this->parser->feed("\xC3\xA9a");

        static::assertCount(2, $events);
        static::assertSame("\xC3\xA9", $events[0]->char);
        static::assertSame('a', $events[1]->char);
    }

    public function testUtf8TwoByteCharacterSplitAcrossFeeds(): void
    {
        static::assertSame([], $this->parser->feed("\xC3"));

        $events = $this->parser->feed("\xA9a");

        static::assertCount(2, $events);
        static::assertSame("\xC3\xA9", $events[0]->char);
        static::assertSame('a', $events[1]->char);
    }

    public function testUtf8FourByteCharacterSplitAcrossFeeds(): void
    {
        static::assertSame([], $this->parser->feed("\xF0"));
        static::assertSame([], $this->parser->feed("\x9F\x98"));

        $events = $this->parser->feed("\x80");

        static::assertCount(1, $events);
        static::assertSame("\xF0\x9F\x98\x80", $events[0]->char);
    }

    public function testUtf8IncompleteCharacterAfterOtherInput(): void
    {
        $events = $this->parser->feed("a\xE2\x9C");

        static::assertCount(1, $events);
        static::assertSame('a', $events[0]->char);

        $events = $this->parser->feed("\x93");

        static::assertCount(1, $events);
        static::assertSame("\xE2\x9C\x93", $events[0]->char);
    }

    public function testUtf8LeadByteDoesNotSwallowFollowingAscii(): void
    {
        $events = $this->parser->feed("\xC3a");

        static::assertCount(1, $events);
        static::assertSame('a', $events[0]->char);
    }

    public function testUtf8LeadByteDoesNotSwallowFollowingEscapeSequence(): void
    {
        $events = $this->parser->feed("\xC3\e[A");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('up'));
    }

    public function testUtf8TruncatedThreeByteCharacterDoesNotWaitForMoreInput(): void
    {
        $events = $this->parser->feed("\xE2a");

        static::assertCount(1, $events);
        static::assertSame('a', $events[0]->char);

        $events = $this->parser->feed("\xE2\x9Cb");

        static::assertCount(1, $events);
        static::assertSame('b', $events[0]->char);
    }

    public function testUtf8LeadByteFollowedByAnotherCharacter(): void
    {
        $events = $this->parser->feed("\xC3\xC3\xA9");

        static::assertCount(1, $events);
        static::assertSame("\xC3\xA9", $events[0]->char);
    }

    public function testUtf8InvalidLeadBytesAreDropped(): void
    {
        static::assertSame([], $this->parser->feed("\xC0\xAF"));
        static::assertSame([], $this->parser->feed("\xC1\xBF"));
        static::assertSame([], $this->parser->feed("\xF5\x80\x80\x80"));
        static::assertSame([], $this->parser->feed("\xFF"));

        $events = $this->parser->feed("\xF8a");

        static::assertCount(1, $events);
        static::assertSame('a', $events[0]->char);
    }

    public function testUtf8OverlongAndSurrogateSequencesAreDropped(): void
    {
        static::assertSame([], $this->parser->feed("\xE0\x80\x80"));
        static::assertSame([], $this->parser->feed("\xED\xA0\x80"));
        static::assertSame([], $this->parser->feed("\xF0\x80\x80\x80"));
        static::assertSame([], $this->parser->feed("\xF4\x90\x80\x80"));
    }

    public function testUtf8BoundaryCharactersAreAccepted(): void
    {
        $events = $this->parser->feed("\xC2\x80\xE0\xA0\x80\xED\x9F\xBF\xF0\x90\x80\x80\xF4\x8F\xBF\xBF");

        static::assertCount(5, $events);
        static::assertSame("\xC2\x80", $events[0]->char);
        static::assertSame("\xE0\xA0\x80", $events[1]->char);
        static::assertSame("\xED\x9F\xBF", $events[2]->char);
        static::assertSame("\xF0\x90\x80\x80", $events[3]->char);
        static::assertSame("\xF4\x8F\xBF\xBF", $events[4]->char);
    }

    public function testFlushPendingKeepsUtf8CharacterSplitAcrossReads(): void
    {
        static::assertSame([], $this->parser->feed("\xC3"));
        static::assertSame([], $this->parser->flushPending());

        $events = $this->parser->feed("\xA9");

        static::assertCount(1, $events);
        static::assertSame('é', $events[0]->char);

        static::assertSame([], $this->parser->feed("\xF0\x9F"));
        static::assertSame([], $this->parser->flushPending());
        static::assertSame([], $this->parser->feed("\x98"));
        static::assertSame([], $this->parser->flushPending());

        $events = $this->parser->feed("\x80");

        static::assertCount(1, $events);
        static::assertSame("\u{1F600}", $events[0]->char);
    }

    public function testFlushPendingDiscardsStaleIncompleteUtf8Character(): void
    {
        static::assertSame([], $this->parser->feed("\xC3"));
        static::assertSame([], $this->parser->flushPending());
        static::assertSame([], $this->parser->flushPending());

        // The lead byte is gone, so a late continuation byte is a stray byte on its own.
        static::assertSame([], $this->parser->feed("\xA9"));

        static::assertSame([], $this->parser->feed("\xF0\x9F\x98"));
        static::assertSame([], $this->parser->flushPending());
        static::assertSame([], $this->parser->flushPending());

        $events = $this->parser->feed("\x80a");

        static::assertCount(1, $events);
        static::assertSame('a', $events[0]->char);
    }

    public function testAltPlusLetter(): void
    {
        $events = $this->parser->feed("\ea");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+a'));
    }

    public function testAltPlusNumber(): void
    {
        $events = $this->parser->feed("\e5");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+5'));
    }

    public function testAltPlusSpecialChar(): void
    {
        $events = $this->parser->feed("\e!");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+!'));
    }

    public function testSs3FunctionKeys(): void
    {
        $events = $this->parser->feed("\eOP\eOQ\eOR\eOS");

        static::assertCount(4, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('f1'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('f2'));
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('f3'));
        static::assertInstanceOf(Event\Key::class, $events[3]);
        static::assertTrue($events[3]->is('f4'));
    }

    public function testSs3ApplicationCursorKeys(): void
    {
        $events = $this->parser->feed("\eOA\eOB\eOC\eOD\eOH\eOF");

        static::assertCount(6, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('up'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('down'));
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('right'));
        static::assertInstanceOf(Event\Key::class, $events[3]);
        static::assertTrue($events[3]->is('left'));
        static::assertInstanceOf(Event\Key::class, $events[4]);
        static::assertTrue($events[4]->is('home'));
        static::assertInstanceOf(Event\Key::class, $events[5]);
        static::assertTrue($events[5]->is('end'));
    }

    public function testSs3ApplicationKeypadKeys(): void
    {
        $events = $this->parser->feed("\eOM\eOq\eOk");

        static::assertCount(3, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('enter'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('1', $events[1]->char);
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertSame('+', $events[2]->char);
    }

    public function testSs3SplitAcrossFeeds(): void
    {
        static::assertSame([], $this->parser->feed("\eO"));

        $events = $this->parser->feed('P');

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('f1'));
    }

    public function testAltShiftOFollowedByByteThatIsNotAnSs3Key(): void
    {
        $events = $this->parser->feed("\eOz\eOZa");

        static::assertCount(5, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+O'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('z', $events[1]->char);
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('alt+O'));
        static::assertInstanceOf(Event\Key::class, $events[3]);
        static::assertSame('Z', $events[3]->char);
        static::assertInstanceOf(Event\Key::class, $events[4]);
        static::assertSame('a', $events[4]->char);
    }

    public function testAltShiftOFollowedByNonFinalByte(): void
    {
        $events = $this->parser->feed("\eO\e[A");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+O'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('up'));

        $events = $this->parser->feed("\eO1");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+O'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('1', $events[1]->char);
    }

    public function testFlushPendingEmitsAltShiftO(): void
    {
        static::assertSame([], $this->parser->feed("\eO"));

        $flushed = $this->parser->flushPending();

        static::assertCount(1, $flushed);
        static::assertTrue($flushed[0]->is('alt+O'));
        static::assertSame([], $this->parser->flushPending());
    }

    public function testEscFollowedByEscEmitsEscapeKey(): void
    {
        $events = $this->parser->feed("\e\e");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('escape'));
    }

    public function testPasteAcrossMultipleFeeds(): void
    {
        $events = $this->parser->feed("\e[200~Hello ");
        static::assertCount(0, $events);

        $events = $this->parser->feed("World\e[201~");
        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Paste::class, $events[0]);
        static::assertSame('Hello World', $events[0]->text);
    }

    public function testPasteStartPartial(): void
    {
        $events = $this->parser->feed("\e[200");
        static::assertCount(0, $events);

        $events = $this->parser->feed("~test\e[201~");
        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Paste::class, $events[0]);
        static::assertSame('test', $events[0]->text);
    }

    public function testPasteEmpty(): void
    {
        $events = $this->parser->feed("\e[200~\e[201~");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Paste::class, $events[0]);
        static::assertSame('', $events[0]->text);
    }

    public function testPasteWithSpecialCharacters(): void
    {
        $events = $this->parser->feed("\e[200~line1\nline2\ttab\e[201~");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Paste::class, $events[0]);
        static::assertSame("line1\nline2\ttab", $events[0]->text);
    }

    public function testInBandResizeInvalidPrefix(): void
    {
        $events = $this->parser->feed("\e[49;25;80t");

        static::assertNull($events[0] ?? null);
    }

    public function testInBandResizeZeroWidth(): void
    {
        $events = $this->parser->feed("\e[48;25;0t");

        static::assertCount(0, $events);
    }

    public function testInBandResizeZeroHeight(): void
    {
        $events = $this->parser->feed("\e[48;0;80t");

        static::assertCount(0, $events);
    }

    public function testInBandResizeTooFewParams(): void
    {
        $events = $this->parser->feed("\e[48;25t");

        static::assertCount(0, $events);
    }

    public function testSgrMouseIncomplete(): void
    {
        $events = $this->parser->feed("\e[<0;10;");
        static::assertCount(0, $events);

        $events = $this->parser->feed('5M');
        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
    }

    public function testSgrMouseInvalidParams(): void
    {
        $events = $this->parser->feed("\e[<0;10M");
        static::assertCount(0, $events);
    }

    public function testCsiSequenceIncompleteWaitsForMore(): void
    {
        $events = $this->parser->feed("\e[1;");
        static::assertCount(0, $events);

        $events = $this->parser->feed('5A');
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+up'));
    }

    public function testInsertKey(): void
    {
        $events = $this->parser->feed("\e[2~");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('insert'));
    }

    public function testHomeTildeForm(): void
    {
        $events = $this->parser->feed("\e[1~");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('home'));
    }

    public function testEndTildeForm(): void
    {
        $events = $this->parser->feed("\e[4~");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('end'));
    }

    public function testFunctionKeysViaCSI(): void
    {
        $events = $this->parser->feed("\e[15~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f5'));

        $events = $this->parser->feed("\e[17~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f6'));

        $events = $this->parser->feed("\e[18~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f7'));

        $events = $this->parser->feed("\e[19~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f8'));

        $events = $this->parser->feed("\e[20~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f9'));

        $events = $this->parser->feed("\e[21~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f10'));

        $events = $this->parser->feed("\e[23~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f11'));

        $events = $this->parser->feed("\e[24~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('f12'));
    }

    public function testShiftTab(): void
    {
        $events = $this->parser->feed("\e[Z");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('shift+tab'));
    }

    public function testSgrMouseWithCtrlModifier(): void
    {
        $events = $this->parser->feed("\e[<16;10;5M");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
        static::assertTrue($events[0]->modifiers->ctrl());
    }

    public function testSgrMouseWithShiftModifier(): void
    {
        $events = $this->parser->feed("\e[<4;10;5M");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
        static::assertTrue($events[0]->modifiers->shift());
    }

    public function testSgrMouseWithAltModifier(): void
    {
        $events = $this->parser->feed("\e[<8;10;5M");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
        static::assertTrue($events[0]->modifiers->alt());
    }

    public function testHighByteSkipped(): void
    {
        $events = $this->parser->feed("\x80");

        static::assertCount(0, $events);
    }

    public function testMixedEventsInSingleFeed(): void
    {
        $events = $this->parser->feed("a\r\t");

        static::assertCount(3, $events);
        static::assertSame('a', $events[0]->char);
        static::assertTrue($events[1]->is('enter'));
        static::assertTrue($events[2]->is('tab'));
    }

    public function testAltOIsRecognizedAsFocusLost(): void
    {
        $events = $this->parser->feed("\e[O");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Focus::class, $events[0]);
        static::assertFalse($events[0]->focused);
    }

    public function testKittyKeyboardEnterNoModifier(): void
    {
        $events = $this->parser->feed("\e[13u");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('enter'));
    }

    public function testKittyKeyboardTabNoModifier(): void
    {
        $events = $this->parser->feed("\e[9u");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('tab'));
    }

    public function testKittyKeyboardEscapeNoModifier(): void
    {
        $events = $this->parser->feed("\e[27u");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('escape'));
    }

    public function testKittyKeyboardBackspaceNoModifier(): void
    {
        $events = $this->parser->feed("\e[127u");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('backspace'));
    }

    public function testKittyKeyboardCharA(): void
    {
        $events = $this->parser->feed("\e[97u");

        static::assertCount(1, $events);
        static::assertSame('a', $events[0]->char);
    }

    public function testKittyKeyboardCtrlEnter(): void
    {
        $events = $this->parser->feed("\e[13;5u");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+enter'));
    }

    public function testKittyKeyboardCtrlA(): void
    {
        $events = $this->parser->feed("\e[97;5u");

        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+a'));
    }

    public function testKittyKeyboardF1(): void
    {
        $events = $this->parser->feed("\e[P\e[Q\e[13~\e[S");

        static::assertCount(4, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('f1'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('f2'));
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('f3'));
        static::assertInstanceOf(Event\Key::class, $events[3]);
        static::assertTrue($events[3]->is('f4'));
    }

    public function testKittyKeyboardNonAsciiCodepoint(): void
    {
        $events = $this->parser->feed("\e[233u\e[233;3u");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('é', $events[0]->char);
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('alt+é'));
    }

    public function testKittyKeyboardReleaseIsNotReportedAsPress(): void
    {
        $events = $this->parser->feed("\e[97;5u\e[97;5:3u\e[1;1:3A\e[13;1:3u");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+a'));
    }

    public function testKittyKeyboardRepeatIsReportedAsPress(): void
    {
        $events = $this->parser->feed("\e[97;5:2u");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+a'));
    }

    public function testKittyKeyboardKeypadAndExtendedFunctionKeys(): void
    {
        $events = $this->parser->feed("\e[57419u\e[57399u\e[57376u");

        static::assertCount(3, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('up'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('0', $events[1]->char);
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('f13'));
    }

    public function testKittyKeyboardCtrlSpaceMatchesLegacyEncoding(): void
    {
        $events = $this->parser->feed("\e[32;5u\x00");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+space'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('ctrl+space'));
    }

    public function testKittyKeyboardAltSpaceMatchesLegacyEncoding(): void
    {
        $events = $this->parser->feed("\e[32;3u\e ");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('alt+ ', $events[0]->name);
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('alt+ ', $events[1]->name);
    }

    public function testKittyKeyboardSuperIsReported(): void
    {
        $events = $this->parser->feed("\e[15;9~\e[97;9u");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('super+f5'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('super+a'));
    }

    public function testKittyKeyboardLockModifiersDoNotHideCtrl(): void
    {
        $events = $this->parser->feed("\e[97;69u");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertNull($events[0]->char);
        static::assertTrue($events[0]->is('ctrl+a'));
    }

    public function testModifiedTildeFunctionKeys(): void
    {
        $events = $this->parser->feed("\e[15;5~\e[24;2~");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('ctrl+f5'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('shift+f12'));
    }

    public function testFlushPendingDoesNotFlushBufferWithMoreThanEsc(): void
    {
        $this->parser->feed("\e[");
        $flushed = $this->parser->flushPending();
        static::assertCount(0, $flushed);
    }

    public function testFlushPendingOnEmptyBuffer(): void
    {
        $flushed = $this->parser->flushPending();
        static::assertCount(0, $flushed);
    }

    public function testModifiedArrowKeysShift(): void
    {
        $events = $this->parser->feed("\e[1;2A");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('shift+up'));

        $events = $this->parser->feed("\e[1;2B");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('shift+down'));

        $events = $this->parser->feed("\e[1;2C");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('shift+right'));

        $events = $this->parser->feed("\e[1;2D");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('shift+left'));
    }

    public function testModifiedArrowKeysAlt(): void
    {
        $events = $this->parser->feed("\e[1;3A");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('alt+up'));
    }

    public function testModifiedFunctionKeysViaCSI(): void
    {
        $events = $this->parser->feed("\e[1;5P");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+f1'));

        $events = $this->parser->feed("\e[1;5Q");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+f2'));

        $events = $this->parser->feed("\e[1;5R");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+f3'));

        $events = $this->parser->feed("\e[1;5S");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+f4'));
    }

    public function testModifiedHomeEndKeys(): void
    {
        $events = $this->parser->feed("\e[1;5H");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+home'));

        $events = $this->parser->feed("\e[1;5F");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+end'));
    }

    public function testModifiedTildeKeys(): void
    {
        $events = $this->parser->feed("\e[5;5~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+page_up'));

        $events = $this->parser->feed("\e[6;5~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('ctrl+page_down'));

        $events = $this->parser->feed("\e[3;2~");
        static::assertCount(1, $events);
        static::assertTrue($events[0]->is('shift+delete'));
    }

    public function testSgrMouseScrollLeft(): void
    {
        $events = $this->parser->feed("\e[<66;5;5M");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
        static::assertSame(Event\MouseKind::ScrollLeft, $events[0]->kind);
        static::assertSame(4, $events[0]->column);
        static::assertSame(4, $events[0]->row);
        static::assertSame(Event\MouseButton::None, $events[0]->button);
    }

    public function testSgrMouseScrollRight(): void
    {
        $events = $this->parser->feed("\e[<67;5;5M");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
        static::assertSame(Event\MouseKind::ScrollRight, $events[0]->kind);
        static::assertSame(Event\MouseButton::None, $events[0]->button);
    }

    public function testSgrMouseHorizontalScrollWithModifier(): void
    {
        // Shift (4) + wheel left (66).
        $events = $this->parser->feed("\e[<70;5;5M");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Mouse::class, $events[0]);
        static::assertSame(Event\MouseKind::ScrollLeft, $events[0]->kind);
        static::assertSame(Event\MouseButton::None, $events[0]->button);
        static::assertTrue($events[0]->modifiers->shift());
    }

    public function testRapidSequentialEvents(): void
    {
        $events = $this->parser->feed("\e[A\e[B\e[C\e[D");

        static::assertCount(4, $events);
        static::assertTrue($events[0]->is('up'));
        static::assertTrue($events[1]->is('down'));
        static::assertTrue($events[2]->is('right'));
        static::assertTrue($events[3]->is('left'));
    }

    public function testPasteFollowedByKeyEvent(): void
    {
        $events = $this->parser->feed("\e[200~paste\e[201~a");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Paste::class, $events[0]);
        static::assertSame('paste', $events[0]->text);
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('a', $events[1]->char);
    }

    public function testKeyEventFollowedByPaste(): void
    {
        $events = $this->parser->feed("a\e[200~paste\e[201~");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('a', $events[0]->char);
        static::assertInstanceOf(Event\Paste::class, $events[1]);
        static::assertSame('paste', $events[1]->text);
    }

    public function testCsiSequenceWithUnknownFinalByteDoesNotSwallowNextKey(): void
    {
        $events = $this->parser->feed("\e[5{a\e[1;2|b");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('a', $events[0]->char);
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('b', $events[1]->char);
    }

    public function testCsiSequenceBrokenByControlByteIsAltBracket(): void
    {
        $events = $this->parser->feed("\e[\r\e[1\e[A");

        static::assertCount(5, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+['));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertTrue($events[1]->is('enter'));
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('alt+['));
        static::assertInstanceOf(Event\Key::class, $events[3]);
        static::assertSame('1', $events[3]->char);
        static::assertInstanceOf(Event\Key::class, $events[4]);
        static::assertTrue($events[4]->is('up'));

        $events = $this->parser->feed("\e[\xC3\xA9");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+['));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('é', $events[1]->char);
    }

    public function testCsiSequenceBrokenByEscapeKeepsFollowingSequence(): void
    {
        $events = $this->parser->feed("\e[3\e[B");

        static::assertCount(3, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('alt+['));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('3', $events[1]->char);
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('down'));
    }

    public function testRxvtModifiedKeys(): void
    {
        $events = $this->parser->feed("\e[3\$x\e[2^y\e[7@z");

        static::assertCount(6, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('shift+delete'));
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('x', $events[1]->char);
        static::assertInstanceOf(Event\Key::class, $events[2]);
        static::assertTrue($events[2]->is('ctrl+insert'));
        static::assertInstanceOf(Event\Key::class, $events[3]);
        static::assertSame('y', $events[3]->char);
        static::assertInstanceOf(Event\Key::class, $events[4]);
        static::assertTrue($events[4]->is('ctrl+shift+home'));
        static::assertInstanceOf(Event\Key::class, $events[5]);
        static::assertSame('z', $events[5]->char);
    }

    public function testDollarIntermediateByteInNonRxvtSequence(): void
    {
        // DECRPM reply: the "$" is an intermediate byte, "y" is the final byte.
        $events = $this->parser->feed("\e[?2026;2\$yq");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('q', $events[0]->char);
    }

    public function testLinuxConsoleFunctionKeys(): void
    {
        $events = $this->parser->feed("\e[[A\e[[B\e[[C\e[[D\e[[E");

        static::assertCount(5, $events);
        foreach (['f1', 'f2', 'f3', 'f4', 'f5'] as $index => $name) {
            static::assertInstanceOf(Event\Key::class, $events[$index]);
            static::assertTrue($events[$index]->is($name));
        }

        static::assertSame([], $this->parser->feed("\e[["));

        $events = $this->parser->feed('A');

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertTrue($events[0]->is('f1'));

        $events = $this->parser->feed("\e[[Za");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('Z', $events[0]->char);
        static::assertInstanceOf(Event\Key::class, $events[1]);
        static::assertSame('a', $events[1]->char);
    }

    public function testSgrMouseSequenceWithUnexpectedFinalByteDoesNotSwallowNextKey(): void
    {
        $events = $this->parser->feed("\e[<0;10;5Xa\e[<0;10;5M");

        static::assertCount(2, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('a', $events[0]->char);
        static::assertInstanceOf(Event\Mouse::class, $events[1]);
        static::assertSame(Event\MouseKind::Press, $events[1]->kind);
    }

    public function testSgrMouseExtraButtonsAreNotReportedAsLeftMiddleOrRight(): void
    {
        // Buttons 8-11: press, release, drag, and a modifier bit on top.
        $events = $this->parser->feed("\e[<128;10;5M\e[<129;10;5m\e[<162;11;5M\e[<147;10;5Ma");

        static::assertCount(1, $events);
        static::assertInstanceOf(Event\Key::class, $events[0]);
        static::assertSame('a', $events[0]->char);
    }
}
