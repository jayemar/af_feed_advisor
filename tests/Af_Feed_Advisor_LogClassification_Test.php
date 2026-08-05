<?php

namespace Tests;

use Af_Feed_Advisor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for classify_log_lines(), the system health report's log-parsing
 * core.
 *
 * Covers the continuation-line bug found in production: TT-RSS logs an
 * HTTP client failure ("!! Last error: ..." / "cache_media: failed with
 * ...") as one line immediately followed by the raw response body as a
 * separate log line (e.g. a bare "error code: 504" or a JSON blob). Those
 * body lines routinely contain the literal word "error", which used to trip
 * the generic ERROR match and get reported as a second, context-free
 * "Application Error" - duplicating (with less information) an issue
 * already correctly counted under Feed Fetch Failures or Media/Enclosure
 * Caching by the line before it.
 */
class Af_Feed_Advisor_LogClassification_Test extends TestCase
{
    private Af_Feed_Advisor $plugin;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $mockHost = $this->createMock(\PluginHost::class);
        $mockHost->method('add_hook')->willReturn(true);

        $this->plugin = new Af_Feed_Advisor();
        $this->plugin->init($mockHost);

        $this->reflection = new ReflectionClass($this->plugin);
    }

    private function classify(array $lines): array
    {
        $m = $this->reflection->getMethod('classify_log_lines');
        $m->setAccessible(true);
        return $m->invoke($this->plugin, $lines);
    }

    // Real excerpt (with docker-compose service prefix, as export-docker-logs.sh
    // writes it) that produced a standalone, context-free "error code: 504"
    // Application Error before this fix.
    public function test_feed_fetch_response_body_is_not_reported_as_app_error(): void
    {
        $lines = array(
            "updater-1  | [20:29:57/173557] <= 1.1727 (sec) exit code: 100\n",
            "updater-1  | [20:29:57/173557] !! Last error: Server error: `GET https://invertedpassion.com/feed/` resulted in a `504 Gateway Timeout` response:\n",
            "updater-1  | error code: 504\n",
            "updater-1  | \n",
            "updater-1  | \n",
            "updater-1  | [20:29:57/173557] Base feed: https://www.trackinghappiness.com/feed/\n",
        );

        $issues = $this->classify($lines);

        $this->assertEmpty($issues['app_errors'], 'Response body line leaked through as a standalone app error');
        $this->assertSame(1, $issues['feed_fetch_total']);
        // "Gateway Timeout" matches categorize_feed_error()'s timeout check
        // before its 500/503/Server Error check ever runs - this is the
        // category the real production line above is actually filed under.
        $this->assertArrayHasKey('Connection Timeout', $issues['feed_fetch_categories']);
    }

    // Real excerpt that produced a standalone, context-free
    // {"error": "Error when parsing query string."} Application Error
    // before this fix - the actual problem (a malformed image URL) was
    // already captured under Media/Enclosure Caching by the line before it.
    public function test_cache_enclosure_response_body_is_not_reported_as_app_error(): void
    {
        $lines = array(
            "updater-1  | [18:05:01/171437] cache_enclosures: failed with 400: Client error: `GET https://www.theglobeandmail.com/resizer/v2/JLZEZYPOO5GF7C5IHSW5QTWXI4.JPG?auth=abc123&width=1200&quality=80` resulted in a `400 Bad Request` response:\n",
            "updater-1  | {\"error\": \"Error when parsing query string.\"}\n",
            "updater-1  | \n",
            "updater-1  | [18:05:02/171402] <= 3.7799 (sec) exit code: 0\n",
        );

        $issues = $this->classify($lines);

        $this->assertEmpty($issues['app_errors'], 'Response body line leaked through as a standalone app error');
        $this->assertSame(1, $issues['media_cache_total']);
        $this->assertArrayHasKey('www.theglobeandmail.com', $issues['media_cache_domains']);
    }

    // Real excerpt (S3's own error response format - a pre-signed
    // bookface-images.s3.us-west-2.amazonaws.com URL rejected as expired)
    // that produced a standalone, context-free "AuthorizationQueryParametersError"
    // Application Error before this fix. Unlike a single-line JSON body,
    // S3's XML error response spans two lines - the XML declaration line,
    // then the actual "<Error>...</Error>" content - so the original
    // one-line-only fix didn't fully absorb it.
    public function test_two_line_xml_response_body_is_not_reported_as_app_error(): void
    {
        $lines = array(
            "updater-1  | [12:07:34/273179] cache_enclosures: failed with 400: Client error: `GET https://bookface-images.s3.us-west-2.amazonaws.com/logos/abc.png?X-Amz-Algorithm=AWS4-HMAC-SHA256` resulted in a `400 Bad Request` response:\n",
            "updater-1  | <?xml version=\"1.0\" encoding=\"UTF-8\"?>\n",
            "updater-1  | <Error><Code>AuthorizationQueryParametersError</Code><Message>Query-string authentication requires the Signature, Expires and AccessKeyId parameters</Message></Error>\n",
            "updater-1  | \n",
            "updater-1  | [12:07:35/273179] Base feed: https://example.com/feed\n",
        );

        $issues = $this->classify($lines);

        $this->assertEmpty($issues['app_errors'], 'XML response body leaked through as a standalone app error');
        $this->assertSame(1, $issues['media_cache_total']);
        $this->assertArrayHasKey('bookface-images.s3.us-west-2.amazonaws.com', $issues['media_cache_domains']);
    }

    // A feed-fetch line with no trailing colon (e.g. a DNS failure, which
    // has no HTTP response body at all) must NOT swallow the next line -
    // there's nothing to skip, so it should be classified normally.
    public function test_feed_fetch_line_without_trailing_colon_does_not_swallow_next_line(): void
    {
        $lines = array(
            "updater-1  | [10:00:00/1] !! Last error: cURL error 6: Could not resolve host: example.com\n",
            "updater-1  | [10:00:01/2] SQLSTATE[XX000]: Internal error: something unrelated broke\n",
        );

        $issues = $this->classify($lines);

        $this->assertSame(1, $issues['feed_fetch_total']);
        $this->assertCount(1, $issues['app_errors']);
        $this->assertStringContainsString('SQLSTATE', $issues['app_errors'][0]['message']);
    }

    // A genuine multi-line application exception should still be reported
    // in full - only lines directly following a feed-fetch/cache-noise line
    // are treated as a response body to swallow.
    public function test_unrelated_exception_is_still_reported(): void
    {
        $lines = array(
            "updater-1  | [10:00:00/1] Uncaught Exception: Something genuinely broke\n",
        );

        $issues = $this->classify($lines);

        $this->assertCount(1, $issues['exceptions']);
        $this->assertSame('Something genuinely broke', $issues['exceptions'][0]['message']);
    }

    // Application error messages must never be truncated - a user
    // explicitly asked for full log lines in the report, since a cut-off
    // message (e.g. "...(truncated...)") often hides the one detail (a
    // domain, a status code) needed to tell whether it's actionable.
    public function test_app_error_message_is_not_truncated(): void
    {
        $long_message = str_repeat('x', 400);
        $lines = array(
            "updater-1  | [10:00:00/1] ERROR: {$long_message}\n",
        );

        $issues = $this->classify($lines);

        $this->assertCount(1, $issues['app_errors']);
        $this->assertStringContainsString($long_message, $issues['app_errors'][0]['message']);
        $this->assertStringNotContainsString('...', $issues['app_errors'][0]['message']);
    }

    private function pluginWithFilterPatterns(array $patterns): Af_Feed_Advisor
    {
        $mockHost = $this->createMock(\PluginHost::class);
        $mockHost->method('add_hook')->willReturn(true);
        $mockHost->method('get')->willReturnCallback(
            function ($plugin, $key, $default) use ($patterns) {
                if ($key === 'log_filter_patterns') {
                    return json_encode($patterns);
                }
                return $default;
            }
        );

        $plugin = new Af_Feed_Advisor();
        $plugin->init($mockHost);
        return $plugin;
    }

    private function classifyWith(Af_Feed_Advisor $plugin, array $lines): array
    {
        $ref = new ReflectionClass($plugin);
        $m = $ref->getMethod('classify_log_lines');
        $m->setAccessible(true);
        return $m->invoke($plugin, $lines);
    }

    // A user-configured plain-text pattern excludes any line containing it
    // (case-insensitively) from the report entirely, not just from one
    // section - it must not reappear as e.g. an infra warning either.
    public function test_user_plain_text_filter_excludes_matching_line(): void
    {
        $plugin = $this->pluginWithFilterPatterns(array('AWS4-HMAC'));
        $lines = array(
            "updater-1  | [10:00:00/1] ERROR: signature mismatch for AWS4-HMAC-SHA256 request\n",
            "updater-1  | [10:00:01/2] SQLSTATE[XX000]: unrelated real error\n",
        );

        $issues = $this->classifyWith($plugin, $lines);

        $this->assertCount(1, $issues['app_errors']);
        $this->assertStringContainsString('SQLSTATE', $issues['app_errors'][0]['message']);
    }

    // A user-configured pattern wrapped in slashes (with flags) is treated
    // as a full regex, matching this plugin's own built-in ignore patterns'
    // convention.
    public function test_user_regex_filter_excludes_matching_line(): void
    {
        $plugin = $this->pluginWithFilterPatterns(array('/timed out/i'));
        $lines = array(
            "updater-1  | [10:00:00/1] !! Last error: cURL error 28: Operation TIMED OUT after 15000ms\n",
        );

        $issues = $this->classifyWith($plugin, $lines);

        $this->assertSame(0, $issues['feed_fetch_total'], 'Filtered feed-fetch line should not be counted at all');
    }

    // A malformed user regex must degrade to "no match" rather than
    // breaking classification of the rest of the log - a bad pattern
    // shouldn't be able to corrupt or crash the whole report.
    public function test_invalid_user_regex_does_not_break_classification(): void
    {
        $plugin = $this->pluginWithFilterPatterns(array('/unterminated['));
        $lines = array(
            "updater-1  | [10:00:00/1] SQLSTATE[XX000]: a real error\n",
        );

        $issues = $this->classifyWith($plugin, $lines);

        $this->assertCount(1, $issues['app_errors']);
        $this->assertStringContainsString('SQLSTATE', $issues['app_errors'][0]['message']);
    }
}
