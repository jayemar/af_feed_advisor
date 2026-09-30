<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use UrlHelper;
use Db;

/**
 * Tests for the "Browser-Rendered Feeds" health-report re-check: for each
 * feed in the "browser_fetch_feed_ids" list, a direct (non-proxied)
 * UrlHelper::fetch() decides whether the site's anti-bot block that
 * originally required the sidecar is still in effect, and
 * render_browser_rendered_section() turns that into the report's HTML.
 */
class Af_Feed_Advisor_BrowserRenderedRecheck_Test extends TestCase
{
    protected function setUp(): void
    {
        UrlHelper::$fetch_return = null;
        UrlHelper::$fetch_exception = null;
        UrlHelper::$last_fetch_options = null;
    }

    private function newPlugin(): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    private function callIsStillNeeded(Af_Feed_Advisor $plugin, string $feed_url): bool
    {
        $ref = new \ReflectionClass($plugin);
        $m = $ref->getMethod('is_still_needed_via_direct_fetch');
        $m->setAccessible(true);
        return $m->invoke($plugin, $feed_url);
    }

    // =========================================================================
    // is_still_needed_via_direct_fetch() - the per-feed re-check
    // =========================================================================

    public function test_successful_direct_fetch_means_no_longer_needed()
    {
        UrlHelper::$fetch_return = '<rss>real content</rss>';

        $result = $this->callIsStillNeeded($this->newPlugin(), 'https://example.com/feed');

        $this->assertFalse($result, 'a real, non-empty response means the block is no longer in effect');
        $this->assertSame('https://example.com/feed', UrlHelper::$last_fetch_options['url']);
    }

    public function test_false_return_means_still_needed()
    {
        // UrlHelper::fetch() itself returns false for any 4xx/5xx response
        // or connection failure - see its doc comment in init.php.
        UrlHelper::$fetch_return = false;

        $result = $this->callIsStillNeeded($this->newPlugin(), 'https://example.com/feed');

        $this->assertTrue($result);
    }

    public function test_exception_means_still_needed()
    {
        UrlHelper::$fetch_exception = new \RuntimeException('connection refused');

        $result = $this->callIsStillNeeded($this->newPlugin(), 'https://example.com/feed');

        $this->assertTrue($result, 'a failed re-check should never read as safe to remove');
    }

    // =========================================================================
    // check_browser_rendered_feeds() - empty-list fast path (Db::pdo() always
    // throws in this test bootstrap, so a DB call attempted at all would fail
    // the test - the same technique the other test files in this plugin use)
    // =========================================================================

    public function test_empty_configured_list_returns_empty_without_touching_db()
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) {
                if ($key === 'browser_fetch_feed_ids') {
                    return json_encode([]);
                }
                return $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);

        // init() itself touches Db::pdo() (ensure_schema()) - snapshot after
        // init so this measures only what check_browser_rendered_feeds()
        // itself triggers, same technique Af_Feed_Advisor_BrowserFetchProxy_Test
        // uses for save().
        $before = Db::$pdo_call_count;

        $ref = new \ReflectionClass($plugin);
        $m = $ref->getMethod('check_browser_rendered_feeds');
        $m->setAccessible(true);
        $result = $m->invoke($plugin);

        $this->assertSame([], $result);
        $this->assertSame($before, Db::$pdo_call_count, 'nothing configured - should never query the DB');
    }

    // =========================================================================
    // render_browser_rendered_section() - pure HTML rendering, no DB/network
    // =========================================================================

    private function callRender(array $results): string
    {
        $ref = new \ReflectionClass(Af_Feed_Advisor::class);
        $m = $ref->getMethod('render_browser_rendered_section');
        $m->setAccessible(true);
        return $m->invoke($this->newPlugin(), $results);
    }

    public function test_empty_results_renders_nothing()
    {
        $html = $this->callRender([]);

        $this->assertSame('', $html);
    }

    public function test_all_still_needed_renders_summary_without_suggesting_removal()
    {
        $html = $this->callRender([
            ['id' => 1, 'title' => 'Feed One', 'still_needed' => true],
            ['id' => 2, 'title' => 'Feed Two', 'still_needed' => true],
        ]);

        $this->assertStringContainsString('All 2 configured feeds still need browser rendering', $html);
        $this->assertStringNotContainsString('consider removing', $html);
        $this->assertStringContainsString('Feed One', $html);
        $this->assertStringContainsString('Feed Two', $html);
    }

    public function test_some_no_longer_needed_suggests_removal_and_flags_the_right_row()
    {
        $html = $this->callRender([
            ['id' => 1, 'title' => 'Still Blocked', 'still_needed' => true],
            ['id' => 2, 'title' => 'Now Fine', 'still_needed' => false],
        ]);

        $this->assertStringContainsString('1 of 2 configured feeds fetched successfully', $html);
        $this->assertStringContainsString('consider removing it from the list', $html);
        $this->assertStringContainsString('May no longer be needed', $html);
        $this->assertStringContainsString('Still needed', $html);
    }

    public function test_plural_wording_when_multiple_feeds_no_longer_needed()
    {
        $html = $this->callRender([
            ['id' => 1, 'title' => 'Now Fine A', 'still_needed' => false],
            ['id' => 2, 'title' => 'Now Fine B', 'still_needed' => false],
        ]);

        $this->assertStringContainsString('consider removing them from the list', $html);
    }

    public function test_feed_links_use_rhesus_edit_feed_url()
    {
        $html = $this->callRender([
            ['id' => 42, 'title' => 'Some Feed', 'still_needed' => false],
        ]);

        $this->assertStringContainsString('href="/#/feed/42?editFeed=42"', $html);
    }
}
