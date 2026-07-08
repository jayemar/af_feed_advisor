<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the configurable health-report frequency feature.
 *
 * Covers:
 *   - get_report_interval_hours() - setting retrieval and validation
 *   - should_check_health()       - elapsed-time gate logic
 *   - save()                      - interval validation on persist
 */
class Af_Feed_Advisor_ReportInterval_Test extends TestCase
{
    private Af_Feed_Advisor $plugin;
    private $mockHost;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->mockHost = $this->createMock(\PluginHost::class);
        $this->mockHost->method('add_hook')->willReturn(true);

        $this->plugin = new Af_Feed_Advisor();
        $this->plugin->init($this->mockHost);

        $this->reflection = new ReflectionClass($this->plugin);
    }

    // -------------------------------------------------------------------------
    // Helper: call a private method with given arguments
    // -------------------------------------------------------------------------

    private function callPrivate(string $method, array $args = [])
    {
        $m = $this->reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->plugin, $args);
    }

    // -------------------------------------------------------------------------
    // Helper: build a plugin instance with a specific stored interval
    // -------------------------------------------------------------------------

    private function pluginWithStoredInterval(int $hours): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($hours) {
                return $key === 'report_interval_hours' ? $hours : $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    // -------------------------------------------------------------------------
    // Helper: build a plugin instance with a specific interval
    // -------------------------------------------------------------------------

    private function pluginWithHealthState(int $last_health_check, int $interval_hours = 12): Af_Feed_Advisor
    {
        return $this->pluginWithInterval($interval_hours);
    }

    private function pluginWithInterval(int $interval_hours = 12): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($interval_hours) {
                if ($key === 'report_interval_hours') return $interval_hours;
                return $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    private function callShouldCheckHealth(Af_Feed_Advisor $plugin, int $now, int $last_run = 0): bool
    {
        $ref = new ReflectionClass($plugin);
        $m   = $ref->getMethod('should_check_health');
        $m->setAccessible(true);
        return (bool)$m->invoke($plugin, $now, $last_run);
    }

    // =========================================================================
    // GROUP 1: get_report_interval_hours()
    //
    // Valid values: 1, 6, 12, 24, 168.  Default: 12.
    // =========================================================================

    public function test_interval_default_when_no_setting(): void
    {
        // host->get returns null -> Db throws -> falls back to REPORT_INTERVAL_HOURS = 12
        $result = $this->callPrivate('get_report_interval_hours');
        $this->assertSame(12, $result);
    }

    /** @dataProvider validIntervalsProvider */
    public function test_interval_valid_values_are_returned(int $hours): void
    {
        $plugin = $this->pluginWithStoredInterval($hours);
        $ref    = new ReflectionClass($plugin);
        $m      = $ref->getMethod('get_report_interval_hours');
        $m->setAccessible(true);
        $this->assertSame($hours, $m->invoke($plugin));
    }

    public function validIntervalsProvider(): array
    {
        return [[1], [6], [12], [24], [168]];
    }

    /** @dataProvider invalidIntervalsProvider */
    public function test_interval_invalid_values_fall_back_to_default(int $hours): void
    {
        $plugin = $this->pluginWithStoredInterval($hours);
        $ref    = new ReflectionClass($plugin);
        $m      = $ref->getMethod('get_report_interval_hours');
        $m->setAccessible(true);
        $this->assertSame(12, $m->invoke($plugin), "Invalid interval {$hours} must fall back to 12");
    }

    public function invalidIntervalsProvider(): array
    {
        return [[0], [2], [5], [7], [13], [48], [100], [167], [169], [999], [-1]];
    }

    // =========================================================================
    // GROUP 2: should_check_health()
    //
    // Uses elapsed time: returns true when (now - last_health_check) >= interval.
    // Uses a fixed $now parameter so tests are deterministic.
    //
    // Base: $now = 1_000_000 seconds since epoch (arbitrary, well past any
    //       realistic interval).
    // =========================================================================

    private const NOW = 1_000_000;

    public function test_health_check_runs_when_never_previously_run(): void
    {
        // last_run=0 (never), any realistic $now satisfies the interval
        $plugin = $this->pluginWithInterval(12);
        $this->assertTrue($this->callShouldCheckHealth($plugin, self::NOW, 0));
    }

    public function test_health_check_runs_when_interval_has_elapsed_12h(): void
    {
        $interval_s = 12 * 3600;
        $last_run   = self::NOW - $interval_s;
        $plugin     = $this->pluginWithInterval(12);
        $this->assertTrue($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_skipped_when_interval_not_elapsed_12h(): void
    {
        $interval_s = 12 * 3600;
        $last_run   = self::NOW - $interval_s + 1; // one second short
        $plugin     = $this->pluginWithInterval(12);
        $this->assertFalse($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_runs_when_interval_has_elapsed_1h(): void
    {
        $last_run = self::NOW - 3600;
        $plugin   = $this->pluginWithInterval(1);
        $this->assertTrue($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_skipped_when_interval_not_elapsed_1h(): void
    {
        $last_run = self::NOW - 3599; // one second short of 1h
        $plugin   = $this->pluginWithInterval(1);
        $this->assertFalse($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_runs_when_interval_has_elapsed_24h(): void
    {
        $last_run = self::NOW - 24 * 3600;
        $plugin   = $this->pluginWithInterval(24);
        $this->assertTrue($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_skipped_when_interval_not_elapsed_24h(): void
    {
        $last_run = self::NOW - (24 * 3600 - 1);
        $plugin   = $this->pluginWithInterval(24);
        $this->assertFalse($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_runs_when_interval_has_elapsed_weekly(): void
    {
        $last_run = self::NOW - 168 * 3600;
        $plugin   = $this->pluginWithInterval(168);
        $this->assertTrue($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    public function test_health_check_skipped_when_interval_not_elapsed_weekly(): void
    {
        $last_run = self::NOW - (168 * 3600 - 1);
        $plugin   = $this->pluginWithInterval(168);
        $this->assertFalse($this->callShouldCheckHealth($plugin, self::NOW, $last_run));
    }

    // =========================================================================
    // GROUP 3: save() - report_interval_hours validation and persistence
    // =========================================================================

    /**
     * Run save() and return all key=>value pairs passed to $host->set().
     */
    private function runSave(array $post): array
    {
        $captured = [];
        $this->mockHost->method('set')->willReturnCallback(
            function ($plugin, $key, $value) use (&$captured) {
                $captured[$key] = $value;
            }
        );

        $_POST = $post;
        ob_start();
        $this->plugin->save();
        ob_end_clean();
        $_POST = [];

        return $captured;
    }

    public function test_save_stores_valid_interval_1(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '1']);
        $this->assertSame(1, $stored['report_interval_hours']);
    }

    public function test_save_stores_valid_interval_6(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '6']);
        $this->assertSame(6, $stored['report_interval_hours']);
    }

    public function test_save_stores_valid_interval_24(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '24']);
        $this->assertSame(24, $stored['report_interval_hours']);
    }

    public function test_save_stores_valid_interval_168(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '168']);
        $this->assertSame(168, $stored['report_interval_hours']);
    }

    public function test_save_rejects_invalid_interval_and_stores_default(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '5']);
        $this->assertSame(12, $stored['report_interval_hours'], 'Invalid interval 5 must fall back to 12');
    }

    public function test_save_rejects_zero_and_stores_default(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '0']);
        $this->assertSame(12, $stored['report_interval_hours'], 'Interval 0 must fall back to 12');
    }

    public function test_save_rejects_arbitrary_large_value_and_stores_default(): void
    {
        $stored = $this->runSave(['report_interval_hours' => '999']);
        $this->assertSame(12, $stored['report_interval_hours'], 'Invalid interval 999 must fall back to 12');
    }

    public function test_save_uses_default_when_interval_omitted(): void
    {
        $stored = $this->runSave([]);
        $this->assertSame(12, $stored['report_interval_hours'], 'Missing interval must default to 12');
    }
}
