<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for the "only report when there's an issue" feed-health option and
 * its separate system-health counterpart, "only report when there's an
 * error".
 *
 * Covers:
 *   - is_quiet_when_clean_enabled()          - feed-health setting retrieval
 *   - is_system_quiet_when_clean_enabled()   - system-health setting retrieval
 *   - save()                                 - persistence of both, independently
 *   - create_consolidated_health_advisory()  - feed-health skip-when-clean gate
 *   - check_system_logs()                    - system-health skip-when-clean gate
 *
 * Db::pdo() throws in the test bootstrap (no real DB in unit tests), so both
 * skip gates are verified by whether their respective report-creation path
 * reaches Db::pdo() at all: if the report is correctly skipped (quiet mode
 * on, nothing to report), no exception is thrown; if it proceeds to
 * build/insert a report, the stubbed Db::pdo() throws. check_system_logs()
 * also calls parse_docker_logs(), which finds no log file in the test
 * environment and returns empty issues without touching the DB - so these
 * tests always exercise the "clean" (no issues) path.
 */
class Af_Feed_Advisor_QuietWhenClean_Test extends TestCase
{
    private function callPrivate(Af_Feed_Advisor $plugin, string $method, array $args = [])
    {
        $ref = new ReflectionClass($plugin);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($plugin, $args);
    }

    private function pluginWithQuietSetting(?bool $quiet): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($quiet) {
                if ($key === 'quiet_when_clean' && $quiet !== null) {
                    return $quiet ? 'true' : 'false';
                }
                return $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    // =========================================================================
    // GROUP 1: is_quiet_when_clean_enabled()
    // =========================================================================

    public function test_defaults_to_disabled_when_no_setting_stored(): void
    {
        $plugin = $this->pluginWithQuietSetting(null);
        $this->assertFalse($this->callPrivate($plugin, 'is_quiet_when_clean_enabled'));
    }

    public function test_enabled_when_stored_true(): void
    {
        $plugin = $this->pluginWithQuietSetting(true);
        $this->assertTrue($this->callPrivate($plugin, 'is_quiet_when_clean_enabled'));
    }

    public function test_disabled_when_stored_false(): void
    {
        $plugin = $this->pluginWithQuietSetting(false);
        $this->assertFalse($this->callPrivate($plugin, 'is_quiet_when_clean_enabled'));
    }

    // =========================================================================
    // GROUP 2: create_consolidated_health_advisory() skip gate
    // =========================================================================

    public function test_skips_report_when_clean_and_quiet_mode_enabled(): void
    {
        $plugin = $this->pluginWithQuietSetting(true);
        // No exception -> Db::pdo() was never reached -> report was skipped.
        $this->callPrivate($plugin, 'create_consolidated_health_advisory', [[], [], 1]);
        $this->addToAssertionCount(1);
    }

    public function test_still_reports_when_broken_feeds_exist_even_in_quiet_mode(): void
    {
        $plugin = $this->pluginWithQuietSetting(true);
        $this->expectException(\RuntimeException::class);
        $this->callPrivate($plugin, 'create_consolidated_health_advisory', [[['id' => 1]], [], 1]);
    }

    public function test_still_reports_when_stale_feeds_exist_even_in_quiet_mode(): void
    {
        $plugin = $this->pluginWithQuietSetting(true);
        $this->expectException(\RuntimeException::class);
        $this->callPrivate($plugin, 'create_consolidated_health_advisory', [[], [['id' => 1]], 1]);
    }

    public function test_force_bypasses_quiet_mode_even_when_clean(): void
    {
        // Manual "Check Feed Health Now" always reports, regardless of the
        // quiet-mode setting - only automatic scheduled checks respect it.
        $plugin = $this->pluginWithQuietSetting(true);
        $this->expectException(\RuntimeException::class);
        $this->callPrivate($plugin, 'create_consolidated_health_advisory', [[], [], 1, true]);
    }

    public function test_reports_when_clean_but_quiet_mode_disabled(): void
    {
        $plugin = $this->pluginWithQuietSetting(false);
        $this->expectException(\RuntimeException::class);
        $this->callPrivate($plugin, 'create_consolidated_health_advisory', [[], [], 1]);
    }

    // =========================================================================
    // GROUP 3: save() persistence
    // =========================================================================

    private function runSave(Af_Feed_Advisor $plugin, $mockHost, array $post): array
    {
        $captured = [];
        $mockHost->method('set')->willReturnCallback(
            function ($plugin, $key, $value) use (&$captured) {
                $captured[$key] = $value;
            }
        );
        $_POST = $post;
        ob_start();
        $plugin->save();
        ob_end_clean();
        $_POST = [];
        return $captured;
    }

    public function test_save_stores_true_when_checkbox_checked(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        $stored = $this->runSave($plugin, $host, ['quiet_when_clean' => 'on']);
        $this->assertSame('true', $stored['quiet_when_clean']);
    }

    public function test_save_stores_false_when_checkbox_omitted(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        $stored = $this->runSave($plugin, $host, []);
        $this->assertSame('false', $stored['quiet_when_clean']);
    }

    // =========================================================================
    // GROUP 4: is_system_quiet_when_clean_enabled() - defaults to TRUE, unlike
    // the feed-health flag above, matching this report's original always-quiet
    // behavior.
    // =========================================================================

    private function pluginWithSystemQuietSetting(?bool $quiet): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        // Simulate a real web session (non-daemon context) so that
        // check_system_logs()'s unconditional set_state() call at the end
        // doesn't fall through to set_state()'s daemon-context DB fallback
        // and throw - these tests are only about the report-skip gate, not
        // state persistence (covered separately below).
        $host->method('get_owner_uid')->willReturn(1);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($quiet) {
                if ($key === 'system_quiet_when_clean' && $quiet !== null) {
                    return $quiet ? 'true' : 'false';
                }
                return $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    public function test_system_quiet_defaults_to_enabled_when_no_setting_stored(): void
    {
        $plugin = $this->pluginWithSystemQuietSetting(null);
        $this->assertTrue($this->callPrivate($plugin, 'is_system_quiet_when_clean_enabled'));
    }

    public function test_system_quiet_enabled_when_stored_true(): void
    {
        $plugin = $this->pluginWithSystemQuietSetting(true);
        $this->assertTrue($this->callPrivate($plugin, 'is_system_quiet_when_clean_enabled'));
    }

    public function test_system_quiet_disabled_when_stored_false(): void
    {
        $plugin = $this->pluginWithSystemQuietSetting(false);
        $this->assertFalse($this->callPrivate($plugin, 'is_system_quiet_when_clean_enabled'));
    }

    // =========================================================================
    // GROUP 5: check_system_logs() skip gate
    // =========================================================================

    public function test_check_system_logs_skips_report_when_clean_and_quiet_enabled(): void
    {
        $plugin = $this->pluginWithSystemQuietSetting(true);
        $found = $this->callPrivate($plugin, 'check_system_logs');
        // No exception -> Db::pdo() was never reached -> report was skipped.
        $this->assertFalse($found);
    }

    public function test_check_system_logs_force_bypasses_quiet_mode_even_when_clean(): void
    {
        // Manual "Check System Health Now" always reports, regardless of
        // the quiet-mode setting - only the automatic daily check respects it.
        $plugin = $this->pluginWithSystemQuietSetting(true);
        $this->expectException(\RuntimeException::class);
        $this->callPrivate($plugin, 'check_system_logs', [true]);
    }

    public function test_check_system_logs_reports_when_clean_but_quiet_disabled(): void
    {
        $plugin = $this->pluginWithSystemQuietSetting(false);
        $this->expectException(\RuntimeException::class);
        $this->callPrivate($plugin, 'check_system_logs');
    }

    // =========================================================================
    // GROUP 6: save() persistence - independent of the feed-health flag
    // =========================================================================

    public function test_save_stores_system_quiet_true_when_checkbox_checked(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        $stored = $this->runSave($plugin, $host, ['system_quiet_when_clean' => 'on']);
        $this->assertSame('true', $stored['system_quiet_when_clean']);
    }

    public function test_save_stores_system_quiet_false_when_checkbox_omitted(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        $stored = $this->runSave($plugin, $host, []);
        $this->assertSame('false', $stored['system_quiet_when_clean']);
    }

    public function test_save_persists_both_flags_independently(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        $stored = $this->runSave($plugin, $host, ['quiet_when_clean' => 'on']);
        $this->assertSame('true', $stored['quiet_when_clean']);
        $this->assertSame('false', $stored['system_quiet_when_clean']);
    }

    // =========================================================================
    // GROUP 7: set_state() daemon-context persistence fallback
    //
    // Reproduces the "Last check: Never" bug: PluginHost::set() silently
    // no-ops its DB write when get_owner_uid() is falsy (housekeeping/updater
    // runs), so set_state() must fall back to writing directly to the admin
    // user's (uid=1) storage row - but only then, never during a real
    // per-user session, or it would clobber that user's own state.
    // =========================================================================

    private function callSetState(Af_Feed_Advisor $plugin, array $state): void
    {
        $ref = new ReflectionClass($plugin);
        $m = $ref->getMethod('set_state');
        $m->setAccessible(true);
        $m->invoke($plugin, $state);
    }

    public function test_set_state_falls_back_to_admin_row_when_no_owner_uid(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get_owner_uid')->willReturn(null); // daemon context
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        \Db::$pdo_call_count = 0;
        $this->callSetState($plugin, ['last_log_check' => 12345]);
        // The fallback's own try/catch swallows Db::pdo()'s exception (same
        // as get_plugin_setting()'s existing pattern), so the call count is
        // what proves the fallback path was actually taken.
        $this->assertSame(1, \Db::$pdo_call_count);
    }

    public function test_set_state_skips_admin_fallback_when_real_session(): void
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get_owner_uid')->willReturn(2); // real per-user session

        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        \Db::$pdo_call_count = 0;
        $this->callSetState($plugin, ['last_log_check' => 12345]);
        // PluginHost::set() alone was trusted to persist - uid=1's row was
        // never touched.
        $this->assertSame(0, \Db::$pdo_call_count);
    }
}
