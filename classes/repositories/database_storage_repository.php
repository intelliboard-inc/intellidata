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

use local_intellidata\helpers\StorageHelper;
use local_intellidata\helpers\SettingsHelper;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class database_storage_repository extends file_storage_repository {

    /**
     * Storage table.
     */
    const STORAGE_TABLE   = 'local_intellidata_storage';

    /**
     * Save data.
     *
     * @param $data
     * @throws \dml_exception
     */
    public function save_data($data) {
        global $DB;

        $record = new \stdClass();
        $record->datatype       = $this->datatype['name'];
        $record->data           = $data;
        $record->timecreated    = time();

        $DB->insert_record(self::STORAGE_TABLE, $record);
    }

    /**
     * Move the buffered rows into the pending folder.
     *
     * Each page of rows is written to its own pending file, and the rows are deleted only
     * once that file is complete. Called by save_file() with the export lock held.
     *
     * @return void
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function stage_buffer() {
        parent::stage_buffer();

        // Get records from database storage.
        $recordscount = $this->get_records_count();
        if (!$recordscount) {
            return;
        }

        $recordslimits = (int)SettingsHelper::get_setting('migrationrecordslimit');

        // Each page is deleted once it is written, so the next page always starts at the beginning.
        $sqlparams = ['limit' => $recordslimits, 'start' => 0];
        for ($i = 0; $recordscount - $i * $recordslimits > 0; $i++) {
            mtrace("Start Exporting for {$this->datatype['name']} from " . ($i * $recordslimits) . " records.");

            // Get records from database.
            $records = $this->get_records($sqlparams);
            if (!$records->valid()) {
                break;
            }

            // Export records to a pending file, then remove them from the table.
            $pendingfile = $this->new_pending_path();
            $partfile = $pendingfile . '.part';
            try {
                $lastrecordid = $this->export_data($partfile, $records);
                if ($lastrecordid && !rename($partfile, $pendingfile)) {
                    throw new \moodle_exception('failed_rename_tempfile', 'local_intellidata');
                }
            } finally {
                // Left over when nothing was written or the write failed; the rows stay in the table.
                if (file_exists($partfile)) {
                    @unlink($partfile);
                }
            }

            if ($lastrecordid) {
                $this->clean_storage($lastrecordid);
            }
        }
    }

    /**
     * Delete files.
     *
     * @param null $params
     * @return int|void
     * @throws \dml_exception
     */
    public function delete_files($params = null) {
        if (!empty($params['resetbuffers'])) {
            self::delete_records(['datatype' => $this->datatype['name']]);
        }
        return parent::delete_files($params);
    }

    /**
     * Get records sql.
     *
     * @param false $count
     * @return string
     */
    public function get_records_sql($count = false) {
        $select = ($count) ?
            "SELECT COUNT(id) as recordscount" :
            "SELECT *";

        $sql = "$select
                  FROM {" . self::STORAGE_TABLE . "}
                 WHERE datatype = :datatype";

        // Pages are deleted up to their last id, so they must come in id order.
        if (!$count) {
            $sql .= " ORDER BY id";
        }

        return $sql;
    }

    /**
     * Get records count.
     *
     * @return int
     * @throws \dml_exception
     */
    public function get_records_count() {
        global $DB;

        $sql = $this->get_records_sql(true);
        return $DB->count_records_sql($sql, ['datatype' => $this->datatype['name']]);
    }

    /**
     * Get records.
     *
     * @param $params
     * @return \moodle_recordset
     * @throws \dml_exception
     */
    public function get_records($params) {
        global $DB;

        $records = $DB->get_recordset_sql(
            $this->get_records_sql(),
            ['datatype' => $this->datatype['name']],
            $params['start'],
            $params['limit']
        );

        return $records;
    }

    /**
     * Export data.
     *
     * @param $tempfile
     * @param $records
     * @return int Id of the last record written, 0 if none.
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    protected function export_data($tempfile, $records) {
        $writerecordslimits = (int)SettingsHelper::get_setting('migrationwriterecordslimit');
        $lastrecordid = 0; $data = [];
        if ($records) {
            $i = 0;
            $countrecords = 0;

            foreach ($records as $record) {
                $data[] = $record->data;
                $lastrecordid = $record->id;

                if ($i >= $writerecordslimits) {
                    mtrace("Complete $countrecords records.");
                    // Save data into the file.
                    StorageHelper::save_in_file($tempfile, implode(PHP_EOL, $data));
                    $data = [];
                    $i = 0;
                }
                $i++;
                $countrecords++;
            }
            $records->close();

            StorageHelper::save_in_file($tempfile, implode(PHP_EOL, $data));
        }

        return $lastrecordid;
    }

    /**
     * Clean storage.
     *
     * @param $lastrecordid
     * @throws \dml_exception
     */
    private function clean_storage($lastrecordid) {
        global $DB;

        $DB->execute("DELETE
                            FROM {" . self::STORAGE_TABLE . "}
                           WHERE datatype = :datatype
                             AND id <= :lastrecordid
                   ",
            [
                'datatype' => $this->datatype['name'],
                'lastrecordid' => $lastrecordid,
            ]
        );
    }

    /**
     * Get log entity data.
     *
     * @param string $crud
     * @param array $params
     * @return false|mixed
     * @throws \dml_exception
     */
    public function get_log_entity_data(string $crud, array $params = []) {
        global $DB;

        $sql = "SELECT *
                  FROM {local_intellidata_storage}
                 WHERE datatype = :datatype";

        $data = [
            'datatype' => $this->datatype['name'],
        ];

        // Search by event name.
        $sql .= " AND " . $DB->sql_like('data', ':searcheventcrud');
        $data['searcheventcrud'] = '%"crud":"' . $crud . '"%';

        // Search by other fields (if exist).
        foreach ($params as $key => $value) {
            $paramkey1 = 'paramkey1' . $key;
            $paramkey2 = 'paramkey2' . $key;
            $sql .= " AND (" . $DB->sql_like('data', ':' . $paramkey1) .
                    " OR " . $DB->sql_like('data', ':' . $paramkey2) . ")";
            $data[$paramkey1] = '%"' . $key . '":"' . $value . '%';
            $data[$paramkey2] = '%"' . $key . '":' . $value . '%';
        }

        $records = $DB->get_records_sql($sql, $data, 0, 1);

        return count($records) ? reset($records) : null;
    }

    /**
     * Delete records.
     *
     * @param array $params
     *
     * @return void
     * @throws \dml_exception A DML specific exception is thrown for any errors.
     */
    public static function delete_records($params = []) {
        global $DB;

        $DB->delete_records(self::STORAGE_TABLE, $params);
    }
}
