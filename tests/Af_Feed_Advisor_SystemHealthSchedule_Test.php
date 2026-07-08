<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for should_check_logs() - the gate that decides whether the
 * Docker-log-based "System Health Report" (errors/warnings/exceptions) runs.
 *
 * Schedule: once daily, at midnight UTC only (hour === 0). Previously ran
 * twice daily in fixed 6am/6pm windows; this now covers the single window.
 */
class Af_Feed_Advisor_SystemHealthSchedule_Test extends TestCase
{
    private Af_Feed_Advisor $plugin;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);

        $this->plugin = new Af_Feed_Advisor();
        $this->plugin->init($host);

        $this->reflection = new ReflectionClass($this->plugin);
    }

    // $already_reported_today bypasses the DB dedup lookup (Db::pdo() always
    // throws in this test bootstrap) so the hour gate can be tested on its
    // own, deterministically.
    private function callShouldCheckLogs(int $now, bool $already_reported_today = false): bool
    {
        $m = $this->reflection->getMethod('should_check_logs');
        $m->setAccessible(true);
        return (bool)$m->invoke($this->plugin, $now, $already_reported_today);
    }

    // 2026-07-08 00:00:00 UTC
    private const MIDNIGHT_UTC = 1783468800;

    public function test_runs_at_exactly_midnight_utc(): void
    {
        $this->assertTrue($this->callShouldCheckLogs(self::MIDNIGHT_UTC));
    }

    public function test_runs_one_second_before_1am_utc(): void
    {
        $this->assertTrue($this->callShouldCheckLogs(self::MIDNIGHT_UTC + 3599));
    }

    public function test_skipped_at_1am_utc(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC + 3600));
    }

    public function test_skipped_at_former_6am_window(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC + 6 * 3600));
    }

    public function test_skipped_at_former_6pm_window(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC + 18 * 3600));
    }

    public function test_skipped_at_noon_utc(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC + 12 * 3600));
    }

    public function test_skipped_one_second_before_midnight_utc(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC - 1));
    }

    public function test_skipped_at_midnight_when_already_reported_today(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC, true));
    }

    public function test_runs_at_midnight_when_not_yet_reported_today(): void
    {
        $this->assertTrue($this->callShouldCheckLogs(self::MIDNIGHT_UTC, false));
    }

    // The already_reported_today bypass is irrelevant outside the midnight
    // hour - the hour gate must still win.
    public function test_already_reported_flag_does_not_widen_the_window(): void
    {
        $this->assertFalse($this->callShouldCheckLogs(self::MIDNIGHT_UTC + 3600, false));
    }
}
