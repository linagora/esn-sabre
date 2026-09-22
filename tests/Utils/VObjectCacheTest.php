<?php

namespace ESN\Utils;

use PHPUnit\Framework\TestCase;

/**
 * @medium
 */
class VObjectCacheTest extends TestCase {

    private function ics($summary = 'Meeting') {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:parse-cache',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20260322T090000Z',
            'DTEND:20260322T100000Z',
            'SUMMARY:' . $summary,
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);
    }

    function testShouldParseIdenticalPayloadOnlyOnce() {
        $cache = new VObjectCache();

        $first = $cache->read($this->ics());
        $second = $cache->read($this->ics());

        $this->assertSame($first, $second);
        $this->assertSame(['parses' => 1, 'hits' => 1], $cache->getStats());
    }

    function testShouldParseDistinctPayloadsSeparately() {
        $cache = new VObjectCache();

        $first = $cache->read($this->ics('Meeting'));
        $second = $cache->read($this->ics('Other meeting'));

        $this->assertNotSame($first, $second);
        $this->assertSame(['parses' => 2, 'hits' => 0], $cache->getStats());
    }

    function testShouldAcceptStreamsAndLeaveThemRewound() {
        $cache = new VObjectCache();

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->ics());
        rewind($stream);

        $fromStream = $cache->read($stream);
        $fromString = $cache->read($this->ics());

        $this->assertSame($fromStream, $fromString);
        $this->assertSame(0, ftell($stream));
        fclose($stream);
    }

    function testShouldHandJCalPayloadsToTheJsonReader() {
        $cache = new VObjectCache();

        $jCal = json_encode($cache->read($this->ics())->jsonSerialize());
        $parsed = $cache->read($jCal);

        $this->assertInstanceOf(\Sabre\VObject\Component\VCalendar::class, $parsed);
        $this->assertSame('parse-cache', (string) $parsed->VEVENT->UID);
    }

    function testReadMutableShouldIsolateCallersFromEachOther() {
        $cache = new VObjectCache();

        $mine = $cache->readMutable($this->ics());
        $mine->VEVENT->SUMMARY = 'Rewritten';

        $shared = $cache->read($this->ics());

        $this->assertSame('Meeting', (string) $shared->VEVENT->SUMMARY);
        // Still a single parse: the mutable copy was cloned off the cached one.
        $this->assertSame(1, $cache->getStats()['parses']);
    }

    function testShouldEvictBeyondCapacityWithoutBreakingHandedOutDocuments() {
        $cache = new VObjectCache(2);

        $evicted = $cache->read($this->ics('First'));
        $cache->read($this->ics('Second'));
        $cache->read($this->ics('Third'));

        // The caller still holds a working document even though its entry is gone:
        // eviction must never destroy() what someone may still be reading.
        $this->assertSame('First', (string) $evicted->VEVENT->SUMMARY);
        $this->assertNotSame($evicted, $cache->read($this->ics('First')));
    }

    function testShouldKeepRecentlyUsedEntriesAlive() {
        $cache = new VObjectCache(2);

        $first = $cache->read($this->ics('First'));
        $cache->read($this->ics('Second'));
        // Touching 'First' makes 'Second' the eviction candidate instead.
        $cache->read($this->ics('First'));
        $cache->read($this->ics('Third'));

        $this->assertSame($first, $cache->read($this->ics('First')));
    }

    function testPutShouldSpareTheNextReaderTheParse() {
        $cache = new VObjectCache();

        $document = \Sabre\VObject\Reader::read($this->ics());
        $cache->put($this->ics(), $document);

        $this->assertSame($document, $cache->read($this->ics()));
        $this->assertSame(['parses' => 0, 'hits' => 1], $cache->getStats());
    }

    function testPutShouldNotDisplaceADocumentCallersMayAlreadyHold() {
        $cache = new VObjectCache();

        $held = $cache->read($this->ics());
        $cache->put($this->ics(), \Sabre\VObject\Reader::read($this->ics()));

        $this->assertSame($held, $cache->read($this->ics()));
    }

    function testResetShouldDropEverything() {
        $cache = new VObjectCache();

        $before = $cache->read($this->ics());
        $cache->reset();
        $after = $cache->read($this->ics());

        $this->assertNotSame($before, $after);
    }

    function testShouldNotCacheUnparseablePayloads() {
        $cache = new VObjectCache();

        $this->expectException(\Sabre\VObject\ParseException::class);

        $cache->read('this is not a calendar');
    }
}
