<?php

namespace ESN\CalDAV;

use ESN\Utils\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Settings;

#[\AllowDynamicProperties]
class RecurrenceExpansionLimitTest extends \PHPUnit\Framework\TestCase {

    // A daily series started in 2010 needs ~5800 occurrences to reach 2026
    const LONG_RUNNING_DAILY_EVENT = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:long-running-daily
DTSTART:20100101T090000Z
DTEND:20100101T100000Z
RRULE:FREQ=DAILY
SUMMARY:Daily stand-up
END:VEVENT
END:VCALENDAR
ICS;

    private $originalLimit;

    protected function setUp(): void {
        $this->originalLimit = Settings::$maxRecurrences;
        Env::reset();
        putenv(RecurrenceExpansionLimit::SETTING);
    }

    protected function tearDown(): void {
        Settings::$maxRecurrences = $this->originalLimit;
        Env::reset();
        putenv(RecurrenceExpansionLimit::SETTING);
    }

    function testDefaultsToTheVObjectLimitWhenUnset() {
        $this->assertSame(3500, RecurrenceExpansionLimit::resolve());
    }

    public static function configuredLimits(): array {
        return [
            'positive integer' => [10000, 10000],
            'positive string' => ['10000', 10000],
            'negative disables' => [-5, RecurrenceExpansionLimit::UNLIMITED],
            'zero keeps the default' => [0, 3500],
            'unparseable keeps the default' => ['lots', 3500],
        ];
    }

    #[DataProvider('configuredLimits')]
    function testResolvesTheConfiguredLimit($configured, int $expected) {
        Env::init([RecurrenceExpansionLimit::SETTING => $configured]);

        $this->assertSame($expected, RecurrenceExpansionLimit::resolve());
    }

    function testReadsTheProcessEnvironment() {
        putenv(RecurrenceExpansionLimit::SETTING . '=8000');

        $this->assertSame(8000, RecurrenceExpansionLimit::resolve());
    }

    function testApplyConfiguresVObject() {
        Env::init([RecurrenceExpansionLimit::SETTING => 8000]);

        RecurrenceExpansionLimit::apply();

        $this->assertSame(8000, Settings::$maxRecurrences);
    }

    function testDefaultLimitCannotReachRecentOccurrencesOfALongRunningSeries() {
        RecurrenceExpansionLimit::apply();

        $this->expectException(MaxInstancesExceededException::class);

        $this->isInJanuary2026(self::LONG_RUNNING_DAILY_EVENT);
    }

    function testRaisedLimitReachesRecentOccurrencesOfALongRunningSeries() {
        Env::init([RecurrenceExpansionLimit::SETTING => 10000]);
        RecurrenceExpansionLimit::apply();

        $this->assertTrue($this->isInJanuary2026(self::LONG_RUNNING_DAILY_EVENT));
    }

    function testDisabledLimitReachesRecentOccurrencesOfALongRunningSeries() {
        Env::init([RecurrenceExpansionLimit::SETTING => -1]);
        RecurrenceExpansionLimit::apply();

        $this->assertTrue($this->isInJanuary2026(self::LONG_RUNNING_DAILY_EVENT));
    }

    private function isInJanuary2026(string $ics): bool {
        return Reader::read($ics)->VEVENT->isInTimeRange(
            new \DateTimeImmutable('2026-01-15T00:00:00Z'),
            new \DateTimeImmutable('2026-01-16T00:00:00Z')
        );
    }
}
