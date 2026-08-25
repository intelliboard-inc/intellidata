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
 * @copyright  2022 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

use local_intellidata\api\apilib;
use local_intellidata\helpers\DBHelper;
use local_intellidata\repositories\reports_repository;
use local_intellidata\services\encryption_service;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2022 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class local_intellidata_sqlreportlib extends external_api {

    /**
     * Statement keywords a stored report may never contain.
     *
     * A stored report is read-only by definition. Without this a single
     * compromise of the vendor-side credential turns save_report() plus
     * run_report() into arbitrary SQL execution against the whole Moodle
     * database.
     */
    const FORBIDDEN_SQL_KEYWORDS = [
        'ALTER', 'ATTACH', 'CALL', 'COMMIT', 'COPY', 'CREATE', 'DELETE', 'DETACH',
        'DO', 'DROP', 'EXEC', 'EXECUTE', 'GRANT', 'IMPORT', 'INSERT', 'INTO',
        'LOAD', 'LOCK', 'MERGE', 'PRAGMA', 'PREPARE', 'RENAME',
        'REVOKE', 'ROLLBACK', 'SAVEPOINT', 'SET', 'TRUNCATE', 'UNLOCK',
        'UPDATE', 'UPSERT', 'VACUUM',
    ];

    /**
     * Function names a stored report may never call.
     *
     * Matched as prefixes, because these are the file, shell and
     * denial-of-service primitives and several of them carry a suffix
     * (LOAD_FILE, UTL_HTTP) that a word-boundary keyword match would step over.
     */
    const FORBIDDEN_SQL_FUNCTION_PREFIXES = [
        'BENCHMARK', 'DBLINK', 'DBMS_', 'LO_EXPORT', 'LO_FROM_BYTEA', 'LO_GET',
        'LO_IMPORT', 'LO_PUT', 'LOAD_EXTENSION', 'LOAD_FILE', 'OPENDATASOURCE',
        'OPENQUERY', 'OPENROWSET', 'PG_BACKEND_PID', 'PG_LS_', 'PG_READ_',
        'PG_SLEEP', 'PG_STAT_FILE', 'PG_TERMINATE_BACKEND', 'QUERY_TO_XML',
        'READTEXT', 'SLEEP', 'SYS_EXEC', 'UTL_FILE', 'UTL_HTTP', 'WRITETEXT',
        'XP_CMDSHELL',
    ];

    /**
     * Blank out string literals and comments, left to right.
     *
     * This has to be one pass. Running independent replacements lets a construct
     * opened inside another be treated as though it were top level: a `--` sitting
     * inside a string literal would end a "comment" that never started, hiding the
     * rest of the line from the keyword scan while the engine still executes it.
     * The same applies in reverse for a quote inside a comment.
     *
     * MySQL's executable comments are deliberately not treated as comments. The
     * server runs the contents of `/*! ... *' . '/`, so blanking them would hide
     * live code.
     *
     * @param string $sql
     * @return string The body with literal and comment contents replaced by spaces.
     */
    private static function strip_literals_and_comments($sql, &$unterminated = false) {
        $mysql = DBHelper::is_mysql_type();
        $len = strlen($sql);
        $out = '';
        $i = 0;
        $unterminated = false;

        while ($i < $len) {
            $c = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Line comment.
            if ($c === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $out .= ' ';
                continue;
            }

            // Hash line comment, MySQL family only.
            if ($mysql && $c === '#') {
                while ($i < $len && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $out .= ' ';
                continue;
            }

            // Block comment. /*! and /*+ are executed rather than ignored, so the
            // contents are kept and only the delimiters are neutralised.
            if ($c === '/' && $next === '*') {
                $executable = ($i + 2 < $len) && ($sql[$i + 2] === '!' || $sql[$i + 2] === '+');
                $close = strpos($sql, '*/', $i + 2);

                if ($close === false) {
                    $unterminated = true;

                    return $out;
                }

                $body = substr($sql, $i + 2, $close - $i - 2);
                $out .= $executable ? ' ' . $body . ' ' : ' ';
                $i = $close + 2;
                continue;
            }

            // PostgreSQL dollar-quoted string: $tag$ ... $tag$, tag optional.
            // Modelled rather than left to the generic quote handling, because
            // an apostrophe inside one is ordinary text and would otherwise open
            // a literal that never closes.
            if (!$mysql && $c === '$' && preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $m)) {
                $tag = $m[0];
                $close = strpos($sql, $tag, $i + strlen($tag));

                if ($close === false) {
                    $unterminated = true;

                    return $out;
                }

                $out .= " '' ";
                $i = $close + strlen($tag);
                continue;
            }

            // String literal, and double-quoted text where the engine reads it
            // as one. PostgreSQL escape strings, E'...', honour a backslash
            // escape whatever standard_conforming_strings is set to.
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $escapes = $mysql;

                if (!$mysql && $quote === "'" && $i > 0) {
                    $prev = $sql[$i - 1];
                    $before = ($i > 1) ? $sql[$i - 2] : ' ';
                    if (($prev === 'E' || $prev === 'e') && !preg_match('/[A-Za-z0-9_]/', $before)) {
                        $escapes = true;
                    }
                }

                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($escapes && $sql[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        if (($i + 1 < $len) && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }

                if (!$closed) {
                    // Fail closed. Modelling every string syntax every engine
                    // supports is not achievable, and the cost of getting it
                    // wrong is that the scan runs to end of input and hides
                    // whatever follows. An unterminated literal is a syntax
                    // error on every engine, so refusing it loses nothing.
                    $unterminated = true;

                    return $out;
                }

                $out .= ' ' . $quote . $quote . ' ';
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Reject any stored report body that is not a single read-only statement.
     *
     * @param string $sqlcode The report body as supplied by the remote service.
     * @return string|null Null when acceptable, otherwise the reason code.
     */
    public static function validate_report_sql($sqlcode) {
        $sql = (string)$sqlcode;

        $unterminated = false;
        $stripped = self::strip_literals_and_comments($sql, $unterminated);

        if ($unterminated) {
            return 'reportsqlunterminatedliteral';
        }

        if (trim($stripped) === '') {
            return 'reportsqlempty';
        }

        // One statement only. A trailing semicolon is allowed.
        if (strpos(rtrim(trim($stripped), ';'), ';') !== false) {
            return 'reportsqlmultiplestatements';
        }

        // Must be a query.
        if (!preg_match('/^\s*\(*\s*(SELECT|WITH)\b/i', $stripped)) {
            return 'reportsqlnotselect';
        }

        foreach (self::FORBIDDEN_SQL_KEYWORDS as $keyword) {
            if (preg_match('/(?<![A-Za-z0-9_])' . $keyword . '(?![A-Za-z0-9_])/i', $stripped)) {
                return 'reportsqlforbiddenkeyword';
            }
        }

        foreach (self::FORBIDDEN_SQL_FUNCTION_PREFIXES as $prefix) {
            if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($prefix, '/') . '/i', $stripped)) {
                return 'reportsqlforbiddenkeyword';
            }
        }

        return null;
    }

    /**
     * The output column names of a report.
     *
     * @param int $reportid
     * @param string $sqlcode The stored report body, before filter substitution.
     * @param string $query The report body with filters already substituted.
     * @param array $sqlparams Bound parameters for that body.
     * @return array|null Column names, or null when they could not be determined.
     */
    private static function report_columns($reportid, $sqlcode, $query, array $sqlparams) {
        global $DB;

        // Filter substitution only ever adds WHERE and ORDER BY clauses, so the
        // output column list is a property of the stored body alone. Caching it
        // against a hash of that body means a searched request costs one query
        // rather than two - these are customer analytics queries, and probing
        // on every keystroke of a server-side search doubled their cost.
        // Re-saving the report changes the hash, so the list is re-probed.
        $cache = \cache::make('local_intellidata', 'sqlreportcolumns');
        $cachekey = $reportid . '_' . sha1($sqlcode);

        $columns = $cache->get($cachekey);
        if (is_array($columns) && $columns) {
            return $columns;
        }

        // One row is enough to learn the column labels.
        $probe = $DB->get_records_sql($query, $sqlparams, 0, 1);

        if (empty($probe)) {
            // A report that currently matches nothing tells us nothing about its
            // columns. That is not a bad column name, so nothing is cached and
            // the caller skips the filter rather than reporting an error.
            return null;
        }

        $columns = array_keys((array)reset($probe));
        $cache->set($cachekey, $columns);

        return $columns;
    }

    /**
     * Resolve a caller-supplied search column against a report's own output columns.
     *
     * The returned value always comes from the result-set metadata, never from
     * the caller, so it cannot carry SQL.
     *
     * @param array $columns The report's own output column names.
     * @param string $searchcolumn The column name the caller asked to search.
     * @return string|null The quoted canonical column, or null if there is no such column.
     */
    private static function resolve_report_column(array $columns, $searchcolumn) {
        $wanted = trim((string)$searchcolumn);

        if ($wanted === '') {
            return null;
        }

        foreach ($columns as $column) {
            if (strcasecmp($column, $wanted) === 0) {
                return self::quote_identifier($column);
            }
        }

        return null;
    }

    /**
     * Quote an identifier that came from result-set metadata.
     *
     * @param string $identifier
     * @return string
     */
    private static function quote_identifier($identifier) {
        // Simple identifiers need no quoting on any engine.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            return $identifier;
        }

        // Report aliases may contain spaces. The value is a column label the
        // database itself reported, so quoting it is safe; only the quote
        // character differs between engines.
        if (DBHelper::is_mysql_type()) {
            // The backtick is built with chr(96) because the Moodle
            // standard forbids backtick literals in strings, and MySQL has no
            // other identifier quote unless ANSI_QUOTES is set, which Moodle
            // does not set.
            $quote = chr(96);

            return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Parameters for run_report() method.
     *
     * @return external_function_parameters
     */
    public static function run_report_parameters() {
        return new external_function_parameters([
            'data' => new external_value(PARAM_RAW, 'Request params'),
        ]);
    }

    /**
     * Run sql report in Moodle.
     *
     * @param $data
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     */
    public static function run_report($data) {
        global $DB;

        try {
            apilib::check_auth();
        } catch (\moodle_exception $e) {
            return [
                'data' => $e->getMessage(),
                'status' => apilib::STATUS_ERROR,
            ];
        }

        $context = context_system::instance();
        self::validate_context($context);

        $params = self::validate_parameters(self::run_report_parameters(), ['data' => $data]);

        // Validate if credentials not empty.
        $encryptionservice = new encryption_service();

        if (!$encryptionservice->validate_credentials()) {
            return [
                'data' => 'emptycredentials',
                'status' => apilib::STATUS_ERROR,
            ];
        }

        // Validate parameters.
        $params = (object) apilib::validate_parameters($params['data'], [
            'external_identifier' => PARAM_TEXT,
            'debug' => PARAM_INT,
            'sortdir' => PARAM_ALPHA,
            'sortcol' => PARAM_INT,
            'search_value' => PARAM_TEXT,
            'search_column' => PARAM_TEXT,
            'timestart' => PARAM_INT,
            'timefinish' => PARAM_INT,
            'courses' => PARAM_SEQUENCE,
            'start' => PARAM_INT,
            'length' => PARAM_INT,
        ]);

        $report = reports_repository::get_by_external_identifier($params->external_identifier);
        $data = [];

        // The active/inactive toggle on sql_reports/report.php, and the
        // status = 0 that save_report() writes on every store, were both
        // decorative: run_report() fetched the record by external identifier
        // and executed its sqlcode without ever reading the flag. An
        // administrator who marked a report inactive had not stopped it
        // running.
        if ($report && empty($report->status)) {
            return [
                'data' => 'reportinactive',
                'status' => apilib::STATUS_ERROR,
            ];
        }

        if ($report) {
            // A stored body may end in a statement terminator. The search path
            // wraps the body in an outer SELECT and the column probe appends a
            // LIMIT, and a semicolon in the middle of either is a syntax error,
            // so drop it here as well as normalising it away in save_report().
            $query = rtrim(trim($report->sqlcode), '; ');
            $sqlparams = [];

            // Prepare sorting.
            if (strrpos($query, ':sorting') !== false) {
                $params->sortcol = $params->sortcol + 1;

                // PARAM_ALPHA lets any alphabetic token through into the
                // ORDER BY position; only the two real sort directions are.
                $sortdir = (strtoupper((string)$params->sortdir) === 'DESC') ? 'DESC' : 'ASC';

                if ($params->sortdir && $params->sortcol) {
                    $sorting = " ORDER BY {$params->sortcol} {$sortdir}";
                    $query = str_replace(":sorting", $sorting, $query);
                } else {
                    $query = str_replace(":sorting", "", $query);
                }
            }

            // Filter data with datefilter.
            if (strpos($query, ':datefilter[') !== false) {
                $filterstart = strpos($query, ':datefilter[');
                $columnstart = $filterstart + 12;
                $columnend = strpos($query, ']', $filterstart) - $columnstart;
                $columnname = substr($query, $columnstart, $columnend);
                $val = ":datefilter[{$columnname}]";

                $params->timestart = isset($params->timestart) ? $params->timestart : false;
                $params->timefinish = isset($params->timefinish) ? $params->timefinish : false;

                if ($params->timestart && $params->timefinish && $columnname) {
                    $sqlparams['timestart'] = $params->timestart;
                    $sqlparams['timefinish'] = $params->timefinish;
                    $like = " AND {$columnname} BETWEEN :timestart AND :timefinish ";
                    $query = str_replace($val, $like, $query);
                } else {
                    $query = str_replace($val, "", $query);
                }
            }

            // Filter data with course filter.
            if ($coursefilter = strpos($query, ':coursefilter[')) {
                $filterstart = $coursefilter + 14;
                $filterend = strpos($query, ']', $coursefilter) - $filterstart;
                $columnname = substr($query, $filterstart, $filterend);
                $val = ":coursefilter[{$columnname}]";

                $params->courses = isset($params->courses) ? $params->courses : false;

                if ($params->courses && $columnname) {
                    list($sql, $coursefilter) = $DB->get_in_or_equal(explode(",", $params->courses), SQL_PARAMS_NAMED, 'courses');
                    $sqlparams = array_merge($sqlparams, $coursefilter);
                    $like = " AND {$columnname} {$sql} ";
                    $query = str_replace($val, $like, $query);
                } else {
                    $query = str_replace($val, "", $query);
                }
            }

            // Filter data with additional filters.
            if (strrpos($query, ':filter') !== false) {
                $query = str_replace(":filter", "", $query);

                // The addslashes() call protected nothing - search_value is bound
                // as :build_by_sql_search below and escaped by the driver - it
                // only inserted literal backslashes into the value, so a search
                // for O'Brien matched only rows containing O\'Brien.
                $searchvalue = isset($params->search_value) ? $params->search_value : false;
                $searchcolumn = isset($params->search_column) ? $params->search_column : false;

                if (($searchvalue || $searchvalue === '0') && $searchcolumn) {
                    // The search_column value is PARAM_TEXT, which permits backticks,
                    // quotes, parentheses and every other SQL metacharacter,
                    // and it lands in the field position of sql_like(), which
                    // parameterises only the pattern. The backtick wrapping
                    // this replaces was not a defence: a payload containing a
                    // backtick closed it, and on PostgreSQL and Oracle no
                    // wrapping was applied at all.
                    //
                    // Resolve it against the report's own output columns and
                    // emit the canonical name from that list, never the
                    // submitted token.
                    $columns = self::report_columns($report->id, $report->sqlcode, $query, $sqlparams);

                    // No column list means the report matched no rows. Adding a
                    // search filter cannot change an empty result set, so skip
                    // it: unknownsearchcolumn is reserved for a name that
                    // genuinely is not among the report's output columns.
                    if ($columns !== null) {
                        $canonicalcolumn = self::resolve_report_column($columns, $searchcolumn);

                        if ($canonicalcolumn === null) {
                            return [
                                'data' => 'unknownsearchcolumn',
                                'status' => apilib::STATUS_ERROR,
                            ];
                        }

                        $key = 'build_by_sql_search';
                        $like = $DB->sql_like($canonicalcolumn, ':' . $key, false, false);
                        $query = "SELECT t.*
                                        FROM ({$query}) t
                                       WHERE t." . $like;
                        $sqlparams[$key] = '%' . $searchvalue . '%';
                    }
                }
            }

            // A caller-supplied debug flag used to raise $CFG->debug and turn
            // $CFG->debugdisplay on for the rest of the request. Mutating the
            // site's error-display configuration from a web service call is not
            // something a remote caller should be able to do.
            if ($params->debug === 2) {
                $data = [$report->sqlcode, $query, $sqlparams];
            } else if (isset($params->start) && $params->length != 0 && $params->length != -1) {
                $data = $DB->get_records_sql($query, $sqlparams, $params->start, $params->length);
            } else {
                $data = $DB->get_records_sql($query, $sqlparams);
            }
        }

        return ['status' => apilib::STATUS_SUCCESS, 'data' => json_encode($data)];
    }

    /**
     * Run report returns.
     *
     * @return external_single_structure
     */
    public static function run_report_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Response status'),
            'data' => new external_value(PARAM_RAW, 'Report data'),
        ]);
    }

    /**
     * Save report parameters.
     *
     * @return external_function_parameters
     */
    public static function save_report_parameters() {
        return new external_function_parameters([
            'data' => new external_value(PARAM_RAW, 'Request params'),
        ]);
    }

    /**
     * Save report.
     *
     * @param $data
     * @return array
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     */
    public static function save_report($data) {
        try {
            apilib::check_auth();
        } catch (\moodle_exception $e) {
            return [
                'data' => $e->getMessage(),
                'status' => apilib::STATUS_ERROR,
            ];
        }

        $context = context_system::instance();
        self::validate_context($context);

        $params = self::validate_parameters(self::save_report_parameters(), ['data' => $data]);

        // Validate if credentials not empty.
        $encryptionservice = new encryption_service();

        if (!$encryptionservice->validate_credentials()) {
            return [
                'data' => 'emptycredentials',
                'status' => apilib::STATUS_ERROR,
            ];
        }

        // Validate parameters.
        $params = apilib::validate_parameters($params['data'], [
            'external_identifier' => PARAM_TEXT,
            'service' => PARAM_URL,
            'name' => PARAM_TEXT,
            // Not PARAM_TEXT: it runs strip_tags(), which silently eats '<>' and
            // turns "WHERE a <> b" into "WHERE a  b". The body would then be
            // validated and stored already corrupted. validate_report_sql() below
            // is the control on this value, not the parameter type.
            'sqlcode' => PARAM_RAW,
        ]);

        // Normalise away a trailing statement terminator before validating and
        // storing, for the same reason run_report() strips it: the body is
        // wrapped in an outer SELECT on the search path.
        $params['sqlcode'] = rtrim(trim($params['sqlcode']), '; ');

        // PARAM_TEXT strips tags; it does not constrain SQL. Without this a
        // report body could be any statement at all, and run_report() executed
        // it verbatim.
        if ($reason = self::validate_report_sql($params['sqlcode'])) {
            return [
                'data' => $reason,
                'status' => apilib::STATUS_ERROR,
            ];
        }

        $report = (object) $params;

        // A newly stored report is active. Before run_report() honoured this
        // flag, every stored report ran regardless, so defaulting a new one to
        // inactive would not be enforcing existing behaviour - it would be
        // introducing a manual approval step that nothing downstream expects.
        // update_or_create() preserves the stored value on update, so an
        // administrator's decision to switch a report off survives later saves.
        $report->status = 1;
        $data = (array) reports_repository::update_or_create($report);

        return [
            'status' => apilib::STATUS_SUCCESS,
            'data' => $data,
        ];
    }

    /**
     * Save report returns.
     *
     * @return external_single_structure
     */
    public static function save_report_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Response status'),
            'data' => new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'Report ID'),
                    'status' => new external_value(PARAM_INT, 'Report status'),
                    'name' => new external_value(PARAM_TEXT, 'Report name'),
                    // clean_returnvalue() applies the declared type to the
                    // response as well, so PARAM_TEXT would strip_tags() a body
                    // that was stored correctly and hand back 'a  b' for
                    // 'a <> b'. Same defect as the request parameter, outbound.
                    'sqlcode' => new external_value(PARAM_RAW, 'Report SQL'),
                    'external_identifier' => new external_value(PARAM_TEXT, 'External identifier'),
                    'service' => new external_value(PARAM_URL, 'Service'),
                    'timecreated' => new external_value(PARAM_INT, 'Report time created'),
                ]
            ),
        ]);
    }

    /**
     * Delete report parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_report_parameters() {
        return new external_function_parameters([
            'data' => new external_value(PARAM_RAW, 'Request params'),
        ]);
    }

    /**
     * Delete report.
     *
     * @param $data
     * @return array
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws restricted_context_exception
     */
    public static function delete_report($data) {
        try {
            apilib::check_auth();
        } catch (\moodle_exception $e) {
            return [
                'data' => $e->getMessage(),
                'status' => apilib::STATUS_ERROR,
            ];
        }

        $context = context_system::instance();
        self::validate_context($context);

        $params = self::validate_parameters(self::save_report_parameters(), ['data' => $data]);

        // Validate if credentials not empty.
        $encryptionservice = new encryption_service();

        if (!$encryptionservice->validate_credentials()) {
            return [
                'data' => 'emptycredentials',
                'status' => apilib::STATUS_ERROR,
            ];
        }

        // Validate parameters.
        $params = apilib::validate_parameters($params['data'], [
            'external_identifier' => PARAM_TEXT,
        ]);

        reports_repository::delete_by_external_identifier($params['external_identifier']);

        return [
            'status' => apilib::STATUS_SUCCESS,
            'data' => '',
        ];
    }

    /**
     * Delete report returns.
     *
     * @return external_single_structure
     */
    public static function delete_report_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Response status'),
            'data' => new external_value(PARAM_TEXT, ''),
        ]);
    }
}
