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
 *
 * @package    local_intellidata
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

namespace local_intellidata\services;

use local_intellidata\helpers\DebugHelper;
use local_intellidata\helpers\ParamsHelper;
use local_intellidata\helpers\SettingsHelper;
use moodle\mod\lti\OAuthConsumer;
use moodle\mod\lti\OAuthRequest;
use moodle\mod\lti\OAuthSignatureMethod_HMAC_SHA1;

/**
 *
 * @package    local_intellidata
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class lti_service {
    /** @var mixed LTI endpoint */
    private $endpoint;

    /** @var mixed LTI consumer key */
    private $key;

    /** @var mixed LTI shared secret */
    private $secret;

    /** @var bool LTI debug mode */
    private $debug;

    /**
     * Role assignments written per committed transaction.
     */
    const ROLE_CHUNK_SIZE = 500;

    /**
     * Set endpoint for LTI
     *
     * @param string $endpoint
     */
    public function set_endpoint($endpoint) {
        $this->endpoint = $endpoint;
    }

    /**
     * lti_service constructor.
     * @throws \dml_exception
     */
    public function __construct() {
        $this->endpoint = SettingsHelper::get_setting('ltitoolurl');
        $this->key = SettingsHelper::get_setting('lticonsumerkey');
        $this->secret = SettingsHelper::get_setting('ltisharedsecret');
        $this->debug = SettingsHelper::get_setting('ltidebug');

    }

    /**
     * Get signed parameters for LTI request
     *
     * @param array $customparameters [param_key => param_value]
     * @return array
     */
    private function lti_request_params($customparameters) {
        global $USER;

        $requestparams = [
            'user_id' => $USER->id,
            'lis_person_contact_email_primary' => $USER->email,
            'lis_person_name_given' => $USER->firstname,
            'lis_person_name_family' => $USER->lastname,
            'lis_person_name_full' => fullname($USER),
            'ext_user_username' => $USER->username,
            'lti_message_type' => 'basic-lti-launch-request',
            'lti_version' => 'LTI-1p0',
            'resource_link_id' => 0,
        ];

        $requestparams = array_merge($requestparams, $customparameters);

        return $this->lti_sign_parameters($requestparams);
    }

    /**
     * Get Lti role.
     *
     * @return \stdClass
     */
    public static function get_lti_role() {
        global $DB;

        return $DB->get_record('role', ['id' => get_config(ParamsHelper::PLUGIN, 'ibnltirole')]);
    }

    /**
     * Reduce a caller-supplied list to unique positive integer ids.
     *
     * @param mixed $list Raw value as received from the web service.
     * @return array List of unique positive integers, re-indexed from zero.
     */
    private static function clean_id_list($list) {
        if (!is_array($list)) {
            throw new \invalid_parameter_exception('Expected a list of ids');
        }

        $ids = [];
        foreach ($list as $value) {
            // Reject rather than coerce. clean_param(PARAM_INT) reduces a value
            // like '1) OR 1=1 --' to 1, which is a real user id, so a malformed
            // payload would have quietly granted the role to whoever holds it
            // instead of being discarded. Cast before ctype_digit(), which reads
            // a small int argument as a character code.
            $value = is_scalar($value) ? trim((string)$value) : '';

            // Reject the whole payload rather than dropping what cannot be
            // parsed. An empty list is a meaningful instruction - it clears the
            // role - so silently reducing garbage to an empty list would turn a
            // malformed request into "unassign everyone".
            if ($value === '' || !ctype_digit($value)) {
                throw new \invalid_parameter_exception('Expected a positive integer id, got: ' . $value);
            }

            $id = clean_param($value, PARAM_INT);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Set Lti role.
     *
     * @param array $ids
     * @param array $roles
     *
     * @return void
     */
    public function set_lti_role($ids = [], $roles = []) {

        // User ids and role ids are always positive integers. Coercing them here
        // is a no-op for every legitimate value - the resolved user set is
        // unchanged - and it guarantees the values reaching the queries below
        // cannot carry SQL. The values arrive from a web service as PARAM_RAW
        // and are persisted verbatim in adhoc task custom data, so they are not
        // cleaned anywhere else on the way in.
        $ids = self::clean_id_list($ids);
        $roles = self::clean_id_list($roles);

        if (!$role = self::get_lti_role()) {
            return;
        }

        $context = \context_system::instance();

        // Use lock to prevent concurrent execution and race conditions.
        $locktype = 'local_intellidata_set_lti_role';
        $lockfactory = \core\lock\lock_config::get_lock_factory($locktype);
        $lockkey = 'lti_role_' . $role->id . '_' . $context->id;

        // Try to acquire lock with 30 second timeout.
        $lock = $lockfactory->get_lock($lockkey, 30);

        if (!$lock) {
            // If we can't get the lock, another task is already processing roles.
            // Log and return - the other task will handle it.
            DebugHelper::error_log('Could not acquire lock for set_lti_role, another task is processing');
            return;
        }

        try {
            $target = self::resolve_target_users($ids, $roles);
            $current = self::current_role_holders($role->id, $context->id);

            // Reconcile rather than rebuild. The previous implementation
            // unassigned every holder and reassigned the whole population on
            // every run, inside one transaction. $roles resolves to every user
            // holding those roles anywhere on the site, so on a large tenant
            // that was tens of thousands of role_assign() calls, each with its
            // own buffered event and context-dirty marker, none of which are
            // flushed until commit - the task exhausted memory or timed out and
            // then retried from the beginning forever.
            //
            // A diff writes nothing at all when the population has not changed,
            // and the chunked commits below mean a run that does fail has still
            // made progress: the next run diffs again and finishes the rest.
            $toassign = array_diff_key($target, $current);
            $tounassign = array_diff_key($current, $target);

            self::apply_role_changes($role->id, $context->id, $toassign, $tounassign);
        } catch (\Exception $e) {
            // Nothing has been written at this point unless apply_role_changes()
            // was already running, and a malformed payload is rejected before
            // that. Re-raising leaves the existing assignments untouched, which
            // is what the raw-SQL version did when its query failed.
            DebugHelper::error_log('Error in set_lti_role: ' . $e->getMessage());
            DebugHelper::error_log('set_lti_role: ids: ' . implode(',', $ids) . ' roles: ' . implode(',', $roles));

            throw $e;
        } finally {
            // Always release the lock, even if an exception occurs.
            $lock->release();
        }
    }

    /**
     * Resolve the set of users that should hold the LTI role.
     *
     * @param array $ids Explicit user ids, already cleaned.
     * @param array $roles Role ids whose holders are included, already cleaned.
     * @return array User id => user id.
     */
    private static function resolve_target_users(array $ids, array $roles) {
        global $DB;

        if (!$ids && !$roles) {
            return [];
        }

        $sqlparams = [];
        $selects = [];

        if ($ids) {
            [$idsql, $idparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'uid');
            $sqlparams = array_merge($sqlparams, $idparams);
            $selects[] = " SELECT u.id
                             FROM {user} u
                            WHERE u.id $idsql ";
        }

        if ($roles) {
            [$rolesql, $roleparams] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED, 'rid');
            $sqlparams = array_merge($sqlparams, $roleparams);
            $selects[] = " SELECT DISTINCT u.id
                             FROM {user} u
                             JOIN {role_assignments} ra ON ra.userid = u.id
                            WHERE ra.roleid $rolesql ";
        }

        $target = [];
        $recordset = $DB->get_recordset_sql(implode(' UNION ', $selects), $sqlparams);
        foreach ($recordset as $record) {
            $target[$record->id] = $record->id;
        }
        $recordset->close();

        return $target;
    }

    /**
     * Users currently holding the role in the given context.
     *
     * @param int $roleid
     * @param int $contextid
     * @return array User id => user id.
     */
    private static function current_role_holders($roleid, $contextid) {
        global $DB;

        $current = [];
        $recordset = $DB->get_recordset_sql(
            'SELECT DISTINCT userid FROM {role_assignments} WHERE roleid = :roleid AND contextid = :contextid',
            ['roleid' => $roleid, 'contextid' => $contextid]
        );
        foreach ($recordset as $record) {
            $current[$record->userid] = $record->userid;
        }
        $recordset->close();

        return $current;
    }

    /**
     * Apply the assignment diff in bounded, separately committed chunks.
     *
     * @param int $roleid
     * @param int $contextid
     * @param array $toassign User ids gaining the role.
     * @param array $tounassign User ids losing it.
     * @return void
     */
    private static function apply_role_changes($roleid, $contextid, array $toassign, array $tounassign) {
        global $DB;

        // Core's role_assign() and role_unassign_all() are the only supported
        // ways to write role_assignments: they record the component and
        // provenance, fire role_assigned / role_unassigned, and invalidate the
        // access caches. The INSERT ... SELECT fast path this replaced did none
        // of those, so an assignment could stay invisible to has_capability()
        // until the caches expired on their own.
        //
        // Moodle buffers events for the duration of a transaction, so the chunk
        // size is what bounds both the event buffer and the lock the writes
        // hold.
        foreach (array_chunk($tounassign, self::ROLE_CHUNK_SIZE) as $chunk) {
            $transaction = $DB->start_delegated_transaction();
            foreach ($chunk as $userid) {
                role_unassign_all(['roleid' => $roleid, 'contextid' => $contextid, 'userid' => $userid]);
            }
            $transaction->allow_commit();
        }

        foreach (array_chunk($toassign, self::ROLE_CHUNK_SIZE) as $chunk) {
            $transaction = $DB->start_delegated_transaction();
            foreach ($chunk as $userid) {
                role_assign($roleid, $userid, $contextid);
            }
            $transaction->allow_commit();
        }
    }

    /**
     * Lti sign parameters.
     *
     * @param $oldparms
     * @return array|null
     */
    public function lti_sign_parameters($oldparms) {
        global $CFG;

        // Moodle core ships this OAuth 1.0a library at mod/lti/OAuth.php under
        // the moodle\mod\lti namespace. The plugin used to carry its own
        // partial fork of six of its classes, which had dropped
        // OAuthUtil::parse_parameters() while OAuthRequest still called it - so
        // any ltitoolurl containing a query string fatally errored the launch.
        // Consuming core's copy fixes that and means the plugin inherits core's
        // fixes to security-relevant signing code.
        require_once($CFG->dirroot . '/mod/lti/OAuth.php');

        $parms = $oldparms;
        $hmacmethod = new OAuthSignatureMethod_HMAC_SHA1();
        $testconsumer = new OAuthConsumer($this->key, $this->secret, null);
        $accreq = OAuthRequest::from_consumer_and_token(
            $testconsumer, '', "POST", $this->endpoint, $parms
        );
        $accreq->sign_request($hmacmethod, $testconsumer, '');
        $newparms = $accreq->get_parameters();

        return $newparms;
    }

    /**
     * Return the launch data required for opening the attendance tool.
     *
     * @param $customparams
     * @return array the endpoint URL and parameters (including the signature)
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function lti_get_launch_data($customparams = []) {
        if (!empty($this->key) && !empty($this->secret) && !empty($this->endpoint)) {
            $parms = $this->lti_request_params($customparams);

            $endpointurl = new \moodle_url(
                SettingsHelper::get_setting('ltitoolurl')
            );
            $endpointparams = $endpointurl->params();

            // Strip querystring params in endpoint url from $parms to avoid duplication.
            if (!empty($endpointparams) && !empty($parms)) {
                foreach (array_keys($endpointparams) as $paramname) {
                    if (isset($parms[$paramname])) {
                        unset($parms[$paramname]);
                    }
                }
            }

        } else {
            // Was echo + exit, which wrote six untranslated words straight to
            // the response body and halted, bypassing the page renderer - so a
            // user with the viewlti capability on a site whose LTI credentials
            // are unset got a blank white page instead of a Moodle error page.
            throw new \moodle_exception('pluginnotconfigured', 'local_intellidata');
        }

        return [$this->endpoint, $parms, $this->debug];
    }
}
