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

namespace local_intellidata\repositories;

use local_intellidata\helpers\DatatypesHelper;
use local_intellidata\helpers\DBHelper;
use local_intellidata\helpers\ExportHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\helpers\StorageHelper;
use local_intellidata\helpers\EventsHelper;
use local_intellidata\services\datatypes_service;
use local_intellidata\services\encryption_service;
use local_intellidata\services\export_service;
use local_intellidata\task\export_adhoc_task;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class database_repository {

    /**
     * Logs display period.
     */
    const LOGS_DISPLAY_PERIOD = 1000;

    /** @var string|null */
    public static $encriptionservice = null;
    /** @var string|null */
    public static $exportservice = null;
    /** @var string|null */
    public static $exportlogrepository = null;
    /** @var string|null */
    public static $writerecordslimits = null;

    /**
     * Init dependencies.
     *
     * @param $datatype
     */
    public static function init($services = null) {
        self::$encriptionservice = (!empty($services['encryptionservice']))
            ? $services['encryptionservice'] : new encryption_service();
        self::$exportservice = (!empty($services['exportservice']))
            ? $services['exportservice'] : new export_service();
        self::$exportlogrepository = (!empty($services['exportlogrepository']))
            ? $services['exportlogrepository'] : new export_log_repository();
        self::$writerecordslimits = (int) SettingsHelper::get_setting('migrationwriterecordslimit');
    }

    /**
     * Export data.
     *
     * @param $datatype
     * @param $params
     * @return int
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function export($datatype, $params, $showlogs = false, $services = null) {

        // Init Services.
        self::init($services);

        list($overalexportedrecords, $lastrecord) = self::process_export($datatype, $params, $showlogs);

        return $overalexportedrecords;
    }

    /**
     * Method to get exporter type.
     *
     * @param $datatype
     * @param $params
     * @param $showlogs
     * @return array|int
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function process_export($datatype, $params, $showlogs) {

        $limit = (int)SettingsHelper::get_setting('exportrecordslimit');

        if ((int)SettingsHelper::get_setting('divideexportbydatatype') && !empty($params['cronprocessing'])) {
            return (!empty($params['adhoctask']))
                ? self::export_adhoc($datatype, $params, $limit, $showlogs)
                : self::export_chunk($datatype, $params, $limit, $showlogs);
        } else {
            return self::export_all($datatype, $limit, $showlogs);
        }
    }

    /**
     * Export data.
     *
     * @param $datatype
     * @param $params
     * @return int
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function export_all($datatype, $limit, $showlogs) {
        // The storage layer reads the step under the name it stores files with
        // (the migration name in migration mode), so it must be written under that name too.
        $stepname = self::$exportservice->get_storage_name($datatype);
        DatatypesHelper::set_datatype_export_step($stepname, 1);

        $start = 0; $overalexportedrecords = 0; $lastrecord = new \stdClass();
        $exportstarttime = microtime();

        // Page by primary key when possible: OFFSET paging re-reads every earlier row for each page.
        $keyset = $limit && self::use_keyset_paging($datatype);
        $cursor = 0;
        $lastid = 0;

        $step = 1;
        try {
            while ($records = self::get_records($datatype, $keyset ? 0 : $start, $limit, $keyset ? $cursor : null)) {

                // Stop export when no records.
                if (!$records->valid()) {
                    break;
                }

                // Export records to storage.
                $starttime = microtime();
                list($exportedrecords, $lastrecord, $lastid) = self::export_records($datatype, $records, $showlogs);
                $overalexportedrecords += $exportedrecords;

                if ($showlogs) {
                    $difftime = microtime_diff($starttime, microtime());
                    mtrace("Datatype '" . $datatype['name'] . "' exported " . $overalexportedrecords . " rows." .
                        " Execution took " . $difftime . " seconds.");
                }

                // Export table to Moodledata.
                ExportHelper::process_file_export(self::$exportservice, ['datatype' => $datatype['name']]);

                // Stop export in no limit.
                if (!$limit) {
                    break;
                }
                if ($keyset) {
                    $cursor = self::next_keyset_cursor($datatype, $cursor, $lastid);
                }
                $start += $limit;
                $step++;

                DatatypesHelper::set_datatype_export_step($stepname, $step);
            }
        } finally {
            DatatypesHelper::delete_datatype_export_step($stepname);
        }

        $difftime = microtime_diff($exportstarttime, microtime());
        mtrace("Datatype '" . $datatype['name'] . "' export completed." .
            " Execution took " . $difftime . " seconds.");

        self::$exportlogrepository->save_last_processed_data(
            $datatype['name'],
            self::get_keyset_pointer($keyset, $lastrecord, $lastid),
            time()
        );

        return [$overalexportedrecords, $lastrecord];
    }

    /**
     * Export one datatype data.
     *
     * @param $datatype
     * @param $params
     * @return int
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function export_chunk($datatype, $params, $limit, $showlogs) {

        $start = (int)SettingsHelper::get_setting('exportstart');
        $overalexportedrecords = 0; $lastrecord = new \stdClass();
        $lastid = 0;

        $keyset = $limit && self::use_keyset_paging($datatype);
        if ($keyset && $start && SettingsHelper::get_setting('exportkeyset') !== $datatype['name']) {
            // The run started before keyset paging, from unordered OFFSET pages: restart it.
            $start = 0;
        }

        $records = self::get_records($datatype, $keyset ? 0 : $start, $limit, self::get_keyset_cursor($datatype, $keyset, $start));

        if ($records->valid()) {
            $starttime = microtime();

            // Export records to storage.
            list($overalexportedrecords, $lastrecord, $lastid) = self::export_records($datatype, $records, $showlogs);

            if ($showlogs) {
                $difftime = microtime_diff($starttime, microtime());
                mtrace("Datatype '" . $datatype['name'] . "' exported " . $overalexportedrecords .
                    " rows (start: " . $start . ").");
                mtrace("Execution took ".$difftime." seconds.");
            }
        }

        if (!$limit || $overalexportedrecords < $limit) {
            // Set next table to process.
            ExportHelper::set_next_export_params($params['nextexporttable']);

            // Update lastexported time and ID.
            self::$exportlogrepository->save_last_processed_data(
                $datatype['name'],
                self::get_keyset_pointer($keyset, $lastrecord, $lastid),
                time()
            );
        } else {
            // Save the cursor, then the marker, then the start: a run stopped between
            // these writes then repeats at most one chunk instead of losing its position.
            self::$exportlogrepository->save_last_processed_data(
                $datatype['name'],
                self::get_keyset_pointer($keyset, $lastrecord, $lastid)
            );

            $marker = $keyset ? $datatype['name'] : '';
            if (SettingsHelper::get_setting('exportkeyset') !== $marker) {
                SettingsHelper::set_setting('exportkeyset', $marker);
            }

            // Increase start export param.
            ExportHelper::set_next_export_params($datatype['name'], ($start + $limit));
        }

        // Export specific table to Moodledata.
        $exportparams = [
            'datatype' => $datatype['name'],
            'rewritable' => (!$start) ? true : false,
        ];
        ExportHelper::process_file_export(self::$exportservice, $exportparams);

        return [$overalexportedrecords, $lastrecord];
    }

    /**
     * Export one datatype with adhoc task.
     *
     * @param $datatype
     * @param $params
     * @return int
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function export_adhoc($datatype, $params, $limit, $showlogs) {

        $start = !empty($params['limit']) ? (int)$params['limit'] : 0;
        $overalexportedrecords = 0; $lastrecord = new \stdClass();
        $lastid = 0;

        $keyset = $limit && self::use_keyset_paging($datatype);
        if ($keyset && $start && empty($params['keyset'])) {
            // The task was queued before keyset paging, from unordered OFFSET pages: restart it.
            $start = 0;
        }

        $records = self::get_records($datatype, $keyset ? 0 : $start, $limit, self::get_keyset_cursor($datatype, $keyset, $start));

        if ($records->valid()) {
            $starttime = microtime();

            // Export records to storage.
            list($overalexportedrecords, $lastrecord, $lastid) = self::export_records($datatype, $records, $showlogs);

            if ($showlogs) {
                $difftime = microtime_diff($starttime, microtime());
                mtrace("Datatype '" . $datatype['name'] . "' exported " . $overalexportedrecords .
                    " rows (start: " . $start . ").");
                mtrace("Execution took ".$difftime." seconds.");
            }
        }

        // Export specific table to Moodledata.
        $exportparams = [
            'datatype' => $datatype['name'],
            'rewritable' => (!$start) ? true : false,
        ];
        ExportHelper::process_file_export(self::$exportservice, $exportparams);

        if (!$limit || $overalexportedrecords < $limit) {

            // Update lastexported time and ID.
            self::$exportlogrepository->save_last_processed_data(
                $datatype['name'],
                self::get_keyset_pointer($keyset, $lastrecord, $lastid),
                time()
            );

            // Set datatype migrated.
            self::$exportlogrepository->save_migrated($datatype['name']);

            // Send callback when files ready.
            if (!empty($params['callbackurl'])) {
                $client = new \curl();
                $client->post($params['callbackurl'], [
                    'data' => self::$encriptionservice->encrypt(json_encode(['datatypes' => $datatype['name']])),
                ]);
            }
        } else {
            // Update only lastexported ID.
            self::$exportlogrepository->save_last_processed_data(
                $datatype['name'],
                self::get_keyset_pointer($keyset, $lastrecord, $lastid)
            );

            // Create next adhoc task.
            $exporttask = new export_adhoc_task();
            $exporttask->set_custom_data([
                'datatypes' => [$datatype['name']],
                'limit' => ($start + $limit),
                'keyset' => $keyset ? 1 : 0,
                'callbackurl' => !empty($params['callbackurl']) ? $params['callbackurl'] : '',
            ]);
            $exporttask->set_next_run_time(time() + MINSECS);
            \core\task\manager::queue_adhoc_task($exporttask);
        }

        return [$overalexportedrecords, $lastrecord];
    }

    /**
     * Get records from DB.
     *
     * @param $datatype
     * @param int $start
     * @param int $limit
     * @param int|null $cursor Keyset cursor: return only rows with a greater id, ordered by id.
     * @return \moodle_recordset
     * @throws \dml_exception
     */
    public static function get_records($datatype, $start = 0, $limit = 0, $cursor = null) {
        list($sql, $sqlparams) = self::get_export_sql($datatype, $cursor);

        $db = DBHelper::get_db_client();

        return $db->get_recordset_sql($sql, $sqlparams, $start, $limit);
    }

    /**
     * Prepare SQL to get data from DB.
     *
     * @param $datatype
     * @param int|null $cursor Keyset cursor for table datatypes (see use_keyset_paging()).
     * @return array
     */
    public static function get_export_sql($datatype, $cursor = null) {

        list($lastexportedtime, $lastexportedid) = self::$exportlogrepository->get_last_processed_data($datatype['name']);

        $sql = $where = '';
        $sqlparams = [];
        if ($datatype['timemodified_field'] && $lastexportedtime > 0) {
            $where = $datatype['timemodified_field'] . ' >= :timemodified';
            $sqlparams['timemodified'] = $lastexportedtime;
        }

        if (!empty($datatype['filterbyid'])) {
            $where .= (!empty($where) ? ' OR ' : '') . 'id > '. $lastexportedid;
        }

        if (!empty($datatype['migration'])) {
            if (empty($where)) {
                $where = 'id > 0';
            }

            $migration = datatypes_service::init_migration($datatype, null, false);
            list($sql, $params) = $migration->get_sql(false, $where, $sqlparams, $lastexportedtime);

            $sqlparams = array_merge($sqlparams, $params);

        } else if (!empty($datatype['table'])) {
            $orderby = '';
            if ($cursor !== null) {
                $where = (!empty($where) ? '(' . $where . ') AND ' : '') . 'id > :keysetcursor';
                $sqlparams['keysetcursor'] = (int)$cursor;
                $orderby = 'ORDER BY id';
            }

            if (!empty($where)) {
                $where = 'WHERE ' . $where;
            }

            $sql = "SELECT *
                      FROM {" . $datatype['table'] . "}
                     $where
                     $orderby";
        }

        return [$sql, $sqlparams];
    }

    /**
     * Whether a datatype can be paged by primary key instead of OFFSET.
     *
     * Only plain table datatypes with an integer id and without a time filter qualify: a time-filtered
     * export is a small incremental set, and ordering it by id can make the
     * database walk the whole primary key index.
     *
     * @param array $datatype
     * @return bool
     */
    public static function use_keyset_paging($datatype) {
        global $DB;

        if (!empty($datatype['migration']) || empty($datatype['table'])) {
            return false;
        }

        [$lastexportedtime] = self::$exportlogrepository->get_last_processed_data($datatype['name']);
        if ($datatype['timemodified_field'] && $lastexportedtime > 0) {
            return false;
        }

        // The cursor is an integer: a table whose id is missing or not an integer keeps OFFSET paging.
        $columns = $DB->get_columns($datatype['table']);

        return isset($columns['id']) && in_array($columns['id']->meta_type, ['I', 'R']);
    }

    /**
     * Next keyset cursor after an exported page.
     *
     * @param array $datatype
     * @param int $cursor
     * @param int $lastid Last raw id of the page.
     * @return int
     * @throws \coding_exception When the cursor does not advance.
     */
    private static function next_keyset_cursor($datatype, $cursor, $lastid) {
        if ($lastid <= $cursor) {
            throw new \coding_exception("Keyset export cursor did not advance for '" . $datatype['name'] . "'.");
        }

        return $lastid;
    }

    /**
     * Keyset cursor for a chunked export.
     *
     * A chunked run resumes after the last id it saved; its first chunk starts from the beginning.
     *
     * @param array $datatype
     * @param bool $keyset
     * @param int $start
     * @return int|null Null when the datatype is not paged by primary key.
     */
    private static function get_keyset_cursor($datatype, $keyset, $start) {
        if (!$keyset) {
            return null;
        }
        if (!$start) {
            return 0;
        }

        [, $lastexportedid] = self::$exportlogrepository->get_last_processed_data($datatype['name']);

        return (int)$lastexportedid;
    }

    /**
     * Record to save as the export pointer of a chunk.
     *
     * Keyset paging resumes from the raw id of the last exported row.
     *
     * @param bool $keyset
     * @param \stdClass $lastrecord
     * @param int $lastid
     * @return \stdClass
     */
    private static function get_keyset_pointer($keyset, $lastrecord, $lastid) {
        return ($keyset && $lastid) ? (object)['id' => $lastid] : $lastrecord;
    }

    /**
     * Export records method.
     *
     * @param $datatype
     * @param $records
     * @param false $showlogs
     * @return array [exported count, last exported record, last raw id]
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function export_records($datatype, $records, $showlogs = false) {

        $recordsnum = 0; $logscounter = 0; $cleanlogs = false;
        $record = new \stdClass();
        $lastid = 0;

        if ($records) {
            $data = [];
            $i = 0;

            if (empty(self::$exportservice)) {
                self::init();
            }

            $isprepareddata = false;
            if (!empty($datatype['migration'])) {
                $migration = datatypes_service::init_migration($datatype, null, false);
                $records = $migration->prepare_records_iterable($records);
                $isprepareddata = true;
            } else {
                $entity = datatypes_service::init_entity($datatype, $data);
            }

            foreach ($records as $record) {
                if (isset($record->id)) {
                    $lastid = (int)$record->id;
                }

                if ($isprepareddata == false) {
                    $entity->set_values($record);
                    $record = $entity->export_data();
                }

                $data[] = self::prepare_entity_data($record);

                // Export data by chanks.
                if ($i >= self::$writerecordslimits) {
                    // Save data into the file.
                    self::export_data($datatype['name'], $data);
                    $data = [];
                    $i = 0;

                    if ($showlogs) {
                        mtrace("");
                        mtrace("Complete $recordsnum records.");
                    }
                }
                $i++;

                $recordsnum++; $logscounter++;

                // Display export logs.
                if ($showlogs && $logscounter == self::LOGS_DISPLAY_PERIOD) {
                    mtrace('.', '');
                    $logscounter = 0; $cleanlogs = true;
                }
            }

            self::export_data($datatype['name'], $data);
        }

        if ($showlogs && $cleanlogs) {
            mtrace("");
        }

        return [$recordsnum, $record, $lastid];
    }

    /**
     * Export data.
     *
     * @param $datatype
     * @param $data
     */
    private static function export_data($datatype, $data) {
        self::$exportservice->store_data($datatype, implode(PHP_EOL, $data));
    }

    /**
     * Prepare entity for export.
     *
     * @param \stdClass $data
     *
     * @return false|string
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    private static function prepare_entity_data($data) {
        return StorageHelper::format_data('csv', $data);
    }

    /**
     * Export ids for specific entity.
     *
     * @param $datatype
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public function export_ids($datatype, $showlogs = true) {

        // Validate if need to export deleted ids.
        if (!$this->process_exportids_enabled($datatype)) {
            return;
        }

        if ($showlogs) {
            $starttime = microtime();
            mtrace("Storing datatype '" . $datatype['name'] . "' ids started at " . date('r') . "...");
        }

        $this->process_export_ids($datatype, $showlogs);

        if ($showlogs) {
            $difftime = microtime_diff($starttime, microtime());
            mtrace("Storing datatype '" . $datatype['name'] . "' ids completed at " . date('r') . ".");
            mtrace("Storing datatype '" . $datatype['name'] . "' ids took " . $difftime . " seconds.");
        }
    }

    /**
     * Process export ids.
     *
     * @param $datatype
     * @param $showlogs
     * @return void
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    private function process_export_ids($datatype, $showlogs) {
        // Process deleted records.
        self::process_deleted_records($datatype, $showlogs);

        // Process created records.
        self::process_created_records($datatype, $showlogs);
    }

    /**
     * Validate if exportids enabled for specific datatype.
     *
     * @param array $datatype
     * @return bool
     * @throws \dml_exception
     */
    private function process_exportids_enabled(array $datatype) {

        // Do not export ids when disabled on system level.
        if (!SettingsHelper::get_setting('exportids')) {
            return false;
        }

        // Do not export ids when disabled for specific datatype.
        if (empty($datatype['exportids'])) {
            return false;
        }

        // Do not export ids for datatype without DB table.
        if (!isset($datatype['table'])) {
            return false;
        }

        // Do not export ids when datatype is rewritable.
        if (!empty($datatype['rewritable'])) {
            return false;
        }

        return true;
    }

    /**
     * Save data to storage.
     *
     * @param $datatype
     * @param $data
     * @param false $eventname
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    public static function save($datatype, $data, $eventname = false) {
        $entity = datatypes_service::init_entity($datatype, $data);
        $entitydata = $entity->export_data();

        $prepareddata = StorageHelper::format_data(SettingsHelper::get_export_dataformat(), $entitydata);

        if (empty(self::$exportservice)) {
            self::init();
        }
        self::$exportservice->store_data($datatype['name'], $prepareddata);
    }

    /**
     * Save data to storage.
     *
     * @param $datatype
     * @param $showlogs
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    private static function process_deleted_records($datatype, $showlogs = true) {

        $exportidrepository = new export_id_repository();

        $deletedrecords = $exportidrepository->get_deleted_ids($datatype['name'], $datatype['table']);

        if ($deletedrecords->valid()) {

            if ($showlogs) {
                mtrace("Storing datatype '" . $datatype['name'] . "' ids: generating deleted events...");
            }

            $deletedids = []; $records = []; $i = 1; $cleanlogs = false;
            foreach ($deletedrecords as $record) {
                $deletedids[] = $record->id;
                $records[] = self::prepare_event_data(
                    $datatype, (object)['id' => $record->id, 'crud' => EventsHelper::CRUD_DELETED]
                );

                if (!empty(SettingsHelper::get_setting('exportrecordslimit'))
                    && $i >= (int)SettingsHelper::get_setting('exportrecordslimit')) {

                    self::save_events($datatype['name'], $records);
                    $records = []; $i = 0;

                    if ($showlogs) {
                        mtrace('.', '');
                        $cleanlogs = true;
                    }
                }

                $i++;
            }

            self::save_events($datatype['name'], $records);

            // Delete records IDs from database.
            $exportidrepository->clean_deleted_ids($datatype['name'], $deletedids);

            if ($showlogs && count($deletedids)) {
                if ($cleanlogs) {
                    mtrace('');
                }
                mtrace("Storing datatype '" . $datatype['name'] . "' ids: deleted " .
                    count($deletedids) . " ids at " . date('r') . ".");
            }
        }
    }

    /**
     * Save created IDs to storage.
     *
     * @param $datatype
     * @param $showlogs
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    private static function process_created_records($datatype, $showlogs = true) {

        $exportidrepository = new export_id_repository();

        $createdrecords = $exportidrepository->get_created_ids($datatype['name'], $datatype['table']);

        if ($createdrecords->valid()) {

            if ($showlogs) {
                mtrace("Storing datatype'" . $datatype['name'] . "'  ids: saving new ids...");
            }

            $records = []; $created = 0; $i = 1; $cleanlogs = false;
            foreach ($createdrecords as $record) {
                $records[] = [
                    'datatype' => $datatype['name'],
                    'dataid' => $record->id,
                    'timecreated' => time(),
                ];

                if (!empty(SettingsHelper::get_setting('exportrecordslimit'))
                        && $i >= (int)SettingsHelper::get_setting('exportrecordslimit')) {

                    $exportidrepository->save($records);
                    if ($showlogs) {
                        mtrace('.', '');
                        $cleanlogs = true;
                    }
                    $records = []; $i = 0;
                }
                $i++; $created++;
            }

            $exportidrepository->save($records);

            if ($showlogs && $created) {
                if ($cleanlogs) {
                    mtrace('');
                }
                mtrace("Storing datatype '" . $datatype['name'] . "' ids: created " . $created . " ids at " . date('r') . ".");
            }
        }
    }

    /**
     * Prepare event data for export.
     *
     * @param $datatype
     * @param $data
     * @param $eventname
     * @return false|string
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    private static function prepare_event_data($datatype, $data, $eventname = false) {
        $entity = datatypes_service::init_entity($datatype, $data);
        $entitydata = $entity->export_data();

        return StorageHelper::format_data(SettingsHelper::get_export_dataformat(), $entitydata);
    }

    /**
     * Save events to the Storage.
     *
     * @param $datatype
     * @param $records
     * @return void
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     */
    private static function save_events(string $datatypename, array $events) {

        if (!count($events)) {
            return;
        }

        if (empty(self::$exportservice)) {
            self::init();
        }

        self::$exportservice->store_data($datatypename, implode(PHP_EOL, $events));
    }
}
