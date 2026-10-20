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
 * Export adhoc task test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\repositories\export_log_repository;
use local_intellidata\services\datatypes_service;
use local_intellidata\services\encryption_service;
use local_intellidata\services\export_service;
use local_intellidata\task\export_adhoc_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');

/**
 * Export adhoc task test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_adhoc_task_test extends \advanced_testcase {

    public function setUp(): void {
        $this->setAdminUser();

        setup_helper::setup_tests_config();
    }

    /**
     * An optional datatype added after the datatypes cache was built must still be exported to a data file (IBV2-2298).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_optional_datatype_added_after_cache_is_built_gets_a_file() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        \core_tag_tag::set_item_tags('core', 'course', $course->id, \context_course::instance($course->id), ['ibv2', 'repro']);

        // Warm the datatypes cache before the datatype exists, as on a live site.
        datatypes_service::get_datatypes(true, true);

        // What exportlib::set_datatype does when IntelliBoard enables a native table.
        $datatype = datatypes_service::generate_optional_datatype('tag_instance');
        (new export_log_repository())->insert_datatype($datatype);

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => [$datatype]]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $files = (new export_service())->get_files(['timestart' => 0]);

        $this->assertArrayHasKey('migration_' . $datatype, $files);
        $this->assertNotEmpty($files['migration_' . $datatype]);
    }

    /**
     * The same datatype exported in chunks (divideexportbydatatype) gets one file per chunk holding every row once (IBV2-2298).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_optional_datatype_added_after_cache_is_built_gets_a_file_per_chunk() {
        global $DB;

        $this->resetAfterTest(true);

        for ($i = 1; $i <= 3; $i++) {
            $course = $this->getDataGenerator()->create_course();
            \core_tag_tag::set_item_tags('core', 'course', $course->id, \context_course::instance($course->id),
                ["ibv2-$i-a", "ibv2-$i-b", 'shared']);
        }
        $expectedids = array_keys($DB->get_records('tag_instance', null, 'id', 'id'));
        $this->assertCount(9, $expectedids);

        // Three chunks of at most 4 rows.
        set_config('divideexportbydatatype', 1, 'local_intellidata');
        set_config('exportrecordslimit', 4, 'local_intellidata');

        datatypes_service::get_datatypes(true, true);

        $datatype = datatypes_service::generate_optional_datatype('tag_instance');
        (new export_log_repository())->insert_datatype($datatype);

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => [$datatype]]);

        ob_start();
        $task->execute();
        // Each chunk queues the next one; run the chain to the end.
        for ($guard = 0; $guard < 10; $guard++) {
            $queued = \core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task');
            if (!$queued) {
                break;
            }
            foreach ($queued as $next) {
                $DB->delete_records('task_adhoc', ['id' => $next->get_id()]);
                $next->execute();
            }
        }
        ob_end_clean();

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task'));

        $files = $DB->get_records_select(
            'files',
            "component = 'local_intellidata' AND filearea = :filearea AND filename <> '.'",
            ['filearea' => 'migration_' . $datatype]
        );
        $this->assertCount(3, $files);

        $exportedids = [];
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
                    $exportedids[] = (int)explode(',', $line)[0];
                }
            }
            $zip->close();
        }

        sort($exportedids);
        $this->assertSame(array_map('intval', $expectedids), $exportedids);
    }
}
