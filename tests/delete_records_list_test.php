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
 * delete_records_list() export test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026 intelliboard.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\helpers\SettingsHelper;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/local/intellidata/tests/setup_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/generator.php');
require_once($CFG->dirroot . '/local/intellidata/tests/test_helper.php');
require_once($CFG->dirroot . '/local/intellidata/tests/custom_db_client_testcase.php');

/**
 * delete_records_list() export test case.
 *
 * Core routes delete_records_list() through $this->delete_records_select(), whose driver
 * override already exports one delete per matched id. A second exporter on
 * delete_records_list() itself duplicated the first id (master) or exported the matched
 * column's value as an id (develop/staging), deleting an unrelated warehouse row.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026 intelliboard.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_records_list_test extends custom_db_client_testcase {
    /**
     * A delete keyed on a non-id column exports exactly one delete per deleted row.
     *
     * @covers \local_intellidata\services\new_export_service::delete_records_select_event
     */
    public function test_non_id_field_exports_each_deleted_id_once(): void {
        global $DB;

        $this->resetAfterTest(true);

        if (!$this->newexportavailable) {
            $this->markTestSkipped('The custom DB driver needs Moodle 3.8 or later.');
        }

        SettingsHelper::set_setting('newtracking', 1);

        $course = generator::create_course(['shortname' => 'ibdeletelistcourse']);
        for ($i = 1; $i <= 3; $i++) {
            $user = generator::create_user(['username' => 'unittest_deletelist_user' . $i]);
            generator::enrol_user(['userid' => $user->id, 'courseid' => $course->id]);
        }

        $enrolid = $DB->get_field('enrol', 'id', ['courseid' => $course->id, 'enrol' => 'manual'], MUST_EXIST);
        $ueids = array_keys($DB->get_records('user_enrolments', ['enrolid' => $enrolid], 'id', 'id'));
        $this->assertCount(3, $ueids);

        $DB->delete_records_list('user_enrolments', 'enrolid', [$enrolid]);

        $this->assertSame($ueids, $this->exported_delete_ids('enrolments'));
    }

    /**
     * Ids of every exported delete for a datatype, sorted, duplicates kept.
     *
     * @param string $datatype
     * @return int[]
     */
    private function exported_delete_ids(string $datatype): array {
        global $DB;

        $ids = [];
        foreach ($DB->get_records('local_intellidata_storage', ['datatype' => $datatype]) as $record) {
            $data = json_decode($record->data);
            if (($data->crud ?? null) === 'd') {
                $ids[] = (int)$data->id;
            }
        }
        sort($ids);

        return $ids;
    }
}
