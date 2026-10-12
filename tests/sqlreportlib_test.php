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
 * Stored SQL report validation test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\helpers\DBHelper;

defined('MOODLE_INTERNAL') || die();

global $CFG;

// The class is global, not namespaced, and is normally loaded by the web
// service layer from its declared classpath, so it is not autoloadable.
require_once($CFG->dirroot . '/local/intellidata/classes/api/sqlreportlib.php');

/**
 * Stored SQL report validation test case.
 *
 * validate_report_sql() is the boundary that decides what a stored report body
 * is allowed to be, so it needs coverage independent of the caller.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellidata_sqlreportlib::validate_report_sql
 */
final class sqlreportlib_test extends \advanced_testcase {
    /**
     * Bodies that must be accepted.
     *
     * @return array
     */
    public static function accepted_provider(): array {
        return [
            'plain select' => ['SELECT id, username FROM {user}'],
            'lowercase' => ['select id from {user}'],
            'leading whitespace' => ["\n\t  SELECT 1"],
            'parenthesised select' => ['(SELECT id FROM {user}) UNION (SELECT id FROM {user})'],
            'common table expression' => ['WITH c AS (SELECT id FROM {user}) SELECT * FROM c'],
            'trailing semicolon' => ['SELECT id FROM {user};'],
            'line comment' => ["SELECT id FROM {user} -- a note\n"],
            'block comment' => ['SELECT id /* a note */ FROM {user}'],
            'keyword inside a literal' => ["SELECT 'DROP TABLE' AS label FROM {user}"],
            'keyword inside an identifier' => ['SELECT u.updated_flag FROM {user} u'],
            'keyword as a column name fragment' => ['SELECT createdby, deletedby FROM {user}'],
            'doubled quote in a literal' => ["SELECT 'O''Brien; DELETE' AS n FROM {user}"],
            'report filter placeholders' => ['SELECT id FROM {user} WHERE 1=1 :filter :sorting'],
            'aggregate with having' => [
                'SELECT courseid, COUNT(id) c FROM {logstore_standard_log} GROUP BY courseid HAVING COUNT(id) > 1',
            ],
            'join and case' => ['SELECT CASE WHEN u.id > 0 THEN 1 ELSE 0 END AS f FROM {user} u JOIN {course} c ON c.id = u.id'],
            'replace is a string function' => ["SELECT REPLACE(username, 'a', 'b') FROM {user}"],
            // Both engines return these as string values - the keyword is data,
            // not code - so this must be accepted. Verified against MySQL and
            // PostgreSQL, which both yield 'a/*' and '*/ DROP' as columns.
            'comment delimiters inside literals' => ["SELECT 'a/*' AS x, '*/ DROP' AS z FROM {user}"],
            'hash inside a literal' => ["SELECT 'a#b' AS x, id FROM {user}"],
        ];
    }

    /**
     * Accepted bodies return no reason code.
     *
     * @dataProvider accepted_provider
     * @param string $sql
     * @return void
     */
    public function test_accepted($sql): void {
        $this->assertNull(\local_intellidata_sqlreportlib::validate_report_sql($sql), 'Should have been accepted: ' . $sql);
    }

    /**
     * Bodies that must be rejected, with the reason code.
     *
     * @return array
     */
    public static function rejected_provider(): array {
        return [
            'empty' => ['', 'reportsqlempty'],
            'whitespace only' => ["  \n ", 'reportsqlempty'],
            'comment only' => ['-- nothing here', 'reportsqlempty'],
            'not a query' => ['UPDATE {user} SET id = 1', 'reportsqlnotselect'],
            'delete' => ['DELETE FROM {user}', 'reportsqlnotselect'],
            'insert' => ['INSERT INTO {user} (id) VALUES (1)', 'reportsqlnotselect'],
            'ddl' => ['DROP TABLE {user}', 'reportsqlnotselect'],
            'stacked statement' => ['SELECT 1; DROP TABLE {user}', 'reportsqlmultiplestatements'],
            'stacked with trailing terminator' => ['SELECT 1; DROP TABLE {user};', 'reportsqlmultiplestatements'],
            'select into outfile' => ["SELECT * INTO OUTFILE '/tmp/x' FROM {user}", 'reportsqlforbiddenkeyword'],
            'select then update' => ['SELECT id FROM {user} WHERE id IN (UPDATE {user} SET id = 1)', 'reportsqlforbiddenkeyword'],
            'grant' => ['SELECT 1 FROM {user} WHERE 1 = 1 GRANT ALL', 'reportsqlforbiddenkeyword'],
            'load_file' => ["SELECT LOAD_FILE('/etc/passwd')", 'reportsqlforbiddenkeyword'],
            'pg_read_file' => ["SELECT PG_READ_FILE('/etc/passwd')", 'reportsqlforbiddenkeyword'],
            'pg_sleep' => ['SELECT PG_SLEEP(10)', 'reportsqlforbiddenkeyword'],
            'benchmark' => ['SELECT BENCHMARK(1000000, MD5(1))', 'reportsqlforbiddenkeyword'],
            'xp_cmdshell' => ["SELECT XP_CMDSHELL('dir')", 'reportsqlforbiddenkeyword'],
            'copy' => ["SELECT 1 FROM {user}; COPY x FROM '/etc/passwd'", 'reportsqlmultiplestatements'],
            'keyword after a closed literal' => ["SELECT 'safe' , TRUNCATE FROM {user}", 'reportsqlforbiddenkeyword'],
            // A line-comment marker inside a literal must not end a comment that
            // never began; the engine still executes what follows it.
            'line comment marker inside a literal' =>
                ["SELECT 'x--' AS a, pg_read_file('/etc/passwd') AS leak FROM {user}", 'reportsqlforbiddenkeyword'],
            'line comment marker inside a predicate literal' =>
                ["SELECT id FROM {user} WHERE 'a--' = 'a' OR pg_sleep(10)::text IS NOT NULL", 'reportsqlforbiddenkeyword'],
            // MySQL executes the contents of an executable comment, so it must
            // be read as code rather than stripped.
            'mysql executable comment' =>
                ["SELECT id FROM {user} /*! INTO OUTFILE '/tmp/x' */", 'reportsqlforbiddenkeyword'],
            'mysql optimiser hint comment' =>
                ["SELECT id FROM {user} /*+ SLEEP(10) */", 'reportsqlforbiddenkeyword'],
            // Server-side file, network and large-object primitives.
            'dblink' => ["SELECT dblink('h','select 1')", 'reportsqlforbiddenkeyword'],
            'pg_read_binary_file' => ["SELECT pg_read_binary_file('/etc/passwd')", 'reportsqlforbiddenkeyword'],
            'pg_stat_file' => ["SELECT pg_stat_file('/etc/passwd')", 'reportsqlforbiddenkeyword'],
            'lo_get' => ['SELECT lo_get(1)', 'reportsqlforbiddenkeyword'],
            'query_to_xml' => ["SELECT query_to_xml('select 1', true, true, '')", 'reportsqlforbiddenkeyword'],
            'pg_ls_waldir' => ['SELECT pg_ls_waldir()', 'reportsqlforbiddenkeyword'],
            // A quote inside a construct the scanner does not model would open a
            // literal that never closes, and the scan would then run to end of
            // input and hide everything after it. Dollar quoting and escape
            // strings are modelled so these reject on the real reason; anything
            // still unterminated is refused outright rather than scanned blind.
            'unterminated literal' =>
                ["SELECT 'unclosed FROM {user}", 'reportsqlunterminatedliteral'],
            'unterminated block comment' =>
                ['SELECT id FROM {user} /* unclosed', 'reportsqlunterminatedliteral'],
        ];
    }

    /**
     * Rejected bodies return the expected reason code.
     *
     * @dataProvider rejected_provider
     * @param string $sql
     * @param string $expected
     * @return void
     */
    public function test_rejected($sql, $expected): void {
        $this->assertSame(
            $expected,
            \local_intellidata_sqlreportlib::validate_report_sql($sql),
            'Should have been rejected: ' . $sql
        );
    }

    /**
     * PostgreSQL string syntaxes MySQL does not have.
     *
     * Dollar quoting and escape strings are modelled so a legitimate report
     * using either is accepted and a dangerous one is rejected for the real
     * reason rather than for failing to parse.
     *
     * The two groups behave differently on MySQL and are asserted separately:
     *
     * - Dollar quoting does not exist there at all, so an apostrophe inside one
     *   opens a literal that cannot be paired and the body is refused outright.
     * - Escape strings do not exist either, but the text still parses: `E` is an
     *   ordinary identifier and `'...'` is an ordinary literal, which on MySQL
     *   does honour a backslash escape. So those bodies pair cleanly, and are
     *   judged on their contents like any other.
     *
     * @return void
     */
    public function test_postgresql_string_syntaxes(): void {
        $dollarlegitimate = [
            'SELECT $$hello$$ AS greeting FROM {user}',
            "SELECT \$tag\$it's fine\$tag\$ AS a FROM {user}",
        ];
        $escapelegitimate = [
            "SELECT E'line\\nbreak' AS a FROM {user}",
        ];
        $dangerous = [
            "SELECT \$\$it's\$\$ AS a, pg_sleep(10) AS boom",
            "SELECT \$tag\$it's\$tag\$ AS a, pg_sleep(10)",
            "SELECT E'x\\'' , pg_sleep(10)",
        ];

        // Whatever the engine, none of these may be accepted.
        foreach ($dangerous as $sql) {
            $this->assertNotNull(
                \local_intellidata_sqlreportlib::validate_report_sql($sql),
                'Should have been rejected on any engine: ' . $sql
            );
        }

        if (DBHelper::is_mysql_type()) {
            // No dollar quoting here, so the apostrophe inside one leaves a
            // literal that cannot be paired.
            foreach ($dollarlegitimate as $sql) {
                if (strpos($sql, "'") === false) {
                    // Nothing to mis-pair; the body is simply read as code.
                    continue;
                }
                $this->assertSame(
                    'reportsqlunterminatedliteral',
                    \local_intellidata_sqlreportlib::validate_report_sql($sql),
                    $sql
                );
            }

            // Escape strings do pair on MySQL, so a harmless one is accepted.
            foreach ($escapelegitimate as $sql) {
                $this->assertNull(
                    \local_intellidata_sqlreportlib::validate_report_sql($sql),
                    'Parses as an ordinary literal on MySQL: ' . $sql
                );
            }

            return;
        }

        foreach (array_merge($dollarlegitimate, $escapelegitimate) as $sql) {
            $this->assertNull(
                \local_intellidata_sqlreportlib::validate_report_sql($sql),
                'Should have been accepted on PostgreSQL: ' . $sql
            );
        }
        foreach ($dangerous as $sql) {
            $this->assertSame(
                'reportsqlforbiddenkeyword',
                \local_intellidata_sqlreportlib::validate_report_sql($sql),
                $sql
            );
        }
    }

    /**
     * Backslash handling inside a literal follows the engine.
     *
     * The payload below is not a contrived one - each engine was asked directly:
     *
     *   MySQL       SELECT 'a\\' , DROP , ' AS x;  ->  x = "a' , DROP , "
     *   PostgreSQL  same statement                ->  ERROR: unterminated quoted string
     *
     * So on MySQL the keyword really is data and the body is a valid statement;
     * on an ANSI-string engine the quotes cannot be paired and the body is not
     * runnable at all. The validator must agree with whichever engine it is on,
     * and must never be the one that reads it the other way round.
     *
     * @return void
     */
    public function test_backslash_literal_is_engine_specific(): void {
        $sql = "SELECT 'a\\' , DROP , ' AS x FROM {user}";

        if (DBHelper::is_mysql_type()) {
            $this->assertNull(
                \local_intellidata_sqlreportlib::validate_report_sql($sql),
                'MySQL parses this as one literal containing the keyword'
            );

            return;
        }

        $this->assertSame(
            'reportsqlunterminatedliteral',
            \local_intellidata_sqlreportlib::validate_report_sql($sql),
            'PostgreSQL cannot pair these quotes, so the body is refused'
        );
    }
}
