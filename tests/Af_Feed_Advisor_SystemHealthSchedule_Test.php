<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the configurable system-log check frequency feature - mirrors
 * Af_Feed_Advisor_ReportInterval_Test.php's coverage of feed health's own
 * report_interval_hours, but for system_check_interval_hours.
 *
 * Covers:
 *   - get_system_check_interval_hours() - setting retrieval and validation
 *   - should_check_logs()                - elapsed-time gate logic
 *   - save()                             - interval validation on persist
 *
 * should_check_logs() previously only ever ran once daily at UTC midnight
 * (hardcoded, not configurable). It's now an elapsed-time gate identical in
 * shape to should_check_health() below, so a user can pick the same
 * Hourly/Every 6 hours/Twice daily/Daily/Weekly options feed health already
 * offers.
 */
class Af_Feed_Advisor_SystemHealthSchedule_Test extends TestCase
{
    private function callPrivate(Af_Feed_Advisor $plugin, string $method, array $args = [])
    {
        $ref = new ReflectionClass($plugin);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($plugin, $args);
    }

    private function pluginWithSystemInterval(int $interval_hours = 24): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($interval_hours) {
                if ($key === 'system_check_interval_hours') return $interval_hours;
                return $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    private function callShouldCheckLogs(Af_Feed_Advisor $plugin, int $now, int $last_run = 0): bool
    {
        return (bool)$this->callPrivate($plugin, 'should_check_logs', [$now, $last_run]);
    }

    // =========================================================================
    // GROUP 1: get_system_check_interval_hours()
    //
    // Valid values: 1, 6, 12, 24, 168. Default: 24.
    // =========================================================================

    public function test_interval_default_when_no_setting(): void
    {
        // host->get returns null -> Db throws -> falls back to SYSTEM_CHECK_INTERVAL_HOURS = 24
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        $this->assertSame(24, $this->callPrivate($plugin, 'get_system_check_interval_hours'));
    }

    /** @dataProvider validIntervalsProvider */
    public function test_interval_valid_values_are_returned(int $hours): void
    {
        $plugin = $this->pluginWithSystemInterval($hours);
        $this->assertSame($hours, $this->callPrivate($plugin, 'get_system_check_interval_hours'));
    }

    public function validIntervalsProvider(): array
    {
        return [[1], [6], [12], [24], [168]];
    }

    /** @dataProvider invalidIntervalsProvider */
    public function test_interval_invalid_values_fall_back_to_default(int $hours): void
    {
        $plugin = $this->pluginWithSystemInterval($hours);
        $this->assertSame(
            24,
            $this->callPrivate($plugin, 'get_system_check_interval_hours'),
            "Invalid interval {$hours} must fall back to 24"
        );
    }

    public function invalidIntervalsProvider(): array
    {
        return [[0], [2], [5], [7], [13], [48], [100], [167], [169], [999], [-1]];
    }

    // =========================================================================
    // GROUP 2: should_check_logs()
    //
    // Uses elapsed time: returns true when (now - last_run) >= interval.
    // Base: $now = 1_000_000 seconds since epoch (arbitrary).
    // =========================================================================

    private const NOW = 1_000_000;

    public function test_check_runs_when_never_previously_run(): void
    {
        $plugin = $this->pluginWithSystemInterval(24);
        $this->assertTrue($this->callShouldCheckLogs($plugin, self::NOW, 0));
    }

    public function test_check_runs_when_interval_has_elapsed_1h(): void
    {
        $last_run = self::NOW - 3600;
        $plugin   = $this->pluginWithSystemInterval(1);
        $this->assertTrue($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_skipped_when_interval_not_elapsed_1h(): void
    {
        $last_run = self::NOW - 3599; // one second short
        $plugin   = $this->pluginWithSystemInterval(1);
        $this->assertFalse($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_runs_when_interval_has_elapsed_6h(): void
    {
        $last_run = self::NOW - 6 * 3600;
        $plugin   = $this->pluginWithSystemInterval(6);
        $this->assertTrue($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_skipped_when_interval_not_elapsed_6h(): void
    {
        $last_run = self::NOW - (6 * 3600 - 1);
        $plugin   = $this->pluginWithSystemInterval(6);
        $this->assertFalse($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_runs_when_interval_has_elapsed_12h(): void
    {
        $last_run = self::NOW - 12 * 3600;
        $plugin   = $this->pluginWithSystemInterval(12);
        $this->assertTrue($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_skipped_when_interval_not_elapsed_12h(): void
    {
        $last_run = self::NOW - (12 * 3600 - 1);
        $plugin   = $this->pluginWithSystemInterval(12);
        $this->assertFalse($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_runs_when_interval_has_elapsed_24h(): void
    {
        $last_run = self::NOW - 24 * 3600;
        $plugin   = $this->pluginWithSystemInterval(24);
        $this->assertTrue($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_skipped_when_interval_not_elapsed_24h(): void
    {
        $last_run = self::NOW - (24 * 3600 - 1);
        $plugin   = $this->pluginWithSystemInterval(24);
        $this->assertFalse($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_runs_when_interval_has_elapsed_weekly(): void
    {
        $last_run = self::NOW - 168 * 3600;
        $plugin   = $this->pluginWithSystemInterval(168);
        $this->assertTrue($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    public function test_check_skipped_when_interval_not_elapsed_weekly(): void
    {
        $last_run = self::NOW - (168 * 3600 - 1);
        $plugin   = $this->pluginWithSystemInterval(168);
        $this->assertFalse($this->callShouldCheckLogs($plugin, self::NOW, $last_run));
    }

    // =========================================================================
    // GROUP 3: save() - system_check_interval_hours validation and persistence
    // =========================================================================

    private function runSave(array $post): array
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $captured = [];
        $host->method('set')->willReturnCallback(
            function ($plugin, $key, $value) use (&$captured) {
                $captured[$key] = $value;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        $_POST = $post;
        ob_start();
        $plugin->save();
        ob_end_clean();
        $_POST = [];

        return $captured;
    }

    /** @dataProvider validIntervalsProvider */
    public function test_save_stores_valid_interval(int $hours): void
    {
        $stored = $this->runSave(['system_check_interval_hours' => (string)$hours]);
        $this->assertSame($hours, $stored['system_check_interval_hours']);
    }

    public function test_save_rejects_invalid_interval_and_stores_default(): void
    {
        $stored = $this->runSave(['system_check_interval_hours' => '5']);
        $this->assertSame(24, $stored['system_check_interval_hours'], 'Invalid interval 5 must fall back to 24');
    }

    public function test_save_uses_default_when_interval_omitted(): void
    {
        $stored = $this->runSave([]);
        $this->assertSame(24, $stored['system_check_interval_hours'], 'Missing interval must default to 24');
    }
}
