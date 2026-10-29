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
 * @package    local_intellidata
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

namespace local_intellidata\tools;

use cache_application as moodle_cache_application;
use local_intellidata\helpers\DebugHelper;
use local_intellidata\repositories\cache_storage_repository as events_cache_storage_repository;
use local_intellidata\repositories\tracking\cache_storage_repository as tracking_cache_storage_repository;
use local_intellidata\services\datatypes_service;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2021 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class cache_application extends moodle_cache_application {

    /** @var bool Guards against re-entering the flush from inside it. */
    protected static $flushing = false;

    /**
     * Flush buffered tracking to the database, then purge.
     *
     * Both this and delete_many() used to `return true` without doing
     * anything, so "Purge all caches", the post-upgrade purge and every
     * $cache->purge() call silently left these caches intact - an operator
     * troubleshooting a site had no way to clear them and no indication of
     * that.
     *
     * Simply removing the override is not the fix either: these caches are a
     * write buffer, and purging them before the cron drain has run would throw
     * away tracking that was never written to the database. So flush first,
     * then purge for real.
     *
     * @return bool
     */
    public function purge() {
        $this->flush_buffer();

        return parent::purge();
    }

    /**
     * Delete many.
     *
     * @param array $keys
     * @param bool $recurse
     * @return bool
     */
    public function delete_many(array $keys, $recurse = true) {
        $this->flush_buffer();

        return parent::delete_many($keys, $recurse);
    }

    /**
     * Write anything buffered in this cache out to its real storage.
     *
     * Both caches this class overrides are write buffers, and each drains a
     * different way, so dispatch on the definition rather than guessing.
     * Failures are reported and swallowed - a purge must not be blocked by a
     * database problem, but it must not be silent about it either, so the
     * failure goes to debugging() as well as the plugin's own log: the latter
     * only writes anything when the plugin's debugenabled setting is on, and
     * losing a buffer flush is not something that should depend on that.
     *
     * @return void
     */
    protected function flush_buffer() {
        // Both drains read and delete individual keys through this same cache
        // object, so guard against re-entry.
        if (self::$flushing) {
            return;
        }

        self::$flushing = true;

        try {
            $definition = $this->get_definition()->get_id();

            if ($definition === 'local_intellidata/tracking') {
                // Per-user page-visit buffer; export_data() writes it to the
                // tracking tables and is self-contained.
                // The constructor's $ajaxrequest argument has no default and
                // export_data() does not read it; false is the non-AJAX
                // value used everywhere the export path constructs this
                // repository.
                (new tracking_cache_storage_repository(false))->export_data();
            } else if ($definition === 'local_intellidata/events') {
                // Per-datatype export buffer. The cache keys are named for the
                // datatype; move each datatype's keys to its pending files, which
                // the next export sends. This needs no export lock, so a running
                // export cannot make the purge skip the buffer.
                $this->flush_event_datatypes();
            }
        } catch (\Throwable $e) {
            $message = 'Flush of IntelliData cache before purge failed: ' . $e->getMessage();
            DebugHelper::error_log($message);
            debugging($message, DEBUG_NORMAL);
        } finally {
            self::$flushing = false;
        }
    }

    /**
     * Drain the events cache, one datatype at a time.
     *
     * @return void
     */
    protected function flush_event_datatypes() {
        if (!$this->store_supports_get_all_keys()) {
            DebugHelper::error_log(
                'IntelliData events cache cannot be enumerated on this cache store, '
                . 'so buffered export records could not be written out before the purge.'
            );

            return;
        }

        $datatypes = datatypes_service::get_all_datatypes();
        $names = [];

        // Keys are '<datatype><CACHE_USER_IDENTIFIER><userid>'. get_all_keys()
        // splits on '-', which that key does not contain, so the suffix
        // survives and an equality test against a datatype name never matches.
        // Prefix-match instead, the way events_cache_storage_repository's own
        // key lookup does.
        foreach ($this->get_all_keys() as $key) {
            foreach (array_keys($datatypes) as $name) {
                $prefix = $name . events_cache_storage_repository::CACHE_USER_IDENTIFIER;
                if ($key === $name || strpos($key, $prefix) === 0) {
                    $names[$name] = true;
                    break;
                }
            }
        }

        foreach (array_keys($names) as $name) {
            $repository = new events_cache_storage_repository($datatypes[$name]);
            $repository->move_buffer_to_pending();
        }
    }

    /**
     * Get all_keys.
     *
     * @return array
     */
    public function get_all_keys() {
        $rawkeys = $this->get_store()->find_all();
        $keys = [];
        foreach ($rawkeys as $rawkey) {
            $keys[] = explode('-', $rawkey)[0];
        }

        return $keys;
    }

    /**
     * Store supports get all keys.
     *
     * @return bool
     */
    public function store_supports_get_all_keys() {
        return method_exists($this->get_store(), 'find_all');
    }

}
