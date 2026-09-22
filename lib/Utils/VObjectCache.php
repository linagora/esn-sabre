<?php

namespace ESN\Utils;

use Sabre\VObject\Document;
use Sabre\VObject\Reader;

/**
 * Request scoped, content addressed VObject parse cache.
 *
 * A single PUT used to parse the very same payload up to four times: every
 * plugin hooked on beforeWriteContent read the node again and called
 * Reader::read() on it. Parsing is by far the most expensive part of handling
 * a calendar object, so the pipeline now parses each distinct payload once and
 * hands the result around.
 *
 * Entries are keyed by content, which makes the cache safe to share between
 * unrelated call sites: the same bytes always yield the same document. The
 * cache is deliberately tiny (see DEFAULT_CAPACITY) because a single write
 * only ever juggles a handful of payloads, while a calendar-query REPORT walks
 * thousands of them and must not be allowed to retain them all.
 *
 * Lifetime rules:
 *   - read() returns the shared instance. Callers must neither mutate nor
 *     destroy() it, or every other holder of the same payload is corrupted.
 *   - readMutable() returns a private copy, safe to mutate and destroy.
 */
class VObjectCache {
    /**
     * Number of parsed documents kept around.
     *
     * A write juggles at most the old object plus a couple of successive
     * revisions of the new one, so four is comfortable. Keeping it small is
     * what stops a REPORT over a large calendar from pinning every event it
     * touches in memory.
     */
    const DEFAULT_CAPACITY = 4;

    /** @var array<string, Document> content key => parsed document, oldest first */
    private $entries = [];

    /** @var int */
    private $capacity;

    /** @var int number of times Reader was actually invoked */
    private $parses = 0;

    /** @var int number of times a parse was avoided */
    private $hits = 0;

    function __construct($capacity = self::DEFAULT_CAPACITY) {
        $this->capacity = max(1, (int) $capacity);
    }

    /**
     * Returns the shared parsed document for this payload.
     *
     * The returned document is shared with every other caller that passed the
     * same bytes: treat it as read only and never destroy() it.
     *
     * @param string|resource $data iCalendar, vCard or jCal payload
     * @return Document
     * @throws \Sabre\VObject\ParseException on malformed input
     */
    function read($data) {
        $data = self::asString($data);
        $key = self::keyFor($data);

        if (isset($this->entries[$key])) {
            $this->hits++;

            // Refresh recency so the entry survives the next eviction.
            $document = $this->entries[$key];
            unset($this->entries[$key]);
            $this->entries[$key] = $document;

            return $document;
        }

        $document = self::parse($data);
        $this->parses++;

        $this->entries[$key] = $document;
        $this->evictOverflow();

        return $document;
    }

    /**
     * Returns a private copy of the parsed document, safe to mutate.
     *
     * The payload is still parsed only once: subsequent callers clone the
     * cached document instead of re-parsing, and cloning a VObject tree is
     * markedly cheaper than lexing one.
     *
     * @param string|resource $data
     * @return Document
     * @throws \Sabre\VObject\ParseException on malformed input
     */
    function readMutable($data) {
        return clone $this->read($data);
    }

    /**
     * Drops every entry. Call this at request boundaries; within a request the
     * capacity bound is enough.
     */
    function reset() {
        $this->entries = [];
    }

    /**
     * Parse accounting, so a test can pin down how many times a request parses.
     *
     * @return array{parses: int, hits: int}
     */
    function getStats() {
        return ['parses' => $this->parses, 'hits' => $this->hits];
    }

    function resetStats() {
        $this->parses = 0;
        $this->hits = 0;
    }

    private function evictOverflow() {
        while (count($this->entries) > $this->capacity) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    /**
     * jCal and vCard-JSON payloads start with a '[', everything else is parsed
     * as its text flavour. Centralising the sniff here is the point: it used to
     * be copy-pasted in Utils::formatIcal, BinaryAttachmentPlugin and
     * EventRealTimePlugin.
     */
    private static function parse($data) {
        if (substr($data, 0, 1) === '[') {
            return Reader::readJson($data);
        }

        return Reader::read($data);
    }

    private static function keyFor($data) {
        // Length is part of the key so that a hash collision would also have to
        // collide on size before it could hand back the wrong document.
        return strlen($data) . ':' . md5($data);
    }

    private static function asString($data) {
        if (is_resource($data)) {
            rewind($data);
            $contents = stream_get_contents($data);
            rewind($data);

            return $contents;
        }

        return $data;
    }
}
