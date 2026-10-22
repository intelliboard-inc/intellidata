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
 * Keyset paging of migration datatype exports.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026 intelliboard.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\repositories\database_repository;
use local_intellidata\repositories\export_log_repository;
use local_intellidata\services\datatypes_service;
use local_intellidata\services\encryption_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/generator.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/fixtures/capturing_export_service.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Keyset paging of migration datatype exports.
 *
 * A datatype reset exports every row of a migration datatype in pages. Unordered OFFSET pages
 * shift when a row is deleted or added during the export, so a live row could be skipped or
 * exported twice.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026 intelliboard.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class migration_keyset_export_test extends \advanced_testcase {
    /** @var int Rows per page. */
    const LIMIT = 10;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        setup_helper::setup_tests_config();

        SettingsHelper::set_setting('exportrecordslimit', self::LIMIT);
        SettingsHelper::set_setting('migrationwriterecordslimit', 1000);
    }

    public function tearDown(): void {
        // The repository keeps its services in static properties.
        database_repository::$encriptionservice = null;
        database_repository::$exportservice = null;
        database_repository::$exportlogrepository = null;
        database_repository::$writerecordslimits = null;

        parent::tearDown();
    }

    /**
     * A grade deleted after the first page must not shift the next page past a live grade.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_all
     * @covers \local_intellidata\repositories\database_repository::get_export_sql
     */
    public function test_usergrades_export_all_does_not_skip_rows_when_rows_are_deleted(): void {
        global $DB;

        SettingsHelper::set_setting('divideexportbydatatype', 0);
        $ids = $this->create_grades(13);
        $exportservice = $this->get_capturing_service('usergrades');

        $exportservice->afterfirstpage = function () use ($DB, $ids) {
            $DB->delete_records('grade_grades', ['id' => $ids[0]]);
        };

        $this->export($exportservice, 'usergrades', []);

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * Chained adhoc export of a migration datatype exports every row once, in id order.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_usergrades_adhoc_chain_exports_every_row_once(): void {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_grades(13);
        $exportservice = $this->get_capturing_service('usergrades');

        $this->run_adhoc_chain($exportservice, 'usergrades');

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * Chunked export of a migration datatype exports every row once, in id order.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_usergrades_chunks_export_every_row_once(): void {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_grades(13);
        $exportservice = $this->get_capturing_service('usergrades');

        // Two chunks; a third run would be the incremental export of these just-graded rows.
        for ($i = 0; $i < 2; $i++) {
            $this->export($exportservice, 'usergrades', ['cronprocessing' => true, 'nextexporttable' => '']);
        }

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * A chained adhoc task resumes from its own cursor, not from the shared last exported id.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_adhoc_task_resumes_from_its_own_cursor(): void {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_grades(13);
        $exportservice = $this->get_capturing_service('usergrades');

        $this->export($exportservice, 'usergrades', ['cronprocessing' => true, 'adhoctask' => true]);
        $task = $this->pop_export_adhoc_task();
        $this->assertNotNull($task);
        $this->assertEquals($ids[self::LIMIT - 1], $task->cursor);

        // Another export of the datatype moves the shared pointer meanwhile.
        (new export_log_repository())->save_last_processed_data('usergrades', (object)['id' => end($ids)]);

        $exportservice->exportedids = [];
        $this->export($exportservice, 'usergrades', [
            'cronprocessing' => true,
            'adhoctask' => true,
            'limit' => $task->limit,
            'keyset' => true,
            'cursor' => $task->cursor,
        ]);

        $this->assertEquals(array_slice($ids, self::LIMIT, self::LIMIT), $exportservice->exportedids);
    }

    /**
     * An adhoc task queued by the OFFSET code restarts a migration datatype from the first row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_adhoc_task_queued_before_keyset_restarts(): void {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_grades(13);
        $exportservice = $this->get_capturing_service('usergrades');

        $this->export(
            $exportservice,
            'usergrades',
            ['cronprocessing' => true, 'adhoctask' => true, 'limit' => self::LIMIT, 'keyset' => false]
        );

        $this->assertEquals(array_slice($ids, 0, self::LIMIT), $exportservice->exportedids);
    }

    /**
     * Logins are paged by user, so a user's logins are never split across two pages.
     *
     * @return void
     * @covers \local_intellidata\entities\userlogins\migration::keyset_column
     */
    public function test_userlogins_export_every_user_once(): void {
        SettingsHelper::set_setting('divideexportbydatatype', 0);

        $userids = [];
        for ($i = 0; $i < 15; $i++) {
            $userids[] = (int)generator::create_user()->id;
        }
        $this->create_logins($userids, 3);

        $exportservice = $this->get_capturing_service('userlogins');
        $this->export($exportservice, 'userlogins', []);

        sort($userids);
        $this->assertEquals($userids, $exportservice->exportedids);
    }

    /**
     * An incremental (time-filtered) export keeps OFFSET paging without a cursor.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::use_keyset_paging
     */
    public function test_time_filtered_migration_export_keeps_offset_paging(): void {
        $datatype = $this->get_datatype('usergrades');
        (new export_log_repository())->save_last_processed_data('usergrades', new \stdClass(), time());
        database_repository::init();

        $this->assertFalse(database_repository::use_keyset_paging($datatype));

        [$sql, $params] = database_repository::get_export_sql($datatype, null);
        $this->assertArrayNotHasKey('keysetcursor', $params);
        $this->assertStringNotContainsString('ORDER BY', $sql);
    }

    /**
     * Every migration datatype builds a query that runs, ordered by its paging key.
     *
     * Keyset datatypes end with ORDER BY their keyset column; the others with their OFFSET order.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::get_export_sql
     * @covers \local_intellidata\entities\migration::keyset_column
     * @covers \local_intellidata\entities\migration::offset_order
     */
    public function test_every_migration_datatype_query_runs_ordered(): void {
        global $DB;

        database_repository::init();
        $checked = 0;

        foreach (datatypes_service::get_required_datatypes() as $name => $datatype) {
            if (empty($datatype['migration'])) {
                continue;
            }
            (new export_log_repository())->insert_datatype($name);

            $migration = datatypes_service::init_migration($datatype, null, false);
            $keyset = database_repository::use_keyset_paging($datatype);
            [$sql, $params] = database_repository::get_export_sql($datatype, $keyset ? 0 : null);

            $order = $keyset ? $migration->keyset_column() : $migration->offset_order();
            if ($order !== null) {
                $this->assertSame(
                    1,
                    preg_match('/ORDER BY\s+' . preg_quote($order, '/') . '\s*$/i', $sql),
                    "$name: $sql"
                );
            } else {
                $this->assertStringNotContainsString('ORDER BY', $sql, $name);
            }
            if ($keyset) {
                $this->assertArrayHasKey('keysetcursor', $params, $name);
            }

            $records = $DB->get_recordset_sql($sql, $params, 0, self::LIMIT);
            $records->close();
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * Run a chained adhoc export the way export_adhoc_task does, without the cron.
     *
     * @param capturing_export_service $exportservice
     * @param string $name
     * @return void
     */
    private function run_adhoc_chain($exportservice, $name) {
        $params = ['cronprocessing' => true, 'adhoctask' => true];
        for ($i = 0; $i < 5; $i++) {
            $this->export($exportservice, $name, $params);

            $task = $this->pop_export_adhoc_task();
            if (!$task) {
                return;
            }
            $params['limit'] = $task->limit;
            $params['keyset'] = !empty($task->keyset);
            $params['cursor'] = !empty($task->cursor) ? (int)$task->cursor : 0;
        }

        $this->fail('The adhoc export chain did not finish.');
    }

    /**
     * Take the queued export adhoc task off the queue.
     *
     * @return \stdClass|null Custom data of the task.
     */
    private function pop_export_adhoc_task() {
        global $DB;

        $record = $DB->get_record('task_adhoc', ['classname' => '\local_intellidata\task\export_adhoc_task']);
        if (!$record) {
            return null;
        }
        $DB->delete_records('task_adhoc', ['id' => $record->id]);

        return json_decode($record->customdata);
    }

    /**
     * Export a datatype.
     *
     * @param capturing_export_service $exportservice
     * @param string $name
     * @param array $params
     * @return void
     */
    private function export($exportservice, $name, $params) {
        ob_start();
        try {
            database_repository::export(
                $exportservice->datatypes[$name],
                $params,
                false,
                [
                    'encryptionservice' => new encryption_service(),
                    'exportservice' => $exportservice,
                    'exportlogrepository' => new export_log_repository(),
                ]
            );
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Export service that keeps the exported ids.
     *
     * @param string $name
     * @return capturing_export_service
     */
    private function get_capturing_service($name) {
        $exportservice = new capturing_export_service(ParamsHelper::MIGRATION_MODE_DISABLED, true, false);
        $exportservice->datatypes[$name] = $this->get_datatype($name);

        return $exportservice;
    }

    /**
     * A required datatype, registered in the export log.
     *
     * @param string $name
     * @return array
     */
    private function get_datatype($name) {
        (new export_log_repository())->insert_datatype($name);

        return datatypes_service::get_required_datatypes()[$name];
    }

    /**
     * Graded users in one course: one grade row per user per grade item.
     *
     * @param int $users
     * @return int[] Every grade_grades id, ascending.
     */
    private function create_grades($users) {
        global $DB;

        $course = generator::create_course();
        $gradeitem = \grade_item::fetch_course_item($course->id);
        for ($i = 0; $i < $users; $i++) {
            $user = generator::create_user();
            generator::enrol_user(['userid' => $user->id, 'courseid' => $course->id]);
            $gradeitem->update_final_grade($user->id, 10 + $i, 'gradebook');
        }

        return array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {grade_grades} ORDER BY id'));
    }

    /**
     * Login events in the standard log.
     *
     * @param int[] $userids
     * @param int $each Logins per user.
     * @return void
     */
    private function create_logins($userids, $each) {
        global $DB;

        $DB->delete_records('logstore_standard_log');

        for ($n = 0; $n < $each; $n++) {
            foreach ($userids as $userid) {
                $DB->insert_record('logstore_standard_log', (object)[
                    'eventname' => '\core\event\user_loggedin',
                    'component' => 'core',
                    'action' => 'loggedin',
                    'target' => 'user',
                    'objecttable' => 'user',
                    'objectid' => $userid,
                    'crud' => 'r',
                    'edulevel' => 0,
                    'contextid' => 1,
                    'contextlevel' => CONTEXT_SYSTEM,
                    'contextinstanceid' => 0,
                    'userid' => $userid,
                    'anonymous' => 0,
                    'timecreated' => time(),
                ]);
            }
        }
    }
}
