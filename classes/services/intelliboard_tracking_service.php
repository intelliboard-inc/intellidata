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

namespace local_intellidata\services;

use local_intellidata\helpers\DBHelper;
use local_intellidata\helpers\PageParamsHelper;
use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\helpers\TrackingHelper;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class intelliboard_tracking_service {

    /**
     * Tracking types.
     */
    const TRACKING_TYPES = [PageParamsHelper::PAGETYPE_COURSE, PageParamsHelper::PAGETYPE_MODULE];

    /**
     * Copy type tracking.
     */
    /**
     * Name of the setting holding the tracking state suspended by this task.
     */
    const SUSPENDED_TRACKING_SETTING = 'intelliboardcopysuspendedtracking';

    /**
     * Copy type Tracking.
     */
    const COPY_TYPE_TRACKING = 'tracking';
    /**
     * Copy type Logs.
     */
    const COPY_TYPE_LOGS = 'logs';
    /**
     * Copy typedetails.
     */
    const COPY_TYPE_DETAILS = 'details';

    /**
     * @var string[]
     */
    private $copytypes = [
        self::COPY_TYPE_TRACKING,
        self::COPY_TYPE_LOGS,
        self::COPY_TYPE_DETAILS,
    ];

    /**
     * Copy process.
     *
     * @return void
     * @throws \dml_exception
     */
    public function copy_process() {
        if (!$this->intelliboard_is_enable()) {
            $this->finish();

            // Returning matters here: finish() clears the copy type and
            // disables the task, and falling through reaches first_start(),
            // which sets the copy type again and switches site tracking off -
            // and since the task is already disabled there is no later run to
            // switch it back on.
            return;
        }

        // Safety net. Tracking is only ever suspended for the duration of a
        // copy, so if the marker survives with no copy in progress a previous
        // run stopped part-way through finish(); restore it now rather than
        // leaving site tracking off indefinitely.
        $suspended = get_config(ParamsHelper::PLUGIN, self::SUSPENDED_TRACKING_SETTING);

        if (!$this->get_copy_type_setting() && $suspended !== false) {
            $this->resume_tracking();
        }

        if (SettingsHelper::get_setting('resetimporttrackingprogress') == 1) {
            // Reset import process.
            SettingsHelper::set_setting('resetimporttrackingprogress', 0);

            $this->set_copy_type_setting(null);

            $this->set_progress_limit(0);
        }

        if (!$this->get_copy_type_setting()) {
            $this->first_start();
        }

        $copymethod = $this->get_copy_method();

        // True if last element copied.
        if ($this->{$copymethod}()) {
            mtrace($copymethod . " table imported!");
            $this->go_to_next_method();
        }
    }

    /**
     * Check IntelliBoard enable.
     *
     * @return bool
     */
    private function intelliboard_is_enable() {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_intelliboard_tracking');
        if (!$dbman->table_exists($table)) {
            return false;
        }

        return true;
    }

    /**
     * Transition to the next data type.
     *
     * @return void
     */
    private function go_to_next_method() {
        $copytype = $this->get_copy_type_setting();

        $methodkey = array_search($copytype, $this->copytypes);
        if ($methodkey !== null) {
            $nextkey = (int)$methodkey + 1;
            if (array_key_exists($nextkey, $this->copytypes)) {
                $this->set_copy_type_setting($this->copytypes[$nextkey]);
            } else if ($nextkey + 1 > count($this->copytypes)) {
                $this->finish();
            }
        }
    }

    /**
     * Copy data form local_intelliboard_tracking to local_intellidata_tracking.
     *
     * @return bool // True if all data is copied.
     */
    public function copy_tracking() {
        global $DB;

        $finish1 = $finish2 = false;

        [$limit, $offset, $willprocessed] = $this->get_limit_data();

        if ($offset == 0) {
            mtrace("Start import 'user trackings' from IntelliBoard plugin!");
        }

        mtrace("Import 'user trackings' records: from - " . $offset . ', to - ' . $willprocessed);

        [$sql, $conditions] = $DB->get_in_or_equal(self::TRACKING_TYPES, SQL_PARAMS_NAMED, 'page');

        $sqltracking = "SELECT MAX(id) as id FROM {local_intellidata_tracking} WHERE page {$sql}";
        $lastidentifier = 0;
        if ($lasttracking = $DB->get_record_sql($sqltracking, $conditions)) {
            $lastidentifier = $lasttracking->id ? : 0;
        }

        // LIMIT is not portable - Oracle needs FETCH FIRST and SQL Server needs
        // TOP or OFFSET FETCH - and it cannot be used inside INSERT ... SELECT
        // anyway. The batch is bounded by selecting this page's ids through the
        // DML layer, which emits the right dialect, and then constraining the
        // insert to that id range. $lastidentifier is bound rather than
        // interpolated.
        $batchparams = $conditions + ['lastid' => $lastidentifier];
        $maxid = $this->get_batch_max_id(
            "SELECT id
               FROM {local_intelliboard_tracking}
              WHERE courseid IS NOT NULL AND page {$sql} AND id > :lastid
           ORDER BY id ASC",
            $batchparams,
            $limit
        );

        if ($maxid !== null) {
            $useragentjson = $DB->sql_concat(
                "'{\"browser\":\"'",
                'useragent',
                "'\",\"os\":\"'",
                'useros',
                "'\"}'"
            );

            $insertsql = "INSERT INTO {local_intellidata_tracking}
                                (id, userid, courseid, page, param, visits, timespend,
                                 firstaccess, lastaccess, timemodified, useragent, ip)
                                SELECT
                                    id,
                                    userid,
                                    courseid,
                                    page,
                                    param,
                                    visits,
                                    timespend,
                                    COALESCE(firstaccess, 0) AS firstaccess,
                                    COALESCE(lastaccess, 0) AS lastaccess,
                                    0 AS timemodified,
                                    {$useragentjson} AS useragent,
                                    userip AS ip
                                FROM {local_intelliboard_tracking}
                                WHERE courseid IS NOT NULL AND page {$sql}
                                  AND id > :lastid AND id <= :maxid";

            $DB->execute($insertsql, $conditions + ['lastid' => $lastidentifier, 'maxid' => $maxid]);
        } else {
            // All data copied.
            $finish1 = true;
        }

        [$sql, $conditions] = $DB->get_in_or_equal(self::TRACKING_TYPES, SQL_PARAMS_NAMED, 'param', false);
        $allcount = $DB->count_records_sql("SELECT COUNT(t.userid)
                                                  FROM (SELECT userid
                                                          FROM {local_intelliboard_tracking}
                                                         WHERE page {$sql}
                                                      GROUP BY userid) t", $conditions);
        if ($offset < $allcount) {
            // This statement aggregates per user, so the batch is a page of user
            // ids rather than a range of row ids. LIMIT/OFFSET moves out of the
            // INSERT ... SELECT and into a plain SELECT, where the DML layer
            // renders it for the engine in use.
            $userids = [];
            $recordset = $DB->get_recordset_sql(
                "SELECT lit.userid
                   FROM {local_intelliboard_tracking} lit
              LEFT JOIN {local_intellidata_tracking} lidt ON lidt.id = lit.id
                  WHERE lit.page {$sql} AND lidt.id IS NULL
               GROUP BY lit.userid
               ORDER BY lit.userid ASC",
                $conditions,
                $offset,
                $limit
            );
            foreach ($recordset as $record) {
                $userids[] = $record->userid;
            }
            $recordset->close();

            if ($userids) {
                [$useridsql, $useridparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

                $useragentjson = $DB->sql_concat(
                    "'{\"browser\":\"'",
                    'MAX(lit.useragent)',
                    "'\",\"os\":\"'",
                    'MAX(lit.useros)',
                    "'\"}'"
                );

                $insertsql = "
                        INSERT INTO {local_intellidata_tracking}
                                (id, userid, courseid, page, param, visits, timespend,
                                 firstaccess, lastaccess, timemodified, useragent, ip)
                            SELECT
                                 MAX(lit.id) AS id,
                                 lit.userid,
                                 0 AS courseid,
                                 'site' AS page,
                                 1 AS param,
                                 SUM(lit.visits) AS visits,
                                 SUM(lit.timespend) AS timespend,
                                 COALESCE(MIN(lit.firstaccess), 0) AS firstaccess,
                                 COALESCE(MAX(lit.lastaccess), 0) AS lastaccess,
                                 0 AS timemodified,
                                 {$useragentjson} AS useragent,
                                 MAX(lit.userip) AS ip
                             FROM {local_intelliboard_tracking} lit
                        LEFT JOIN {local_intellidata_tracking} lidt ON lidt.id = lit.id
                            WHERE lit.page {$sql} AND lidt.id IS NULL
                              AND lit.userid {$useridsql}
                         GROUP BY lit.userid";

                $DB->execute($insertsql, $conditions + $useridparams);
            }
        } else {
            // All data copied.
            $finish2 = true;
        }

        if ($finish1 && $finish2) {
            $this->set_progress_limit(0);

            return true;
        }

        $this->set_progress_limit($willprocessed);

        return false;
    }

    /**
     * Copy data form local_intelliboard_logs to local_intellidata_trlogs.
     *
     * @return bool // True if all data is copied.
     */
    public function copy_logs() {
        global $DB;

        [$limit, $offset, $willprocessed] = $this->get_limit_data();
        if ($offset == 0) {
            mtrace("Start import 'user trackings logs' from IntelliBoard plugin!");
        }

        mtrace("Import 'user trackings logs' records: from - " . $offset . ', to - ' . $willprocessed);

        $lastidentifier = 0;
        if ($lasttracking = $DB->get_record_sql("SELECT MAX(id) as id FROM {local_intellidata_trlogs}")) {
            $lastidentifier = $lasttracking->id ? : 0;
        }

        // LIMIT is not portable and cannot be used inside INSERT ... SELECT.
        // The batch is bounded by resolving this page's highest id through the
        // DML layer and constraining the insert to that range instead.
        // $lastidentifier is bound rather than interpolated.
        $maxid = $this->get_batch_max_id(
            "SELECT id FROM {local_intelliboard_logs} WHERE id > :lastid ORDER BY id ASC",
            ['lastid' => $lastidentifier],
            $limit
        );

        if ($maxid !== null) {
            $insertsql = "INSERT INTO {local_intellidata_trlogs}
                                   SELECT *, 0 AS timemodified
                                     FROM {local_intelliboard_logs}
                                    WHERE id > :lastid AND id <= :maxid";

            $DB->execute($insertsql, ['lastid' => $lastidentifier, 'maxid' => $maxid]);

            $this->set_progress_limit($willprocessed);
            return false;
        }

        // All data copied.
        [$sql, $conditions] = $DB->get_in_or_equal(self::TRACKING_TYPES, SQL_PARAMS_NAMED, 'param', false);
        $ids = DBHelper::get_operator('GROUP_CONCAT', 'id', ['separator' => ',']);
        $duplicates = $DB->get_records_sql("
                    SELECT
                         MAX(id) as id,
                         $ids as ids
                    FROM {local_intelliboard_tracking}
                   WHERE page {$sql}
                GROUP BY userid
                  HAVING COUNT(id) > 1", $conditions);

        foreach ($duplicates as $record) {
            $ids = explode(',', $record->ids);
            $newid = $record->id;
            $duplicates = array_diff($ids, [$record->id]);

            [$sql, $conditions] = $DB->get_in_or_equal($duplicates, SQL_PARAMS_NAMED);
            $conditions['newid'] = $newid;

            $DB->execute("UPDATE {local_intellidata_trlogs} SET trackid=:newid WHERE trackid {$sql}", $conditions);
        }

        $unresolweditems = $DB->get_recordset_sql("SELECT l.id
                                    FROM {local_intellidata_trlogs} l
                               LEFT JOIN {local_intellidata_tracking} t ON t.id = l.trackid
                                    WHERE t.id IS NULL");
        $items = [];
        $i = 1;
        foreach ($unresolweditems as $item) {
            $items[] = $item->id;

            if ($i >= 500) {
                [$sql, $conditions] = $DB->get_in_or_equal($items);
                $DB->execute("DELETE FROM {local_intellidata_trlogs} WHERE id {$sql}", $conditions);
                $items = [];
                $i = 1;
            }
            $i++;
        }

        if (!empty($items)) {
            [$sql, $conditions] = $DB->get_in_or_equal($items);
            $DB->execute("DELETE FROM {local_intellidata_trlogs} WHERE id {$sql}", $conditions);
        }

        $this->set_progress_limit(0);

        return true;
    }

    /**
     * Copy data form local_intelliboard_details to local_intellidata_trdetails.
     *
     * @return bool // True if all data is copied.
     */
    public function copy_details() {
        global $DB;

        [$limit, $offset, $willprocessed] = $this->get_limit_data();
        if ($offset == 0) {
            mtrace("Start import 'user trackings log details' from IntelliBoard plugin!");
        }

        mtrace("Import 'user trackings log details' records: from - " . $offset . ', to - ' . $willprocessed);

        $lastidentifier = 0;
        if ($lasttracking = $DB->get_record_sql("SELECT MAX(id) as id FROM {local_intellidata_trdetails}")) {
            $lastidentifier = $lasttracking->id ? : 0;
        }

        // LIMIT is not portable and cannot be used inside INSERT ... SELECT.
        // The batch is bounded by resolving this page's highest id through the
        // DML layer and constraining the insert to that range instead.
        // $lastidentifier is bound rather than interpolated.
        $maxid = $this->get_batch_max_id(
            "SELECT id FROM {local_intelliboard_details} WHERE id > :lastid ORDER BY id ASC",
            ['lastid' => $lastidentifier],
            $limit
        );

        if ($maxid !== null) {
            $insertsql = "INSERT INTO {local_intellidata_trdetails}
                                   SELECT *, 0 AS timemodified
                                     FROM {local_intelliboard_details}
                                    WHERE id > :lastid AND id <= :maxid";

            $DB->execute($insertsql, ['lastid' => $lastidentifier, 'maxid' => $maxid]);

            $this->set_progress_limit($willprocessed);
            return false;
        }

        $unresolweditems = $DB->get_recordset_sql("SELECT d.id
                                        FROM {local_intellidata_trdetails} d
                                        LEFT JOIN {local_intellidata_trlogs} l ON l.id = d.logid
                                        WHERE l.id IS NULL");
        $items = [];
        $i = 1;
        foreach ($unresolweditems as $item) {
            $items[] = $item->id;

            if ($i >= 500) {
                [$sql, $conditions] = $DB->get_in_or_equal($items);
                $DB->execute("DELETE FROM {local_intellidata_trdetails} WHERE id {$sql}", $conditions);
                $items = [];
                $i = 1;
            }
            $i++;
        }

        if (!empty($items)) {
            [$sql, $conditions] = $DB->get_in_or_equal($items);
            $DB->execute("DELETE FROM {local_intellidata_trdetails} WHERE id {$sql}", $conditions);
        }

        $this->set_progress_limit(0);

        return true;
    }

    /**
     * Set progress limit.
     *
     * @param int $value
     * @return void.
     */
    private function set_progress_limit($value) {
        SettingsHelper::set_setting('intelliboardcopyprocessedlimit', $value);
    }

    /**
     * Get progress limit.
     *
     * @return string.
     */
    private function get_progress_limit() {
        return SettingsHelper::get_setting('intelliboardcopyprocessedlimit');
    }

    /**
     * Resolve the highest id in the next batch, or null when the batch is empty.
     *
     * Used to bound a bulk INSERT ... SELECT by an id range, because LIMIT is
     * not portable and is not valid inside INSERT ... SELECT. The limit is
     * applied here, on a plain SELECT, where the DML layer renders it for the
     * engine in use.
     *
     * @param string $sql A SELECT of id, ordered ascending.
     * @param array $params Bound parameters for that SELECT.
     * @param int $limit Batch size.
     * @return int|null
     */
    private function get_batch_max_id($sql, array $params, $limit) {
        global $DB;

        $maxid = null;

        $recordset = $DB->get_recordset_sql($sql, $params, 0, $limit);
        foreach ($recordset as $record) {
            $maxid = $record->id;
        }
        $recordset->close();

        return $maxid;
    }

    /**
     * Get limit data.
     *
     * @return array
     */
    private function get_limit_data() {
        $querylimit = (int)SettingsHelper::get_setting('exportrecordslimit') * 10;

        $processedlimit = $this->get_progress_limit();

        return [
            $querylimit,
            $processedlimit,
            $processedlimit + $querylimit,
        ];
    }

    /**
     * Get copy method.
     *
     * @return string.
     */
    private function get_copy_method() {
        $method = $this->get_copy_type_setting();
        if (!$method) {
            $method = $this->copytypes[0];
        }

        return 'copy_' . $method;
    }

    /**
     * Get copy type setting.
     *
     * @return mixed.
     */
    private function get_copy_type_setting() {
        return SettingsHelper::get_setting('intelliboardcopydatatype');
    }

    /**
     * Set copy type setting.
     *
     * @return mixed.
     */
    private function set_copy_type_setting($value) {
        return SettingsHelper::set_setting('intelliboardcopydatatype', $value);
    }

    /**
     * Run on first startup.
     *
     * @return void.
     */
    private function first_start() {
        $this->remove_all_intellidata_tracking();

        $this->set_copy_type_setting($this->copytypes[0]);

        // The disable_tracking() call used to be a no-op (its set_config arguments were
        // transposed), so the copy has always run with tracking left as the
        // administrator set it. Now that it really switches tracking off for
        // the duration of a multi-run copy, the previous value has to be
        // remembered so finish() can put it back rather than assuming the
        // default.
        // Written once only: a copy restarted through
        // resetimporttrackingprogress calls first_start() again, and
        // overwriting the marker then would record the already-suspended value
        // and lose the administrator's original setting.
        if (get_config(ParamsHelper::PLUGIN, self::SUSPENDED_TRACKING_SETTING) === false) {
            SettingsHelper::set_setting(
                self::SUSPENDED_TRACKING_SETTING,
                (int)(bool)SettingsHelper::get_setting('enabledtracking')
            );
        }

        TrackingHelper::disable_tracking();
    }

    /**
     * Restore the tracking setting saved by first_start().
     *
     * @return void
     */
    private function resume_tracking() {
        $suspended = get_config(ParamsHelper::PLUGIN, self::SUSPENDED_TRACKING_SETTING);

        if ($suspended === false || $suspended === null) {
            // Nothing was suspended by this task, so leave the setting alone.
            return;
        }

        if ((int)$suspended === 1) {
            TrackingHelper::enable_tracking();
        } else {
            TrackingHelper::disable_tracking();
        }

        unset_config(self::SUSPENDED_TRACKING_SETTING, ParamsHelper::PLUGIN);
    }

    /**
     * Run on all data copied.
     *
     * @return void.
     */
    private function finish() {
        $this->set_progress_limit(0);

        $this->set_copy_type_setting(null);

        // Put the tracking setting back to whatever it was before the copy
        // started, rather than unconditionally switching it on.
        $this->resume_tracking();

        self::change_task_status();
    }

    /**
     * Clearing old data from intellidata tranking tables.
     *
     * @return void.
     */
    private function remove_all_intellidata_tracking() {
        global $DB;

        $DB->delete_records('local_intellidata_tracking');

        $DB->delete_records('local_intellidata_trlogs');

        $DB->delete_records('local_intellidata_trdetails');
    }

    /**
     * Disabled this task after copy process.
     *
     * @return bool
     * @throws \dml_exception
     */
    private static function change_task_status($disabled = true) {
        global $DB;

        $taskrecord = $DB->get_record('task_scheduled', ['classname' => '\local_intellidata\task\copy_intelliboard_tracking']);

        if ($taskrecord) {
            $task = \core\task\manager::scheduled_task_from_record($taskrecord);
            $task->set_disabled($disabled);

            return \core\task\manager::configure_scheduled_task($task);
        }
    }

    /**
     * Disable copy IntelliBoard tracking task.
     *
     * @return void
     */
    public static function disable_copy_intelliboard_tracking_task() {
        self::change_task_status();
    }
}
