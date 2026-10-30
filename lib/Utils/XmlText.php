<?php

namespace ESN\Utils;

/**
 * Helpers for text that ends up in XML 1.0 documents, which forbid most C0 control characters.
 */
class XmlText {

    const ILLEGAL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    static function stripIllegalCharacters($text) {
        return is_string($text) ? preg_replace(self::ILLEGAL_CHARACTERS, '', $text) : $text;
    }

    static function containsIllegalCharacters($text) {
        return is_string($text) && preg_match(self::ILLEGAL_CHARACTERS, $text) === 1;
    }
}
