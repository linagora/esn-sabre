<?php

namespace ESN\Utils;

class Utils {

    static function firstEmailAddress($user) {
        if (array_key_exists('accounts', $user)) {
            foreach ($user['accounts'] as $account) {
                if ($account['type'] === 'email') {
                    return $account['emails'][0];
                }
            }
        }

        return null;
    }

    static function calendarPathFromUri($principal, $calendarUri) {
        $uriExploded = explode('/', $principal);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    static function objectPathFromUri($principal, $calendarUri, $objectUri) {
        $uriExploded = explode('/', $principal);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri . '/' . $objectUri;
    }

}