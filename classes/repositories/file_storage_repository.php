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

use local_intellidata\helpers\DatatypesHelper;
use local_intellidata\helpers\StorageHelper;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class file_storage_repository {

    /**
     * Storage folder name.
     */
    const STORAGE_FOLDER_NAME = 'ibdata';

    /**
     * The name of the folder for storing files by user.
     */
    const STORAGE_FOLDER_BY_USER_NAME = 'byusers';

    /**
     * Pending files folder suffix.
     */
    const PENDING_FOLDER_NAME = 'pending';
    /**
     * Extension of the file an export assembles in the pending folder.
     */
    const RUN_FILE_TYPE = 'run';

    /**
     * Storage file type.
     */
    const STORAGE_FILE_TYPE = 'csv';
    /**
     * Storage files component.
     */
    const STORAGE_FILES_COMPONENT = 'local_intellidata';

    /** @var string|null */
    public $storagefolder = null;
    /** @var string|null */
    public $storagefile = null;
    /** @var string|null */
    public $datatype = null;

    /**
     * File storage repository construct.
     *
     * @param $datatype
     */
    public function __construct($datatype = null) {
        $this->datatype = $datatype;
        $this->storagefolder = self::get_storage_folder();
        $withuser = $datatype && isset($datatype['migrationmode']) && $datatype['migrationmode'] ? false : true;
        $this->storagefile = $datatype ? self::get_storage_file($withuser) : null;
    }

    /**
     * Get storage folder path.
     *
     * @return false|string
     */
    protected function get_storage_folder() {
        return make_temp_directory(self::STORAGE_FOLDER_NAME);
    }

    /**
     * Get storing files by user folder path.
     *
     * @return void
     */
    protected function make_storage_datatype_by_user_folder() {
        make_temp_directory(self::STORAGE_FOLDER_NAME . '/' . $this->datatype['name'] . '_' . self::STORAGE_FOLDER_BY_USER_NAME);
    }

    /**
     * Get storage file path.
     *
     * @return string
     */
    public function get_storage_file($withuser = true) {
        return $this->storagefolder . '/' . $this->get_file_name($withuser);
    }

    /**
     * Get storage file name path.
     *
     * @return string
     */
    public function get_file_name($withuser = true) {
        global $USER;

        if ($withuser) {
            $foldername = $this->datatype['name'] . '_' . self::STORAGE_FOLDER_BY_USER_NAME;
            $userid = !empty($USER->id) ? $USER->id : '';
            return $foldername . '/user_' . $userid . '.' . self::STORAGE_FILE_TYPE;
        }

        return $this->datatype['name'] . '.' . self::STORAGE_FILE_TYPE;
    }

    /**
     * Get temp file path.
     *
     * @return string
     */
    public function get_temp_file() {
        return $this->storagefolder . '/' . $this->datatype['name'] . '_temp.' . self::STORAGE_FILE_TYPE;
    }

    /**
     * Save data to the file.
     *
     * @param $data
     * @throws \moodle_exception
     */
    public function save_data($data) {
        $this->make_storage_datatype_by_user_folder();

        StorageHelper::save_in_file($this->storagefile, $data);
    }

    /**
     * Save file to the storage.
     *
     * Collects everything buffered for this storage into one export file. Holds the export
     * lock while it does, so two exports never work on the same files: with the lock held
     * elsewhere a routine export leaves the data for the next run, and an export that must
     * not be skipped ($datatype['waitforlock']) waits, then throws.
     *
     * @return \stored_file|null
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \file_exception
     * @throws \moodle_exception
     * @throws \stored_file_creation_exception
     */
    public function save_file() {
        $wait = !empty($this->datatype['waitforlock']);

        $lock = $this->get_export_lock($wait);
        if (!$lock) {
            if ($wait) {
                throw new \moodle_exception('failed_export_lock', 'local_intellidata', '', $this->datatype['name']);
            }
            if (CLI_SCRIPT) {
                mtrace("Export of {$this->datatype['name']} left for the next run: another export holds its lock.");
            }
            return null;
        }

        try {
            $this->stage_buffer();
            return $this->save_pending_files();
        } finally {
            $lock->release();
        }
    }

    /**
     * Take this storage's export lock.
     *
     * @param bool $wait
     * @return \core\lock\lock|false
     */
    protected function get_export_lock($wait) {
        return StorageHelper::get_export_lock($this->datatype['name'], $wait);
    }

    /**
     * Move this storage's live buffer into its pending folder.
     *
     * Each file is renamed, not copied. A writer that still has the old file open notices
     * the rename under its lock (StorageHelper::save_in_file) and starts a new file, and
     * save_pending_files() locks every pending file before reading it, so a writer that
     * got its lock first finishes before the file is read.
     *
     * @return void
     */
    protected function stage_buffer() {
        $files = [];

        if (empty($this->datatype['migrationmode'])) {
            $files = $this->get_user_files();
        }
        $files[] = $this->get_storage_file(false);

        foreach ($files as $file) {
            $this->move_to_pending($file);
        }
    }

    /**
     * Get the per-user buffer files (shards) of this storage.
     *
     * @return array
     */
    protected function get_user_files() {
        return glob($this->storagefolder . '/' . $this->datatype['name'] . '_' .
            self::STORAGE_FOLDER_BY_USER_NAME . '/user_*.' . self::STORAGE_FILE_TYPE) ?: [];
    }

    /**
     * Get the pending folder of this storage.
     *
     * Holds complete files waiting for the next export: buffers moved there by the export
     * itself, and blocks written by writers that flush early (cache storage). Nothing else
     * writes to a pending file, so no export can delete one while it is being written.
     *
     * @return string
     */
    public function get_pending_folder() {
        return make_temp_directory(self::STORAGE_FOLDER_NAME . '/' . $this->datatype['name'] . '_' . self::PENDING_FOLDER_NAME);
    }

    /**
     * Get a new, unique path in the pending folder.
     *
     * Names sort in the order they were created, so exports keep records in order.
     *
     * @param string $extension
     * @return string
     */
    protected function new_pending_path($extension = self::STORAGE_FILE_TYPE) {
        return $this->get_pending_folder() . '/' . sprintf('%.6F', microtime(true)) . '_' .
            random_string(8) . '.' . $extension;
    }

    /**
     * Move a buffer file into the pending folder, if it exists.
     *
     * @param string $file
     * @return void
     * @throws \moodle_exception
     */
    protected function move_to_pending($file) {
        if (!file_exists($file)) {
            return;
        }

        if (!@rename($file, $this->new_pending_path())) {
            clearstatcache(true, $file);
            if (file_exists($file)) {
                throw new \moodle_exception('failed_rename_tempfile', 'local_intellidata');
            }
        }
    }

    /**
     * Write a block of records to a new pending file.
     *
     * @param iterable $records
     * @return void
     * @throws \moodle_exception
     */
    public function write_pending_block($records) {
        StorageHelper::write_new_file($this->new_pending_path(), $records);
    }

    /**
     * Export every pending file of this storage as one data file.
     *
     * Call with the export lock held. Also exports a <name>_temp.csv left by an older
     * version of the plugin. Pending files are deleted only after the data file is saved;
     * if anything fails they stay for the next export.
     *
     * @return \stored_file|null
     * @throws \moodle_exception
     */
    protected function save_pending_files() {
        $pendingfolder = $this->get_pending_folder();

        // A run file is only ever a copy of pending files, so one left by a failed export is spare.
        foreach (glob($pendingfolder . '/*.' . self::RUN_FILE_TYPE) as $stalefile) {
            @unlink($stalefile);
        }

        $this->move_to_pending($this->get_temp_file());

        $files = glob($pendingfolder . '/*.' . self::STORAGE_FILE_TYPE);
        if (empty($files)) {
            return null;
        }
        sort($files);

        $runfile = $this->new_pending_path(self::RUN_FILE_TYPE);
        try {
            $files = $this->assemble_run_file($files, $runfile);

            clearstatcache(true, $runfile);
            if (filesize($runfile) > 0) {
                $params = [
                    'datatype' => $this->datatype['name'],
                    'filename' => StorageHelper::generate_filename(),
                    'tempdir' => $this->storagefolder,
                    'tempfile' => $runfile,
                ];

                $step = DatatypesHelper::get_datatype_export_step($this->datatype['name']);
                if ($this->datatype['rewritable'] && ($step < 2)) {
                    $this->delete_files();
                }

                $file = StorageHelper::save_file($params);
            } else {
                $file = null;
            }
        } catch (\Throwable $e) {
            @unlink($runfile);
            StorageHelper::report_failure("Export of {$this->datatype['name']} failed, its pending files are kept: " .
                $e->getMessage());
            throw $e;
        }

        @unlink($runfile);
        foreach ($files as $pendingfile) {
            if (!@unlink($pendingfile)) {
                StorageHelper::report_failure('Failed to remove exported pending file ' . $pendingfile);
            }
        }

        return $file;
    }

    /**
     * Join pending files into one run file.
     *
     * Files are separated by a newline and NUL bytes are dropped. Each file is locked
     * before it is read, which waits for a writer that locked it before it was moved.
     * A file that cannot be opened, locked or read to the end is left out and reported,
     * and stays for a later export, so one bad file never holds up the others.
     *
     * @param array $files
     * @param string $runfile
     * @return array The files joined into the run file.
     * @throws \moodle_exception
     */
    protected function assemble_run_file(array $files, $runfile) {
        $out = @fopen($runfile, 'x');
        if ($out === false) {
            throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $runfile);
        }

        $joined = [];
        $hasrecords = false;
        try {
            foreach ($files as $file) {
                // Read-write mode: on NFS an exclusive flock() is emulated with fcntl(), which needs it.
                $in = @fopen($file, 'r+');
                if ($in === false) {
                    $in = @fopen($file, 'r');
                }
                if ($in === false || !flock($in, LOCK_EX)) {
                    if ($in !== false) {
                        fclose($in);
                    }
                    StorageHelper::report_failure("Pending file {$file} cannot be read, it is left for a later export.");
                    continue;
                }

                $start = ftell($out);
                $hadrecords = $hasrecords;
                $first = true;
                try {
                    while (($chunk = fread($in, 262144)) !== false && $chunk !== '') {
                        $payload = str_replace("\0", '', $chunk);
                        if ($payload === '') {
                            continue;
                        }
                        if ($first && $hasrecords) {
                            $payload = PHP_EOL . $payload;
                        }
                        $first = false;
                        if (fwrite($out, $payload) !== strlen($payload)) {
                            throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $runfile);
                        }
                        $hasrecords = true;
                    }

                    // A read error looks like the end of the file to the loop, so confirm the end was reached.
                    $complete = feof($in);
                } finally {
                    flock($in, LOCK_UN);
                    fclose($in);
                }
                if (!$complete) {
                    // Take back what this file added, so a later export reads it whole.
                    if (!ftruncate($out, $start) || (fseek($out, $start) !== 0)) {
                        throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $runfile);
                    }
                    $hasrecords = $hadrecords;
                    StorageHelper::report_failure("Pending file {$file} could not be read to the end, " .
                        "it is left for a later export.");
                    continue;
                }

                $joined[] = $file;
            }

            if ($hasrecords && fwrite($out, PHP_EOL) !== strlen(PHP_EOL)) {
                throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $runfile);
            }
            if (!fflush($out)) {
                throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $runfile);
            }
        } finally {
            fclose($out);
        }

        return $joined;
    }

    /**
     * Retrieve files list.
     *
     * @param array $params
     * @return array
     * @throws \dml_exception
     */
    public function get_files($params = []) {
        global $CFG;
        require_once("$CFG->libdir/externallib.php");

        $context = \context_system::instance();
        $component = self::STORAGE_FILES_COMPONENT;

        $timestart = (!empty($params['timestart'])) ? $params['timestart'] : 0;
        $timeend = (!empty($params['timeend'])) ? $params['timeend'] : 0;

        return self::get_area_files(
            $context->id, $component, $this->datatype['name'], false, "timecreated", true, $timestart, $timeend
        );
    }

    /**
     * Retrieve files list from storage.
     *
     * @param $contextid
     * @param $component
     * @param $filearea
     * @param false $itemid
     * @param string $sort
     * @param bool $includedirs
     * @param int $timestart
     * @param int $timeend
     * @param int $limitfrom
     * @param int $limitnum
     * @param string $order
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_area_files($contextid, $component, $filearea, $itemid = false, $sort = "timecreated",
                                   $includedirs = true, $timestart = 0, $timeend = 0, $limitfrom = 0,
                                   $limitnum = 0, $order = 'ASC') {
        global $DB;

        $fs = get_file_storage();

        list($areasql, $conditions) = $DB->get_in_or_equal($filearea, SQL_PARAMS_NAMED);
        $conditions['contextid'] = $contextid;
        $conditions['component'] = $component;

        if ($itemid !== false && is_array($filearea)) {
            throw new coding_exception('You cannot specify multiple fileareas as well as an itemid.');
        } else if ($itemid !== false) {
            $itemidsql = ' AND f.itemid = :itemid ';
            $conditions['itemid'] = $itemid;
        } else {
            $itemidsql = '';
        }

        $timefiltersql = '';
        if (!empty($timestart)) {
            $conditions['timestart'] = $timestart;
            $timefiltersql .= 'AND f.timemodified >= :timestart';
        }
        if (!empty($timeend)) {
            $conditions['timeend'] = $timeend;
            $timefiltersql .= ' AND f.timemodified <= :timeend';
        }

        $includedirssql = '';
        if (!$includedirs) {
            $includedirssql = 'AND f.filename != :dot';
            $conditions['dot'] = '.';
        }

        if ($limitfrom && !$limitnum) {
            throw new coding_exception('If specifying $limitfrom you must also specify $limitnum');
        }

        $sql = "SELECT ".self::instance_sql_fields('f', 'r')."
                  FROM {files} f
             LEFT JOIN {files_reference} r
                       ON f.referencefileid = r.id
                 WHERE f.contextid = :contextid
                       AND f.component = :component
                       AND mimetype IS NOT NULL
                       AND f.filearea $areasql
                       $includedirssql
                       $timefiltersql
                       $itemidsql";
        if (!empty($sort)) {
            $sql .= " ORDER BY {$sort} {$order}";
        }

        $files = [];
        $filerecords = $DB->get_records_sql($sql, $conditions, $limitfrom, $limitnum);
        foreach ($filerecords as $filerecord) {
            $areafile = $fs->get_file_instance($filerecord);

            $file = [];
            $file['filename'] = $areafile->get_filename();
            $file['filearea'] = $areafile->get_filearea();
            $file['itemid'] = $areafile->get_itemid();
            $file['filepath'] = $areafile->get_filepath();
            $file['mimetype'] = $areafile->get_mimetype();
            $file['filesize'] = $areafile->get_filesize();
            $file['timemodified'] = $areafile->get_timemodified();
            $file['isexternalfile'] = $areafile->is_external_file();
            if ($file['isexternalfile']) {
                $file['repositorytype'] = $areafile->get_repository_type();
            }
            $fileitemid = $areafile->get_itemid();
            $file['fileurl'] = \moodle_url::make_webservice_pluginfile_url($contextid, $component, $filearea,
                $fileitemid, $areafile->get_filepath(), $areafile->get_filename())->out(false);
            $files[] = $file;
        }

        return $files;
    }

    /**
     * Get sql to retrieve fiels from storage.
     *
     * Get the sql formated fields for a file instance to be created from a
     * {files} and {files_refernece} join.
     *
     * @param string $filesprefix the table prefix for the {files} table
     * @param string $filesreferenceprefix the table prefix for the {files_reference} table
     * @return string the sql to go after a SELECT
     */
    private static function instance_sql_fields($filesprefix, $filesreferenceprefix) {
        $filefields = [
            'contenthash', 'pathnamehash', 'contextid', 'component', 'filearea',
            'itemid', 'filepath', 'filename', 'userid', 'filesize', 'mimetype', 'status', 'source',
            'author', 'license', 'timecreated', 'timemodified', 'sortorder', 'referencefileid',
        ];

        $referencefields = [
            'repositoryid' => 'repositoryid',
            'reference' => 'reference',
            'lastsync' => 'referencelastsync',
        ];
        $fields = [];
        $fields[] = $filesprefix.'.id AS id';
        foreach ($filefields as $field) {
            $fields[] = "{$filesprefix}.{$field}";
        }

        foreach ($referencefields as $field => $alias) {
            $fields[] = "{$filesreferenceprefix}.{$field} AS {$alias}";
        }

        return implode(', ', $fields);
    }


    /**
     * Update time modified files.
     *
     * @param int $timemodified
     *
     * @return void
     * @throws \dml_exception
     */
    public function update_timemodified_files($timemodified) {
        global $DB;

        $context = \context_system::instance();

        $conditions = [
            'contextid = :contextid',
            'component = :component',
            'filearea = :filearea',
        ];
        $sqlparams = [
            'contextid' => $context->id,
            'component' => self::STORAGE_FILES_COMPONENT,
            'filearea' => $this->datatype['name'],
        ];

        $wheresql = implode(' AND ', $conditions);

        $filerecords = $DB->get_records_sql(
            "SELECT *
                   FROM {files}
                  WHERE $wheresql
               ORDER BY itemid",
            $sqlparams
        );

        if ($filerecords) {
            foreach ($filerecords as $filerecord) {
                $filerecord->timemodified = $timemodified;
                $DB->update_record('files', $filerecord);
            }
        }
    }

    /**
     * Delete files from storage.
     *
     * @param null $params
     * @return int
     * @throws \dml_exception
     */
    public function delete_files($params = null) {
        global $DB;

        $fs = get_file_storage();
        $context = \context_system::instance();

        $conditions = [
            'contextid = :contextid',
            'component = :component',
        ];

        $sqlparams = [
            'contextid' => $context->id,
            'component' => self::STORAGE_FILES_COMPONENT,
        ];

        if (!empty($this->datatype['name'])) {
            $conditions[] = 'filearea = :filearea';
            $sqlparams['filearea'] = $this->datatype['name'];
        }

        if (!empty($params['timemodified'])) {
            $conditions[] = 'timemodified < :timemodified';
            $sqlparams['timemodified'] = $params['timemodified'];
        }

        if (!empty($params['itemid'])) {
            $conditions[] = 'itemid = :itemid';
            $sqlparams['itemid'] = $params['itemid'];
        }

        $wheresql = implode(' AND ', $conditions);

        $filerecords = $DB->get_records_sql(
            "SELECT *
                   FROM {files}
                  WHERE $wheresql
               ORDER BY itemid",
            $sqlparams
        );

        foreach ($filerecords as $filerecord) {
            $fs->get_file_instance($filerecord)->delete();
        }

        return count($filerecords);
    }

    /**
     * Delete the buffered files of this storage from the temp folder.
     *
     * Only this storage's own files: the whole folder used to be emptied, which threw away
     * the unexported records of every other datatype on each reset.
     *
     * @return bool
     */
    public function delete_temp_files() {
        // A writer that still has a removed shard open notices under its lock and starts a new one.
        $files = array_merge($this->get_user_files(), [$this->get_storage_file(false), $this->get_temp_file()]);
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return StorageHelper::delete_all_files($this->get_pending_folder());
    }
}
