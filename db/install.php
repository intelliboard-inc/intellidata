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
 * @package    local_intellidata
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

use local_intellidata\services\config_service;
use local_intellidata\services\datatypes_service;

/**
 * Local IntelliData plugin installation script.
 *
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 */
function xmldb_local_intellidata_install() {

    // Set exportformat for csv.
    set_config('exportdataformat', 'csv', 'local_intellidata');

    // Setup config in database.
    $configservice = new config_service(datatypes_service::get_all_datatypes());
    $configservice->setup_config();

    // Enable required native datatypes.
    datatypes_service::enable_required_native_datatypes();

    // No outbound call at install time.
    //
    // This used to POST the installing administrator's email address, the site
    // URL, language, database engine, Moodle and plugin versions, the visible
    // course count and the non-deleted user count to a hardcoded vendor
    // endpoint (/api/prospects/moodle/). There was no consent prompt, no
    // opt-out setting and no privacy notice, and it happened during install -
    // before an administrator has had any opportunity to configure the plugin,
    // so consent could not have been asked for even in principle. Nothing in
    // the product depended on it: pairing is done with the API key and
    // identifier the administrator enters, and the remote service can read
    // site information through the authenticated API once paired.
}
