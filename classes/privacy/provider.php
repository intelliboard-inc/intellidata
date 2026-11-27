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
 * Privacy Subsystem implementation for local_intellidata
 *
 * @package    local_intellidata
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

namespace local_intellidata\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

defined('MOODLE_INTERNAL') || die();

if (interface_exists('\core_privacy\local\request\core_userlist_provider')) {

    /**
     * ID userlist provider.
     */
    interface id_userlist_provider extends \core_privacy\local\request\core_userlist_provider {
    }
} else {
    /**
     * ID userlist provider.
     */
    interface id_userlist_provider {
    }
}

/**
 * Implementation of the privacy subsystem plugin provider for the intelliboard activity module.
 *
 * @copyright  2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\subsystem\provider,
        \core_privacy\local\request\subsystem\plugin_provider,
        id_userlist_provider {

    /**
     * Returns meta data about this system.
     *
     * @param   collection     $items The initialised collection to add items to.
     * @return  collection     A listing of user data stored through this system.
     */
    public static function get_metadata(collection $items): collection {
        // Every column below is checked against db/install.xml. The previous
        // declaration named four columns that do not exist on
        // local_intellidata_tracking (rel, type, instance, timecreated) while
        // omitting the ones that actually hold personal data, notably ip and
        // useragent.
        $items->add_database_table('local_intellidata_tracking', [
            'userid' => 'privacy:metadata:local_intellidata_tracking:userid',
            'courseid' => 'privacy:metadata:local_intellidata_tracking:courseid',
            'page' => 'privacy:metadata:local_intellidata_tracking:page',
            'param' => 'privacy:metadata:local_intellidata_tracking:param',
            'visits' => 'privacy:metadata:local_intellidata_tracking:visits',
            'timespend' => 'privacy:metadata:local_intellidata_tracking:timespend',
            'firstaccess' => 'privacy:metadata:local_intellidata_tracking:firstaccess',
            'lastaccess' => 'privacy:metadata:local_intellidata_tracking:lastaccess',
            'timemodified' => 'privacy:metadata:local_intellidata_tracking:timemodified',
            'useragent' => 'privacy:metadata:local_intellidata_tracking:useragent',
            'ip' => 'privacy:metadata:local_intellidata_tracking:ip',
        ], 'privacy:metadata:local_intellidata_tracking');

        // The 'local_intellidata_trdetails' table stores time spent per hour.
        $items->add_database_table('local_intellidata_trdetails', [
            'logid' => 'privacy:metadata:local_intellidata_trdetails:logid',
            'visits' => 'privacy:metadata:local_intellidata_trdetails:visits',
            'timespend' => 'privacy:metadata:local_intellidata_trdetails:timespend',
            'timepoint' => 'privacy:metadata:local_intellidata_trdetails:timepoint',
            'timemodified' => 'privacy:metadata:local_intellidata_trdetails:timemodified',
        ], 'privacy:metadata:local_intellidata_trdetails');

        // The 'local_intellidata_trlogs' table stores time spent per day.
        $items->add_database_table('local_intellidata_trlogs', [
            'trackid' => 'privacy:metadata:local_intellidata_trlogs:trackid',
            'visits' => 'privacy:metadata:local_intellidata_trlogs:visits',
            'timespend' => 'privacy:metadata:local_intellidata_trlogs:timespend',
            'timepoint' => 'privacy:metadata:local_intellidata_trlogs:timepoint',
            'timemodified' => 'privacy:metadata:local_intellidata_trlogs:timemodified',
        ], 'privacy:metadata:local_intellidata_trlogs');

        // The 'local_intellidata_config' table stores information about plugin configuration.
        $items->add_database_table('local_intellidata_config', [
            'tabletype' => 'privacy:metadata:local_intellidata_config:tabletype',
            'datatype' => 'privacy:metadata:local_intellidata_config:datatype',
            'status' => 'privacy:metadata:local_intellidata_config:status',
            'timemodified_field' => 'privacy:metadata:local_intellidata_config:timemodified_field',
            'rewritable' => 'privacy:metadata:local_intellidata_config:rewritable',
            'filterbyid' => 'privacy:metadata:local_intellidata_config:filterbyid',
            'events_tracking' => 'privacy:metadata:local_intellidata_config:events_tracking',
            'usermodified' => 'privacy:metadata:local_intellidata_config:usermodified',
            'timecreated' => 'privacy:metadata:local_intellidata_config:timecreated',
            'timemodified' => 'privacy:metadata:local_intellidata_config:timemodified',
        ], 'privacy:metadata:local_intellidata_config');

        return $items;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * In the case of intelliboard, that is any intelliboard where the user has made any post,
     * rated any content, or has any preferences.
     *
     * @param   int         $userid     The user to search.
     * @return  contextlist   $contextlist  The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $contextlist = new contextlist();

        // This returned an empty contextlist, and core drops a component whose
        // contextlist is empty (privacy/classes/manager.php), so the plugin was
        // reporting that it holds nothing for anybody: a subject access request
        // returned no IntelliData data and an erasure request never reached
        // delete_data_for_user().
        //
        // The plugin's tracking tables are site-wide and keyed by user rather
        // than owned by a course, so the system context is the honest place to
        // declare them - and it is added only when the user actually has rows,
        // so users with no tracking data are not reported as having any.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND EXISTS (
                       SELECT 1
                         FROM {local_intellidata_tracking} t
                        WHERE t.userid = :userid
                   )";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_SYSTEM,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        // The system context is the only one this plugin declares in
        // get_contexts_for_userid(), so export against that one and ignore any
        // other context the request happens to carry.
        $systemcontext = null;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $systemcontext = $context;
                break;
            }
        }

        if ($systemcontext === null) {
            return;
        }

        $user = $contextlist->get_user();

        // The synthetic key was a product of the three row ids, which collides
        // trivially (2*3*4 == 1*6*4) and so silently dropped rows from the
        // keyed result. The rows are built into a plain list instead, and
        // streamed so a heavily tracked user cannot exhaust memory.
        $sql = "SELECT t.id AS trackingid,
                       t.courseid,
                       t.page,
                       t.param,
                       t.visits,
                       t.timespend,
                       t.firstaccess,
                       t.lastaccess,
                       t.timemodified,
                       t.useragent,
                       t.ip,
                       l.id AS dayid,
                       l.timepoint AS day_time,
                       l.visits AS day_visits,
                       l.timespend AS day_timespent,
                       d.id AS hourid,
                       d.timepoint AS hour_time,
                       d.visits AS hour_visits,
                       d.timespend AS hour_timespent
                  FROM {local_intellidata_tracking} t
             LEFT JOIN {local_intellidata_trlogs} l ON l.trackid = t.id
             LEFT JOIN {local_intellidata_trdetails} d ON d.logid = l.id
                 WHERE t.userid = :userid
              ORDER BY t.id, l.id, d.id";

        $records = [];
        $recordset = $DB->get_recordset_sql($sql, ['userid' => $user->id]);
        foreach ($recordset as $record) {
            $records[] = $record;
        }
        $recordset->close();

        if (!empty($records)) {
            \core_privacy\local\request\writer::with_context($systemcontext)
                    ->export_data([], (object) [
                        'records' => $records,
                    ]);
        }
    }


    /**
     * Delete all data for all users in the specified context.
     *
     * @param   context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_SYSTEM) {
            // Matches the system context declared in
            // get_contexts_for_userid(): everything the plugin tracks is
            // site-wide, so a system-context erasure clears all of it.
            $DB->delete_records('local_intellidata_trdetails');
            $DB->delete_records('local_intellidata_trlogs');
            $DB->delete_records('local_intellidata_tracking');
        } else if ($context->contextlevel == CONTEXT_COURSE) {
            $params = [
                'courseid' => $context->instanceid,
            ];
            $items = $DB->get_records("local_intellidata_tracking", $params);

            foreach ($items as $item) {
                $logs = $DB->get_records("local_intellidata_trlogs", ['trackid' => $item->id]);

                foreach ($logs as $log) {
                    $DB->delete_records('local_intellidata_trdetails', [
                      'logid' => $log->id,
                    ]);
                }
                $DB->delete_records('local_intellidata_trlogs', [
                  'trackid' => $item->id,
                ]);
            }
            $DB->delete_records('local_intellidata_tracking', $params);
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            $params = [
                'page' => 'module',
                'param' => $context->instanceid,
            ];

            $items = $DB->get_records("local_intellidata_tracking", $params);

            foreach ($items as $item) {
                $logs = $DB->get_records("local_intellidata_trlogs", ['trackid' => $item->id]);

                foreach ($logs as $log) {
                    $DB->delete_records('local_intellidata_trdetails', [
                      'logid' => $log->id,
                    ]);
                }
                $DB->delete_records('local_intellidata_trlogs', [
                  'trackid' => $item->id,
                ]);
            }
            $DB->delete_records('local_intellidata_tracking', $params);
        }
        return;
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $userid = $user->id;

        $items = $DB->get_records("local_intellidata_tracking", ['userid' => $userid]);

        foreach ($items as $item) {
            $logs = $DB->get_records("local_intellidata_trlogs", ['trackid' => $item->id]);

            foreach ($logs as $log) {
                $DB->delete_records('local_intellidata_trdetails', [
                    'logid' => $log->id,
                ]);
            }
            $DB->delete_records('local_intellidata_trlogs', [
                'trackid' => $item->id,
            ]);
        }
        $DB->delete_records('local_intellidata_tracking', [
            'userid' => $userid,
        ]);
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param   userlist    $userlist   The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            // The plugin declares its tracking tables at system context in
            // get_contexts_for_userid(), so it must answer for that context
            // here too, otherwise the two directions disagree.
            $userlist->add_from_sql('userid', "SELECT userid FROM {local_intellidata_tracking}", []);
        } else if ($context->contextlevel == CONTEXT_COURSE) {
            $params = [
                'courseid' => $context->instanceid,
            ];
            $sql = "SELECT userid FROM {local_intellidata_tracking} WHERE courseid = :courseid";
            $userlist->add_from_sql('userid', $sql, $params);
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            $params = [
                'cmid' => $context->instanceid,
            ];
            $sql = "SELECT userid FROM {local_intellidata_tracking} WHERE page = 'module' AND param = :cmid";
            $userlist->add_from_sql('userid', $sql, $params);
        }
    }
    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $users = $userlist->get_userids();

        foreach ($users as $userid) {
            $items = $DB->get_records("local_intellidata_tracking", ['userid' => $userid]);

            foreach ($items as $item) {
                $logs = $DB->get_records("local_intellidata_trlogs", ['trackid' => $item->id]);

                foreach ($logs as $log) {
                    $DB->delete_records('local_intellidata_trdetails', [
                      'logid' => $log->id,
                    ]);
                }
                $DB->delete_records('local_intellidata_trlogs', [
                  'trackid' => $item->id,
                ]);
            }
            $DB->delete_records('local_intellidata_tracking', [
              'userid' => $userid,
            ]);
        }
    }
}
