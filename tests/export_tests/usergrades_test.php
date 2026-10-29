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
 * User Grade migration test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2021
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata\export_tests;

use grade_category;
use grade_grade;
use local_intellidata\custom_db_client_testcase;
use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\helpers\StorageHelper;
use local_intellidata\generator;
use local_intellidata\setup_helper;
use local_intellidata\test_helper;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/generator.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/local/intellidata/tests/custom_db_client_testcase.php');

/**
 * User Grade migration test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2021
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or late
 */
class usergrades_test extends custom_db_client_testcase {

    /**
     * Test graded.
     *
     * @covers \local_intellidata\entities\usergrades\usergrade
     * @covers \local_intellidata\entities\usergrades\migration
     * @covers \local_intellidata\entities\usergrades\observer
     */
    public function test_graded() {
        if (test_helper::is_new_phpunit()) {
            $this->resetAfterTest(true);
        }

        if ($this->newexportavailable) {
            SettingsHelper::set_setting('newtracking', 1);
            $this->graded_test(1);
            SettingsHelper::set_setting('newtracking', 0);
        }

        $this->graded_test(0);
    }

    /**
     * Graded test.
     *
     * @param int $tracking
     *
     * @return void
     * @throws \invalid_parameter_exception
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    private function graded_test($tracking) {
        $userdata = [
            'firstname' => 'unit test grade user',
            'username' => 'unittest_grade_user' . $tracking,
            'password' => 'Unittest_GradeUser1!',
        ];

        // Create User.
        $user = generator::create_user($userdata);

        // Create Course.
        $course = generator::create_course(['shortname' => 'ibusergradecourse' . $tracking]);

        $data = [
            'userid' => $user->id,
            'courseid' => $course->id,
        ];

        // Enrol User.
        generator::enrol_user($data);

        $gradecategory = grade_category::fetch_course_category($course->id);
        $gradecategory->load_grade_item();

        $gradeitem = $gradecategory->grade_item;
        $gradeitem->update_final_grade($user->id, 10, 'gradebook');

        $gradegrade = new grade_grade(['userid' => $user->id, 'itemid' => $gradeitem->id], true);
        $gradegrade->grade_item = $gradeitem;

        \core\event\user_graded::create_from_grade($gradegrade);

        unset($data['courseid']);

        $entity = new \local_intellidata\entities\usergrades\usergrade($gradegrade);
        $entitydata = $entity->export();
        $entitydata = test_helper::filter_fields($entitydata, $data);

        $storage = StorageHelper::get_storage_service(['name' => 'usergrades']);
        $datarecord = $storage->get_log_entity_data($tracking == 1 ? 'c' : 'u', $data);

        $datarecorddata = test_helper::filter_fields(json_decode($datarecord->data), $data);

        $this->assertNotEmpty($datarecord);
        $this->assertEquals($entitydata, $datarecorddata);
    }

    /**
     * A deleted grade is exported as a delete when new tracking is off.
     *
     * Before IBV2-2402 nothing observed grade_deleted, and the hourly DB export cannot see
     * a row that is gone, so these deletes never left the site.
     *
     * @covers \local_intellidata\entities\usergrades\observer::grade_deleted
     */
    public function test_grade_deleted_without_new_tracking() {
        $this->resetAfterTest(true);

        SettingsHelper::set_setting('newtracking', 0);

        foreach ([1, 0] as $eventstracking) {
            SettingsHelper::set_setting('eventstracking', $eventstracking);

            $gradegrade = $this->create_grade('deleted' . $eventstracking);
            $since = $this->last_storage_id();

            $gradegrade->delete('test');

            $this->assertSame(
                [(int)$gradegrade->id],
                $this->exported_delete_ids_since($since),
                "eventstracking={$eventstracking}"
            );
        }
    }

    /**
     * With new tracking on, the grade_deleted export only adds copies of the same id.
     *
     * The custom DB driver already exports this delete; the observer must not export any
     * other id. Duplicate deletes of one id are idempotent downstream.
     *
     * @covers \local_intellidata\entities\usergrades\observer::grade_deleted
     */
    public function test_grade_deleted_with_new_tracking() {
        $this->resetAfterTest(true);

        if (!$this->newexportavailable) {
            $this->markTestSkipped('The custom DB driver needs Moodle 3.8 or later.');
        }

        SettingsHelper::set_setting('newtracking', 1);

        $gradegrade = $this->create_grade('deletednt');
        $since = $this->last_storage_id();

        $gradegrade->delete('test');

        $ids = $this->exported_delete_ids_since($since);
        $this->assertNotEmpty($ids);
        $this->assertSame([(int)$gradegrade->id], array_values(array_unique($ids)));
    }

    /**
     * A grade delete rolled back with its transaction is not exported.
     *
     * Runs on the default cache storage: database storage is itself rolled back with the
     * transaction, so it would pass even if the observer ran inside it.
     *
     * @covers \local_intellidata\entities\usergrades\observer::grade_deleted
     */
    public function test_grade_deleted_rolled_back_is_not_exported() {
        global $DB;

        $this->resetAfterTest(true);

        SettingsHelper::set_setting('newtracking', 0);
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);

        $rolledback = $this->create_grade('rolledback');
        $committed = $this->create_grade('committed');

        $transaction = $DB->start_delegated_transaction();
        $rolledback->delete('test');
        try {
            $transaction->rollback(new \moodle_exception('rollback'));
        } catch (\moodle_exception $e) {
            $this->assertSame('rollback', $e->errorcode);
        }

        $committed->delete('test');

        $ids = $this->cached_delete_ids();
        $this->assertContains((int)$committed->id, $ids);
        $this->assertNotContains((int)$rolledback->id, $ids);
    }

    /**
     * An enrolled user with a course total grade, the grade item loaded so delete() fires grade_deleted.
     *
     * @param string $suffix
     * @return grade_grade
     */
    private function create_grade(string $suffix) {
        $user = generator::create_user(['username' => 'unittest_grade_' . $suffix]);
        $course = generator::create_course(['shortname' => 'ibgrade' . $suffix]);
        generator::enrol_user(['userid' => $user->id, 'courseid' => $course->id]);

        $gradecategory = grade_category::fetch_course_category($course->id);
        $gradecategory->load_grade_item();
        $gradeitem = $gradecategory->grade_item;
        $gradeitem->update_final_grade($user->id, 10, 'gradebook');

        $gradegrade = grade_grade::fetch(['userid' => $user->id, 'itemid' => $gradeitem->id]);
        $gradegrade->grade_item = $gradeitem;

        return $gradegrade;
    }

    /**
     * Highest database storage record id so far.
     *
     * @return int
     */
    private function last_storage_id(): int {
        global $DB;

        return (int)$DB->get_field_sql('SELECT MAX(id) FROM {local_intellidata_storage}');
    }

    /**
     * Ids of usergrades deletes stored after a storage record id, sorted, duplicates kept.
     *
     * @param int $since
     * @return int[]
     */
    private function exported_delete_ids_since(int $since): array {
        global $DB;

        $records = $DB->get_records_select(
            'local_intellidata_storage',
            'datatype = :datatype AND id > :since',
            ['datatype' => 'usergrades', 'since' => $since]
        );

        $ids = [];
        foreach ($records as $record) {
            $data = json_decode($record->data);
            if (($data->crud ?? null) === 'd') {
                $ids[] = (int)$data->id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Ids of usergrades deletes held in the cache storage.
     *
     * @return int[]
     */
    private function cached_delete_ids(): array {
        $repository = new \local_intellidata\repositories\cache_storage_repository(['name' => 'usergrades']);

        $call = function (string $method, ...$args) use ($repository) {
            $reflection = new \ReflectionMethod($repository, $method);
            $reflection->setAccessible(true);
            return $reflection->invoke($repository, ...$args);
        };

        $cache = $call('get_cache_storage');
        $ids = [];
        foreach ((array)$call('get_record', $cache, $call('cache_key')) as $data) {
            $data = is_string($data) ? json_decode($data) : (object)$data;
            if (($data->crud ?? null) === 'd') {
                $ids[] = (int)$data->id;
            }
        }

        return $ids;
    }

    /**
     * An ungraded item must export exactly as a zero, not as a NULL.
     *
     * IBV2-943 pins the coercion at usergrade::prepare_export_data() (the
     * `$object->finalgrade ?? 0`). Moodle materialises a grade_grades row at
     * enrolment/regrade long before anything is graded, so this path covers a large
     * share of every site's grade rows. IntelliStream was changed to match this
     * behaviour; if the coercion is ever dropped here the two stacks diverge again
     * and grade-gated customer reports silently lose whole courses.
     *
     * Asserted as an equivalence against a real zero rather than against literal
     * "0.00" so the test does not depend on site decimal/letter configuration.
     *
     * @covers \local_intellidata\entities\usergrades\usergrade::prepare_export_data
     */
    public function test_ungraded_item_exports_as_zero() {
        $this->resetAfterTest(true);

        $fixture = $this->ungraded_fixture();

        $ungraded = $this->grade_row($fixture, null, 0, 100);
        $zero = $this->grade_row($fixture, 0, 0, 100);

        $ungradedexport = \local_intellidata\entities\usergrades\usergrade::prepare_export_data($ungraded);
        $zeroexport = \local_intellidata\entities\usergrades\usergrade::prepare_export_data($zero);

        $this->assertSame($zeroexport->score, $ungradedexport->score);
        $this->assertSame($zeroexport->letter, $ungradedexport->letter);
        $this->assertSame($zeroexport->point, $ungradedexport->point);

        // And they are genuinely populated zeros, not a pair of matching NULLs.
        $this->assertNotNull($ungradedexport->score);
        $this->assertNotNull($ungradedexport->point);
        $this->assertEquals(0, (float) $ungradedexport->score);
        $this->assertEquals(0, (float) $ungradedexport->point);
    }

    /**
     * A zero-width grade band yields no percentage but still yields a point.
     *
     * IBV2-943: core's percentage formatter returns '' when grademax == grademin
     * (a weightless category), while the REAL formatter still returns a value. That
     * asymmetry is why a healthy IntelliData warehouse shows 100% grade_point
     * coverage against ~97% grade_score, and IntelliStream reproduces it.
     *
     * @covers \local_intellidata\entities\usergrades\usergrade::prepare_export_data
     */
    public function test_ungraded_item_on_zero_width_band_has_no_percentage() {
        $this->resetAfterTest(true);

        $export = \local_intellidata\entities\usergrades\usergrade::prepare_export_data(
            $this->grade_row($this->ungraded_fixture(), null, 0, 0)
        );

        $this->assertSame('', $export->score);
        $this->assertNotSame('', $export->point);
        $this->assertEquals(0, (float) $export->point);
    }

    /**
     * An enrolled user and the course grade item to hang the ungraded rows off.
     *
     * @return \stdClass with ->gradeitem and ->userid
     */
    private function ungraded_fixture() {
        $user = generator::create_user([
            'firstname' => 'unit test ungraded user',
            'username' => 'unittest_ungraded_user',
            'password' => 'Unittest_GradeUser1!',
        ]);
        $course = generator::create_course(['shortname' => 'ibungradedcourse']);
        generator::enrol_user(['userid' => $user->id, 'courseid' => $course->id]);

        $gradecategory = grade_category::fetch_course_category($course->id);
        $gradecategory->load_grade_item();

        return (object) ['gradeitem' => $gradecategory->grade_item, 'userid' => $user->id];
    }

    /**
     * A grade_grades-shaped row, as prepare_export_data() receives it.
     *
     * rawgrademin/rawgrademax are set explicitly because prepare_export_data()
     * prefers them over the item's own band, which is what makes the zero-width
     * case above expressible without mutating the gradebook.
     *
     * @param \stdClass $fixture
     * @param float|null $finalgrade
     * @param float $rawgrademin
     * @param float $rawgrademax
     *
     * @return \stdClass
     */
    private function grade_row($fixture, $finalgrade, $rawgrademin, $rawgrademax) {
        return (object) [
            'id' => 1,
            'itemid' => $fixture->gradeitem->id,
            'userid' => $fixture->userid,
            'finalgrade' => $finalgrade,
            'rawgrademin' => $rawgrademin,
            'rawgrademax' => $rawgrademax,
            'feedback' => '',
            'hidden' => 0,
            'timemodified' => 1700000000,
            'usermodified' => null,
        ];
    }
}
