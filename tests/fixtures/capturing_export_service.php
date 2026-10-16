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
 * Export service fixture that keeps exported rows in memory.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\services\export_service;

/**
 * Export service fixture that keeps exported rows in memory.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capturing_export_service extends export_service {
    /** @var array Ids of the exported rows, in export order. */
    public $exportedids = [];

    /** @var callable|null Called once after the first page is saved. */
    public $afterfirstpage = null;

    /** @var int|null Throw when this page number is stored. */
    public $failonpage = null;

    /** @var int */
    private $pages = 0;

    /**
     * Keep the ids of the stored rows instead of writing them to storage.
     *
     * @param string $datatype
     * @param string $data
     */
    public function store_data($datatype, $data) {
        if ($this->failonpage !== null && ($this->pages + 1) == $this->failonpage) {
            throw new \coding_exception('Simulated export failure.');
        }

        foreach (explode(PHP_EOL, $data) as $line) {
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line, ',', '"', '\\');
            $this->exportedids[] = (int)$values[0];
        }
    }

    /**
     * Count pages instead of writing files.
     *
     * @param array $params
     * @return array
     */
    public function save_files($params = []) {
        $this->pages++;

        if ($this->pages == 1 && $this->afterfirstpage) {
            call_user_func($this->afterfirstpage);
        }

        return [];
    }

    /**
     * No files are written.
     *
     * @param array $params
     * @return array
     */
    public function get_files($params = []) {
        return [];
    }
}
