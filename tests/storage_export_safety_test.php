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
 * Buffered tracking storage export safety test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\helpers\ExportHelper;
use local_intellidata\helpers\MigrationHelper;
use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\SettingsHelper;
use local_intellidata\helpers\StorageHelper;
use local_intellidata\helpers\TasksHelper;
use local_intellidata\persistent\export_logs;
use local_intellidata\repositories\cache_storage_repository;
use local_intellidata\repositories\database_storage_repository;
use local_intellidata\repositories\export_log_repository;
use local_intellidata\repositories\file_storage_repository;
use local_intellidata\services\export_service;
use local_intellidata\task\cleaner_task;
use local_intellidata\task\export_adhoc_task;
use local_intellidata\task\migration_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/fixtures/moving_file_stream.php');

/**
 * Buffered tracking storage export safety test case.
 *
 * Tracked events wait in a storage buffer (cache, files or a table) until the hourly
 * export writes them to a data file. Every test here checks that buffered events reach
 * an exported file exactly once while something else works on the same storage.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellidata\repositories\file_storage_repository
 * @covers     \local_intellidata\repositories\cache_storage_repository
 * @covers     \local_intellidata\repositories\database_storage_repository
 * @covers     \local_intellidata\helpers\StorageHelper
 * @covers     \local_intellidata\services\export_service
 */
final class storage_export_safety_test extends \advanced_testcase {
    /** Datatype the tests buffer events for. */
    const DATATYPE = 'usergrades';

    /** @var int Next event id. */
    private $nextid = 1;

    /**
     * Set up the plugin.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest(true);
        $this->setAdminUser();

        setup_helper::setup_tests_config();
        SettingsHelper::set_setting('migrationwriterecordslimit', 1000);

        // The export service exports only datatypes that have an export log.
        (new export_log_repository())->insert_datatype(self::DATATYPE);
    }

    /**
     * Skip when stored files cannot be read back on this Moodle version.
     *
     * @return void
     */
    private function require_readable_files() {
        if (!test_helper::is_get_local_path_from_storedfile_callable()) {
            $this->markTestSkipped('Stored files cannot be read back on this Moodle version.');
        }
    }

    /**
     * Get the datatype definition the export service uses.
     *
     * @return array
     */
    private function datatype() {
        return (new export_service())->get_datatype(self::DATATYPE);
    }

    /**
     * Buffer $count events through the configured storage and return their ids.
     *
     * @param int $count
     * @return int[]
     */
    private function buffer_events($count) {
        $exportservice = new export_service();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $this->nextid++;
            $exportservice->store_data(self::DATATYPE, json_encode(['id' => $id, 'crud' => 'd']));
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Run the routine export of the datatype.
     *
     * @return array Saved files.
     */
    private function export() {
        ob_start();
        try {
            return (new export_service())->save_files(['datatype' => self::DATATYPE]);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Assert that at least one debugging message was raised, and clear them.
     *
     * @return void
     */
    private function assert_failure_reported() {
        $this->assertNotEmpty($this->getDebuggingMessages());
        $this->resetDebugging();
    }

    /**
     * Ids in every data file exported for a datatype so far.
     *
     * @param string $datatype
     * @return int[]
     */
    private function exported_ids($datatype = self::DATATYPE) {
        $exportservice = new export_service();
        $files = $exportservice->get_files(['datatype' => $datatype]);
        $storagefolder = (new file_storage_repository($this->datatype()))->storagefolder;

        $ids = [];
        $data = StorageHelper::get_data_from_exportfiles($datatype, $storagefolder, $files[$datatype]);
        foreach ($data as $row) {
            if ($row !== null) {
                $ids[] = (int)$row->id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * A reset of another datatype must not delete events buffered for this one.
     *
     * The reset used to empty the whole temp folder, including the block a cache buffer
     * had just flushed for every other datatype.
     *
     * @return void
     */
    public function test_reset_of_another_datatype_keeps_flushed_events(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);

        // Past 1,000 events the cache buffer is flushed to disk; the rest stay cached.
        $ids = $this->buffer_events(1006);

        (new export_service())->delete_files(['datatype' => 'users', 'timemodified' => time()]);

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * The daily cleaner removes old data files only, never events still to be exported.
     *
     * @return void
     */
    public function test_cleaner_keeps_buffered_events(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);

        $ids = $this->buffer_events(10);

        ob_start();
        (new cleaner_task())->execute();
        ob_end_clean();

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * A reset of the datatype itself still throws its buffer away, as it always has.
     *
     * @return void
     */
    public function test_reset_of_the_datatype_clears_its_buffer(): void {
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);
        $this->buffer_events(1003);

        (new export_service())->delete_files(['datatype' => self::DATATYPE, 'timemodified' => time()]);

        $this->assertSame([], $this->export());
    }

    /**
     * When the flushed block cannot be written, its events stay in the cache.
     *
     * The write is tried again once per 1,000 events, not on every event.
     *
     * @return void
     */
    public function test_failed_flush_keeps_events(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);
        $this->expectOutputRegex('/local_intellidata: Could not write a usergrades pending file/');

        $pendingfolder = (new file_storage_repository($this->datatype()))->get_pending_folder();
        chmod($pendingfolder, 0555);
        try {
            if (is_writable($pendingfolder)) {
                $this->markTestSkipped('Running as a user that can write to read-only folders.');
            }

            $ids = $this->buffer_events(1001);
            $this->assert_failure_reported();

            $ids = array_merge($ids, $this->buffer_events(999));
            $this->assertEmpty($this->getDebuggingMessages());

            $ids = array_merge($ids, $this->buffer_events(1));
        } finally {
            chmod($pendingfolder, 0777);
        }
        $this->assert_failure_reported();

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * While another export holds the lock, a routine export leaves the data for later.
     *
     * The test pins the database record lock factory: the Postgres and MySQL factories
     * let the same connection take a lock twice, so the test could not hold it against
     * itself.
     *
     * @return void
     */
    public function test_export_waits_for_the_lock_holder(): void {
        global $CFG;
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';

        $ids = $this->buffer_events(3);

        $lock = \core\lock\lock_config::get_lock_factory('local_intellidata_export')->get_lock(self::DATATYPE, 0);
        $this->assertTrue($lock !== false);
        try {
            $this->assertSame([], $this->export());
        } finally {
            $lock->release();
        }

        $this->export();
        $this->assertSame($ids, $this->exported_ids());

        // Exported once: a second export finds nothing.
        $this->assertSame([], $this->export());
    }

    /**
     * An export that must not be skipped fails loudly when it cannot get the lock.
     *
     * The ad hoc export marks the datatype migrated and calls IntelliBoard back after
     * saving its files; a silent skip would report files that do not exist yet.
     *
     * @return void
     */
    public function test_waiting_export_throws_without_the_lock(): void {
        $datatype = $this->datatype();
        $datatype['waitforlock'] = true;

        $repository = new class ($datatype) extends file_storage_repository {
            /**
             * The lock is never available.
             *
             * @param bool $wait
             * @return false
             */
            protected function get_export_lock($wait) {
                return false;
            }
        };

        $this->expectException(\moodle_exception::class);
        $repository->save_file();
    }

    /**
     * Events stored while an export drains the cache are kept for the next export.
     *
     * @return void
     */
    public function test_events_stored_during_a_cache_drain_are_kept(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);

        $ids = $this->buffer_events(5);

        $repository = new class ($this->datatype()) extends cache_storage_repository {
            /** @var bool Whether the concurrent event was stored. */
            public $stored = false;

            /**
             * Store a new event while the drain writes its first block.
             *
             * @param iterable $records
             * @return void
             */
            public function write_pending_block($records) {
                if (!$this->stored) {
                    $this->stored = true;
                    $this->save_data(json_encode(['id' => 9999, 'crud' => 'd']));
                }
                parent::write_pending_block($records);
            }
        };
        ob_start();
        $repository->save_file();
        ob_end_clean();
        $this->assertTrue($repository->stored);
        $this->assertSame($ids, $this->exported_ids());

        $this->export();
        $this->assertSame(array_merge($ids, [9999]), $this->exported_ids());
    }

    /**
     * A temp file left by an older version of the plugin is exported once.
     *
     * @return void
     */
    public function test_legacy_temp_file_is_exported(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);

        $repository = new file_storage_repository($this->datatype());
        StorageHelper::save_in_file($repository->get_temp_file(), json_encode(['id' => 1, 'crud' => 'd']));
        StorageHelper::save_in_file($repository->get_temp_file(), json_encode(['id' => 2, 'crud' => 'd']));

        $this->export();
        $this->assertSame([1, 2], $this->exported_ids());
        $this->assertFalse(file_exists($repository->get_temp_file()));
    }

    /**
     * File storage: every user's shard is exported, then removed.
     *
     * @return void
     */
    public function test_file_storage_exports_all_shards(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::FILE_STORAGE);

        $ids = $this->buffer_events(2);
        $this->setUser($this->getDataGenerator()->create_user());
        $ids = array_merge($ids, $this->buffer_events(1));

        $this->export();
        $this->assertSame($ids, $this->exported_ids());

        $repository = new file_storage_repository($this->datatype());
        $this->assertSame([], glob($repository->storagefolder . '/' . self::DATATYPE . '_byusers/*.csv'));
        $this->assertSame([], glob($repository->get_pending_folder() . '/*'));
    }

    /**
     * Database storage: buffered rows are exported, then removed from the table.
     *
     * @return void
     */
    public function test_database_storage_exports_and_clears_rows(): void {
        global $DB;
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::DATABASE_STORAGE);

        $ids = $this->buffer_events(3);

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
        $this->assertSame(0, $DB->count_records('local_intellidata_storage', ['datatype' => self::DATATYPE]));
    }

    /**
     * Database storage: a buffer larger than one page is exported in full, each row once.
     *
     * Each page is deleted after it is written, so reading the next page at an offset
     * skipped as many rows as the page held, and then deleted them unexported.
     *
     * @return void
     */
    public function test_database_storage_exports_every_page(): void {
        global $DB;
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::DATABASE_STORAGE);
        SettingsHelper::set_setting('migrationrecordslimit', 2);

        $ids = $this->buffer_events(5);

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
        $this->assertSame(0, $DB->count_records('local_intellidata_storage', ['datatype' => self::DATATYPE]));
    }

    /**
     * Database storage: a page that fails to write leaves its rows in the table and no partial file.
     *
     * @return void
     */
    public function test_database_storage_keeps_rows_when_a_page_fails(): void {
        global $DB;
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::DATABASE_STORAGE);

        $ids = $this->buffer_events(3);

        $repository = new class ($this->datatype()) extends database_storage_repository {
            /**
             * Write the page, then fail as a full disk would.
             *
             * @param string $tempfile
             * @param \moodle_recordset $records
             * @return int
             */
            protected function export_data($tempfile, $records) {
                parent::export_data($tempfile, $records);
                throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $tempfile);
            }
        };

        ob_start();
        try {
            $repository->save_file();
            $this->fail('The failed page must stop the export.');
        } catch (\moodle_exception $e) {
            $this->assertSame('failed_write_file', $e->errorcode);
        } finally {
            ob_end_clean();
        }

        $this->assertSame(3, $DB->count_records('local_intellidata_storage', ['datatype' => self::DATATYPE]));
        $this->assertSame([], glob($repository->get_pending_folder() . '/*'));

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * File storage: a reset of the datatype also throws away its per-user buffer files.
     *
     * @return void
     */
    public function test_reset_of_the_datatype_clears_its_file_shards(): void {
        SettingsHelper::set_setting('trackingstorage', StorageHelper::FILE_STORAGE);
        $this->buffer_events(3);

        $repository = new file_storage_repository($this->datatype());
        $shards = $repository->storagefolder . '/' . self::DATATYPE . '_byusers/*.csv';
        $this->assertNotEmpty(glob($shards));

        (new export_service())->delete_files(['datatype' => self::DATATYPE, 'timemodified' => time()]);

        $this->assertSame([], glob($shards));
        $this->assertSame([], $this->export());
    }

    /**
     * A routine export that fails for one datatype still exports the others.
     *
     * @return void
     */
    public function test_failed_datatype_does_not_stop_the_routine_export(): void {
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::FILE_STORAGE);
        (new export_log_repository())->insert_datatype('users');
        $this->expectOutputRegex('/local_intellidata: Export of usergrades failed, its buffer is kept for the next run/');

        $ids = $this->buffer_events(2);
        (new export_service())->store_data('users', json_encode(['id' => 7, 'crud' => 'u']));

        // The usergrades buffer cannot be moved into its pending folder.
        $pendingfolder = (new file_storage_repository($this->datatype()))->get_pending_folder();
        chmod($pendingfolder, 0555);
        try {
            if (is_writable($pendingfolder)) {
                $this->markTestSkipped('Running as a user that can write to read-only folders.');
            }

            (new export_service())->save_files();
            $this->assert_failure_reported();
            $this->assertSame([7], $this->exported_ids('users'));
            $this->assertSame([], $this->exported_ids());
        } finally {
            chmod($pendingfolder, 0777);
        }

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * The hourly export does not wait for an ad hoc or migration export of the same storage.
     *
     * Its second pass, for the migration files, runs in migration mode; it must still leave
     * a locked storage to the next run instead of holding the cron for the long wait.
     *
     * @return void
     */
    public function test_routine_export_does_not_wait_for_a_migration_export(): void {
        global $CFG;
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';

        $ids = $this->buffer_events(3);

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_intellidata_export');
        $lock = $lockfactory->get_lock('migration_' . self::DATATYPE, 0);
        $this->assertTrue($lock !== false);
        try {
            $start = time();
            ob_start();
            try {
                ExportHelper::process_files_export(new export_service(), ['datatype' => self::DATATYPE]);
            } finally {
                ob_end_clean();
            }
            $this->assertLessThan(StorageHelper::EXPORT_LOCK_WAIT_TIMEOUT, time() - $start);
        } finally {
            $lock->release();
        }

        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * A reset from a page or web service does not wait long for a running export.
     *
     * @return void
     */
    public function test_reset_does_not_wait_long_for_the_lock(): void {
        global $CFG;
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $this->expectOutputRegex('/local_intellidata: Reset of usergrades runs without the export lock/');

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_intellidata_export');
        $lock = $lockfactory->get_lock(self::DATATYPE, 0);
        $this->assertTrue($lock !== false);
        try {
            $start = time();
            (new export_service())->delete_files(['datatype' => self::DATATYPE, 'timemodified' => time()]);
            $this->assertLessThan(StorageHelper::EXPORT_LOCK_WAIT_TIMEOUT, time() - $start);
            $this->assert_failure_reported();
        } finally {
            $lock->release();
        }
    }

    /**
     * A pending file that cannot be read is left for later and does not stop the others.
     *
     * @return void
     */
    public function test_unreadable_pending_file_does_not_block_the_export(): void {
        $this->require_readable_files();

        $repository = new file_storage_repository($this->datatype());
        $repository->write_pending_block([json_encode(['id' => 1, 'crud' => 'd'])]);
        $repository->write_pending_block([json_encode(['id' => 2, 'crud' => 'd'])]);
        $files = glob($repository->get_pending_folder() . '/*.csv');
        sort($files);
        $this->assertCount(2, $files);

        chmod($files[0], 0000);
        try {
            if (is_readable($files[0])) {
                $this->markTestSkipped('Running as a user that can read unreadable files.');
            }

            $this->export();
            $this->assert_failure_reported();
            $this->assertSame([2], $this->exported_ids());
            $this->assertTrue(file_exists($files[0]));
        } finally {
            chmod($files[0], 0666);
        }

        $this->export();
        $this->assertSame([1, 2], $this->exported_ids());
    }

    /**
     * Purging the events cache while an export runs keeps the buffered events.
     *
     * @return void
     */
    public function test_cache_purge_keeps_events_while_an_export_runs(): void {
        global $CFG;
        $this->require_readable_files();
        SettingsHelper::set_setting('trackingstorage', StorageHelper::CACHE_STORAGE);
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';

        $ids = $this->buffer_events(3);

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_intellidata_export');
        $lock = $lockfactory->get_lock(self::DATATYPE, 0);
        $this->assertTrue($lock !== false);
        try {
            \cache::make('local_intellidata', 'events')->purge();
        } finally {
            $lock->release();
        }

        $this->export();
        $this->assertSame($ids, $this->exported_ids());
    }

    /**
     * A writer that opened a storage file just before an export moved it writes to the live file.
     *
     * The writer opens the file through a stream wrapper that moves the file away, the way
     * an export does, while the writer waits for its lock.
     *
     * @return void
     */
    public function test_writer_follows_a_moved_file(): void {
        $dir = make_temp_directory('local_intellidata_writer_test');
        $live = $dir . '/user_1.csv';
        $moved = $dir . '/moved.csv';
        foreach ([$live, $moved] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        StorageHelper::save_in_file($live, 'first');

        stream_wrapper_register(moving_file_stream::PROTOCOL, moving_file_stream::class);
        moving_file_stream::$moveto = $moved;
        try {
            StorageHelper::save_in_file(moving_file_stream::PROTOCOL . '://' . $live, 'second');
        } finally {
            stream_wrapper_unregister(moving_file_stream::PROTOCOL);
        }

        $this->assertNull(moving_file_stream::$moveto);
        $this->assertSame('first', file_get_contents($moved));
        $this->assertSame('second', file_get_contents($live));
    }

    /**
     * Run $callback while the migration storage of $datatype cannot move its buffer to the pending folder.
     *
     * @param string $datatype
     * @param callable $callback
     * @return void
     */
    private function with_failing_migration_export($datatype, callable $callback) {
        $migrationdatatype = (new export_service())->get_datatype($datatype);
        $migrationdatatype['name'] = 'migration_' . $datatype;
        $pendingfolder = (new file_storage_repository($migrationdatatype))->get_pending_folder();

        chmod($pendingfolder, 0555);
        try {
            if (is_writable($pendingfolder)) {
                $this->markTestSkipped('Running as a user that can write to read-only folders.');
            }

            ob_start();
            try {
                $callback();
            } finally {
                ob_end_clean();
                // The routine saves on the way report the same failure and go on.
                $this->resetDebugging();
            }
        } finally {
            chmod($pendingfolder, 0777);
        }
    }

    /**
     * Mocked curl responses not used yet. They are static, so a test that leaves one unused
     * clears them.
     *
     * @param bool $clear Clear them, and return the count before.
     * @return int
     */
    private function unused_curl_responses($clear = false) {
        $mockresponses = new \ReflectionProperty(\curl::class, 'mockresponses');
        if (PHP_VERSION_ID < 80100) {
            $mockresponses->setAccessible(true);
        }

        $count = count($mockresponses->getValue());
        if ($clear) {
            $mockresponses->setValue(null, []);
        }

        return $count;
    }

    /**
     * The migration task does not tell IntelliBoard the files are ready when one is not saved.
     *
     * @return void
     */
    public function test_migration_callback_waits_for_its_files(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        SettingsHelper::set_setting('migrationcallbackurl', 'https://callback.invalid/migration');
        $this->unused_curl_responses(true);
        \curl::mock_response('ok');

        // The migration task disables the other tasks until it completes.
        MigrationHelper::disable_sheduled_tasks([TasksHelper::TASK_CLASS_MIGRATIONS]);

        (new export_service(ParamsHelper::MIGRATION_MODE_ENABLED))
            ->store_data(self::DATATYPE, json_encode(['id' => 1, 'crud' => 'c']));

        $completemigration = new \ReflectionMethod(migration_task::class, 'complete_migration');
        if (PHP_VERSION_ID < 80100) {
            $completemigration->setAccessible(true);
        }

        $this->with_failing_migration_export(self::DATATYPE, function () use ($completemigration) {
            try {
                $completemigration->invoke(new migration_task());
                $this->fail('The migration completed without its files.');
            } catch (\moodle_exception $e) {
                // The failure reaches the caller.
                $this->assertNotEmpty($e->errorcode);
            }
        });

        $this->assertSame(1, $this->unused_curl_responses(true));
        $this->assertTrue(\core\task\manager::get_scheduled_task(TasksHelper::TASK_CLASS_EXPORT_FILES)->get_disabled());
    }

    /**
     * An export that waits for its files passes the wait on to the files export.
     *
     * The migration CLI calls IntelliBoard back right after this export.
     *
     * @return void
     */
    public function test_waiting_export_waits_for_its_files(): void {
        $exportservice = new export_service(ParamsHelper::MIGRATION_MODE_ENABLED);
        $exportservice->store_data(self::DATATYPE, json_encode(['id' => 1, 'crud' => 'c']));

        $this->with_failing_migration_export(self::DATATYPE, function () use ($exportservice) {
            try {
                ExportHelper::process_export($exportservice, ['waitforlock' => true]);
                $this->fail('The export completed without its files.');
            } catch (\moodle_exception $e) {
                // The failure reaches the caller.
                $this->assertNotEmpty($e->errorcode);
            }
        });
    }

    /**
     * A chunked ad hoc export does not mark the datatype migrated, or call back, when its file is not saved.
     *
     * @return void
     */
    public function test_chunked_adhoc_export_waits_for_its_files(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        SettingsHelper::set_setting('divideexportbydatatype', 1);
        SettingsHelper::set_setting('exportrecordslimit', 1000);
        (new export_log_repository())->insert_datatype('users');
        $this->unused_curl_responses(true);
        \curl::mock_response('ok');

        $task = new export_adhoc_task();
        $task->set_custom_data([
            'datatypes' => ['users'],
            'callbackurl' => 'https://callback.invalid/api/moodle/callback/1/datatype-export',
            // A chained chunk: it does not reset the storage first. All users fit in this one, so it is the last.
            'limit' => 1000,
        ]);

        $this->with_failing_migration_export('users', function () use ($task) {
            try {
                $task->execute();
                $this->fail('The ad hoc export completed without its file.');
            } catch (\moodle_exception $e) {
                // The failure reaches the caller.
                $this->assertNotEmpty($e->errorcode);
            }
        });

        $this->assertEquals(0, export_logs::get_record(['datatype' => 'users'])->get('migrated'));
        $this->assertSame(1, $this->unused_curl_responses(true));
    }
}
