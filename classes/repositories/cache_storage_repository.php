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
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */

namespace local_intellidata\repositories;

use local_intellidata\helpers\SettingsHelper;
use local_intellidata\helpers\StorageHelper;

/**
 * This plugin provides access to Moodle data in form of analytics and reports in real time.
 *
 * @package    local_intellidata
 * @copyright  2020 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @see    http://intelliboard.net/
 */
class cache_storage_repository extends file_storage_repository {

    /**
     * Storage component.
     */
    const STORAGE_COMPONENT = 'local_intellidata';
    /**
     * Cache area.
     */
    const CACHE_AREA = 'events';
    /**
     * Cache user identifier.
     */
    const CACHE_USER_IDENTIFIER = '_idcacheuserid_';

    /**
     * Separates a buffer key from the generation it belongs to.
     */
    const CACHE_GENERATION_IDENTIFIER = '_g';

    /**
     * Suffix of the key that holds a datatype's current generation.
     */
    const CACHE_GENERATION_KEY = '_idcachegeneration';

    /** @var null|string */
    public $datatype = null;

    /**
     * Get cache storage.
     *
     * @return \cache_application|\cache_session|\cache_store
     */
    private function get_cache_storage() {
        return \cache::make(self::STORAGE_COMPONENT, self::CACHE_AREA);
    }

    /**
     * Get record from cache.
     *
     * @param $cache
     * @return array
     */
    private function get_record($cache, $key) {
        return ($cache->has($key))
            ? $cache->get($key)
            : [];
    }

    /**
     * Get cache key.
     *
     * Keys carry the datatype's current generation. An export moves the generation on
     * before it drains the buffer, so events stored while it drains go to new keys
     * instead of to the keys it is reading and deleting.
     *
     * @return string
     */
    private function cache_key() {
        global $USER;

        return $this->datatype['name'] . self::CACHE_USER_IDENTIFIER .
            (!empty($USER->id) ? $USER->id : 0) .
            self::CACHE_GENERATION_IDENTIFIER . $this->get_generation($this->get_cache_storage());
    }

    /**
     * Get the cache key that holds the datatype's current generation.
     *
     * @return string
     */
    private function generation_key() {
        return $this->datatype['name'] . self::CACHE_GENERATION_KEY;
    }

    /**
     * Get the datatype's current generation.
     *
     * @param $cache
     * @return int
     */
    private function get_generation($cache) {
        return (int)$cache->get($this->generation_key());
    }

    /**
     * Get the generation a buffer key belongs to.
     *
     * @param string $key
     * @return int|null Null for a key written by an older version of the plugin.
     */
    private function key_generation($key) {
        $pattern = '/^' . preg_quote($this->datatype['name'] . self::CACHE_USER_IDENTIFIER, '/') .
            '\d+' . preg_quote(self::CACHE_GENERATION_IDENTIFIER, '/') . '(\d+)$/';

        return preg_match($pattern, $key, $matches) ? (int)$matches[1] : null;
    }

    /**
     * Get datatype cache keys.
     *
     * @return string
     */
    private function get_datatype_keys($cache) {
        $cachekeys = (method_exists($cache, 'get_all_keys')) ? $cache->get_all_keys() : [];

        $datatypekeys = [];
        foreach ($cachekeys as $key) {
            $keyprefix = $this->datatype['name'] . self::CACHE_USER_IDENTIFIER;
            if ($key == $this->datatype['name'] ||
                substr($key, 0, strlen($keyprefix)) === $keyprefix) {
                $datatypekeys[] = $key;
            }
        }

        return $datatypekeys;
    }

    /**
     * Save data to storage.
     *
     * A buffer that grows past 1,000 events is written to a pending file of its own and
     * removed from the cache. The file is complete before an export can see it, and only
     * this key is touched: other users' buffers stay for the export.
     *
     * The key is deleted before the block is written, unlike the hourly drain. An event
     * stored under the key during the write then starts a new buffer instead of being
     * deleted with this one. The cost: a fatal error (time or memory limit, killed worker)
     * between the delete and the rename loses this block; a caught exception does not.
     *
     * @param $data
     * @throws \moodle_exception
     */
    public function save_data($data) {
        $cache = $this->get_cache_storage();
        $cachekey = $this->cache_key();
        $cacherecord = $this->get_record($cache, $cachekey);

        $cacherecord[] = $data;

        $inprocessmigration = (SettingsHelper::get_setting('migrationdatatype') == $this->datatype['name']);
        // Write a block once per 1,000 events, so a write that keeps failing is not retried on every event.
        if ((count($cacherecord) > 1000) && (count($cacherecord) % 1000 == 1) && !$inprocessmigration) {
            $cache->delete($cachekey);

            try {
                $this->write_pending_block($cacherecord);
                return;
            } catch (\Throwable $e) {
                StorageHelper::report_failure("Could not write a {$this->datatype['name']} pending file, " .
                    "the events stay in the cache: " . $e->getMessage());

                // Put the events back, after anything stored under this key meanwhile.
                $cacherecord = array_merge($cacherecord, $this->get_record($cache, $cachekey));
            }
        }

        if (!$cache->set($cachekey, $cacherecord)) {
            StorageHelper::report_failure("Error saving an event to the cache, key: {$cachekey}");
        }
    }

    /**
     * Move the cache buffer into the pending folder.
     *
     * Moves the generation on first, so new events go to new keys, then writes each older
     * key to a pending file and deletes the key only after its file is complete. Called
     * by save_file() with the export lock held.
     *
     * @return void
     * @throws \moodle_exception
     */
    protected function stage_buffer() {
        parent::stage_buffer();

        $cache = $this->get_cache_storage();
        $generation = $this->get_generation($cache) + 1;
        if (!$cache->set($this->generation_key(), $generation)) {
            throw new \moodle_exception('failed_write_file', 'local_intellidata', '', $this->generation_key());
        }

        foreach ($this->get_datatype_keys($cache) as $key) {
            if ($this->key_generation($key) !== $generation) {
                $this->move_key_to_pending($cache, $key);
            }
        }
    }

    /**
     * Move every buffered event of the datatype into the pending folder, without the export lock.
     *
     * For a cache purge, which must neither wait for a running export nor lose the buffer.
     * A pending file is complete before any export can see it, so this is safe beside one;
     * at worst a key both read is exported twice.
     *
     * @return void
     * @throws \moodle_exception
     */
    public function move_buffer_to_pending() {
        $cache = $this->get_cache_storage();

        foreach ($this->get_datatype_keys($cache) as $key) {
            $this->move_key_to_pending($cache, $key);
        }
    }

    /**
     * Write one buffer key to a pending file, then delete the key.
     *
     * @param \cache $cache
     * @param string $key
     * @return void
     * @throws \moodle_exception
     */
    private function move_key_to_pending($cache, $key) {
        $records = $this->get_record($cache, $key);
        if (!is_array($records)) {
            StorageHelper::report_failure("Error reading events from the cache, key: {$key}");
            return;
        }

        if (count($records)) {
            $this->write_pending_block($records);
        }

        // One key at a time: delete_many() is overridden to flush the whole buffer.
        $cache->delete($key);
    }

    /**
     * Delete data for specific datatype.
     *
     * @param null $params
     * @return int|void
     * @throws \dml_exception
     */
    public function delete_files($params = null) {
        if (!empty($params['resetbuffers'])) {
            $cache = $this->get_cache_storage();
            $keys = $this->get_datatype_keys($cache);

            $this->clean_storage($cache, $keys);
        }

        return parent::delete_files($params);
    }

    /**
     * Delete data from storage.
     *
     * @param $cache
     * @param $keys
     */
    public function clean_storage($cache, $keys) {
        if (count($keys)) {
            foreach ($keys as $key) {
                if ($cache->has($key)) {
                    $cache->delete($key);
                }
            }
        }
    }
}
