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

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

namespace local_intellidata\repositories\tracking;

use local_intellidata\helpers\DebugHelper;
use local_intellidata\helpers\PageParamsHelper;
use local_intellidata\helpers\SettingsHelper;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class tracking_repository {

    /**
     * Type live.
     */
    const TYPE_LIVE = 0;
    /**
     * Type cache,
     */
    const TYPE_CACHE = 1;
    /**
     * Type file.
     */
    const TYPE_FILE = 2;

    /**
     * Create tracking record.
     *
     * @param $pageparams
     * @param false $ajaxrequest
     * @throws \dml_exception
     */
    public static function create_record($pageparams, $ajaxrequest = false) {
        global $USER;
        $compresstracking = SettingsHelper::get_setting('compresstracking');

        $data = new \stdClass();
        $data->userid       = !empty($pageparams['user']) ? $pageparams['user'] : $USER->id;
        $data->courseid     = PageParamsHelper::get_courseid($pageparams);
        $data->page         = $pageparams['page'];
        $data->param        = $pageparams['param'];
        $data->timespend    = $pageparams['time'];
        // get_user_agent_string() returns false when the request carried no
        // User-Agent header, and the DML layer casts a bool to int - so without
        // the fallback this char column would store the string '0' rather than
        // an empty value.
        $data->useragent    = !empty($pageparams['useragent']) ? $pageparams['useragent']
            : (\core_useragent::get_user_agent_string() ?: '');

        // Core's getremoteaddr() replaces a hand-rolled X-Forwarded-For /
        // HTTP_CLIENT_IP / REMOTE_ADDR chain. Both of those headers are
        // client-supplied and were trusted unconditionally, so every address
        // recorded here and exported to the analytics service was spoofable by
        // sending one request header. getremoteaddr() honours
        // $CFG->getremoteaddrconf, which lets the operator decide which proxy
        // headers are trustworthy for their own topology.
        $ip = getremoteaddr();
        $data->ip = ($ip) ? $ip : 0;

        try {
            $storage = self::get_storage($compresstracking, $ajaxrequest);
            $storage->save_data($data);
        } catch (\Exception $e) {
            DebugHelper::error_log($e->getMessage());
        }
    }

    /**
     * Export record to storage.
     *
     * @throws \dml_exception
     */
    public static function export_records() {
        $compresstracking = SettingsHelper::get_setting('compresstracking');

        try {
            $storage = self::get_storage($compresstracking);
            $storage->export_data();
        } catch (\Exception $e) {
            DebugHelper::error_log($e->getMessage());
        }
    }

    /**
     * Get storage for tracking.
     *
     * @param $compresstracking
     * @param false $ajaxrequest
     * @return cache_storage_repository|file_storage_repository|live_storage_repository|void
     */
    public static function get_storage($compresstracking, $ajaxrequest = false) {
        switch ($compresstracking){
            case self::TYPE_FILE:
                return new file_storage_repository($ajaxrequest);
            case self::TYPE_LIVE:
                return new live_storage_repository($ajaxrequest);
            case self::TYPE_CACHE:
            default:
                return new cache_storage_repository($ajaxrequest);
        }
    }
}
