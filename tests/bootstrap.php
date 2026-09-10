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
    const HOOK_FETCH_FEED = 11;

    public function add_hook($hook, $plugin, $priority = 10) { return true; }
    public function get($plugin, $key, $default = null) { return $default; }
    public function set($plugin, $key, $value) { return true; }
    public function get_owner_uid() { return null; }
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
// block and returns the supplied default - no real DB needed. Callers that
// wrap Db::pdo() in their own try/catch (e.g. set_state()'s daemon-context
// fallback) can't be asserted on via an uncaught exception, so pdo() also
// tracks how many times it was invoked for tests that need to distinguish
// "the fallback was attempted" from "the fallback was correctly skipped".
class Db
{
    public static int $pdo_call_count = 0;

    public static function pdo()
    {
        self::$pdo_call_count++;
        throw new \RuntimeException('Database not available in unit tests');
    }
}

// Controllable test double for TT-RSS's real UrlHelper::fetch() - tests set
// $fetch_return / $fetch_exception and inspect $last_fetch_options to assert
// on the URL a caller requested.
class UrlHelper
{
    public static $fetch_return = null;
    public static $fetch_exception = null;
    public static ?array $last_fetch_options = null;

    public static function fetch($options)
    {
        self::$last_fetch_options = $options;
        if (self::$fetch_exception) {
            throw self::$fetch_exception;
        }
        return self::$fetch_return;
    }
}

function checkbox_to_sql_bool(string $val): string
{
    return ($val === 'on' || $val === '1' || $val === 'true') ? 'true' : 'false';
}

function sql_bool_to_bool(?string $s): bool
{
    return $s && ($s !== 'f' && $s !== 'false');
}

function __($str) { return $str; }

require_once __DIR__ . '/../init.php';
