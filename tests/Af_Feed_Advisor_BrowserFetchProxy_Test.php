<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use UrlHelper;

/**
 * Tests for hook_fetch_feed()'s browser-fetch-proxy override: feeds listed
 * in the "browser_fetch_feed_ids" setting are fetched via the
 * browser-fetch-proxy sidecar instead of TT-RSS's own HTTP client, falling
 * back to the normal fetch (by returning $feed_data unchanged) for every
 * other feed, and whenever the sidecar itself fails.
 */
class Af_Feed_Advisor_BrowserFetchProxy_Test extends TestCase
{
    private int $lastSavePdoCallDelta = 0;

    protected function setUp(): void
    {
        UrlHelper::$fetch_return = null;
        UrlHelper::$fetch_exception = null;
        UrlHelper::$last_fetch_options = null;
    }

    private function pluginWithFeedIds(array $feed_ids): Af_Feed_Advisor
    {
        $host = $this->createMock(\PluginHost::class);
        $host->method('add_hook')->willReturn(true);
        $host->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($feed_ids) {
                if ($key === 'browser_fetch_feed_ids') {
                    return json_encode($feed_ids);
                }
                return $default;
            }
        );
        $plugin = new Af_Feed_Advisor();
        $plugin->init($host);
        return $plugin;
    }

    public function test_feed_not_in_list_passes_through_unchanged()
    {
        $plugin = $this->pluginWithFeedIds([197, 200]);

        $result = $plugin->hook_fetch_feed('original-data', 'https://example.com/feed', 1, 42, 0, '', '');

        $this->assertSame('original-data', $result);
        $this->assertNull(UrlHelper::$last_fetch_options, 'should not call UrlHelper::fetch for an unlisted feed');
    }

    public function test_feed_in_list_fetches_via_proxy_and_returns_its_data()
    {
        UrlHelper::$fetch_return = '<rss>proxied content</rss>';
        $plugin = $this->pluginWithFeedIds([200]);

        $result = $plugin->hook_fetch_feed('original-data', 'https://verbatimbooks.com/feed/', 1, 200, 0, '', '');

        $this->assertSame('<rss>proxied content</rss>', $result);
        $this->assertNotNull(UrlHelper::$last_fetch_options);
        $this->assertSame(
            'http://browser-fetch-proxy/fetch?url=' . urlencode('https://verbatimbooks.com/feed/'),
            UrlHelper::$last_fetch_options['url']
        );
    }

    public function test_proxy_exception_falls_back_to_original_data()
    {
        UrlHelper::$fetch_exception = new \RuntimeException('connection refused');
        $plugin = $this->pluginWithFeedIds([200]);

        $result = $plugin->hook_fetch_feed('original-data', 'https://verbatimbooks.com/feed/', 1, 200, 0, '', '');

        $this->assertSame('original-data', $result);
    }

    public function test_proxy_empty_response_falls_back_to_original_data()
    {
        UrlHelper::$fetch_return = false;
        $plugin = $this->pluginWithFeedIds([200]);

        $result = $plugin->hook_fetch_feed('original-data', 'https://verbatimbooks.com/feed/', 1, 200, 0, '', '');

        $this->assertSame('original-data', $result);
    }

    // =========================================================================
    // save() - the "Browser-Rendered Feeds" search-and-add list submits feed
    // URLs (see hook_prefs_tab()), which save() must resolve back to feed
    // IDs before persisting - hook_fetch_feed() above still matches on ID.
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

        // init() itself touches Db::pdo() (ensure_schema()) - snapshot after
        // init so callers can measure exactly what save() itself triggered.
        $before_save_pdo_calls = \Db::$pdo_call_count;

        $_POST = $post;
        ob_start();
        try {
            $plugin->save();
        } finally {
            ob_end_clean();
            $_POST = [];
            $this->lastSavePdoCallDelta = \Db::$pdo_call_count - $before_save_pdo_calls;
        }

        return $captured;
    }

    public function test_save_with_no_urls_stores_empty_list_without_touching_db()
    {
        $stored = $this->runSave([]);

        $this->assertSame('[]', $stored['browser_fetch_feed_ids']);
        $this->assertSame(0, $this->lastSavePdoCallDelta, 'no URLs submitted - should never need to query the DB');
    }

    public function test_save_with_blank_urls_stores_empty_list_without_touching_db()
    {
        $stored = $this->runSave(['browser_fetch_feed_urls' => "\n \n"]);

        $this->assertSame('[]', $stored['browser_fetch_feed_ids']);
        $this->assertSame(0, $this->lastSavePdoCallDelta);
    }

    public function test_save_with_urls_resolves_via_db()
    {
        // Db::pdo() always throws in this test bootstrap (no real DB) - so
        // reaching it is exactly how we confirm save() attempted to resolve
        // the submitted URLs, the same technique this file's other tests
        // already use for Db::pdo()-dependent code paths (see
        // Af_Feed_Advisor_QuietWhenClean_Test's docblock).
        $this->expectException(\RuntimeException::class);
        try {
            $this->runSave(['browser_fetch_feed_urls' => "https://verbatimbooks.com/feed/"]);
        } finally {
            $this->assertSame(1, $this->lastSavePdoCallDelta, 'a submitted URL should trigger exactly one DB lookup');
        }
    }
}
