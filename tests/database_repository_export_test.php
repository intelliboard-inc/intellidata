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
 * Database repository paging test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\helpers\DatatypesHelper;
use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\helpers\StorageHelper;
use local_intellidata\persistent\datatypeconfig;
use local_intellidata\repositories\database_repository;
use local_intellidata\repositories\export_log_repository;
use local_intellidata\repositories\file_storage_repository;
use local_intellidata\services\database_service;
use local_intellidata\services\encryption_service;
use local_intellidata\services\export_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/fixtures/capturing_export_service.php');

/**
 * Database repository paging test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class database_repository_export_test extends \advanced_testcase {
    /** @var string */
    const DATATYPE = 'db_logstore_standard_log';

    /** @var int Rows per page. */
    const LIMIT = 10;

    /** @var int Rows in the table: three pages. */
    const ROWS = 25;

    public function setUp(): void {
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
    }

    /**
     * A row deleted after the first page must not shift the next page past an unexported row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_all
     * @covers \local_intellidata\repositories\database_repository::get_export_sql
     */
    public function test_export_all_does_not_skip_rows_when_rows_are_deleted() {
        global $DB;

        SettingsHelper::set_setting('divideexportbydatatype', 0);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));

        // Log retention removes an already exported row while the export runs.
        $exportservice->afterfirstpage = function () use ($DB, $ids) {
            $DB->delete_records('logstore_standard_log', ['id' => $ids[0]]);
        };

        $this->export($exportservice, []);

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * A migration-mode full export of a rewritable datatype keeps the file of every page.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_all
     * @covers \local_intellidata\services\export_service::get_storage_name
     */
    public function test_export_all_in_migration_mode_keeps_every_page() {
        if (!test_helper::is_get_local_path_from_storedfile_callable()) {
            return $this->assertTrue(true, 'Skipping test_export_all_in_migration_mode_keeps_every_page test.');
        }

        SettingsHelper::set_setting('divideexportbydatatype', 0);
        $ids = $this->create_log_rows(self::ROWS);
        $datatype = $this->get_datatype(true, false);

        $exportservice = new export_service(ParamsHelper::MIGRATION_MODE_ENABLED, true, false);
        $exportservice->datatypes[self::DATATYPE] = $datatype;

        $this->export($exportservice, []);

        $migrationname = $exportservice->get_migration_name($datatype);
        $files = $exportservice->get_files(['datatype' => self::DATATYPE]);
        $this->assertCount((int)ceil(self::ROWS / self::LIMIT), $files[$migrationname]);

        // Every row of every page is in the files.
        $data = StorageHelper::get_data_from_exportfiles(
            $migrationname,
            (new file_storage_repository($datatype))->storagefolder,
            $files[$migrationname]
        );
        $this->assertCount(count($ids), $data);
    }

    /**
     * A failed full export does not leave its export step in the cache.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_all
     */
    public function test_export_all_clears_the_step_when_it_fails() {
        SettingsHelper::set_setting('divideexportbydatatype', 0);
        $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));
        $exportservice->failonpage = 2;

        try {
            $this->export($exportservice, []);
            $this->fail('The simulated export failure was not raised.');
        } catch (\moodle_exception $e) {
            $this->assertEquals(0, DatatypesHelper::get_datatype_export_step(self::DATATYPE));
        }
    }

    /**
     * Chunked export of a filterbyid datatype exports every row exactly once.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_export_chunk_with_filterbyid_exports_every_row_once() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(false, true));

        for ($i = 0; $i < 5; $i++) {
            $this->export($exportservice, ['cronprocessing' => true, 'nextexporttable' => '']);
        }

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * Chained adhoc export of a filterbyid datatype exports every row exactly once.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_export_adhoc_with_filterbyid_exports_every_row_once() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(false, true));

        $this->run_adhoc_chain($exportservice, ['cronprocessing' => true, 'adhoctask' => true]);

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * Chained adhoc export of a rewritable datatype exports every row exactly once.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_export_adhoc_rewritable_exports_every_row_once() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));

        $this->run_adhoc_chain($exportservice, ['cronprocessing' => true, 'adhoctask' => true]);

        $this->assertEquals($ids, $exportservice->exportedids);
    }

    /**
     * An adhoc task queued by the OFFSET code restarts its datatype from the first row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_export_adhoc_restarts_a_task_queued_before_keyset_paging() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));

        // The old code stored an id from an unordered page.
        (new export_log_repository())->save_last_processed_data(self::DATATYPE, (object)['id' => $ids[15]]);

        $this->export($exportservice, ['cronprocessing' => true, 'adhoctask' => true, 'limit' => self::LIMIT]);

        $this->assertEquals(array_slice($ids, 0, self::LIMIT), $exportservice->exportedids);
    }

    /**
     * A chained adhoc task queued by the keyset code resumes after the last exported id.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_export_adhoc_resumes_a_keyset_task() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));

        $this->export($exportservice, ['cronprocessing' => true, 'adhoctask' => true]);
        $task = $this->pop_export_adhoc_task();

        $this->assertNotNull($task);
        $this->assertNotEmpty($task->keyset);

        $exportservice->exportedids = [];
        $this->export($exportservice, [
            'cronprocessing' => true,
            'adhoctask' => true,
            'limit' => $task->limit,
            'keyset' => true,
        ]);

        $this->assertEquals(array_slice($ids, self::LIMIT, self::LIMIT), $exportservice->exportedids);
    }

    /**
     * A chunked run started by the OFFSET code restarts its datatype from the first row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_export_chunk_restarts_a_run_started_before_keyset_paging() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));

        // The old code stored an offset and an id from an unordered page, and no marker.
        SettingsHelper::set_setting('exportdatatype', self::DATATYPE);
        SettingsHelper::set_setting('exportstart', self::LIMIT);
        (new export_log_repository())->save_last_processed_data(self::DATATYPE, (object)['id' => $ids[15]]);

        $this->export($exportservice, ['cronprocessing' => true, 'nextexporttable' => '']);

        $this->assertEquals(array_slice($ids, 0, self::LIMIT), $exportservice->exportedids);
    }

    /**
     * A chunked keyset run resumes after the last exported id.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_export_chunk_resumes_a_keyset_run() {
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $ids = $this->create_log_rows(self::ROWS);
        $exportservice = $this->get_capturing_service($this->get_datatype(true, false));

        $this->export($exportservice, ['cronprocessing' => true, 'nextexporttable' => '']);
        $this->assertEquals(self::DATATYPE, SettingsHelper::get_setting('exportkeyset'));
        $this->assertEquals(self::LIMIT, (int)SettingsHelper::get_setting('exportstart'));

        $exportservice->exportedids = [];
        $this->export($exportservice, ['cronprocessing' => true, 'nextexporttable' => '']);

        $this->assertEquals(array_slice($ids, self::LIMIT, self::LIMIT), $exportservice->exportedids);
    }

    /**
     * A time-filtered export keeps the previous query: no primary key cursor and no ORDER BY.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::use_keyset_paging
     * @covers \local_intellidata\repositories\database_repository::get_export_sql
     */
    public function test_time_filtered_export_keeps_offset_paging() {
        $datatype = $this->get_datatype(false, false);
        $datatype['timemodified_field'] = 'timecreated';
        (new export_log_repository())->save_last_processed_data(self::DATATYPE, new \stdClass(), time());
        database_repository::init();

        $keyset = database_repository::use_keyset_paging($datatype);
        $this->assertFalse($keyset);

        // Build the query the way export_all() does.
        [$sql, $params] = database_repository::get_export_sql($datatype, $keyset ? 0 : null);
        $this->assertStringNotContainsString('ORDER BY', $sql);
        $this->assertArrayNotHasKey('keysetcursor', $params);
        $this->assertArrayHasKey('timemodified', $params);
    }

    /**
     * A table whose id is not an integer keeps OFFSET paging.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::use_keyset_paging
     */
    public function test_table_with_non_integer_id_keeps_offset_paging() {
        global $DB;

        $table = new \xmldb_table('local_intellidata_charid');
        $table->add_field('id', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '20', null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_temp_table($table);

        $datatype = $this->get_datatype(true, false);
        $datatype['table'] = 'local_intellidata_charid';
        database_repository::init();

        $this->assertFalse(database_repository::use_keyset_paging($datatype));
        $this->assertTrue(database_repository::use_keyset_paging($this->get_datatype(true, false)));
    }

    /**
     * The log table is never synced for deleted ids, even when its config would allow it.
     *
     * @return void
     * @covers \local_intellidata\services\database_service::export
     */
    public function test_logstore_is_excluded_from_deleted_ids_sync() {
        SettingsHelper::set_setting('divideexportbydatatype', 0);
        SettingsHelper::set_setting('newtracking', 0);
        SettingsHelper::set_setting('exportids', 1);
        $this->create_log_rows(self::ROWS);

        $datatype = $this->get_datatype(false, true);
        $datatype['exportids'] = true;
        $exportservice = $this->get_capturing_service($datatype);

        $databaseservice = new class (false, $this->get_services($exportservice)) extends database_service {
            /** @var bool */
            public $exportidscalled = false;

            /**
             * Replace the repository with one that records export_ids() calls.
             *
             * @param bool $showlogs
             * @param array $services
             */
            public function __construct($showlogs, $services) {
                parent::__construct($showlogs, $services);
                $service = $this;
                $this->repo = new class ($service) extends database_repository {
                    /** @var database_service */
                    private $service;

                    /**
                     * Constructor.
                     *
                     * @param database_service $service
                     */
                    public function __construct($service) {
                        $this->service = $service;
                    }

                    /**
                     * Record the call.
                     *
                     * @param array $datatype
                     * @param bool $showlogs
                     */
                    public function export_ids($datatype, $showlogs = true) {
                        $this->service->exportidscalled = true;
                    }
                };
            }
        };

        ob_start();
        $databaseservice->export($datatype, []);
        ob_end_clean();

        $this->assertFalse($databaseservice->exportidscalled);
    }

    /**
     * Run a chained adhoc export the way export_adhoc_task does, without the cron.
     *
     * @param capturing_export_service $exportservice
     * @param array $params
     * @return void
     */
    private function run_adhoc_chain($exportservice, $params) {
        for ($i = 0; $i < 5; $i++) {
            $this->export($exportservice, $params);

            $task = $this->pop_export_adhoc_task();
            if (!$task) {
                return;
            }
            $params['limit'] = $task->limit;
            $params['keyset'] = !empty($task->keyset);
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
     * Export the test datatype.
     *
     * @param export_service $exportservice
     * @param array $params
     * @return void
     */
    private function export($exportservice, $params) {
        ob_start();
        try {
            database_repository::export(
                $exportservice->datatypes[self::DATATYPE],
                $params,
                false,
                $this->get_services($exportservice)
            );
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Services for the repository.
     *
     * @param export_service $exportservice
     * @return array
     */
    private function get_services($exportservice) {
        return [
            'encryptionservice' => new encryption_service(),
            'exportservice' => $exportservice,
            'exportlogrepository' => new export_log_repository(),
        ];
    }

    /**
     * Export service that keeps the exported ids.
     *
     * @param array $datatype
     * @return capturing_export_service
     */
    private function get_capturing_service($datatype) {
        $exportservice = new capturing_export_service(ParamsHelper::MIGRATION_MODE_DISABLED, true, false);
        $exportservice->datatypes[self::DATATYPE] = $datatype;

        return $exportservice;
    }

    /**
     * Optional datatype for the log table.
     *
     * @param bool $rewritable
     * @param bool $filterbyid
     * @return array
     */
    private function get_datatype($rewritable, $filterbyid) {
        (new export_log_repository())->insert_datatype(self::DATATYPE);

        return [
            'name' => self::DATATYPE,
            'tabletype' => datatypeconfig::TABLETYPE_OPTIONAL,
            'table' => 'logstore_standard_log',
            'migration' => false,
            'entity' => false,
            'observer' => false,
            'timemodified_field' => false,
            'filterbyid' => $filterbyid,
            'rewritable' => $rewritable,
            'databaseexport' => true,
            'exportids' => false,
        ];
    }

    /**
     * Create log rows.
     *
     * @param int $count
     * @return int[] Ids in ascending order.
     */
    private function create_log_rows($count) {
        global $DB;

        $DB->delete_records('logstore_standard_log');

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int)$DB->insert_record('logstore_standard_log', (object)[
                'eventname' => '\core\event\course_viewed',
                'component' => 'core',
                'action' => 'viewed',
                'target' => 'course',
                'crud' => 'r',
                'edulevel' => 2,
                'contextid' => 1,
                'contextlevel' => CONTEXT_SYSTEM,
                'contextinstanceid' => 0,
                'userid' => 2,
                'anonymous' => 0,
                'timecreated' => time(),
            ]);
        }

        return $ids;
    }
}
