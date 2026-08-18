<?php

namespace ESN\Utils;

#[\AllowDynamicProperties]
class EnvTest extends \PHPUnit\Framework\TestCase {

    const NAME = 'ESN_TEST_SETTING';

    protected function setUp(): void {
        Env::reset();
        putenv(self::NAME);
    }

    protected function tearDown(): void {
        Env::reset();
        putenv(self::NAME);
    }

    // Resolution order: config.json, then environment, then default

    function testGetReturnsNullWhenSetNowhere() {
        $this->assertNull(Env::get(self::NAME));
    }

    function testGetFallsBackToTheEnvironmentWhenConfigIsEmpty() {
        putenv(self::NAME . '=from-env');

        $this->assertEquals('from-env', Env::get(self::NAME));
    }

    function testConfigTakesPrecedenceOverTheEnvironment() {
        putenv(self::NAME . '=from-env');
        Env::init([self::NAME => 'from-config']);

        $this->assertEquals('from-config', Env::get(self::NAME));
    }

    function testEmptyConfigValueFallsBackToTheEnvironment() {
        putenv(self::NAME . '=from-env');
        Env::init([self::NAME => '']);

        $this->assertEquals('from-env', Env::get(self::NAME));
    }

    function testNullConfigValueFallsBackToTheEnvironment() {
        putenv(self::NAME . '=from-env');
        Env::init([self::NAME => null]);

        $this->assertEquals('from-env', Env::get(self::NAME));
    }

    function testEmptyEnvironmentValueIsTreatedAsUnset() {
        putenv(self::NAME . '=   ');

        $this->assertNull(Env::get(self::NAME));
    }

    function testNonScalarConfigValueFallsBackToTheEnvironment() {
        putenv(self::NAME . '=from-env');
        Env::init([self::NAME => ['nested' => true]]);

        $this->assertEquals('from-env', Env::get(self::NAME));
    }

    function testNonScalarConfigValueFallsBackToTheDefault() {
        Env::init([self::NAME => ['nested' => true]]);

        $this->assertTrue(Env::getBoolean(self::NAME, true));
        $this->assertEquals('fallback', Env::getString(self::NAME, 'fallback'));
        $this->assertEquals(200, Env::getInteger(self::NAME, 200));
    }

    function testZeroIsAValidValue() {
        Env::init([self::NAME => 0]);

        $this->assertEquals(0, Env::getInteger(self::NAME, 200));
        $this->assertFalse(Env::getBoolean(self::NAME, true));
    }

    function testInitIgnoresANonArraySection() {
        Env::init(null);

        $this->assertNull(Env::get(self::NAME));
    }

    function testResetDropsTheConfigSection() {
        Env::init([self::NAME => 'from-config']);
        Env::reset();

        $this->assertNull(Env::get(self::NAME));
    }

    // getString

    function testGetStringReturnsTheDefaultWhenUnset() {
        $this->assertEquals('fallback', Env::getString(self::NAME, 'fallback'));
        $this->assertNull(Env::getString(self::NAME));
    }

    function testGetStringTrimsSurroundingWhitespace() {
        Env::init([self::NAME => '  filter  ']);

        $this->assertEquals('filter', Env::getString(self::NAME));
    }

    function testGetStringRendersJsonBooleans() {
        Env::init([self::NAME => true]);
        $this->assertEquals('true', Env::getString(self::NAME));

        Env::init([self::NAME => false]);
        $this->assertEquals('false', Env::getString(self::NAME));
    }

    // getBoolean

    function testGetBooleanReturnsTheDefaultWhenUnset() {
        $this->assertTrue(Env::getBoolean(self::NAME, true));
        $this->assertFalse(Env::getBoolean(self::NAME, false));
    }

    function testGetBooleanAcceptsJsonBooleans() {
        Env::init([self::NAME => false]);
        $this->assertFalse(Env::getBoolean(self::NAME, true));

        Env::init([self::NAME => true]);
        $this->assertTrue(Env::getBoolean(self::NAME, false));
    }

    /**
     * @dataProvider truthyValuesProvider
     */
    function testGetBooleanAcceptsTruthySpellings($value) {
        Env::init([self::NAME => $value]);

        $this->assertTrue(Env::getBoolean(self::NAME, false));
    }

    static function truthyValuesProvider() {
        return [['true'], ['TRUE'], ['True'], ['1'], ['yes'], ['on'], [' true ']];
    }

    /**
     * @dataProvider falsyValuesProvider
     */
    function testGetBooleanAcceptsFalsySpellings($value) {
        Env::init([self::NAME => $value]);

        $this->assertFalse(Env::getBoolean(self::NAME, true));
    }

    static function falsyValuesProvider() {
        return [['false'], ['FALSE'], ['0'], ['no'], ['off'], [' false ']];
    }

    function testGetBooleanFallsBackToTheDefaultOnUnparseableValues() {
        Env::init([self::NAME => 'maybe']);

        $this->assertTrue(Env::getBoolean(self::NAME, true));
        $this->assertFalse(Env::getBoolean(self::NAME, false));
    }

    function testGetBooleanReadsTheEnvironment() {
        putenv(self::NAME . '=false');

        $this->assertFalse(Env::getBoolean(self::NAME, true));
    }

    // getInteger

    function testGetIntegerReturnsTheDefaultWhenUnset() {
        $this->assertEquals(200, Env::getInteger(self::NAME, 200));
    }

    function testGetIntegerAcceptsStringsAndNatives() {
        Env::init([self::NAME => '50']);
        $this->assertEquals(50, Env::getInteger(self::NAME, 200));

        Env::init([self::NAME => 50]);
        $this->assertEquals(50, Env::getInteger(self::NAME, 200));

        Env::init([self::NAME => ' -1 ']);
        $this->assertEquals(-1, Env::getInteger(self::NAME, 200));
    }

    function testGetIntegerFallsBackToTheDefaultOnUnparseableValues() {
        Env::init([self::NAME => '12abc']);

        $this->assertEquals(200, Env::getInteger(self::NAME, 200));
    }
}
