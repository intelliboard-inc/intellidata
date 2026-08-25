<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_intellidata\api;

use local_intellidata\helpers\DeprecatedHelper;
use local_intellidata\services\encryption_service;

/**
 * IntelliData Api lib.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apilib {

    /**
     * Status success.
     */
    const STATUS_SUCCESS = 'success';
    /**
     * Status error.
     */
    const STATUS_ERROR = 'error';

    /**
     * Clock skew tolerated when checking the auth header's expiry, in seconds.
     *
     * Both bounds below are relative to this site's clock while the header was
     * stamped against the issuer's, so any difference between the two shows up
     * as apparent skew. Self-hosted sites are not reliably running NTP, and a
     * site whose clock is a few minutes behind the issuer must not fail closed
     * on every call. Against a 24 hour window this margin costs nothing.
     */
    const AUTH_CLOCK_SKEW = 600;

    /**
     * Longest expiry an inbound auth header may claim, in seconds.
     *
     * This is deliberately not encryption_service::AUTH_LIFE_TIME. That
     * constant is the lifetime this plugin stamps on its own outbound headers;
     * inbound headers are minted by the analytics service, which uses a
     * 24 hour window. Verifying against the outbound constant would reject
     * every authenticated request the service sends.
     *
     * The bound exists to stop a captured header from staying replayable
     * forever, so it has to be at least as long as the issuer's window;
     * shortening it is a coordinated change on the service side first.
     */
    const AUTH_MAX_LIFE_TIME = DAYSECS;

    /**
     * Return the request's IntelliData auth header, or an empty string.
     *
     * @return string
     */
    public static function get_auth_header() {
        $headers = DeprecatedHelper::getallheaders();

        foreach (['Auth', 'auth', 'Authorization', 'authorization'] as $name) {
            if (!empty($headers[$name])) {
                return $headers[$name];
            }
        }

        return '';
    }

    /**
     * Is an IntelliData auth header present on this request?
     *
     * @return bool
     */
    public static function has_auth_header() {
        return self::get_auth_header() !== '';
    }

    /**
     * Validate the request's auth header.
     *
     * @return bool
     * @throws \moodle_exception
     * @throws \dml_exception
     */
    public static function check_auth() {

        $encryptionservice = new encryption_service();
        $authheader = self::get_auth_header();

        // These were thrown with an English sentence in the $errorcode
        // position, so Moodle rendered "[[Auth header required]]" instead of the
        // message.
        if ($authheader === '') {
            throw new \moodle_exception('error_authheaderrequired', 'local_intellidata');
        }

        $authheader = json_decode($encryptionservice->decrypt($authheader), true);

        if (!is_array($authheader)) {
            throw new \moodle_exception('error_invalidencryptioncredentials', 'local_intellidata');
        }

        $identifier = !empty($authheader['clientidentifier']) ? $authheader['clientidentifier'] : '';

        if (!$identifier) {
            throw new \moodle_exception('error_invalidencryptioncredentials', 'local_intellidata');
        }

        $exp = !empty($authheader['exp']) ? (int)$authheader['exp'] : 0;
        $now = time();

        // The exp claim was only checked against a lower bound, so it was
        // unbounded above: a header minted with exp far in the future stayed
        // replayable indefinitely. Bound it on both sides, against the
        // issuer's window rather than this plugin's own outbound lifetime.
        $maxexp = $now + self::AUTH_MAX_LIFE_TIME + self::AUTH_CLOCK_SKEW;

        // Tolerance is applied to both bounds, so a clock difference in either
        // direction is forgiven by the same margin.
        if ($exp < $now - self::AUTH_CLOCK_SKEW || $exp > $maxexp) {
            throw new \moodle_exception('error_authenticationexpired', 'local_intellidata');
        }

        if (!hash_equals((string)$encryptionservice->clientidentifier, (string)$identifier)) {
            throw new \moodle_exception('error_invalidauthentication', 'local_intellidata');
        }

        return true;
    }

    /**
     * Validate API params.
     *
     * @param $params
     * @param array $rules
     * @return mixed
     * @throws \coding_exception
     */
    public static function validate_parameters($params, $rules = []) {

        $encryptionservice = new encryption_service();
        $params = self::clean_parameters(
            json_decode($encryptionservice->decrypt($params), true),
            $rules
        );

        return $params;
    }

    /**
     * Clean API params.
     *
     * @param $params
     * @param array $rules
     * @return mixed
     * @throws \coding_exception
     */
    public static function clean_parameters($params, $rules = []) {

        if (count($rules)) {
            foreach ($rules as $name => $type) {
                if (isset($params[$name])) {
                    $params[$name] = (is_array($params[$name]))
                        ? clean_param_array($params[$name], $type)
                        : clean_param($params[$name], $type);
                }
            }
        }

        return $params;
    }

}
