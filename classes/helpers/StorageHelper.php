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

namespace local_intellidata\helpers;

use local_intellidata\persistent\export_logs;
use local_intellidata\persistent\logs;
use local_intellidata\repositories\database_storage_repository;
use local_intellidata\repositories\file_storage_repository;
use local_intellidata\services\encryption_service;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class StorageHelper {

    /**
     * File storage.
     */
    const FILE_STORAGE = 0;
    /**
     * Databse storage.
     */
    const DATABASE_STORAGE = 1;
    /**
     * Cache storage.
     */
    const CACHE_STORAGE = 2;

    /**
     * Seconds a routine export waits for the export lock before leaving the storage to the next run.
     */
    const EXPORT_LOCK_TIMEOUT = 5;

    /**
     * Seconds an export that must not be skipped (ad hoc, migration, reset) waits for the export lock.
     */
    const EXPORT_LOCK_WAIT_TIMEOUT = 600;

    /**
     * Get storage service.
     *
     * @param $datatype
     * @return database_storage_repository|file_storage_repository
     * @throws \dml_exception
     */
    public static function get_storage_service($datatype) {

        if (!empty($datatype['migrationmode']) || !empty($datatype['rewritable'])) {
            return new file_storage_repository($datatype);
        }

        $storagename = 'local_intellidata\\repositories\\' . self::get_storage_classname();
        return new $storagename($datatype);
    }

    /**
     * Return the list of all repositories.
     *
     * @return string[]
     */
    public static function repositories() {
        return [
            self::FILE_STORAGE => 'file_storage_repository',
            self::DATABASE_STORAGE => 'database_storage_repository',
            self::CACHE_STORAGE => 'cache_storage_repository',
        ];
    }

    /**
     * Get storage repository based on plugin configuration.
     *
     * @return string
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_storage_classname() {
        $trackingstorage = SettingsHelper::get_setting('trackingstorage');
        $repositories = self::repositories();

        if (!isset($repositories[$trackingstorage])) {
            throw new \moodle_exception("storage_not_exits", 'local_intellidata');
        }

        return $repositories[$trackingstorage];
    }

    /**
     * Count exported rows in a storage file, and how many of them are creations.
     *
     * @param string $filepath
     * @return array ['total' => int, 'created' => int]
     */
    public static function count_records_in_file($filepath) {
        $counts = ['total' => 0, 'created' => 0];

        $handle = @fopen($filepath, 'r');
        if ($handle === false) {
            return $counts;
        }

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                continue;
            }

            $counts['total']++;

            // The crud marker is the last field of every exported row, so a
            // created record is a line ending in ",c" - what the grep matched.
            if (substr($line, -2) === ',' . EventsHelper::CRUD_CREATED) {
                $counts['created']++;
            }
        }

        fclose($handle);

        return $counts;
    }

    /**
     * Save file.
     *
     * @param $params
     * @return \stored_file|null
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \file_exception
     * @throws \moodle_exception
     * @throws \stored_file_creation_exception
     */
    public static function save_file($params) {

        $fs = get_file_storage();
        $context = \context_system::instance();

        $filename = $params['filename'];
        $tempdir = $params['tempdir'];
        $tempfile = $params['tempfile'];

        if (!file_exists($tempfile)) {
            DebugHelper::error_log('File is not exists or empty ' . $tempfile);
            return null;
        }

        // Was two exec() calls - grep ",c$" | wc -l and cat | wc -l - with the
        // temp path interpolated unquoted into a shell string. Both are a
        // single streaming pass in PHP, which also works where exec() is
        // disabled by disable_functions, where the temp path contains a space,
        // and on Windows.
        $counts = self::count_records_in_file($tempfile);
        $countcreated = $counts['created'];
        $countexported = $counts['total'];

        // Firstly zip temp file.
        $zipfilename = $filename.'.zip';
        $tempzipfilepath = $tempdir . '/dec_'.$zipfilename;
        $zipfilepath = $tempdir . '/'.$zipfilename;
        $zippacker = get_file_packer('application/zip');

        // Zip file.
        $result = $zippacker->archive_to_pathname(
            [$filename => $tempfile], $tempzipfilepath
        );

        if ($result === false) {
            if (!@unlink($tempzipfilepath)) {
                throw new \moodle_exception("failed_remove_file", 'local_intellidata', '', $tempzipfilepath);
            }
            throw new \moodle_exception("failed_zip_packing", 'local_intellidata', '', $filename);
        }
        DebugHelper::error_log('Created Temp Zip File ' . $tempzipfilepath);

        $encriptionservice = new encryption_service();
        if (!$encriptionservice->encrypt_file($tempzipfilepath, $zipfilepath)) {
            @unlink($zipfilepath);
            DebugHelper::error_log('Error encrypt file: ' . $zipfilepath);
            throw new \moodle_exception('can_not_encrypt_file', 'local_intellidata');
        }

        $component = 'local_intellidata';
        $filearea = $params['datatype'];

        // Save file.
        $filerecord = [
            'component' => $component,
            'filearea' => $filearea,
            'contextid' => $context->id,
            'filepath' => '/',
            'filename' => $zipfilename,
        ];
        $filerecord['itemid'] = self::get_new_itemid($filerecord);

        $file = $fs->create_file_from_pathname($filerecord, $zipfilepath);
        DebugHelper::error_log('Created Data File ' . $zipfilename . ' - ' . $file->get_id());

        if (!unlink($tempfile)) {
            throw new \moodle_exception("failed_remove_file", 'local_intellidata', '', $tempfile);
        }
        DebugHelper::error_log('Removed Temp File ' . $tempfile);

        if (!unlink($tempzipfilepath)) {
            throw new \moodle_exception("failed_remove_file", 'local_intellidata', '', $tempzipfilepath);
        }
        DebugHelper::error_log('Removed Temp Zip File ' . $tempzipfilepath);

        if (!unlink($zipfilepath)) {
            throw new \moodle_exception("failed_remove_file", 'local_intellidata', '', $zipfilepath);
        }
        DebugHelper::error_log('Removed Temp encrypted Zip File ' . $zipfilepath);

        // Save log when file exported.
        $logs = new logs(0, [
            'datatype' => $params['datatype'],
            'type' => logs::TYPE_FILE_EXPORT,
            'action' => logs::ACTION_CREATED,
            'details' => json_encode($filerecord),
            'count_in_file' => $countexported,
        ]);
        $logs->save();

        if ($record = export_logs::get_record(['datatype' => $params['datatype']])) {
            $record->set('count_in_files', (int)$record->get('count_in_files') + (int)$countcreated);
            $record->save();
        }

        return $file;
    }

    /**
     * Retrieve new itemid for file.
     *
     * @param $filerecord
     * @return int
     * @throws \coding_exception
     */
    public static function get_new_itemid($filerecord) {

        $itemid = 0;
        $fs = get_file_storage();
        $filerecord = (object)$filerecord;

        $files = $fs->get_area_files(
            $filerecord->contextid,
            $filerecord->component,
            $filerecord->filearea,
            false,
            "itemid",
            true
        );

        if (count($files)) {
            $file = end($files);
            $itemid = $file->get_itemid() + 1;
        }

        DebugHelper::error_log('The new itemid for file ' . $filerecord->filearea . ' is ' . $itemid);

        return $itemid;
    }

    /**
     * Generate filename.
     *
     * @param int $length
     * @return false|string
     */
    public static function generate_filename($length = 16) {
        // The $cstrong flag was passed but never checked, so weak material would have
        // been used silently. random_bytes() throws instead.
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    /**
     * Generate file URL.
     *
     * @param $file
     * @return \moodle_url
     */
    public static function make_pluginfile_url($file) {
        return \moodle_url::make_pluginfile_url(
            $file->contextid,
            $file->component,
            $file->filearea,
            $file->itemid,
            $file->filepath,
            $file->filename
        );
    }

    /**
     * Delete file.
     *
     * @param $id
     * @return bool
     * @throws \dml_exception
     */
    public static function delete_file($id) {
        global $DB;

        $fs = get_file_storage();

        // Scoped to this plugin's own files. The lookup used to be
        // $DB->get_record('files', ['id' => $id]) with no component check,
        // reached from logs/index.php with the file id straight off the query
        // string - so local/intellidata:viewlogs, a capability declared read,
        // could delete any row in mdl_files: any user avatar, any assignment
        // submission, any backup. The only legitimate caller lists this
        // plugin's own export archives, so nothing is lost by the scope.
        $filerecord = $DB->get_record('files', [
            'id' => $id,
            'component' => 'local_intellidata',
        ]);

        if (!$filerecord) {
            throw new \moodle_exception('filenotexists', 'local_intellidata');
        }

        $fs->get_file_instance($filerecord)->delete();

        return true;
    }

    /**
     * Convert filesize.
     *
     * @param $bytes
     * @return string
     */
    public static function convert_filesize($bytes) {
        $i = floor(log($bytes) / log(1024));
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        return sprintf('%.02F', $bytes / pow(1024, $i)) * 1 . ' ' . $sizes[$i];
    }

    /**
     * Save data to file.
     *
     * @param $storagefile
     * @param $data
     * @return void
     * @throws \moodle_exception
     */
    public static function save_in_file($storagefile, $data) {
        // An export renames a live storage file into its pending folder before reading it.
        // A writer that opened the file just before that rename would otherwise get the lock
        // after the export has read the file and append to a copy that is about to be
        // deleted. So check, under the lock, that the handle is still the file at
        // $storagefile, and open it again when it is not.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $handle = @fopen($storagefile, 'a');
            if ($handle === false) {
                break;
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                break;
            }

            clearstatcache(true, $storagefile);
            $opened = fstat($handle);
            $current = @stat($storagefile);

            $moved = ($current === false) || ($opened['ino'] !== $current['ino']) ||
                ($opened['dev'] !== $current['dev']) || ($opened['nlink'] < 1);
            if ($moved) {
                flock($handle, LOCK_UN);
                fclose($handle);
                continue;
            }

            $line = ($opened['size'] > 0) ? PHP_EOL . $data : $data;
            $written = fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            if ($written === strlen($line)) {
                return;
            }
            break;
        }

        throw new \moodle_exception("failed_write_file", 'local_intellidata', '', $storagefile);
    }

    /**
     * Write records to a new file in one go, so readers never see it half written.
     *
     * The data goes to a temporary name first and is renamed to $filepath only when it is
     * complete. Readers of a pending folder only pick up *.csv files.
     *
     * @param string $filepath
     * @param iterable $records Lines, without line endings.
     * @return void
     * @throws \moodle_exception
     */
    public static function write_new_file($filepath, $records) {
        $tmpfile = $filepath . '.tmp';
        $handle = @fopen($tmpfile, 'x');
        if ($handle === false) {
            throw new \moodle_exception("failed_write_file", 'local_intellidata', '', $tmpfile);
        }

        $written = true;
        $first = true;
        foreach ($records as $record) {
            $line = ($first ? '' : PHP_EOL) . $record;
            $first = false;
            if (fwrite($handle, $line) !== strlen($line)) {
                $written = false;
                break;
            }
        }
        $written = fflush($handle) && $written;
        fclose($handle);

        if (!$written || !rename($tmpfile, $filepath)) {
            @unlink($tmpfile);
            throw new \moodle_exception("failed_write_file", 'local_intellidata', '', $filepath);
        }
    }

    /**
     * Take the lock that serialises collecting and exporting one storage.
     *
     * Everything that moves buffered records out of a storage (exports, drains, resets)
     * holds this lock, so two of them never work on the same files at the same time.
     *
     * @param string $storagename Datatype name the storage uses (migration_<name> in migration mode).
     * @param bool $wait Wait for a running export to finish instead of giving up quickly.
     * @return \core\lock\lock|false False when the lock is held elsewhere.
     */
    public static function get_export_lock($storagename, $wait = false) {
        $timeout = $wait ? self::EXPORT_LOCK_WAIT_TIMEOUT : self::EXPORT_LOCK_TIMEOUT;

        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_intellidata_export');
            return $factory->get_lock($storagename, $timeout);
        } catch (\Throwable $e) {
            self::report_failure("Could not take the export lock for {$storagename}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Report a storage failure that would otherwise lose tracked records silently.
     *
     * Goes to debugging() whatever the plugin's debugenabled setting is, and to the task
     * output when it runs from cron or the CLI. DebugHelper::error_log() is not used here:
     * it only logs when debugging is enabled, and it writes through the tracking storage
     * that may be what is failing.
     *
     * @param string $message
     * @return void
     */
    public static function report_failure($message) {
        $message = 'local_intellidata: ' . $message;
        if (CLI_SCRIPT) {
            mtrace($message);
        }
        debugging($message, DEBUG_NORMAL);
    }

    /**
     * Delete all files from path.
     *
     * @param $path
     * @return bool
     */
    public static function delete_all_files($path) {
        $files = glob($path . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    /**
     * Format data based on config.
     *
     * @param $format
     * @param $data
     * @return false|string
     */
    public static function format_data($format, $data) {
        $eventdata = '';

        switch ($format) {
            case 'json':
                $eventdata = json_encode($data);
                break;
            case 'csv':
                $stream = fopen('php://memory', 'r+');

                fputcsv($stream, (array)$data, ",", '"', '\\');
                rewind($stream);

                $eventdata = rtrim(stream_get_contents($stream));
                break;
        }

        return $eventdata;
    }

    /**
     * Get data from file.
     *
     * @param $storagefile
     * @param $format
     * @return array
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function get_from_file($storagefile, $format = 'json') {

        if (!file_exists($storagefile)) {
            DebugHelper::error_log('Error in reading Temp File. File ' . $storagefile . ' not exists');
            throw new \moodle_exception('filenotexists', 'local_intellidata');
        }

        $file = fopen($storagefile, 'r');

        $data = [];
        while (($line = fgets($file)) !== false) {
            $data[] = ($format == 'json') ? json_decode($line) : explode(',', $line);
        }
        fclose($file);

        return $data;
    }

    /**
     * Extract data from export files.
     *
     * @param $datatype
     * @param $tempdir
     * @param $exportfiles
     * @return array
     * @throws \moodle_exception
     */
    public static function get_data_from_exportfiles($datatype, $tempdir, $exportfiles = []) {

        $data = [];

        if (!count($exportfiles)) {
            return $data;
        }

        foreach ($exportfiles as $file) {
            $data[] = self::extract_data_from_archive($datatype, $tempdir, $file);
        }

        return array_merge([], ...$data);
    }

    /**
     * Extract data from export archive file.
     *
     * @param $datatype
     * @param $tempdir
     * @param $exportfile
     * @return array
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public static function extract_data_from_archive($datatype, $tempdir, $exportfile) {

        $fs = get_file_storage();
        $filesystem = $fs->get_file_system();
        $context = \context_system::instance();

        $file = $fs->get_file(
            $context->id,
            'local_intellidata',
            $datatype,
            $exportfile['itemid'],
            $exportfile['filepath'],
            $exportfile['filename']
        );

        $tempzipfilepath = $tempdir . '/' . $exportfile['filename'];

        $sourcezipfilepath = $filesystem->get_local_path_from_storedfile($file, true);
        $encriptionservice = new encryption_service();
        if (!$encriptionservice->decrypt_file($sourcezipfilepath, $tempzipfilepath)) {
            @unlink($tempzipfilepath);
            DebugHelper::error_log('Error decrypt file: ' . $sourcezipfilepath);
            throw new \moodle_exception('can_not_decrypt_file', 'local_intellidata');
        }

        $zippacker = get_file_packer('application/zip');

        // Zip file.
        $zippacker->extract_to_pathname(
            $tempzipfilepath, $tempdir
        );

        $tempfilepath = $tempdir . '/' . str_replace('.zip', '', $exportfile['filename']);

        // Get data from temp file.
        $data = self::get_from_file($tempfilepath);

        // Remove temp file.
        unlink($tempfilepath);

        return $data;
    }
}
