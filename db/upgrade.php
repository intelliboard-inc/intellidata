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
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

use local_intellidata\services\config_service;
use local_intellidata\services\datatypes_service;
use local_intellidata\services\export_service;
use local_intellidata\repositories\export_log_repository;
use local_intellidata\persistent\datatypeconfig;
use local_intellidata\persistent\export_logs;
use local_intellidata\task\export_adhoc_task;
use local_intellidata\helpers\DebugHelper;
use local_intellidata\helpers\DBHelper;
use local_intellidata\helpers\DatatypesHelper;

/**
 * Local IntelliData plugin integration upgrade.
 *
 * @param $oldversion
 * @return true
 * @throws coding_exception
 * @throws ddl_change_structure_exception
 * @throws ddl_exception
 * @throws ddl_table_missing_exception
 * @throws dml_exception
 * @throws downgrade_exception
 * @throws upgrade_exception
 */
function xmldb_local_intellidata_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2020091002) {
        $table = new xmldb_table('local_intellidata_export_log');

        // Adding fields to table local_intellidata_export_log.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('last_exported_time', XMLDB_TYPE_INTEGER, '11', null, null, null);
        $table->add_field('last_exported_id', XMLDB_TYPE_INTEGER, '11', null, null, null);

        // Adding keys to table local_intellidata_export_log.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_export_log.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2020091002, 'local', 'intellidata');
    }

    if ($oldversion < 2020091400) {
        $table = new xmldb_table('local_intellidata_logs');

        // Adding fields to table local_intellidata_export_log.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('details', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_logs.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_logs.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2020091400, 'local', 'intellidata');
    }

    if ($oldversion < 2020100700) {
        $table = new xmldb_table('local_intellidata_export_log');
        $field = new xmldb_field('migrated', XMLDB_TYPE_INTEGER, '1', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2020100700, 'local', 'intellidata');
    }

    if ($oldversion < 2020102300) {
        $table = new xmldb_table('local_intellidata_storage');

        // Adding fields to table local_intellidata_storage.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_storage.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_storage.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2020102300, 'local', 'intellidata');
    }

    if ($oldversion < 2021020400) {

        // Define table local_intellidata_tracked_bb to be delete.
        $table = new xmldb_table('local_intellidata_tracked_bb');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Intellidata savepoint reached.
        upgrade_plugin_savepoint(true, 2021020400, 'local', 'intellidata');
    }

    if ($oldversion < 2021020409) {
        $table = new xmldb_table('local_intellidata_export_log');
        $field = new xmldb_field('timestart', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('recordscount', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2021020409, 'local', 'intellidata');
    }

    if ($oldversion < 2021041303) {

        // Define table local_intellidata_export_ids to be created.
        $table = new xmldb_table('local_intellidata_export_ids');

        // Adding fields to table local_intellidata_export_ids.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('ids', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_export_ids.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_export_ids.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Intellidata savepoint reached.
        upgrade_plugin_savepoint(true, 2021041303, 'local', 'intellidata');
    }

    if ($oldversion < 2021043001) {
        $table = new xmldb_table('local_intellidata_export_log');
        $field = new xmldb_field('timestart', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('recordscount', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2021043001, 'local', 'intellidata');
    }

    if ($oldversion < 2021111010) {
        $table = new xmldb_table('local_intellidata_tracking');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('page', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('param', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timespend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('firstaccess', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastaccess', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('useragent', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('ip', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Conditionally launch create table for local_intelliboard_tracking.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Add index to local_intellidata_tracking.
        $table = new xmldb_table('local_intellidata_tracking');
        $index = new xmldb_index('userid_page_param_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'page', 'param']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('local_intellidata_trlogs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('trackid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timespend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timepoint', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('trackid', XMLDB_KEY_FOREIGN, ['trackid'], 'local_intellidata_tracking', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Add index to local_intellidata_trlogs.
        $table = new xmldb_table('local_intellidata_trlogs');
        $index = new xmldb_index('trackid_timepoint_idx', XMLDB_INDEX_NOTUNIQUE, ['trackid', 'timepoint']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('local_intellidata_trdetails');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('logid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timespend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timepoint', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('logid', XMLDB_KEY_FOREIGN, ['logid'], 'local_intellidata_trlogs', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Add index to local_intelliboard_details.
        $table = new xmldb_table('local_intellidata_trdetails');
        $index = new xmldb_index('logid_timepoint_idx', XMLDB_INDEX_NOTUNIQUE, ['logid', 'timepoint']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2021111010, 'local', 'intellidata');
    }

    if ($oldversion < 2021111900) {
        // Define table local_intellidata_export_ids to be created.
        $table = new xmldb_table('local_intellidata_export_ids');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Adding fields to table local_intellidata_export_ids.
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('dataid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_export_ids.
        $table->add_key('datatype_dataid_unique', XMLDB_KEY_UNIQUE, ['datatype', 'dataid']);

        // Conditionally launch create table for local_intellidata_export_ids.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Intellidata savepoint reached.
        upgrade_plugin_savepoint(true, 2021111900, 'local', 'intellidata');
    }

    if ($oldversion < 2022011800) {
        $items = $DB->get_records_sql("SELECT t.id
                                         FROM {local_intellidata_tracking} t
                                    LEFT JOIN {user} u ON u.id=t.userid
                                    LEFT JOIN {course} c ON c.id=t.courseid
                                    LEFT JOIN {course_modules} cm ON cm.id=t.param AND t.page='module'
                                        WHERE u.id IS NULL OR c.id IS NULL OR cm.id IS NULL");

        foreach ($items as $item) {
            $logs = $DB->get_records("local_intellidata_trlogs", ['trackid' => $item->id]);

            foreach ($logs as $log) {
                $DB->delete_records('local_intellidata_trdetails', [
                    'logid' => $log->id,
                ]);
            }
            $DB->delete_records('local_intellidata_trlogs', [
                'trackid' => $item->id,
            ]);
            $DB->delete_records('local_intellidata_tracking', [
                'id' => $item->id,
            ]);
        }

        // Intellidata savepoint reached.
        upgrade_plugin_savepoint(true, 2022011800, 'local', 'intellidata');
    }

    if ($oldversion < 2022020305) {

        // Define table local_intellidata_reports to be created.
        $table = new xmldb_table('local_intellidata_reports');

        // Adding fields to table local_intellidata_reports.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sqlcode', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('service', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('external_identifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_intellidata_reports.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_reports.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Intellidata savepoint reached.
        upgrade_plugin_savepoint(true, 2022020305, 'local', 'intellidata');
    }

    if ($oldversion < 2022032200) {
        $table = new xmldb_table('local_intellidata_export_log');
        $field = new xmldb_field('tabletype', XMLDB_TYPE_INTEGER, '1', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2022032200, 'local', 'intellidata');
    }

    if ($oldversion < 2022032400) {
        $table = new xmldb_table('local_intellidata_config');

        // Adding fields to table local_intellidata_config.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tabletype', XMLDB_TYPE_INTEGER, '1', null, null, null, 1);
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, null, null, 1);
        $table->add_field('timemodified_field', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('rewritable', XMLDB_TYPE_INTEGER, '1', null, null, null, 0);
        $table->add_field('events_tracking', XMLDB_TYPE_INTEGER, '1', null, null, null, 1);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_config.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_config.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2022032400, 'local', 'intellidata');
    }

    if ($oldversion < 2022032800) {
        $table = new xmldb_table('local_intellidata_config');
        $field = new xmldb_field('filterbyid', XMLDB_TYPE_INTEGER, '1', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2022032800, 'local', 'intellidata');
    }

    if ($oldversion < 2022033000) {
        // Setup config in database.
        $configservice = new config_service(datatypes_service::get_all_datatypes());
        $configservice->setup_config();

        upgrade_plugin_savepoint(true, 2022033000, 'local', 'intellidata');
    }

    if ($oldversion < 2022050300) {
        // Define table local_intellidata_export_ids to be created.
        $table = new xmldb_table('local_intellidata_export_ids');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Adding fields to table local_intellidata_export_ids.
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('dataid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_export_ids.
        $table->add_key('datatype_dataid_unique', XMLDB_KEY_UNIQUE, ['datatype', 'dataid']);

        // Conditionally launch create table for local_intellidata_export_ids.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Add column to track migration progress.
        $table = new xmldb_table('local_intellidata_export_log');
        $field = new xmldb_field('recordsmigrated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2022050300, 'local', 'intellidata');
    }

    if ($oldversion < 2022051902) {

        // Setup config in database.
        $configservice = new config_service(datatypes_service::get_all_datatypes());
        $configservice->setup_config();

        // Add new datatypes to export ad-hoc task..
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => ['userinfocategories', 'userinfofields', 'userinfodatas'],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2022051902, 'local', 'intellidata');
    }

    if ($oldversion < 2022052500) {

        // Set exportdataformat to the csv.
        set_config('exportdataformat', 'csv', 'local_intellidata');

        upgrade_plugin_savepoint(true, 2022052500, 'local', 'intellidata');
    }

    if ($oldversion < 2022053100) {
        // Add primary key, $dbman->add_key not working, error 'Primary Keys can be added at table create time only'.
        $DB->execute('ALTER TABLE {local_intellidata_export_ids}
                           ADD CONSTRAINT {local_intellidata_export_ids_primary}
                           PRIMARY KEY (datatype, dataid)');
        upgrade_plugin_savepoint(true, 2022053100, 'local', 'intellidata');
    }

    if ($oldversion < 2022053105) {

        // Update local_intellidata_export_log table.
        $table = new xmldb_table("local_intellidata_export_log");
        $field = new xmldb_field('recordsmigrated', XMLDB_TYPE_INTEGER, '11', null, false, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timestart', XMLDB_TYPE_INTEGER, '11', null, false, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('migrated', XMLDB_TYPE_INTEGER, '1', null, false, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('recordsmigrated', XMLDB_TYPE_INTEGER, '11', null, false, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('recordscount', XMLDB_TYPE_INTEGER, '11', null, false, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        // Update local_intellidata_storage table.
        $table = new xmldb_table("local_intellidata_storage");
        $field = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, false, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        // Update local_intellidata_tracking table.
        $table = new xmldb_table("local_intellidata_tracking");

        // Remove index from local_intellidata_tracking table.
        $index = new xmldb_index('userid_page_param_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'page', 'param']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        // Remove key from local_intellidata_tracking table.
        $key = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $dbman->drop_key($table, $key);

        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('page', XMLDB_TYPE_CHAR, '100', null, false, null, '');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('param', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timespend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('firstaccess', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('lastaccess', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        // Add key and index for local_intellidata_tracking table.
        $dbman->add_key($table, $key);
        $dbman->add_index($table, $index);

        // Update local_intellidata_trlogs table.
        $table = new xmldb_table("local_intellidata_trlogs");

        // Remove key from local_intellidata_trlogs table.
        $key = new xmldb_key('trackid', XMLDB_KEY_FOREIGN, ['trackid'], 'local_intellidata_tracking', ['id']);
        $dbman->drop_key($table, $key);

        // Remove index from local_intellidata_trlogs table.
        $index = new xmldb_index('trackid_timepoint_idx', XMLDB_INDEX_NOTUNIQUE, ['trackid', 'timepoint']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $field = new xmldb_field('trackid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timespend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timepoint', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        // Add key and index for local_intellidata_trlogs table.
        $dbman->add_key($table, $key);
        $dbman->add_index($table, $index);

        // Update local_intellidata_trdetails table.
        $table = new xmldb_table("local_intellidata_trdetails");

        // Remove key from local_intellidata_trdetails table.
        $key = new xmldb_key('logid', XMLDB_KEY_FOREIGN, ['logid'], 'local_intellidata_trlogs', ['id']);
        $dbman->drop_key($table, $key);

        // Remove index from local_intellidata_trdetails table.
        $index = new xmldb_index('logid_timepoint_idx', XMLDB_INDEX_NOTUNIQUE, ['logid', 'timepoint']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        $field = new xmldb_field('logid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('visits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timespend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $field = new xmldb_field('timepoint', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        try {
            $dbman->change_field_type($table, $field);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        // Add key and index for local_intellidata_trdetails table.
        $dbman->add_key($table, $key);
        $dbman->add_index($table, $index);

        upgrade_plugin_savepoint(true, 2022053105, 'local', 'intellidata');
    }

    if ($oldversion < 2022053107) {

        $table = new xmldb_table("local_intellidata_export_ids");

        try {
            $key = new xmldb_key('datatype_dataid_unique', XMLDB_KEY_UNIQUE, ['datatype', 'dataid']);
            // Remove key from local_intellidata_export_ids table.
            $dbman->drop_key($table, $key);

            $field = new xmldb_field('datatype', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $dbman->change_field_type($table, $field);

            $field = new xmldb_field('dataid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $dbman->change_field_type($table, $field);

            // Add key and index for local_intellidata_export_ids table.
            $dbman->add_key($table, $key);
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        // Adding keys to table local_intellidata_config.
        $table = new xmldb_table("local_intellidata_config");
        $key = new xmldb_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2022053107, 'local', 'intellidata');
    }

    if ($oldversion < 2022062800) {
        $table = new xmldb_table('local_intellidata_config');
        $field = new xmldb_field('params', XMLDB_TYPE_TEXT, null, null, false, null, null);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2022062800, 'local', 'intellidata');
    }

    // Remove CURF from Optional tables, as it moved to Required tables.
    if ($oldversion < 2022063000) {

        $exportlogrepository = new export_log_repository();
        $exportservice = new export_service();

        $datatypestodelete = [
            'user_info_category',
            'user_info_data',
            'user_info_field',
        ];

        foreach ($datatypestodelete as $datatype) {

            // Delete old export files.
            $exportservice->delete_files([
                'datatype' => $datatype,
                'timemodified' => time(),
            ]);

            // Delete export logs.
            if ($exportlogrepository->get_datatype_export_log($datatype)) {
                $exportlogrepository->remove_datatype($datatype);
            }

            // Delete configuration.
            if ($conf = datatypeconfig::get_record(['datatype' => $datatype])) {
                $conf->delete();
            }
        }

        upgrade_plugin_savepoint(true, 2022063000, 'local', 'intellidata');
    }

    // Divide quizquestionanswers to few datatypes.
    if ($oldversion < 2022080401) {

        $exportlogrepository = new export_log_repository();
        $exportservice = new export_service();

        $datatype = 'quizquestionanswers';

        // Delete old export files.
        $exportservice->delete_files([
            'datatype' => $datatype,
            'timemodified' => time(),
        ]);

        // Delete export logs.
        if ($exportlogrepository->get_datatype_export_log($datatype)) {
            $exportlogrepository->remove_datatype($datatype);
        }

        // Delete configuration.
        if ($conf = datatypeconfig::get_record(['datatype' => $datatype])) {
            $conf->delete();
        }

        upgrade_plugin_savepoint(true, 2022080401, 'local', 'intellidata');
    }

    // Enable plugin by default for all existing connections.
    if ($oldversion < 2022080402) {

        set_config('ispluginsetup', 1, 'local_intellidata');

        upgrade_plugin_savepoint(true, 2022080402, 'local', 'intellidata');
    }

    if ($oldversion < 2022081000) {

        $exportlogrepository = new export_log_repository();
        $exportservice = new export_service();

        // Delete duplicated datatypes.
        $datatypestodelete = [
            'quizquestionattempt',
            'quizquestionattemptstep',
            'quizquestionattemptstepdata',
            'ltisubmissions',
        ];

        foreach ($datatypestodelete as $datatype) {
            $exportservice->delete_files([
                'datatype' => $datatype,
                'timemodified' => time(),
            ]);

            // Delete export logs.
            if ($exportlogrepository->get_datatype_export_log($datatype)) {
                $exportlogrepository->remove_datatype($datatype);
            }

            // Delete configuration.
            if ($conf = datatypeconfig::get_record(['datatype' => $datatype])) {
                $conf->delete();
            }
        }

        // Add new LTI datatype.
        $exportlogrepository = new export_log_repository();
        $datatype = 'ltisubmittion';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2022081000, 'local', 'intellidata');
    }

    // Reset and add new datatypes to the export.
    if ($oldversion < 2022081001) {

        $exportlogrepository = new export_log_repository();

        // Add new datatypes to the plugin config and export.
        $datatypes = [
            'quizquestionattempts',
            'quizquestionattemptsteps',
            'quizquestionattemptstepsdata',
        ];

        foreach ($datatypes as $datatype) {

            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2022081001, 'local', 'intellidata');
    }

    // Add new datatypes to the export.
    if ($oldversion < 2022103101) {

        // Add new LTI Types datatypes datatype.
        $exportlogrepository = new export_log_repository();
        $datatype = 'ltitypes';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2022103101, 'local', 'intellidata');
    }

    // Reset and add new survey to the export.
    if ($oldversion < 2022110400) {

        $exportlogrepository = new export_log_repository();

        // Add new datatypes to the plugin config and export.
        $datatypes = [
            'survey',
            'surveyanswers',
        ];

        foreach ($datatypes as $datatype) {
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2022110400, 'local', 'intellidata');
    }

    // Reset and add new survey to the export.
    if ($oldversion < 2022112905) {
        // Define table local_intellidata_export_ids to be created.
        $table = new xmldb_table('local_intellidata_export_ids');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Adding fields to table local_intellidata_export_ids.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('datatype', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('dataid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);

        // Adding keys to table local_intellidata_reports.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for local_intellidata_export_ids.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2022112905, 'local', 'intellidata');
    }

    // Add indexes for tracking tables.
    if ($oldversion < 2022121500) {

        $tables = [
            'local_intellidata_tracking',
            'local_intellidata_trdetails',
            'local_intellidata_trlogs',
        ];

        // Add index to tables.
        foreach ($tables as $table) {
            $table = new xmldb_table($table);
            $index = new xmldb_index('timemodified_idx', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        upgrade_plugin_savepoint(true, 2022121500, 'local', 'intellidata');
    }

    if ($oldversion < 2022121501) {
        $table = new xmldb_table('local_intellidata_config');
        $field = new xmldb_field('tableindex', XMLDB_TYPE_CHAR, '100', null, null, null, null);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2022121501, 'local', 'intellidata');
    }

    // Add new datatypes to the export.
    if ($oldversion < 2023020701) {

        // Add new LTI Types datatypes datatype.
        $exportlogrepository = new export_log_repository();
        $datatype = 'coursesections';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2023020701, 'local', 'intellidata');
    }

    if ($oldversion < 2023022801) {

        // Define table local_intellidata_bbb_rec_tr to be delete.
        $table = new xmldb_table('local_intellidata_bbb_rec_tr');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Intellidata savepoint reached.
        upgrade_plugin_savepoint(true, 2023022801, 'local', 'intellidata');
    }

    // Add new datatypes to the export.
    if ($oldversion < 2023031701) {

        $exportlogrepository = new export_log_repository();

        // Add new datatypes to the plugin config and export.
        $datatypes = [
            'coursegroups',
            'coursegroupmembers',
        ];

        foreach ($datatypes as $datatype) {
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2023031701, 'local', 'intellidata');
    }

    if ($oldversion < 2023041800) {
        // Add index to local_intellidata_tracking.
        $table = new xmldb_table('local_intellidata_tracking');
        $index = new xmldb_index('page_param_idx', XMLDB_INDEX_NOTUNIQUE, ['page', 'param']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2023041800, 'local', 'intellidata');
    }

    if ($oldversion < 2023042000) {

        $datatypes = [
            'users', 'quizattempts', 'quizquestionattempts',
        ];

        $exportlogrepository = new export_log_repository();
        foreach ($datatypes as $datatype) {
            $record = datatypeconfig::get_record(['datatype' => $datatype]);

            // Reset export logs.
            $exportlogrepository->reset_datatype($datatype);

            // Delete old export files.
            $exportservice = new export_service();
            $exportservice->delete_files([
                'datatype' => $datatype,
                'timemodified' => time(),
            ]);

            // Add task to migrate records.
            if ($record->get('tabletype') == datatypeconfig::TABLETYPE_REQUIRED) {
                $exporttask = new export_adhoc_task();
                $exporttask->set_custom_data([
                    'datatypes' => [$record->get('datatype')],
                ]);

                \core\task\manager::queue_adhoc_task($exporttask);
            }
        }

        upgrade_plugin_savepoint(true, 2023042000, 'local', 'intellidata');
    }

    if ($oldversion < 2023050404) {

        $datatypes = [
            'competency', 'competency_usercomp', 'competency_coursecomp',
            'competency_usercompcourse', 'competency_modulecomp', 'competency_plan',
            'competency_usercompplan', 'tenant', 'tool_tenant', 'tool_tenant_user',
            'roleassignments',
        ];

        $exportlogrepository = new export_log_repository();
        foreach ($datatypes as $datatype) {
            $record = datatypeconfig::get_record(['datatype' => $datatype]);

            if (!$record) {
                continue;
            }

            // Reset export logs.
            $exportlogrepository->reset_datatype($datatype);
        }

        upgrade_plugin_savepoint(true, 2023050404, 'local', 'intellidata');
    }

    if ($oldversion < 2023051500) {
        \local_intellidata\helpers\TasksHelper::init_refresh_export_progress_adhoc_task();

        upgrade_plugin_savepoint(true, 2023051500, 'local', 'intellidata');
    }

    if ($oldversion < 2023051602) {
        $table = new xmldb_table('local_intellidata_config');
        $field = new xmldb_field('deletedevent', XMLDB_TYPE_CHAR, '255', null, null, null, null);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2023051602, 'local', 'intellidata');
    }

    // Remove DB triggers.
    if ($oldversion < 2023051700) {

        $datatypes = datatypes_service::get_datatypes();
        try {
            foreach ($datatypes as $datatype) {
                if (isset($datatype['table'])) {
                    DBHelper::remove_deleted_id_triger($datatype['name'], $datatype['table']);
                }
            }

            DBHelper::remove_deleted_id_functions();
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        upgrade_plugin_savepoint(true, 2023051700, 'local', 'intellidata');
    }

    if ($oldversion < 2023060500) {

        // Delete old export files.
        $datatypes = datatypes_service::get_optional_datatypes_for_export();
        $exportservice = new export_service();
        $exportservice->delete_files(['datatypes' => $datatypes]);

        // Delete old datatype export ids.
        $DB->execute("DELETE FROM {local_intellidata_export_ids} WHERE datatype NOT IN
        (SELECT datatype FROM {local_intellidata_config} WHERE tabletype=:tabletype OR tabletype=:tabletype2)",
            [
                'tabletype' => datatypeconfig::TABLETYPE_REQUIRED,
                'tabletype2' => datatypeconfig::TABLETYPE_LOGS,
            ]);

        // Delete old datatype storage.
        $DB->execute("DELETE FROM {local_intellidata_storage} WHERE datatype NOT IN
        (SELECT datatype FROM {local_intellidata_config} WHERE tabletype=:tabletype OR tabletype=:tabletype2)",
            [
                'tabletype' => datatypeconfig::TABLETYPE_REQUIRED,
                'tabletype2' => datatypeconfig::TABLETYPE_LOGS,
            ]);

        // Delete old datatype logs.
        $DB->execute("DELETE FROM {local_intellidata_logs} WHERE datatype NOT IN
        (SELECT datatype FROM {local_intellidata_config} WHERE tabletype=:tabletype OR tabletype=:tabletype2)",
            [
                'tabletype' => datatypeconfig::TABLETYPE_REQUIRED,
                'tabletype2' => datatypeconfig::TABLETYPE_LOGS,
            ]);

        $prefix = datatypeconfig::OPTIONAL_TABLE_PREFIX;
        $DB->execute(
            "UPDATE {local_intellidata_config}
            SET datatype = CONCAT('{$prefix}', datatype)
            WHERE tabletype = :tabletype AND  datatype NOT LIKE '" . $prefix . "%'",
            [
                'tabletype' => datatypeconfig::TABLETYPE_OPTIONAL,
            ]
        );

        $DB->execute(
            "UPDATE {local_intellidata_export_log}
            SET datatype = CONCAT('{$prefix}', datatype),
                last_exported_time=0,
                last_exported_id=0,
                migrated=0,
                timestart=0,
                recordsmigrated=0,
                recordscount=0
            WHERE tabletype = :tabletype AND  datatype NOT LIKE '" . $prefix . "%'",
            [
                'tabletype' => datatypeconfig::TABLETYPE_OPTIONAL,
            ]
        );

        // Delete duplicates config datatypes.
        $ids = $DB->get_records_sql('SELECT max(id)
                                           FROM {local_intellidata_config}
                                       GROUP BY datatype
                                         HAVING COUNT(*) > 1');
        $ids = array_keys($ids);
        if ($ids) {
            $DB->execute("DELETE FROM {local_intellidata_config}
                            WHERE id IN (" . implode(',', $ids) . ")");
        }

        // Delete triggers if exists.
        $datatypes = datatypes_service::get_datatypes();
        try {
            foreach ($datatypes as $datatype) {
                if (isset($datatype['table'])) {
                    $datatypename = datatypes_service::get_optional_table($datatype['name']);
                    DBHelper::remove_deleted_id_triger($datatypename, $datatype['table']);
                }
            }

            DBHelper::remove_deleted_id_functions();
        } catch (moodle_exception $e) {
            DebugHelper::error_log($e->getMessage());
        }

        $configservice = new config_service(datatypes_service::get_all_optional_datatypes());
        $configservice->setup_config();

        upgrade_plugin_savepoint(true, 2023060500, 'local', 'intellidata');
    }

    if ($oldversion < 2023070500) {

        // Delete excluded tables.
        DatatypesHelper::delete_excluded_tables();

        upgrade_plugin_savepoint(true, 2023070500, 'local', 'intellidata');
    }

    if ($oldversion < 2023070503) {

        $datatypes = [
            'question_categories',
        ];

        $exportlogrepository = new export_log_repository();
        foreach ($datatypes as $datatype) {
            $datatype = datatypes_service::generate_optional_datatype($datatype);
            $record = datatypeconfig::get_record(['datatype' => $datatype]);

            if (!$record) {
                continue;
            }

            $configservice = new config_service([$datatype => datatypes_service::get_datatype($datatype)]);
            $configservice->setup_config();

            // Reset export logs.
            $exportlogrepository->reset_datatype($datatype);
        }

        upgrade_plugin_savepoint(true, 2023070503, 'local', 'intellidata');
    }

    if ($oldversion < 2023071901) {

        $exportlogrepository = new export_log_repository();
        $datatype = 'coursesections';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2023071901, 'local', 'intellidata');
    }

    if ($oldversion < 2023101200) {

        $exportlogrepository = new export_log_repository();
        $datatype = 'userinfofields';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2023101200, 'local', 'intellidata');
    }

    if ($oldversion < 2024011800) {
        $table = new xmldb_table('local_intellidata_export_log');
        $field = new xmldb_field('count_in_files', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_intellidata_logs');
        $field = new xmldb_field('count_in_file', XMLDB_TYPE_INTEGER, '11', null, null, null, 0);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024011800, 'local', 'intellidata');
    }

    if ($oldversion < 2024011801) {

        $exportlogrepository = new export_log_repository();
        $datatype = 'activities';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2024011801, 'local', 'intellidata');
    }

    // Reset/export "quizquestionattempts" datatype.
    if ($oldversion < 2024030100) {

        $exportlogrepository = new export_log_repository();

        $datatype = 'quizquestionattempts';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2024030100, 'local', 'intellidata');
    }

    // Reset/export "quizquestionattempts" datatype.
    if ($oldversion < 2024030800) {

        $exportlogrepository = new export_log_repository();

        $datatype = 'usergrades';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2024030800, 'local', 'intellidata');
    }

    // Add new config/reset/export "db_scale" datatype.
    if ($oldversion < 2024040802) {
        $datatypename = datatypes_service::generate_optional_datatype('scale');
        $datatypes = datatypes_service::get_all_datatypes();
        if (isset($datatypes[$datatypename])) {
            $dbscale = $datatypes[$datatypename];

            $configservice = new \local_intellidata\services\config_service([$datatypename => $dbscale]);
            $configservice->setup_config();

            $exportlogrepository = new export_log_repository();
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatypename);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatypename],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2024040802, 'local', 'intellidata');
    }

    // Reset/export "gradeitems" datatype.
    if ($oldversion < 2024041500) {

        $exportlogrepository = new export_log_repository();

        $datatype = 'gradeitems';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2024041500, 'local', 'intellidata');
    }

    if ($oldversion < 2024042600) {

        $DB->execute("UPDATE {local_intellidata_export_log} SET tabletype=1 WHERE datatype LIKE 'db_%' AND tabletype=0");

        upgrade_plugin_savepoint(true, 2024042600, 'local', 'intellidata');
    }

    // Add new config/reset/export "db_user_lastaccess" datatype.
    if ($oldversion < 2024050300) {
        $datatypename = datatypes_service::generate_optional_datatype('user_lastaccess');
        $datatypes = datatypes_service::get_all_datatypes();
        if (isset($datatypes[$datatypename])) {
            $dbscale = $datatypes[$datatypename];

            $configservice = new \local_intellidata\services\config_service([$datatypename => $dbscale]);
            $configservice->setup_config();

            $exportlogrepository = new export_log_repository();
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatypename);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatypename],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2024050300, 'local', 'intellidata');
    }

    // Add new config "db_logstore_standard_log" datatype.
    if ($oldversion < 2024051601) {
        $datatypename = datatypes_service::generate_optional_datatype('logstore_standard_log');
        $datatypes = datatypes_service::get_all_datatypes();
        if (isset($datatypes[$datatypename])) {
            $dbscale = $datatypes[$datatypename];

            $configservice = new \local_intellidata\services\config_service([$datatypename => $dbscale]);
            $configservice->setup_config();
        }

        upgrade_plugin_savepoint(true, 2024051601, 'local', 'intellidata');
    }

    // Add new config "course_completion_criteria" datatype.
    if ($oldversion < 2024061102) {
        $datatypename = datatypes_service::generate_optional_datatype('course_completion_criteria');
        $datatypes = datatypes_service::get_all_datatypes();
        if (isset($datatypes[$datatypename])) {
            $dbscale = $datatypes[$datatypename];

            $configservice = new \local_intellidata\services\config_service([$datatypename => $dbscale]);
            $configservice->setup_config();

            $exportlogrepository = new export_log_repository();
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatypename);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatypename],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2024061102, 'local', 'intellidata');
    }

    // Reset/export "trackinglog" datatype.
    if ($oldversion < 2024061800) {

        $exportlogrepository = new export_log_repository();

        $datatype = 'trackinglog';

        // Insert or update log record for datatype.
        $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

        // Add new datatypes to export ad-hoc task.
        $exporttask = new export_adhoc_task();
        $exporttask->set_custom_data([
            'datatypes' => [$datatype],
        ]);
        \core\task\manager::queue_adhoc_task($exporttask);

        upgrade_plugin_savepoint(true, 2024061800, 'local', 'intellidata');
    }

    // Reset/export "users" and "courses" datatypes.
    if ($oldversion < 2024070500) {

        $exportlogrepository = new export_log_repository();

        $datatypes = ['users', 'courses'];

        foreach ($datatypes as $datatype) {
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2024070500, 'local', 'intellidata');
    }

    // Add new config 'messages, message_conversation_members, message_user_actions' datatypes.
    if ($oldversion < 2024073101) {
        $reqdatatypes = [
            'messages', 'message_conversation_members', 'message_user_actions',
        ];
        foreach ($reqdatatypes as $reqdatatype) {
            $datatypename = datatypes_service::generate_optional_datatype($reqdatatype);
            $datatypes = datatypes_service::get_all_datatypes();
            if (isset($datatypes[$datatypename])) {
                $dbscale = $datatypes[$datatypename];

                $configservice = new \local_intellidata\services\config_service([$datatypename => $dbscale]);
                $configservice->setup_config();

                $exportlogrepository = new export_log_repository();
                $exportlogrepository->insert_datatype($datatypename);
            }
        }

        upgrade_plugin_savepoint(true, 2024073101, 'local', 'intellidata');
    }

    // Add new config 'syslogs' datatype.
    if ($oldversion < 2024092501) {

        // Insert or update log record for datatype.
        (new export_log_repository())->insert_datatype('syslogs', export_logs::TABLE_TYPE_UNIFIED, true);

        upgrade_plugin_savepoint(true, 2024092501, 'local', 'intellidata');
    }

    // Reset/export "gradeitems" datatype.
    if ($oldversion < 2024101800) {

        $exportlogrepository = new export_log_repository();

        $datatypes = ['gradeitems'];

        foreach ($datatypes as $datatype) {
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2024101800, 'local', 'intellidata');
    }

    if ($oldversion < 2025022601) {

        try {
            // The PostgreSQL branch named a column, time_points, that does not
            // exist - it is timepoint, as db/install.xml declares. The
            // statement threw, the surrounding catch swallowed it into
            // DebugHelper::error_log() (itself a no-op unless debugenabled is
            // on), and the savepoint below was written anyway, so on PostgreSQL
            // this normalisation never happened and could never be retried.
            // Sites that have already passed this savepoint are repaired by the
            // 2026082402 step at the end of this file.
            $timestampu = $CFG->dbtype == DBHelper::POSTGRES_TYPE ?
                'EXTRACT(EPOCH FROM (TO_TIMESTAMP(timepoint)::DATE))' :
                'UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(timepoint)))';

            $DB->execute('UPDATE {local_intellidata_trlogs} SET timepoint = ' . $timestampu);
        } catch (Throwable $e) {
            DebugHelper::error_log($e->getMessage());
        }

        upgrade_plugin_savepoint(true, 2025022601, 'local', 'intellidata');
    }

    if ($oldversion < 2025050801) {

        $DB->delete_records_select('local_intellidata_config', "datatype IN('survey', 'surveyanswers')");
        $DB->delete_records_select('local_intellidata_export_log',
                                   "datatype IN('survey', 'surveyanswers', 'db_survey', 'db_survey_analysis', 'db_survey_answers', 'db_survey_questions')");
        $DB->delete_records_select('local_intellidata_export_ids', "datatype IN('survey', 'surveyanswers')");

        upgrade_plugin_savepoint(true, 2025050801, 'local', 'intellidata');
    }

    // Reset/export "activities" datatypes.
    if ($oldversion < 2025061300) {

        $exportlogrepository = new export_log_repository();

        $datatypes = ['activities'];

        foreach ($datatypes as $datatype) {
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2025061300, 'local', 'intellidata');
    }

    // Add new datatypes to the export.
    if ($oldversion < 2025081200) {

        $exportlogrepository = new export_log_repository();

        // Add new datatypes to the plugin config and export.
        $datatypes = ['rubrics'];

        foreach ($datatypes as $datatype) {
            // Insert or update log record for datatype.
            $exportlogrepository->insert_datatype($datatype, export_logs::TABLE_TYPE_UNIFIED, true);

            // Add new datatypes to export ad-hoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype],
            ]);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        upgrade_plugin_savepoint(true, 2025081200, 'local', 'intellidata');
    }

    if ($oldversion < 2025081300) {
        $reqdatatypes = [
            'gradingform_rubric_criteria',
            'gradingform_rubric_fillings',
            'gradingform_rubric_levels',
            'grading_instances',
        ];
        foreach ($reqdatatypes as $reqdatatype) {
            $datatypename = datatypes_service::generate_optional_datatype($reqdatatype);
            $datatypes = datatypes_service::get_all_datatypes();
            if (isset($datatypes[$datatypename])) {
                $dbscale = $datatypes[$datatypename];

                $configservice = new \local_intellidata\services\config_service([$datatypename => $dbscale]);
                $configservice->setup_config();

                $exportlogrepository = new export_log_repository();
                $exportlogrepository->insert_datatype($datatypename);

                // Add new datatypes to export ad-hoc task.
                $exporttask = new export_adhoc_task();
                $exporttask->set_custom_data([
                    'datatypes' => [$datatypename],
                ]);
                \core\task\manager::queue_adhoc_task($exporttask);
            }
        }

        upgrade_plugin_savepoint(true, 2025081300, 'local', 'intellidata');
    }

    // Update configuration after removing 'enrol_*' from allowed system tables list.
    // This allows export of enrol_* tables by removing the restriction.
    if ($oldversion < 2026021801) {
        $configservice = new config_service(datatypes_service::get_all_datatypes());
        $configservice->setup_config();
        $configservice->cache_config();

        upgrade_plugin_savepoint(true, 2026021801, 'local', 'intellidata');
    }

    if ($oldversion < 2026082401) {
        // Settings that no longer exist. Left behind they would keep showing in
        // the config report and, for webservicetoken, would keep a privileged
        // credential in mdl_config_plugins after the code that used it is gone.
        //
        // 'debug' held the LTI debug flag and is deliberately NOT carried over
        // to its new name. The old checkbox stored the literal string 'text'
        // and lti_service read a key nothing ever wrote, so LTI debug output
        // has always been off in practice; migrating the value would switch the
        // manual debug launch page on for those sites at upgrade. The new
        // ltidebug setting starts unset, and an administrator who wants debug
        // output can now tick the box and have it work.
        unset_config('webservicetoken', 'local_intellidata');
        unset_config('ltiassigndefaultmethod', 'local_intellidata');
        unset_config('debug', 'local_intellidata');

        // Four TrackingHelper toggles passed set_config()'s arguments in the
        // wrong order, so instead of writing local_intellidata settings they
        // created plugin namespaces literally called 'enabled' and
        // 'enabledtracking', each holding a 'local_intellidata' key.
        // Scoped to name = 'local_intellidata' so only the rows this plugin
        // created are removed, never a real setting that happens to live in a
        // namespace of that name.
        foreach (['enabled', 'enabledtracking'] as $junknamespace) {
            $DB->delete_records('config_plugins', [
                'plugin' => $junknamespace,
                'name' => 'local_intellidata',
            ]);
        }

        upgrade_plugin_savepoint(true, 2026082401, 'local', 'intellidata');
    }

    if ($oldversion < 2026082402) {
        // Repair for the 2025022601 step, which left both engines wrong in
        // different ways.
        //
        // Its PostgreSQL branch referenced a column that does not exist. That
        // statement threw, the exception was swallowed, and the savepoint was
        // written regardless - so no PostgreSQL site ever ran the
        // normalisation, and none could retry it.
        //
        // Its MySQL branch ran, but normalised to midnight in the database
        // session timezone rather than midnight UTC, which is what the
        // application writes. On a server not running UTC it therefore moved
        // every row - including rows that were already correct - to a day
        // boundary the application will never produce again.
        //
        // Both are repaired the same way, so this is not gated by engine.
        local_intellidata_normalise_trlogs_timepoint();

        // The run_report() function now honours the active/inactive flag, which it never
        // read before. Reports already stored on this site were created under
        // the old behaviour, where status was set to 0 on every save and
        // ignored at execution - so enforcing it without this would silently
        // stop every existing report from returning data. Activate what is
        // already there; from now on a newly pushed report has to be approved.
        $DB->set_field('local_intellidata_reports', 'status', 1, ['status' => 0]);

        // The external_identifier column is the lookup and upsert key for that table and
        // had no index at all.
        //
        // reports_repository::update_or_create() is a read-then-write with no
        // locking, and get_record() only raises a debugging notice when it
        // matches more than one row, so duplicate identifiers are already
        // reachable through concurrent saves - and the column is NOT NULL
        // char(255), so '' is a legal duplicate value too. Adding the unique
        // index without clearing them first would abort this step before its
        // savepoint and leave the site stuck in "upgrade required" pending
        // manual surgery on a customer database. Keep the highest id per
        // identifier, which is the row update_or_create() would have found.
        $duplicates = $DB->get_records_sql(
            'SELECT external_identifier, MAX(id) AS keepid
               FROM {local_intellidata_reports}
           GROUP BY external_identifier
             HAVING COUNT(id) > 1'
        );

        foreach ($duplicates as $duplicate) {
            $DB->delete_records_select(
                'local_intellidata_reports',
                'external_identifier = :identifier AND id <> :keepid',
                ['identifier' => $duplicate->external_identifier, 'keepid' => $duplicate->keepid]
            );
        }

        $table = new xmldb_table('local_intellidata_reports');
        $index = new xmldb_index('external_identifier_uix', XMLDB_INDEX_UNIQUE, ['external_identifier']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082402, 'local', 'intellidata');
    }

    return true;
}

/**
 * Normalise local_intellidata_trlogs.timepoint to midnight UTC.
 *
 * Written as a batched loop rather than one statement: this
 * table is one row per user per page per day, so on a large tenant a single
 * rewriting UPDATE means a long lock, a large WAL burst and heavy table bloat,
 * and because the savepoint is only reached afterwards, a web upgrade that hit
 * max_execution_time would restart the whole thing on every retry.
 *
 * The WHERE clause makes the loop idempotent - a row already holding the
 * normalised value is not rewritten - so a restart resumes rather than repeats.
 *
 * timemodified is advanced with the value, because it is the incremental
 * export watermark: without that, Moodle would be normalised and the analytics
 * warehouse would keep the old timepoints indefinitely, which defeats the
 * purpose of normalising. Every repaired row therefore re-exports on the next
 * run.
 *
 * Runs on every engine. PostgreSQL never ran the original step at all; MySQL
 * ran it against the database session timezone instead of UTC, so a non-UTC
 * MySQL server carries the same misalignment by a different route. The
 * expression is integer arithmetic, so one pass repairs both, and a site whose
 * rows are already aligned matches nothing and is untouched.
 *
 * Note this is a one-time repair of stored history. It does not police
 * intelliboard_tracking_service::copy_logs(), which copies timepoint verbatim
 * out of local_intelliboard_logs and can therefore introduce misaligned rows
 * after this has run.
 *
 * @return void
 */
function local_intellidata_normalise_trlogs_timepoint() {
    global $DB;

    // The target is midnight UTC, because that is what the application writes.
    // storage_repository::get_today_time() is
    // new \DateTime('today', new \DateTimeZone('UTC')), and it is the source of
    // timepoint for all three tracking storage repositories - so a stored value
    // is always an exact multiple of 86400. Rows predating that change hold a
    // full timestamp, which is what this repair collapses.
    //
    // Neither engine's date functions are the reference. Both
    // UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(timepoint))) and
    // DATE_TRUNC('day', TO_TIMESTAMP(timepoint)) resolve the day in the
    // *session* timezone, and Moodle never issues SET TimeZone. On a server not
    // running UTC either one rewrites rows to a value the application will never
    // produce again: the next write for that user and day computes midnight UTC,
    // does not match the repaired row, and inserts a second trlogs row for the
    // same day. trackid_timepoint_idx is not unique, so nothing prevents it, and
    // both rows then export.
    //
    // Integer arithmetic avoids the question entirely. The modulo goes through
    // sql_modulo() rather than a literal %, because this step is not gated by
    // engine any more and Oracle has no % operator - it spells modulo
    // MOD(a, b), and would raise ORA-00911 here. Nothing catches that, so it
    // would propagate before the savepoint below is written and leave the site
    // in "upgrade required".
    $modulo = $DB->sql_modulo('timepoint', 86400);
    $normalised = "timepoint - $modulo";
    $batchsize = 20000;

    $minid = (int)$DB->get_field_sql('SELECT MIN(id) FROM {local_intellidata_trlogs}');
    $maxid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {local_intellidata_trlogs}');

    if (!$maxid) {
        return;
    }

    $now = time();

    for ($from = $minid; $from <= $maxid; $from += $batchsize) {
        // The upgrade-context helper: it raises the time limit and keeps the
        // upgrade progress bar alive, so a large table cannot silently die on
        // max_execution_time part way through.
        upgrade_set_timeout(600);

        $DB->execute(
            "UPDATE {local_intellidata_trlogs}
                SET timepoint = $normalised,
                    timemodified = :now
              WHERE id >= :fromid
                AND id < :toid
                AND $modulo <> 0",
            ['now' => $now, 'fromid' => $from, 'toid' => $from + $batchsize]
        );

        mtrace('local_intellidata: normalised trlogs.timepoint up to id ' . ($from + $batchsize - 1));
    }
}
