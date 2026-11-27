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

use local_intellidata\persistent\datatypeconfig;
use local_intellidata\repositories\config_repository;
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

    /**
     * A chunked export carries the callback URL down the chain and POSTs it once, after the last chunk (IBV2-2235).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_chunked_export_posts_callback_after_last_chunk() {
        $this->resetAfterTest(true);

        $datatype = $this->setup_chunked_tag_instance_export();
        $callbackurl = 'https://callback.invalid/api/moodle/callback/1/datatype-export';

        // One spare response, so a second POST would show as a second consumed mock.
        \curl::mock_response('ok');
        \curl::mock_response('ok');

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => [$datatype], 'callbackurl' => $callbackurl]);

        [$mockcounts, $chained] = $this->run_export_chain($task);

        // 9 rows, 4 per chunk: the parent task plus two chained chunks.
        $this->assertCount(3, $mockcounts);
        $this->assertCount(2, $chained);
        foreach ($chained as $customdata) {
            $this->assertSame($callbackurl, $customdata->callbackurl);
        }

        // Nothing is sent before the last chunk, then exactly one POST.
        $this->assertSame([2, 2, 1], $mockcounts);
    }

    /**
     * A chunked export of two datatypes POSTs the callback once per datatype (IBV2-2235).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_chunked_export_posts_callback_once_per_datatype() {
        $this->resetAfterTest(true);

        $taginstancedatatype = $this->setup_chunked_tag_instance_export();
        $tagsdatatype = datatypes_service::generate_optional_datatype('tag');
        (new export_log_repository())->insert_datatype($tagsdatatype);

        \curl::mock_response('ok');
        \curl::mock_response('ok');
        \curl::mock_response('ok');

        $task = new export_adhoc_task();
        $task->set_custom_data([
            'datatypes' => [$taginstancedatatype, $tagsdatatype],
            'callbackurl' => 'https://callback.invalid/api/moodle/callback/1/datatype-export',
        ]);

        [$mockcounts] = $this->run_export_chain($task);

        $this->assertSame(1, end($mockcounts));
    }

    /**
     * A chunked export queued without a callback URL sends nothing (IBV2-2235).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_chunked_export_without_callback_posts_nothing() {
        $this->resetAfterTest(true);

        $datatype = $this->setup_chunked_tag_instance_export();

        \curl::mock_response('ok');

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => [$datatype]]);

        [$mockcounts, $chained] = $this->run_export_chain($task);

        $this->assertSame([1, 1, 1], $mockcounts);
        foreach ($chained as $customdata) {
            $this->assertEmpty($customdata->callbackurl);
        }
    }

    /**
     * Create 9 tag_instance rows and export them in chunks of 4.
     *
     * @return string The tag_instance datatype name.
     */
    private function setup_chunked_tag_instance_export() {
        for ($i = 1; $i <= 3; $i++) {
            $course = $this->getDataGenerator()->create_course();
            \core_tag_tag::set_item_tags(
                'core',
                'course',
                $course->id,
                \context_course::instance($course->id),
                ["ibv2-$i-a", "ibv2-$i-b", 'shared']
            );
        }

        set_config('divideexportbydatatype', 1, 'local_intellidata');
        set_config('exportrecordslimit', 4, 'local_intellidata');

        datatypes_service::get_datatypes(true, true);

        $datatype = datatypes_service::generate_optional_datatype('tag_instance');
        (new export_log_repository())->insert_datatype($datatype);

        return $datatype;
    }

    /**
     * Run an export task and every task it queues.
     *
     * @param export_adhoc_task $task
     * @return array Unused mocked curl responses after each export task, and the custom data of each chained chunk.
     */
    private function run_export_chain(export_adhoc_task $task) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');

        $mockresponses = new \ReflectionProperty(\curl::class, 'mockresponses');
        if (PHP_VERSION_ID < 80100) {
            $mockresponses->setAccessible(true);
        }

        $mockcounts = [];
        $chained = [];
        $pending = [$task];

        ob_start();
        for ($guard = 0; $pending && $guard < 20; $guard++) {
            $current = array_shift($pending);
            $current->execute();

            $queued = \core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task');
            foreach ($queued as $next) {
                $DB->delete_records('task_adhoc', ['id' => $next->get_id()]);
                $pending[] = $next;
            }

            // A task that only splits the datatypes exports nothing; count the export tasks.
            if (count($current->get_custom_data()->datatypes) == 1) {
                $mockcounts[] = count($mockresponses->getValue());
                if (isset($current->get_custom_data()->limit)) {
                    $chained[] = $current->get_custom_data();
                }
            }
        }
        ob_end_clean();

        $this->assertEmpty($pending);

        return [$mockcounts, $chained];
    }

    /**
     * An optional datatype disabled in the plugin config, then enabled from IntelliBoard, is skipped with a log line:
     * no rows in a nameless temp file, not marked migrated. An enabled datatype in the same task still exports (IBV2-2322).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_optional_datatype_disabled_in_config_is_skipped() {
        global $CFG;

        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        \core_tag_tag::set_item_tags('core', 'course', $course->id, \context_course::instance($course->id), ['ibv2', 'repro']);

        $disabled = datatypes_service::generate_optional_datatype('tag_area');
        $enabled = datatypes_service::generate_optional_datatype('tag_instance');
        $this->disable_in_config($disabled);

        // What exportlib::set_datatype does when IntelliBoard enables native tables: the config status is not touched.
        $exportlogrepository = new export_log_repository();
        $exportlogrepository->insert_datatype($disabled);
        $exportlogrepository->insert_datatype($enabled);

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => [$disabled, $enabled]]);

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString("Datatype '{$disabled}' skipped", $output);
        $this->assertFileDoesNotExist($CFG->tempdir . '/ibdata/migration_.csv');
        $this->assertEquals(0, $exportlogrepository->get_datatype($disabled)->get('migrated'));

        $files = (new export_service())->get_files(['timestart' => 0]);
        $this->assertArrayNotHasKey('migration_' . $disabled, $files);
        $this->assertNotEmpty($files['migration_' . $enabled]);
        $this->assertEquals(1, $exportlogrepository->get_datatype($enabled)->get('migrated'));
    }

    /**
     * The same skip in the chunked (divideexportbydatatype) export (IBV2-2322).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_optional_datatype_disabled_in_config_is_skipped_in_chunks() {
        global $CFG;

        $this->resetAfterTest(true);

        set_config('divideexportbydatatype', 1, 'local_intellidata');
        set_config('exportrecordslimit', 4, 'local_intellidata');

        $datatype = datatypes_service::generate_optional_datatype('tag_area');
        $this->disable_in_config($datatype);

        $exportlogrepository = new export_log_repository();
        $exportlogrepository->insert_datatype($datatype);

        $task = new export_adhoc_task();
        $task->set_custom_data(['datatypes' => [$datatype]]);

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString("Datatype '{$datatype}' skipped", $output);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task'));
        $this->assertFileDoesNotExist($CFG->tempdir . '/ibdata/migration_.csv');
        $this->assertEquals(0, $exportlogrepository->get_datatype($datatype)->get('migrated'));
        $this->assertEquals(0, $exportlogrepository->get_datatype($datatype)->get('last_exported_id'));
    }

    /**
     * The full export calls back once for the datatypes it exported, and not at all when every datatype is skipped:
     * IntelliBoard marks each datatype in the callback as loaded (IBV2-2322).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_full_export_callback_skips_disabled_datatypes() {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        \core_tag_tag::set_item_tags('core', 'course', $course->id, \context_course::instance($course->id), ['ibv2', 'repro']);

        $disabled = datatypes_service::generate_optional_datatype('tag_area');
        $enabled = datatypes_service::generate_optional_datatype('tag_instance');
        $this->disable_in_config($disabled);

        $exportlogrepository = new export_log_repository();
        $exportlogrepository->insert_datatype($disabled);
        $exportlogrepository->insert_datatype($enabled);

        $callbackurl = 'https://callback.invalid/api/moodle/callback/1/datatype-export';

        // One enabled datatype: exactly one POST (one spare response left).
        \curl::mock_response('ok');
        \curl::mock_response('ok');
        $this->run_task(['datatypes' => [$disabled, $enabled], 'callbackurl' => $callbackurl]);
        $this->assertSame(1, $this->unused_curl_mocks());

        // Only the disabled datatype: no POST.
        $exportlogrepository->insert_datatype($disabled);
        $this->run_task(['datatypes' => [$disabled], 'callbackurl' => $callbackurl]);
        $this->assertSame(1, $this->unused_curl_mocks());
    }

    /**
     * The chunked export sends no callback for a skipped datatype (IBV2-2322).
     *
     * @return void
     * @runInSeparateProcess
     * @covers \local_intellidata\task\export_adhoc_task::execute
     */
    public function test_chunked_export_callback_skips_disabled_datatype() {
        $this->resetAfterTest(true);

        set_config('divideexportbydatatype', 1, 'local_intellidata');
        set_config('exportrecordslimit', 4, 'local_intellidata');

        $datatype = datatypes_service::generate_optional_datatype('tag_area');
        $this->disable_in_config($datatype);
        (new export_log_repository())->insert_datatype($datatype);

        \curl::mock_response('ok');
        $this->run_task([
            'datatypes' => [$datatype],
            'callbackurl' => 'https://callback.invalid/api/moodle/callback/1/datatype-export',
        ]);

        $this->assertSame(1, $this->unused_curl_mocks());
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks('\\local_intellidata\\task\\export_adhoc_task'));
    }

    /**
     * Run one export task with the given custom data, discarding its output.
     *
     * @param array $customdata
     * @return void
     */
    private function run_task(array $customdata) {
        $task = new export_adhoc_task();
        $task->set_custom_data($customdata);

        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * Count the mocked curl responses not consumed yet: each POST consumes one.
     *
     * @return int
     */
    private function unused_curl_mocks() {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $mockresponses = new \ReflectionProperty(\curl::class, 'mockresponses');
        if (PHP_VERSION_ID < 80100) {
            $mockresponses->setAccessible(true);
        }

        return count($mockresponses->getValue());
    }

    /**
     * Untick a datatype's status in the plugin config, as the admin Datatypes configuration does.
     *
     * @param string $datatype
     * @return void
     */
    private function disable_in_config($datatype) {
        $configrepository = new config_repository();
        $record = $configrepository->get_record(['datatype' => $datatype]);
        $this->assertNotEmpty($record);

        $record->set('status', datatypeconfig::STATUS_DISABLED);
        $record->save();
        $configrepository->cache_config();
    }
}
