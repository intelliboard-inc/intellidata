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
 * Extra library for intellidata plugin.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2023
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_intellidata\api\apilib;
use local_intellidata\helpers\DBHelper;
use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\TrackingHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\services\encryption_service;

/**
 * Return pluginfile URL.
 *
 * @param $course
 * @param $cm
 * @param $context
 * @param $filearea
 * @param $args
 * @param $forcedownload
 * @param array $options
 * @return false|void
 * @throws coding_exception
 * @throws moodle_exception
 * @throws require_login_exception
 * @throws required_capability_exception
 */
function local_intellidata_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG, $PAGE;
    require_once($CFG->dirroot . '/repository/lib.php');

    // Clean any output buffers at the start to prevent corruption
    // This prevents "Unsupported redirect detected" errors in ZIP files
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Get current URL
    $currenturl = $PAGE->url->out(false);

    // Additional auth validation.
    // Check if this is API call (webservice) or regular web access
    $isapi = stristr($currenturl, '/webservice/pluginfile.php') ||
             stristr($currenturl, 'webservice') ||
             (!empty($_SERVER['HTTP_AUTH']) || !empty($_SERVER['HTTP_AUTHORIZATION']));

    if ($isapi) {
        try {
            apilib::check_auth();
        } catch (\moodle_exception $e) {
            // Ensure no output before sending error
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            send_file_not_found();
            return;
        }
    } else {
        require_login();
        require_capability('local/intellidata:viewlogs', $context);
    }

    $itemid = array_shift($args);
    $filename = array_shift($args);
    $filepath = '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_intellidata', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        // Ensure no output before returning
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        return false; // The file does not exist.
    }

    // Verify file is complete and readable before sending
    if ($file->get_filesize() == 0) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        send_file_not_found();
        return;
    }

    if ($isapi) {
        send_stored_file($file, 86400, 0, $forcedownload, $options);
    } else {
        $encryptionservice = new encryption_service();
        $enczipfile = $file->copy_content_to_temp();

        // Prepare temp area.
        $tempfolder = make_temp_directory('local_intellidata');
        $tempfile = $tempfolder . '/' . $file->get_filename();

        $encryptionservice->decrypt_file($enczipfile, $tempfile);

        // Rename file to human friendly.
        $zip = new ZipArchive;
        $zip->open($tempfile);
        $zip->renameIndex( 0, $filearea . '.csv');
        $zip->close();

        // Ensure clean output buffer before sending file
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        send_file($tempfile, $filearea . '_' . $filename, 86400, 0, false, $forcedownload, '', true, $options);
        unlink($enczipfile);
        unlink($tempfile);
        die();
    }
}

/**
 * Add IntelliData LTI menu to the navigation.
 *
 * @param global_navigation $nav
 * @throws dml_exception
 */
function local_intellidata_extend_navigation(global_navigation $nav) {
    (new \local_intellidata\helpers\CustomMenuHelper())->setup($nav);
    local_intellidata_tracking_init();
}

/**
 * Return custom sidebar icon.
 *
 * @return string[]
 */
function local_intellidata_get_fontawesome_icon_map() {
    return [
        'local_intellidata:i/area_chart' => 'fa-area-chart',
    ];
}

/**
 * Init IntelliBoard tracking.
 *
 * @throws dml_exception
 */
function local_intellidata_tracking_init() {
    if (TrackingHelper::tracking_enabled()) {
        $tracking = new \local_intellidata\services\tracking_service();
        $tracking->track();
    }
}

if (!ParamsHelper::compare_release('4.4.999')) {
    /**
     * Allow plugins to callback as soon possible after setup.php is loaded.
     *
     * @return void
     * @throws dml_exception
     */
    function local_intellidata_after_config() {
        global $DB;

        if (TrackingHelper::new_tracking_enabled()) {
            $DB = DBHelper::get_db_client(DBHelper::PENETRATION_TYPE_EXTERNAL);
        }
    }
}

