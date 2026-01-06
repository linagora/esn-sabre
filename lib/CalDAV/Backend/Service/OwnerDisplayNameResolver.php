<?php

namespace ESN\CalDAV\Backend\Service;

/**
 * Owner Display Name Resolver
 *
 * Service to resolve and format owner display names for shared/subscribed calendars.
 * Used to append owner information to calendar names at creation time.
 */
class OwnerDisplayNameResolver {
    private $principalBackend;

    public function __construct($principalBackend) {
        $this->principalBackend = $principalBackend;
    }

    /**
     * Extract owner principal URI from calendar source path
     *
     * @param string $sourcePath e.g., "calendars/54b64eadf6d7d8e41d263e0e/publicCal1"
     * @return string|null e.g., "principals/users/54b64eadf6d7d8e41d263e0e"
     */
    public function extractOwnerPrincipalFromSource($sourcePath) {
        // Remove leading slash if present
        $sourcePath = ltrim($sourcePath, '/');

        // Parse: calendars/{userId}/{calendarUri}
        $parts = explode('/', $sourcePath);
        if (count($parts) >= 2 && $parts[0] === 'calendars') {
            return 'principals/users/' . $parts[1];
        }

        return null;
    }

    /**
     * Get owner principal URI from calendar instances
     *
     * @param array $calendarInstances Array of calendar instance documents from CalendarInstanceDAO
     * @return string|null Owner principal URI
     */
    public function getOwnerPrincipalFromInstances($calendarInstances) {
        foreach ($calendarInstances as $instance) {
            if (isset($instance['access']) && (int)$instance['access'] === \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER) {
                return $instance['principaluri'];
            }
        }

        return null;
    }

    /**
     * Get display name for a principal
     *
     * @param string $principalUri e.g., "principals/users/54b64eadf6d7d8e41d263e0e"
     * @return string|null Display name or email address, null if not found
     */
    public function getDisplayName($principalUri) {
        if (!$principalUri) {
            return null;
        }

        $principal = $this->principalBackend->getPrincipalByPath($principalUri);
        if (!$principal) {
            return null;
        }

        // Try displayname first
        if (!empty($principal['{DAV:}displayname'])) {
            return trim($principal['{DAV:}displayname']);
        }

        // Fallback to email address
        if (!empty($principal['{http://sabredav.org/ns}email-address'])) {
            return $principal['{http://sabredav.org/ns}email-address'];
        }

        return null;
    }

    /**
     * Append owner name to calendar displayname
     *
     * @param string|null $displayname Current displayname
     * @param string $ownerDisplayName Owner's display name
     * @return string Modified displayname with owner name in brackets
     */
    public function appendOwnerName($displayname, $ownerDisplayName) {
        if (!$ownerDisplayName) {
            return $displayname ?: 'Calendar';
        }

        $baseName = $displayname ?: 'Calendar';
        return $baseName . ' (' . $ownerDisplayName . ')';
    }
}
