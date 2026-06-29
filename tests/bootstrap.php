<?php
/**
 * Bootstrap for PHPUnit tests - stubs for TT-RSS framework dependencies.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Use UTC so bucket timestamps are deterministic in tests.
date_default_timezone_set('UTC');

class PluginHost
{
    const HOOK_ARTICLE_FILTER = 1;
    const HOOK_PREFS_TAB = 4;
    const HOOK_HOUSE_KEEPING = 10;

    public function add_hook($hook, $plugin, $priority = 10) { return true; }
    public function get($plugin, $key, $default = null) { return $default; }
    public function set($plugin, $key, $value) { return true; }
}

class Plugin
{
    public function api_version() { return 2; }
}

class Debug
{
    const LOG_VERBOSE = 1;
    const LOG_NORMAL = 0;

    public static function log($msg, $level = 0) {}
}

// Db::pdo() throws so that get_plugin_setting() falls through its catch
// block and returns the supplied default - no real DB needed.
class Db
{
    public static function pdo()
    {
        throw new \RuntimeException('Database not available in unit tests');
    }
}

function checkbox_to_sql_bool(string $val): string
{
    return ($val === 'on' || $val === '1' || $val === 'true') ? 'true' : 'false';
}

function __($str) { return $str; }

require_once __DIR__ . '/../init.php';
