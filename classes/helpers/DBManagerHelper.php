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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/ddllib.php');

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class DBManagerHelper {

    /**
     * Get tables list from install files.
     *
     * @return array
     */
    public static function get_install_xml_tables() {
        $tables = [];

        foreach (self::get_install_xml_files() as $plugintype => $plugins) {
            foreach ($plugins as $plugin => $filename) {
                $xmldbfile = new \xmldb_file($filename);
                if (!$xmldbfile->loadXMLStructure()) {
                    continue;
                }

                $structure = $xmldbfile->getStructure();
                if (!$structure) {
                    continue;
                }

                $xmltables = $structure->getTables();

                foreach ($xmltables as $xmltable) {

                    // Prepare table details.
                    $table = [
                        'name' => $xmltable->getName(),
                        'plugintype' => $plugintype,
                        'plugin' => $plugin,
                    ];

                    // Prepare table keys.
                    $xmlkeys = $xmltable->getKeys();
                    if (count($xmlkeys)) {
                        foreach ($xmlkeys as $key) {
                            $table['keys'][$key->getName()] = self::extract_xml_key($key);
                        }
                    }

                    $tables[$xmltable->getName()] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * Extract xml key.
     *
     * @param $key
     * @return array
     */
    private static function extract_xml_key($key) {
        return [
            'name' => $key->getName(),
            'fields' => $key->getFields(),
            'reftable' => $key->getReftable(),
            'reffields' => $key->getReffields(),
        ];
    }

    /**
     * Get the list of install.xml files.
     *
     * @return array
     */
    public static function get_install_xml_files() {
        global $CFG;

        $files = [];
        $files['moodle']['core'] = $CFG->libdir.'/db/install.xml';

        // Then, all the ones defined by core_component::get_plugin_types().
        $plugintypes = \core_component::get_plugin_types();

        foreach ($plugintypes as $plugintype => $pluginbasedir) {
            if ($plugins = \core_component::get_plugin_list($plugintype)) {
                foreach ($plugins as $plugin => $plugindir) {
                    $filename = "{$plugindir}/db/install.xml";
                    if (file_exists($filename)) {
                        $files[$plugintype][$plugin] = $filename;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Get field default value.
     *
     * @param $column
     * @return mixed|string
     */
    public static function get_field_default_value($column) {
        return ($column->has_default) ? $column->default_value : '';
    }


    /**
     * Is this a table this plugin owns?
     *
     * A plugin owns its own tables and nothing else. Moodle core owns the core
     * schema, and every other plugin owns its own - altering either from here
     * means this plugin's schema changes are invisible to the owner's
     * install.xml, survive this plugin's uninstall unless it happens to clean
     * them up, and can collide with an index the owner adds later under the
     * same name.
     *
     * @param string $tablename Table name without the prefix.
     * @return bool
     */
    public static function is_plugin_owned_table($tablename) {
        return strpos((string)$tablename, 'local_intellidata_') === 0;
    }

    /**
     * The DDL that would add the index, for an administrator to hand to a DBA.
     *
     * @param string $tablename Table name without the prefix.
     * @param string $indexname Column to index.
     * @return string
     */
    public static function get_index_ddl($tablename, $indexname) {
        global $CFG;

        $prefixed = $CFG->prefix . $tablename;

        return "CREATE INDEX {$prefixed}_{$indexname}_idx ON {$prefixed} ({$indexname});";
    }

    /**
     * Create DB index on one of this plugin's own tables.
     *
     * Refuses any other table. The export filters on a datatype's
     * timemodified field, so an unindexed column means a full scan, and this
     * used to add the index wherever the datatype pointed - which for nearly
     * every datatype is a core table such as user, course, grade_grades or
     * logstore_standard_log. Schema changes to tables this plugin does not own
     * are not this plugin's to make; get_index_ddl() gives an administrator the
     * statement to hand to whoever does own the database.
     *
     * @param string $tablename
     * @param string $indexname
     * @return bool True when the index was created or already existed.
     */
    public static function create_index($tablename, $indexname) {
        global $DB;

        if (!self::is_plugin_owned_table($tablename)) {
            DebugHelper::error_log(
                'Refused to create an index on ' . $tablename . ': not a local_intellidata table.'
            );

            return false;
        }

        $dbman = $DB->get_manager();

        $table = new \xmldb_table($tablename);
        $index = new \xmldb_index($indexname . '_idx', XMLDB_INDEX_NOTUNIQUE, [$indexname]);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        return true;
    }

    /**
     * Delete a DB index this plugin recorded as having created.
     *
     * Unlike create_index(), this is not restricted to the plugin's own tables.
     * Earlier releases did add indexes to core tables, and those are recorded in
     * the plugin's own config; refusing to remove them would strand this
     * plugin's schema changes on a customer's core tables permanently, which is
     * worse than the cleanup. Nothing here creates such an index any more.
     *
     * @param string $tablename
     * @param string $indexname
     * @return void
     */
    public static function delete_index($tablename, $indexname) {
        global $DB;

        $dbman = $DB->get_manager();

        $table = new \xmldb_table($tablename);
        $index = new \xmldb_index($indexname . '_idx', XMLDB_INDEX_NOTUNIQUE, [$indexname]);

        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
    }
}
