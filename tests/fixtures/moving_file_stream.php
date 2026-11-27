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
 * Stream wrapper fixture that moves a file while a writer waits for its lock.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

/**
 * Stream wrapper over local files that moves the file the first time it is locked.
 *
 * Stands in for an export that renames a storage file between a writer opening it and
 * the writer getting its lock.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moving_file_stream {
    /** Protocol the wrapper is registered under. */
    const PROTOCOL = 'localintellidatamove';

    /** @var string|null Where to move the file on the next exclusive lock. */
    public static $moveto = null;

    /** @var resource|null Stream context, set by PHP. */
    public $context;

    /** @var resource Handle of the real file. */
    private $handle;

    /** @var string Path of the real file. */
    private $path;

    /**
     * Get the real path of a wrapper URL.
     *
     * @param string $url
     * @return string
     */
    private static function real_path($url) {
        return substr($url, strlen(self::PROTOCOL . '://'));
    }

    /**
     * Open the real file.
     *
     * @param string $url
     * @param string $mode
     * @param int $options
     * @param string|null $openedpath
     * @return bool
     */
    public function stream_open($url, $mode, $options, &$openedpath) {
        $this->path = self::real_path($url);
        $this->handle = @fopen($this->path, $mode);

        return $this->handle !== false;
    }

    /**
     * Move the file away on the first exclusive lock, then lock the handle.
     *
     * @param int $operation
     * @return bool
     */
    public function stream_lock($operation) {
        // PHP asks with 0 whether the stream can be locked at all.
        if ($operation === 0) {
            return true;
        }

        if ((self::$moveto !== null) && ($operation & LOCK_EX)) {
            rename($this->path, self::$moveto);
            self::$moveto = null;
        }

        return flock($this->handle, $operation);
    }

    /**
     * Stat the open handle.
     *
     * @return array|false
     */
    public function stream_stat() {
        return fstat($this->handle);
    }

    /**
     * Stat a path.
     *
     * @param string $url
     * @param int $flags
     * @return array|false
     */
    public function url_stat($url, $flags) {
        return @stat(self::real_path($url));
    }

    /**
     * Write to the real file.
     *
     * @param string $data
     * @return int|false
     */
    public function stream_write($data) {
        return fwrite($this->handle, $data);
    }

    /**
     * Flush the real file.
     *
     * @return bool
     */
    public function stream_flush() {
        return fflush($this->handle);
    }

    /**
     * Close the real file.
     *
     * @return void
     */
    public function stream_close() {
        fclose($this->handle);
    }
}
