<?php

namespace ESN\Utils;

/**
 * Central accessor for the runtime settings that used to be read straight from
 * the process environment.
 *
 * Every setting is resolved in this order:
 *
 *   1. the "environment" section of config.json (see Env::init)
 *   2. the process environment, for deployments that still export the
 *      variables without regenerating config.json
 *   3. the default provided by the caller
 *
 * Null values and empty strings are treated as "not set", so a blank entry in
 * config.json falls through to the environment instead of shadowing it. That
 * also means a setting cannot be forced to the empty string, which matches the
 * previous getenv() based behaviour.
 *
 * Values may be given either as JSON natives (true, 42) or as strings
 * ("true", "42"), since config.json is hand editable while the environment can
 * only ever carry strings.
 */
#[\AllowDynamicProperties]
class Env {

    /**
     * The "environment" section of config.json, keyed by variable name.
     */
    private static $config = [];

    /**
     * Seed the resolver with the "environment" section of config.json.
     *
     * Safe to call with null or a partial section; anything missing falls back
     * to the process environment and then to the caller's default.
     */
    static function init($environmentConfig) {
        self::$config = is_array($environmentConfig) ? $environmentConfig : [];
    }

    /**
     * Drop the config.json section, leaving only the process environment.
     *
     * Mainly useful to isolate tests from each other.
     */
    static function reset() {
        self::$config = [];
    }

    /**
     * The raw value of a setting, or null when it is set nowhere.
     *
     * @return mixed
     */
    static function get($name) {
        if (array_key_exists($name, self::$config)) {
            $value = self::$config[$name];

            // Non scalars can only come from a malformed config.json; ignoring
            // them falls back to the default, which is the safe value for
            // every setting, rather than blowing up the whole server.
            if (is_scalar($value) && $value !== '') {
                return $value;
            }
        }

        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return string|null the setting as a string, or $default when unset
     */
    static function getString($name, $default = null) {
        $value = self::get($name);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return trim((string) $value);
    }

    /**
     * The setting as a boolean.
     *
     * Accepts the usual "1/true/yes/on" and "0/false/no/off" spellings, in any
     * case. Unset, empty and unparseable values all yield $default, so a
     * feature flag defaulting to true can only be turned off by an explicit
     * false-ish value.
     *
     * @return bool
     */
    static function getBoolean($name, $default) {
        $value = self::get($name);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * The setting as an integer. Unparseable values yield $default.
     *
     * @return int
     */
    static function getInteger($name, $default) {
        $value = self::get($name);

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        $value = trim((string) $value);

        return is_numeric($value) ? (int) $value : $default;
    }
}
