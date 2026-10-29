<?php

namespace ESN\CardDAV;

use ESN\Utils\Env;
use PHPUnit\Framework\Attributes\DataProvider;

class ContactFiltersTest extends \PHPUnit\Framework\TestCase {

    const BOOK = '0123456789abcdef01234567';

    protected function setUp(): void {
        Env::reset();
        putenv(ContactFilters::SEARCH_MIN_LENGTH_SETTING);
    }

    protected function tearDown(): void {
        Env::reset();
        putenv(ContactFilters::SEARCH_MIN_LENGTH_SETTING);
    }

    private function searchMatches(string $search, string $carddata): int {
        $regex = ContactFilters::query(self::BOOK, ['search' => $search])['carddata'];
        // MongoDB evaluates the pattern with PCRE, as preg_match does
        $result = @preg_match('~' . str_replace('~', '\~', $regex->getPattern()) . '~' . $regex->getFlags(), $carddata);
        $this->assertNotFalse($result, 'search pattern that does not compile');

        return $result;
    }

    function testSearchIsTreatedAsLiteralNotRegex(): void {
        $this->assertSame(1, $this->searchMatches('(p', 'x(py'));
        $this->assertSame(0, $this->searchMatches('a.c', 'abc'));
        $this->assertSame(1, $this->searchMatches('a.c', 'xa.cx'));
    }

    function testSearchIsCaseInsensitive(): void {
        $this->assertSame(1, $this->searchMatches('JOHN', "FN:john doe"));
    }

    public static function metacharacters(): array {
        return [['('], ['['], ['\\'], ['*abc'], ['(a+)+$'], ['a|b'], ['^FN'], ['x{2}'], ['~/#-'], ["nul\0byte"]];
    }

    #[DataProvider('metacharacters')]
    function testMetacharactersOnlyMatchThemselves(string $search): void {
        $this->assertSame(1, $this->searchMatches($search, 'before' . $search . 'after'));
        $this->assertSame(0, $this->searchMatches($search, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaab'));
    }

    function testSearchMinLengthDefaultsToThree(): void {
        $this->assertFalse(ContactFilters::isSearchLongEnough('ab'));
        $this->assertFalse(ContactFilters::isSearchLongEnough('  ab  '));
        $this->assertTrue(ContactFilters::isSearchLongEnough('abc'));
        $this->assertTrue(ContactFilters::isSearchLongEnough('éèà'));
    }

    function testSearchMinLengthIsConfigurable(): void {
        Env::init([ContactFilters::SEARCH_MIN_LENGTH_SETTING => 5]);
        $this->assertFalse(ContactFilters::isSearchLongEnough('abcd'));
        $this->assertTrue(ContactFilters::isSearchLongEnough('abcde'));

        putenv(ContactFilters::SEARCH_MIN_LENGTH_SETTING . '=0');
        Env::reset();
        $this->assertTrue(ContactFilters::isSearchLongEnough(''));

        putenv(ContactFilters::SEARCH_MIN_LENGTH_SETTING . '=-1');
        $this->assertTrue(ContactFilters::isSearchLongEnough(''));

        putenv(ContactFilters::SEARCH_MIN_LENGTH_SETTING . '=nonsense');
        $this->assertSame(3, ContactFilters::searchMinLength());
    }
}
