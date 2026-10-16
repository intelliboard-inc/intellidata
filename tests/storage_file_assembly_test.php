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
 * File counting and shard assembly test case.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellidata;

use local_intellidata\helpers\StorageHelper;
use local_intellidata\repositories\file_storage_repository;

/**
 * File counting and shard assembly test case.
 *
 * These paths used to shell out - grep, wc, cat, tail, tr and rm - with the
 * datatype name interpolated unquoted into the command string. The pure PHP
 * replacements have to produce the same bytes and the same counts, so the
 * expectations here are the shell pipeline's own output.
 *
 * @package    local_intellidata
 * @subpackage intellidata
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_intellidata\helpers\StorageHelper::count_records_in_file
 * @covers     \local_intellidata\repositories\file_storage_repository::merge_user_files
 */
final class storage_file_assembly_test extends \advanced_testcase {
    /** @var string Directory holding this test's scratch files. */
    private $dir;

    /**
     * Set up a scratch directory under the test dataroot.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest(true);
        $this->dir = make_temp_directory('local_intellidata_assembly_test');
    }

    /**
     * Write a shard the way save_in_file() does, which prepends the newline.
     *
     * @param string $name
     * @param array $lines
     * @return string Full path.
     */
    private function write_shard($name, array $lines) {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;

        if (file_exists($path)) {
            unlink($path);
        }

        foreach ($lines as $line) {
            StorageHelper::save_in_file($path, $line);
        }

        return $path;
    }

    /**
     * Counting rows and created rows without grep and wc.
     *
     * @return void
     */
    public function test_count_records_in_file(): void {
        $path = $this->write_shard('counts.jsonl', [
            '{"id":1},c',
            '{"id":2},u',
            '{"id":3},c',
            '{"id":4},d',
        ]);

        $counts = StorageHelper::count_records_in_file($path);

        $this->assertSame(4, $counts['total']);
        $this->assertSame(2, $counts['created']);
    }

    /**
     * Blank lines are not rows, and a missing file is not a fatal.
     *
     * @return void
     */
    public function test_count_records_in_file_edge_cases(): void {
        $empty = $this->dir . DIRECTORY_SEPARATOR . 'empty.jsonl';
        touch($empty);

        $this->assertSame(['total' => 0, 'created' => 0], StorageHelper::count_records_in_file($empty));

        $missing = $this->dir . DIRECTORY_SEPARATOR . 'does_not_exist.jsonl';
        $this->assertSame(['total' => 0, 'created' => 0], StorageHelper::count_records_in_file($missing));

        $blanks = $this->dir . DIRECTORY_SEPARATOR . 'blanks.jsonl';
        file_put_contents($blanks, PHP_EOL . '{"id":1},c' . PHP_EOL . PHP_EOL);
        $this->assertSame(['total' => 1, 'created' => 1], StorageHelper::count_records_in_file($blanks));
    }

    /**
     * Shard assembly must match the shell pipeline byte for byte.
     *
     * The pipeline was:
     *   for f in <shards>; do echo ""; cat $f; rm $f; done \
     *     | tail -n +2 | tr -d '\0' >> <out>; echo "" >> <out>
     *
     * @return void
     */
    public function test_merge_user_files_matches_shell_output(): void {
        $shards = [
            $this->write_shard('shard_a', ['{"u":1},c', '{"u":2},u']),
            $this->write_shard('shard_b', ["{\"u\":3,\"n\":\"has\0nul\"},c"]),
            $this->write_shard('shard_c', ['{"u":4},d']),
        ];

        // Build the expected bytes from the shard contents, exactly as the
        // pipeline did: a newline before each shard, the first one discarded by
        // tail, NUL bytes dropped, one trailing newline.
        $expected = '';
        $first = true;
        foreach ($shards as $shard) {
            if (!$first) {
                $expected .= PHP_EOL;
            }
            $first = false;
            $expected .= str_replace("\0", '', file_get_contents($shard));
        }
        $expected .= PHP_EOL;

        $out = $this->dir . DIRECTORY_SEPARATOR . 'merged';
        if (file_exists($out)) {
            unlink($out);
        }

        $repository = new file_storage_repository(['name' => 'users']);
        $method = new \ReflectionMethod($repository, 'merge_user_files');
        $method->setAccessible(true);
        $method->invoke($repository, $shards, $out);

        $this->assertSame($expected, file_get_contents($out));

        // Every shard is consumed, as rm did.
        foreach ($shards as $shard) {
            $this->assertFileDoesNotExist($shard);
        }

        // And no NUL byte survives, as tr -d did.
        $this->assertStringNotContainsString("\0", file_get_contents($out));
    }
}
