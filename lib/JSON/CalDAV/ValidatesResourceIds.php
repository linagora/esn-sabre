<?php

namespace ESN\JSON\CalDAV;

/**
 * Trait for validating resource IDs to prevent path injection attacks
 */
trait ValidatesResourceIds {
    /**
     * Validates that a resource ID is safe to use in paths
     *
     * Rejects IDs containing:
     * - Forward slashes (/)
     * - Backslashes (\)
     * - Dot-segments (..)
     * - Percent-encoded slashes (%2F, %2f, %5C, %5c)
     *
     * @param string|null $id The ID to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidResourceId($id) {
        // ID must be non-empty
        if (!isset($id) || !$id) {
            return false;
        }

        // Reject path injection characters
        if (strpos($id, '/') !== false ||
            strpos($id, '\\') !== false ||
            strpos($id, '..') !== false ||
            stripos($id, '%2f') !== false ||
            stripos($id, '%5c') !== false) {
            return false;
        }

        return true;
    }
}
