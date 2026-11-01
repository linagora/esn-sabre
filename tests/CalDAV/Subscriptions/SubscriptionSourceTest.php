<?php

namespace ESN\CalDAV\Subscriptions;

use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionSourceTest extends \PHPUnit\Framework\TestCase {

    public static function readSources(): array {
        return [
            'canonical' => ['calendars/home/cal'],
            'leading slash' => ['/calendars/home/cal'],
            'trailing slash' => ['calendars/home/cal/'],
            'trailing double slash' => ['/calendars/home/cal//'],
        ];
    }

    public static function unreadSources(): array {
        return [
            'null' => [null],
            'empty' => [''],
            'absolute URL' => ['http://localhost/calendars/home/cal'],
            'query' => ['calendars/home/cal?x=1'],
            'fragment' => ['calendars/home/cal#f'],
            'double slash' => ['calendars//home/cal'],
            'dot segment' => ['calendars/./cal'],
            'parent segment' => ['calendars/home/..'],
            'extra segment' => ['calendars/home/cal/event.ics'],
            'json suffixed home' => ['calendars/home.json'],
            'not a calendar home' => ['addressbooks/home/cal'],
        ];
    }

    #[DataProvider('readSources')]
    function testParseReadsCalendarPaths($source) {
        $this->assertSame(['home', 'cal'], SubscriptionSource::parse($source));
        $this->assertSame('calendars/home/cal', SubscriptionSource::toPath($source));
    }

    #[DataProvider('unreadSources')]
    function testParseRejectsAnythingElse($source) {
        $this->assertNull(SubscriptionSource::parse($source));
        $this->assertNull(SubscriptionSource::toPath($source));
    }
}
