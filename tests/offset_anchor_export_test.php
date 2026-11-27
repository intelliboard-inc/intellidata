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
 * OFFSET paging of datatypes that filter by id.
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
use local_intellidata\task\export_adhoc_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/generator.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/fixtures/capturing_export_service.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * OFFSET paging of datatypes that filter by id.
 *
 * An OFFSET page counts its rows from the run's first row, so the id filter of a paged run must
 * not move during the run. quizquestionrelations (Moodle 4.0+) and survey page by OFFSET and
 * filter by id.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026 intelliboard.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class offset_anchor_export_test extends \advanced_testcase {
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
     * Chunked export of quiz question relations exports every row once.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_quizquestionrelations_chunks_export_every_row_once(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        $this->run_chunks($exportservice, 'quizquestionrelations');

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
        $this->assertSame('', SettingsHelper::get_setting('exportanchor'));
    }

    /**
     * Chained adhoc export of quiz question relations, run by the task, exports every row once.
     *
     * The run starts from last exported id 0, so the task must carry an anchor of 0.
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_quizquestionrelations_adhoc_task_chain_exports_every_row_once(): void {
        global $DB;

        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        (new export_log_repository())->insert_datatype('quizquestionrelations');

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => ['quizquestionrelations']]);

        $chained = [];
        ob_start();
        try {
            $task->execute();
            for ($guard = 0; $guard < 10; $guard++) {
                $queued = \core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task');
                if (!$queued) {
                    break;
                }
                foreach ($queued as $next) {
                    $chained[] = $next->get_custom_data();
                    $DB->delete_records('task_adhoc', ['id' => $next->get_id()]);
                    $next->execute();
                }
            }
        } finally {
            ob_end_clean();
        }

        $this->assertEmpty(
            \core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task'),
            'The adhoc export chain did not finish.'
        );
        $this->assertEquals($expected, $this->exported_relations($this->read_migration_files('quizquestionrelations')));

        $this->assertNotEmpty($chained);
        foreach ($chained as $customdata) {
            $this->assertSame(0, $customdata->anchorid);
        }
    }

    /**
     * An incremental adhoc chain exports every new row and no row of the earlier run.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_quizquestionrelations_incremental_adhoc_chain_exports_only_new_rows(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $first = $this->create_quiz_slots(4, 4, 1);
        $exportservice = $this->get_capturing_service('quizquestionrelations');
        $this->run_adhoc_chain($exportservice, 'quizquestionrelations');
        $this->assertEquals($first, $this->exported_relations($exportservice->exportedrows));

        $new = $this->create_quiz_slots(9, 10, 3);
        $exportservice->exportedrows = [];
        $this->run_adhoc_chain($exportservice, 'quizquestionrelations');

        $this->assertEquals($new, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * An adhoc chain keeps its anchor when another export moves the last exported id.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_quizquestionrelations_adhoc_chain_ignores_another_export(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        $this->export($exportservice, 'quizquestionrelations', ['cronprocessing' => true, 'adhoctask' => true]);
        $task = $this->pop_export_adhoc_task();
        $this->assertNotNull($task);

        // Another export of the datatype moves the shared pointer meanwhile.
        (new export_log_repository())->save_last_processed_data(
            'quizquestionrelations',
            (object)['id' => $this->max_slot_id()]
        );

        $this->run_adhoc_chain($exportservice, 'quizquestionrelations', $this->task_params($task));

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * export_all keeps its anchor when another export moves the last exported id.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_all
     */
    public function test_quizquestionrelations_export_all_ignores_another_export(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 0);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        $maxid = $this->max_slot_id();
        $exportservice->afterfirstpage = function () use ($maxid) {
            (new export_log_repository())->save_last_processed_data('quizquestionrelations', (object)['id' => $maxid]);
        };

        $this->export($exportservice, 'quizquestionrelations', []);

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * An adhoc task queued without an anchor restarts its run from the first row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_adhoc_task_queued_without_anchor_restarts(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        // A task queued by 1.301.0: OFFSET start, no anchor.
        $this->run_adhoc_chain(
            $exportservice,
            'quizquestionrelations',
            ['limit' => self::LIMIT, 'keyset' => false, 'cursor' => 0]
        );

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A reset run queued without an anchor restarts from id 0, below the id its old pages saved.
     *
     * The old pages moved the last exported id past rows they skipped; the datatype is not
     * migrated yet, so the restarted run exports those rows too.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_adhoc_reset_run_queued_without_anchor_restarts_from_zero(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        // Two pages of a 1.301.0 reset run: the pointer is past rows its second page skipped.
        (new export_log_repository())->save_last_processed_data(
            'quizquestionrelations',
            (object)['id' => $this->slot_ids()[self::LIMIT - 1]]
        );
        $this->run_adhoc_chain(
            $exportservice,
            'quizquestionrelations',
            ['limit' => 2 * self::LIMIT, 'keyset' => false, 'cursor' => 0]
        );

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A chunked reset run without an anchor restarts from id 0, below the id its old chunks saved.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_chunk_reset_run_without_anchor_restarts_from_zero(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        (new export_log_repository())->save_last_processed_data(
            'quizquestionrelations',
            (object)['id' => $this->slot_ids()[self::LIMIT - 1]]
        );
        SettingsHelper::set_setting('exportstart', 2 * self::LIMIT);
        $this->run_chunks($exportservice, 'quizquestionrelations');

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A first chunk that stopped after it moved the last exported id runs again from its anchor.
     *
     * With 9 fixed slots and 3 slots with both references, the first page ends between the two
     * rows of slot 10: an anchor at the moved id would skip the second row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_chunk_first_chunk_stopped_after_pointer_save_keeps_its_anchor(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 0, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        // The first chunk saved its anchor and moved the pointer, then stopped before it saved the start.
        SettingsHelper::set_setting('exportanchor', 'quizquestionrelations:0');
        (new export_log_repository())->save_last_processed_data(
            'quizquestionrelations',
            (object)['id' => $this->slot_ids()[self::LIMIT - 1]]
        );
        $this->run_chunks($exportservice, 'quizquestionrelations');

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A first chunk after a reset does not use a higher anchor left by an earlier run.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_chunk_first_chunk_ignores_a_higher_stored_anchor(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        // An earlier run stopped with its anchor set; then the datatype was reset to id 0.
        SettingsHelper::set_setting('exportanchor', 'quizquestionrelations:' . $this->max_slot_id());
        $this->run_chunks($exportservice, 'quizquestionrelations');

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * An incremental run of a migrated datatype queued without an anchor also restarts from id 0.
     *
     * Its old pages moved the last exported id past new rows they skipped. The restarted run
     * exports every row again: no new row is lost.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_adhoc_incremental_run_queued_without_anchor_restarts_from_zero(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $old = $this->create_quiz_slots(4, 4, 1);
        $exportlogrepository = new export_log_repository();
        $exportservice = $this->get_capturing_service('quizquestionrelations');
        $exportlogrepository->save_migrated('quizquestionrelations');

        $new = $this->create_quiz_slots(9, 10, 3);
        // Two pages of a 1.301.0 incremental run: the pointer is past new rows its second page skipped.
        $exportlogrepository->save_last_processed_data(
            'quizquestionrelations',
            (object)['id' => $this->slot_ids()[count(array_unique(array_map('intval', $old))) + self::LIMIT - 1]]
        );
        $this->run_adhoc_chain(
            $exportservice,
            'quizquestionrelations',
            ['limit' => 2 * self::LIMIT, 'keyset' => false, 'cursor' => 0]
        );

        $all = array_merge($old, $new);
        sort($all);
        $this->assertEquals($all, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A chunked run with no anchor of its datatype restarts from the first row.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_chunk_without_own_anchor_restarts(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        // A run in progress, with the anchor of another datatype left over.
        SettingsHelper::set_setting('exportanchor', 'survey:999999');
        SettingsHelper::set_setting('exportstart', self::LIMIT);
        $this->run_chunks($exportservice, 'quizquestionrelations');

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A chunked run that started on keyset pages restarts when it falls back to OFFSET pages.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_chunk_started_on_keyset_restarts_with_offset(): void {
        $this->require_question_references();
        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $expected = $this->create_quiz_slots(9, 10, 3);
        $exportservice = $this->get_capturing_service('quizquestionrelations');

        // An anchor of this datatype left by an earlier run, and the keyset marker of this run.
        SettingsHelper::set_setting('exportanchor', 'quizquestionrelations:' . $this->max_slot_id());
        SettingsHelper::set_setting('exportkeyset', 'quizquestionrelations');
        SettingsHelper::set_setting('exportstart', self::LIMIT);
        $this->run_chunks($exportservice, 'quizquestionrelations');

        $this->assertEquals($expected, $this->exported_relations($exportservice->exportedrows));
    }

    /**
     * A datatype on keyset pages queues no anchor.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_adhoc
     */
    public function test_keyset_datatype_has_no_anchor(): void {
        global $DB;

        SettingsHelper::set_setting('divideexportbydatatype', 1);

        $course = generator::create_course();
        $gradeitem = \grade_item::fetch_course_item($course->id);
        for ($i = 0; $i < 13; $i++) {
            $user = generator::create_user();
            generator::enrol_user(['userid' => $user->id, 'courseid' => $course->id]);
            $gradeitem->update_final_grade($user->id, 10 + $i, 'gradebook');
        }
        $this->assertGreaterThan(self::LIMIT, $DB->count_records('grade_grades'));

        $exportservice = $this->get_capturing_service('usergrades');
        $this->export($exportservice, 'usergrades', ['cronprocessing' => true, 'adhoctask' => true]);
        $task = $this->pop_export_adhoc_task();

        $this->assertNotNull($task);
        $this->assertNotEmpty($task->keyset);
        $this->assertFalse(property_exists($task, 'anchorid'));
    }

    /**
     * Chunked export of survey after a time-filtered export keeps the id it started from.
     *
     * @return void
     * @covers \local_intellidata\repositories\database_repository::export_chunk
     */
    public function test_survey_chunks_after_time_filter_export_every_row_once(): void {
        global $DB;

        if (ParamsHelper::compare_release('4.99')) {
            $this->markTestSkipped('The survey datatype exists before Moodle 4.99 only.');
        }

        SettingsHelper::set_setting('divideexportbydatatype', 1);
        $course = generator::create_course();
        $timemodified = time() - DAYSECS;
        for ($i = 0; $i < 25; $i++) {
            $DB->insert_record('survey', (object)[
                'course' => $course->id,
                'template' => 0,
                'timecreated' => $timemodified,
                'timemodified' => $timemodified,
                'name' => 'Survey ' . $i,
                'intro' => '',
                'questions' => '',
            ]);
        }
        $exportservice = $this->get_capturing_service('survey');

        // An earlier export left a time, so survey pages by OFFSET; no row is newer than that time.
        (new export_log_repository())->save_last_processed_data('survey', (object)['id' => 0], time());
        database_repository::init();
        $this->assertFalse(database_repository::use_keyset_paging($exportservice->datatypes['survey']));

        $this->run_chunks($exportservice, 'survey');

        $expected = array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {survey} ORDER BY id'));
        $exported = $exportservice->exportedids;
        sort($exported);
        $this->assertEquals($expected, $exported);
    }

    /**
     * Skip a test when the site has no question references (Moodle before 4.0).
     *
     * @return void
     */
    private function require_question_references() {
        if (!ParamsHelper::compare_release('4.0.0')) {
            $this->markTestSkipped('Quiz slots reference questions from Moodle 4.0.');
        }
    }

    /**
     * Quiz slots with fixed questions, random questions, or both.
     *
     * With 9 fixed, 10 random and 3 slots with both, the two rows of the first slot with both
     * are rows 20 and 21: a page boundary falls between two rows of one slot.
     *
     * @param int $fixed Slots with a question reference.
     * @param int $random Slots with a question set reference.
     * @param int $both Slots with both references.
     * @return string[] Expected "slotid:type" relations, sorted.
     */
    private function create_quiz_slots($fixed, $random, $both) {
        global $DB;

        $contextid = \context_system::instance()->id;
        $categoryid = $DB->insert_record('question_categories', (object)[
            'name' => 'IBV2-2426',
            'contextid' => $contextid,
            'info' => '',
            'stamp' => make_unique_id_code(),
            'parent' => 0,
        ]);
        $quizid = (int)$DB->get_field_sql('SELECT COALESCE(MAX(quizid), 0) + 1 FROM {quiz_slots}');

        $relations = [];
        $total = $fixed + $random + $both;
        for ($slot = 1; $slot <= $total; $slot++) {
            $slotid = $DB->insert_record('quiz_slots', (object)[
                'slot' => $slot,
                'quizid' => $quizid,
                'page' => 1,
                'maxmark' => 1,
            ]);

            if ($slot <= $fixed || $slot > $fixed + $random) {
                $entryid = $DB->insert_record('question_bank_entries', (object)['questioncategoryid' => $categoryid]);
                $DB->insert_record('question_versions', (object)[
                    'questionbankentryid' => $entryid,
                    'questionid' => $slotid,
                    'version' => 1,
                ]);
                $DB->insert_record('question_references', (object)[
                    'usingcontextid' => $contextid,
                    'component' => 'mod_quiz',
                    'questionarea' => 'slot',
                    'itemid' => $slotid,
                    'questionbankentryid' => $entryid,
                ]);
                $relations[] = $slotid . ':q';
            }
            if ($slot > $fixed) {
                $DB->insert_record('question_set_references', (object)[
                    'usingcontextid' => $contextid,
                    'component' => 'mod_quiz',
                    'questionarea' => 'slot',
                    'itemid' => $slotid,
                    'questionscontextid' => $contextid,
                    'filtercondition' => json_encode(['questioncategoryid' => (int)$categoryid]),
                ]);
                $relations[] = $slotid . ':c';
            }
        }

        sort($relations);

        return $relations;
    }

    /**
     * Exported quiz question relations as sorted "slotid:type" strings.
     *
     * @param array $rows Values of the exported rows.
     * @return string[]
     */
    private function exported_relations($rows) {
        $columns = array_keys(\local_intellidata\entities\quizquestionrelations\quizquestionrelation::properties_definition());
        $type = array_search('type', $columns);

        $relations = [];
        foreach ($rows as $values) {
            $this->assertContains($values[$type], ['q', 'c']);
            $relations[] = (int)$values[0] . ':' . $values[$type];
        }
        sort($relations);

        return $relations;
    }

    /**
     * Rows of the migration files of a datatype.
     *
     * @param string $name
     * @return array Values of every row.
     */
    private function read_migration_files($name) {
        global $DB;

        $files = $DB->get_records_select(
            'files',
            "component = 'local_intellidata' AND filearea = :filearea AND filename <> '.'",
            ['filearea' => 'migration_' . $name]
        );

        $rows = [];
        $fs = get_file_storage();
        $encryptionservice = new encryption_service();
        foreach ($files as $filerecord) {
            $encrypted = make_request_directory() . '/encrypted';
            $zippath = make_request_directory() . '/file.zip';
            file_put_contents($encrypted, $fs->get_file_by_id($filerecord->id)->get_content());
            $encryptionservice->decrypt_file($encrypted, $zippath);

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($zippath));
            for ($i = 0; $i < $zip->numFiles; $i++) {
                foreach (array_filter(explode("\n", trim($zip->getFromIndex($i)))) as $line) {
                    $rows[] = str_getcsv($line, ',', '"', '\\');
                }
            }
            $zip->close();
        }

        return $rows;
    }

    /**
     * Quiz slot ids, ascending.
     *
     * @return int[]
     */
    private function slot_ids() {
        global $DB;

        return array_map('intval', $DB->get_fieldset_sql('SELECT id FROM {quiz_slots} ORDER BY id'));
    }

    /**
     * Largest quiz slot id.
     *
     * @return int
     */
    private function max_slot_id() {
        global $DB;

        return (int)$DB->get_field_sql('SELECT MAX(id) FROM {quiz_slots}');
    }

    /**
     * Run chunked exports of a datatype until its run completes.
     *
     * @param capturing_export_service $exportservice
     * @param string $name
     * @return void
     */
    private function run_chunks($exportservice, $name) {
        for ($i = 0; $i < 10; $i++) {
            $this->export($exportservice, $name, ['cronprocessing' => true, 'nextexporttable' => '']);
            if (!(int)SettingsHelper::get_setting('exportstart')) {
                return;
            }
        }

        $this->fail('The chunked export did not finish.');
    }

    /**
     * Run a chained adhoc export the way export_adhoc_task does, without the cron.
     *
     * @param capturing_export_service $exportservice
     * @param string $name
     * @param array $taskparams Params of the first task.
     * @return void
     */
    private function run_adhoc_chain($exportservice, $name, $taskparams = []) {
        for ($i = 0; $i < 10; $i++) {
            $this->export($exportservice, $name, ['cronprocessing' => true, 'adhoctask' => true] + $taskparams);

            $task = $this->pop_export_adhoc_task();
            if (!$task) {
                return;
            }
            $taskparams = $this->task_params($task);
        }

        $this->fail('The adhoc export chain did not finish.');
    }

    /**
     * Export params of a queued task, as export_adhoc_task passes them.
     *
     * @param \stdClass $task Custom data of the task.
     * @return array
     */
    private function task_params($task) {
        return [
            'limit' => (int)$task->limit,
            'keyset' => !empty($task->keyset),
            'cursor' => !empty($task->cursor) ? (int)$task->cursor : 0,
            'anchorid' => isset($task->anchorid) ? (int)$task->anchorid : null,
        ];
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
     * Export service that keeps the exported rows.
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
}
