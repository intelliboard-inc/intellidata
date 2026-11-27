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

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class DBHelper {
    /**
     * Mysql type.
     */
    const MYSQL_TYPE = 'mysqli';
    /**
     * Postgres type.
     */
    const POSTGRES_TYPE = 'pgsql';
    /**
     * MariaDB type.
     */
    const MARIADB_TYPE = 'mariadb';
    /**
     * Mssql type.
     */
    const MSSQL_TYPE = 'mssql';
    /**
     * Sqlsrv type.
     */
    const SQLSRV_TYPE = 'sqlsrv';
    /**
     * Aurora mysql type.
     */
    const AURORAMYSQL_TYPE = 'auroramysql';
    /**
     * OCI type.
     */
    const OCI_TYPE = 'oci';
    /**
     * Penetration type Internal.
     */
    const PENETRATION_TYPE_INTERNAL = 'internal';
    /**
     * Penetration type External.
     */
    const PENETRATION_TYPE_EXTERNAL = 'external';

    /**
     * @var array
     */
    public static $customdbclient = [];

    /**
     * @var array[]
     */
    public static $supporteddbclients = [
        'internal' => [
            self::MYSQL_TYPE,
            self::MARIADB_TYPE,
            self::AURORAMYSQL_TYPE,
        ],
        'external' => [
            self::MYSQL_TYPE,
            self::MARIADB_TYPE,
            self::POSTGRES_TYPE,
            self::OCI_TYPE,
            self::AURORAMYSQL_TYPE,
        ],
    ];

    /**
     * Get operator.
     *
     * @param $id
     * @param $value
     * @param array $params
     * @param null $dbtype
     * @return string|null
     */
    public static function get_operator($id, $value, $params = [], $dbtype = null) {
        global $CFG;

        $operators = [
            'TIME_TO_SEC' => [
                self::MYSQL_TYPE => 'TIME_TO_SEC',
                self::POSTGRES_TYPE => function($value, $params) {
                    return "extract ('epoch' from TO_TIMESTAMP($value, 'HH24:MI:SS')::TIME)";
                },
            ],
            'SEC_TO_TIME' => [
                self::MYSQL_TYPE => 'SEC_TO_TIME',
                self::POSTGRES_TYPE => '',
            ],
            'CONCAT' => [
                self::MYSQL_TYPE => function($value, $params) {
                    $res = "CONCAT(";
                    foreach ($params as $key => $param) {
                        $res .= ($key > 0 ? ", " . $value . ", " : "") . $param;
                    }
                    return $res . ")";
                },
                self::OCI_TYPE => function($value, $params) {
                    $res = "";
                    foreach ($params as $key => $param) {
                        $res .= ($key > 0 ? " || " . $value . " || " : "") . $param;
                    }
                    return $res;
                },
            ],
            'GROUP_CONCAT' => [
                self::MYSQL_TYPE => function($value, $params = ['separator' => ', ']) {

                    if (empty($params['order'])) {
                        $params['order'] = '';
                    }

                    return "GROUP_CONCAT($value SEPARATOR '" . $params['separator'] . "')";
                },
                self::POSTGRES_TYPE => function($value, $params = ['separator' => ', ']) {

                    if (empty($params['order'])) {
                        $params['order'] = '';
                    }

                    return "string_agg($value::character varying, '" . $params['separator'] . "')";
                },
            ],
            'WEEKDAY' => [
                self::MYSQL_TYPE => 'WEEKDAY',
                self::POSTGRES_TYPE => function($value, $params) {
                    return "extract(dow from $value::timestamp)";
                },
            ],
            'DAYOFWEEK' => [
                self::MYSQL_TYPE => function($value, $params) {
                    return "(DAYOFWEEK($value) - 1)";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "EXTRACT(DOW FROM $value)";
                },
            ],
            'DATE_FORMAT_A' => [
                self::MYSQL_TYPE => function($value, $params) {
                    return "DATE_FORMAT($value, '%a')";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "to_char($value, 'Day')";
                },
            ],
            'FROM_UNIXTIME' => [
                self::MYSQL_TYPE => function($value, $params = []) {

                    $format = isset($params['format']) ? $params['format'] : '%Y-%m-%d %T';
                    $pureparam = isset($params['pureparam']) ? $params['pureparam'] : false;

                    return "FROM_UNIXTIME($value, " . (!$pureparam ? "'{$format}'" : "{$format}") . ")";
                },
                self::POSTGRES_TYPE => function($value, $params = []) {
                    $format = isset($params['format']) ? $params['format'] : 'YYYY-mm-dd HH24:MI:SS';
                    $pureparam = isset($params['pureparam']) ? $params['pureparam'] : false;
                    return "to_char(to_timestamp({$value}), " . (!$pureparam ? "'{$format}'" : "{$format}") . ")";
                },
            ],
            'MONTH' => [
                self::MYSQL_TYPE => function($value, $params) {
                    return "MONTH(FROM_UNIXTIME({$value}))";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "EXTRACT(MONTH FROM to_timestamp({$value}))";
                },
            ],
            'INSERT' => [
                self::MYSQL_TYPE => function($value, $params) {
                    $sentence = $params['sentence'];
                    $position = isset($params['position']) ? $params['position'] : 1;
                    $length   = isset($params['length']) ? $params['length'] : "CHAR_LENGTH($value)";

                    return "INSERT($sentence, $position, $length, $value)";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    $sentence = $params['sentence'];
                    $position = isset($params['position']) ? $params['position'] : 1;
                    $length   = isset($params['length']) ? $params['length'] : "CHAR_LENGTH($value)";

                    return "OVERLAY($sentence placing $value from $position for $length)";
                },
            ],
            'DAY' => [
                self::MYSQL_TYPE => 'DAY',
                self::POSTGRES_TYPE => function($value, $params) {
                    return "date_part('day', $value)";
                },
            ],
            'YEAR' => [
                self::MYSQL_TYPE => function($value, $params) {
                    return "YEAR(FROM_UNIXTIME({$value}))";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "EXTRACT(YEAR FROM to_timestamp({$value}))";
                },
            ],
            'FIND_IN_SET' => [
                self::MYSQL_TYPE => function($value, $params) {
                    if (!isset($params['field'])) {
                        throw new \Exception('parameter "field" is required');
                    }

                    return "FIND_IN_SET({$params['field']}, {$value})";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    if (!isset($params['field'])) {
                        throw new \Exception('parameter "field" is required');
                    }

                    return "{$params['field']} = ANY (string_to_array({$value},','))";
                },
            ],
            'CAST_FLOAT' => [
                self::MYSQL_TYPE => function($value, $params) {
                    if (!isset($params['field'])) {
                        throw new \Exception('parameter "field" is required');
                    }

                    return "CAST({$params['field']} AS DECIMAL({$value}))";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    if (!isset($params['field'])) {
                        throw new \Exception('parameter "field" is required');
                    }

                    return "CAST({$params['field']} AS FLOAT)";
                },
            ],
            'JSON_UNQUOTE' => [
                self::MYSQL_TYPE => function($value, $params) {
                    return "JSON_UNQUOTE($value)";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "($value)::int";
                },
            ],
            'JSON_EXTRACT' => [
                self::MYSQL_TYPE => function($value, $params) {
                    return "JSON_EXTRACT($value, '$.{$params['path']}')";
                },
                self::MSSQL_TYPE => function($value, $params) {
                    return "JSON_VALUE($value, '$.{$params['path']}')";
                },
                self::OCI_TYPE => function($value, $params) {
                    return "JSON_VALUE($value, '$.{$params['path']}')";
                },
                self::SQLSRV_TYPE => function($value, $params) {
                    return "JSON_VALUE($value, '$.{$params['path']}')";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "$value::json->>'{$params['path']}'";
                },
            ],
            'SUBSTRING' => [
                self::OCI_TYPE => function($value, $params) {
                    return "SUBSTR($value, " . $params['from'] . ", " . $params['to'] . ")";
                },
                self::POSTGRES_TYPE => function($value, $params) {
                    return "SUBSTRING($value, " . $params['from'] . ", " . $params['to'] . ")";
                },
                self::MYSQL_TYPE => function($value, $params) {
                    return "SUBSTRING($value, " . $params['from'] . ", " . $params['to'] . ")";
                },
                self::MARIADB_TYPE => function($value, $params) {
                    return "SUBSTRING($value, " . $params['from'] . ", " . $params['to'] . ")";
                },
                self::MSSQL_TYPE => function($value, $params) {
                    return "SUBSTRING($value, " . $params['from'] . ", " . $params['to'] . ")";
                },
                self::SQLSRV_TYPE => function($value, $params) {
                    return "SUBSTRING($value, " . $params['from'] . ", " . $params['to'] . ")";
                },
            ],
        ];

        if ($dbtype === null) {
            if ($CFG->dbtype == self::MARIADB_TYPE || $CFG->dbtype == self::AURORAMYSQL_TYPE) {
                $dbtype = self::MYSQL_TYPE;
            } else {
                $dbtype = $CFG->dbtype;
            }
        }

        if (empty($operators[$id])) {
            return null;
        }

        $operator = $operators[$id];

        if (is_array($operators[$id])) {
            if (empty($operators[$id][$dbtype])) {
                $operator = $operators[$id][self::MYSQL_TYPE];
            } else {
                $operator = $operators[$id][$dbtype];
            }
        }

        if (is_string($operator)) {
            $value = $operator . '(' . $value . ')';
        } else {
            $value = $operator($value, $params);
        }

        return $value;
    }

    /**
     * Remove id triggers.
     *
     * @param $datatype
     * @param $table
     * @return void
     * @throws \dml_exception
     */
    public static function remove_deleted_id_triger($datatype, $table) {
        global $CFG, $DB;

        if ($CFG->dbtype == self::POSTGRES_TYPE) {
            $DB->execute("DROP TRIGGER IF EXISTS deleted_{$datatype} ON {{$table}}");
        } else {
            $DB->execute("DROP TRIGGER IF EXISTS before_delete_{$datatype}");
        }
    }

    /**
     * Return tables prefix.
     *
     * @return mixed
     */
    public static function get_tables_prefix() {
        global $CFG;
        return (PHPUNIT_TEST) ? $CFG->phpunit_prefix : $CFG->prefix;
    }

    /**
     * Delete trigger functions.
     *
     * @return void
     * @throws \dml_exception
     */
    public static function remove_deleted_id_functions() {
        global $CFG, $DB;

        if ($CFG->dbtype == self::POSTGRES_TYPE) {
            $DB->execute("DROP FUNCTION IF EXISTS " . self::get_tables_prefix() . "insert_deleted_id() CASCADE");
        }
    }

    /**
     * Check if database is mysql type.
     *
     * @return bool
     * @throws \dml_exception
     */
    public static function is_mysql_type() {
        global $CFG;

        if ($CFG->dbtype == self::MYSQL_TYPE || $CFG->dbtype == self::MARIADB_TYPE || $CFG->dbtype == self::AURORAMYSQL_TYPE) {
            return true;
        }

        return false;
    }

    /**
     * Get driver instance.
     *
     * @param $type
     * @param $penetrationtype
     * @return mixed|null
     */
    public static function get_driver_instance($type, $penetrationtype) {
        $classname = $type . '_custom_moodle_database_' . $penetrationtype;
        $libfile   = __DIR__ . "/custom_db_drivers/{$penetrationtype}/{$classname}.php";

        if (!file_exists($libfile)) {
            return null;
        }

        require_once($libfile);
        return new $classname();
    }

    /**
     * Get DB client.
     *
     * @param $penetrationtype
     * @return mixed|\moodle_database|null
     * @throws \dml_exception
     */
    public static function get_db_client($penetrationtype = self::PENETRATION_TYPE_INTERNAL) {
        global $CFG, $DB;

        $enablecustomdbdriver = (int)SettingsHelper::get_setting('enablecustomdbdriver');
        if (
            ($penetrationtype == self::PENETRATION_TYPE_INTERNAL && $enablecustomdbdriver == 0) ||
            ($penetrationtype == self::PENETRATION_TYPE_EXTERNAL && !TrackingHelper::new_tracking_enabled())
        ) {
            return $DB;
        }

        if (in_array($CFG->dbtype, self::$supporteddbclients[$penetrationtype])) {
            $db = self::get_driver_instance($CFG->dbtype, $penetrationtype);
            $temptables = null;

            if (isset(self::$customdbclient[$penetrationtype]) &&
                !self::is_database_connected(self::$customdbclient[$penetrationtype])) {
                $temptables = $db::get_temptables(self::$customdbclient[$penetrationtype]);
                try {
                    self::$customdbclient[$penetrationtype]->dispose();
                } catch (\Throwable $e) {
                    // Already disposed, which is the expected case here.
                    // `$e->getMessage();` on its own was a statement with no
                    // effect, and an empty catch is a static-analysis finding,
                    // so record it on the plugin's own channel instead.
                    DebugHelper::error_log('Dispose of stale DB client: ' . $e->getMessage());
                }

                unset(self::$customdbclient[$penetrationtype]);
            }

            if ((!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) && (!defined('CLI_SCRIPT') || !CLI_SCRIPT) && $penetrationtype == self::PENETRATION_TYPE_EXTERNAL) {
                $temptables = $db::get_temptables($DB);
                // DB session handler keeps the pre-after_config $DB; disposing it breaks session writes.
                $sessionusesdefaultdb = empty($CFG->session_handler_class)
                    && !empty($CFG->dbsessions)
                    && $DB->session_lock_supported();
                if (!$sessionusesdefaultdb) {
                    try {
                        $DB->dispose();
                    } catch (\Throwable $e) {
                        // Already disposed, which is the expected case here.
                        DebugHelper::error_log('Dispose of core DB client: ' . $e->getMessage());
                    }
                }

                // Reset DI container because container already has a registered dependency \moodle_database.
                if (class_exists('\core\di') && method_exists('\core\di', 'reset_container')) {
                    \core\di::reset_container();
                }
            }

            if (!isset(self::$customdbclient[$penetrationtype])) {
                $db->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
                if ($temptables !== null) {
                    $db->set_temptables($db, $temptables);
                }

                self::$customdbclient[$penetrationtype] = $db;
            }

            return self::$customdbclient[$penetrationtype];
        }

        return $DB;
    }

    /**
     * Is database connected.
     *
     * @param $db
     * @return bool
     */
    public static function is_database_connected($db) {
        try {
            $db->get_records_sql("SELECT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
