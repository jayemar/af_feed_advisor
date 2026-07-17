<?php
/**
 * Tiny Tiny RSS Plugin: Feed Advisor
 *
 * Analyzes feed content patterns and generates advisory articles with
 * configuration recommendations for optimal feed display.
 *
 * @author Your Name
 * @copyright 2026
 * @license GPLv2 or later
 */

class Af_Feed_Advisor extends Plugin
{
    private $host;

    // Advisory issue types
    const ISSUE_ENCLOSURES_DISABLED = 'enclosures_disabled';
    const ISSUE_ENCLOSURES_ENABLED = 'enclosures_enabled';
    const ISSUE_EMPTY_CONTENT_TYPE = 'empty_content_type';

    // Enclosure categories from bulk analysis
    const CATEGORY_DISABLE = 'disable';
    const CATEGORY_ENABLE_IMAGES = 'enable_images';
    const CATEGORY_ENABLE_MEDIA = 'enable_media';

    // Feed health monitoring
    const ISSUE_FEED_404 = 'feed_404';
    const ISSUE_FEED_403 = 'feed_403';
    const ISSUE_FEED_DNS = 'feed_dns';
    const ISSUE_FEED_TIMEOUT = 'feed_timeout';
    const ISSUE_FEED_PARSE = 'feed_parse';
    const ISSUE_FEED_SERVER = 'feed_server';
    const ISSUE_FEED_OTHER = 'feed_other';

    const FEED_HEALTH_LABEL = 'feed-health';
    const FEED_HEALTH_BROKEN_DAYS = 7;
    const FEED_STALE_DAYS = 365;
    const REPORT_INTERVAL_HOURS = 24;
    const SYSTEM_CHECK_INTERVAL_HOURS = 24;

    // Synthetic feed this plugin's own articles (health reports, system
    // advisories, notification articles) are attached to, so they get a
    // proper name/icon and a place in the feed tree instead of landing in
    // Archived (feed_id NULL) indistinguishable from real archived articles.
    const ADVISOR_FEED_URL = 'feed-advisor:local';

    // Rhesus runs as its own container on its own port (see docker-compose.yaml's
    // rhesus-server service) rather than under the same host/path as TT-RSS
    // itself, so a deep link into it has to assume this port rather than being
    // derivable from the current request.
    const RHESUS_PORT = 3001;

    // Builds a link into Rhesus's article view for a given feed, using the
    // current request's hostname (works whether accessed by IP, localhost, or
    // a real domain) with Rhesus's own port substituted in.
    private function rhesus_feed_url($feed_id) {
        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return "{$proto}://{$host}:" . self::RHESUS_PORT . "/feed/{$feed_id}";
    }

    function about()
    {
        return array(
            2.2,
            'Analyzes feeds, monitors system health, and provides recommendations',
            'Feed Advisor'
        );
    }

    function init($host)
    {
        $this->host = $host;

        // Register hooks
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
    }

    function get_generated_feeds($feed_id = null)
    {
        return array(
            'advisories' => array(
                'title' => 'System Advisories',
                'id' => -999,
            )
        );
    }

    function api_version()
    {
        return 2;
    }

    /**
     * Hook called when an article is processed
     */
    function hook_article_filter($article)
    {
        // Only analyze on feed updates if enabled
        if ($this->is_enabled()) {
            $feed_id = $article['owner_uid'] ? $article['feed']['id'] : null;
            if ($feed_id && $this->should_analyze($feed_id)) {
                $this->analyze_feed($feed_id);
            }
        }

        return $article;
    }

    /**
     * Hook called during housekeeping (periodic tasks)
     */
    function hook_house_keeping()
    {
        if ($this->is_enabled()) {
            if ($this->is_system_health_enabled() && $this->should_check_logs()) {
                $this->check_system_logs();
            }
            if ($this->should_check_health()) {
                $sth = Db::pdo()->query("SELECT id FROM ttrss_users WHERE id > 0");
                foreach ($sth->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $this->check_feed_health((int)$uid);
                }
            }
        }
    }

    /**
     * Check if feed advisor is enabled
     */
    private function is_enabled()
    {
        return sql_bool_to_bool($this->host->get($this, 'enabled', true));
    }

    /**
     * Check if auto-apply mode is enabled
     */
    private function is_auto_apply_enabled()
    {
        return sql_bool_to_bool($this->host->get($this, 'auto_apply', false));
    }

    /**
     * Check if enclosure display advisory is enabled
     */
    private function is_enclosure_check_enabled()
    {
        return sql_bool_to_bool($this->host->get($this, 'enclosure_check', true));
    }

    /**
     * Check if system health reports are enabled
     */
    private function is_system_health_enabled()
    {
        return sql_bool_to_bool($this->host->get($this, 'system_health', true));
    }

    /**
     * Check if feed health reports should be skipped when there's nothing to report
     */
    private function is_quiet_when_clean_enabled()
    {
        return sql_bool_to_bool($this->host->get($this, 'quiet_when_clean', false));
    }

    /**
     * Check if system health reports should be skipped when there are no log
     * errors/warnings/exceptions to report. Defaults to true, matching this
     * report's original always-quiet behavior.
     */
    private function is_system_quiet_when_clean_enabled()
    {
        return sql_bool_to_bool($this->host->get($this, 'system_quiet_when_clean', true));
    }

    /**
     * Check if we should analyze this feed
     */
    private function should_analyze($feed_id)
    {
        $state = $this->get_state();

        // Don't re-analyze if we've already created an advisory recently
        if (isset($state['advised'][$feed_id])) {
            $advisory = $state['advised'][$feed_id];
            $age_days = (time() - $advisory['timestamp']) / 86400;

            // Re-analyze after 30 days if not dismissed
            if (!$advisory['dismissed'] && $age_days < 30) {
                return false;
            }
        }

        return true;
    }

    /**
     * Analyze a feed's content patterns
     */
    private function analyze_feed($feed_id)
    {
        $pdo = Db::pdo();

        // Get feed info
        $sth = $pdo->prepare('SELECT title, feed_url, always_display_enclosures FROM ttrss_feeds WHERE id = ?');
        $sth->execute([$feed_id]);
        $feed = $sth->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            return;
        }

        // Analyze recent articles (last 20)
        $sth = $pdo->prepare('
            SELECT e.id, e.content
            FROM ttrss_entries e
            JOIN ttrss_user_entries ue ON e.id = ue.ref_id
            WHERE ue.feed_id = ?
            ORDER BY e.date_entered DESC
            LIMIT 20
        ');
        $sth->execute([$feed_id]);
        $articles = $sth->fetchAll(PDO::FETCH_ASSOC);

        if (empty($articles)) {
            return;
        }

        // Count articles with enclosures
        $sth = $pdo->prepare('
            SELECT COUNT(DISTINCT e.id) as count
            FROM ttrss_entries e
            JOIN ttrss_user_entries ue ON e.id = ue.ref_id
            JOIN ttrss_enclosures enc ON e.id = enc.post_id
            WHERE ue.feed_id = ?
            AND e.id IN (
                SELECT id FROM (
                    SELECT e2.id
                    FROM ttrss_entries e2
                    JOIN ttrss_user_entries ue2 ON e2.id = ue2.ref_id
                    WHERE ue2.feed_id = ?
                    ORDER BY e2.date_entered DESC
                    LIMIT 20
                ) recent
            )
        ');
        $sth->execute([$feed_id, $feed_id]);
        $articles_with_enclosures = $sth->fetch(PDO::FETCH_COLUMN);

        // Count total enclosures
        $sth = $pdo->prepare('
            SELECT COUNT(*) as count
            FROM ttrss_enclosures enc
            JOIN ttrss_entries e ON enc.post_id = e.id
            JOIN ttrss_user_entries ue ON e.id = ue.ref_id
            WHERE ue.feed_id = ?
            AND e.id IN (
                SELECT id FROM (
                    SELECT e2.id
                    FROM ttrss_entries e2
                    JOIN ttrss_user_entries ue2 ON e2.id = ue2.ref_id
                    WHERE ue2.feed_id = ?
                    ORDER BY e2.date_entered DESC
                    LIMIT 20
                ) recent
            )
        ');
        $sth->execute([$feed_id, $feed_id]);
        $total_enclosures = $sth->fetch(PDO::FETCH_COLUMN);

        // Count articles with inline images
        $articles_with_inline = 0;
        foreach ($articles as $article) {
            if (preg_match('/<img/i', $article['content'])) {
                $articles_with_inline++;
            }
        }

        // Determine recommendation
        $analysis = array(
            'feed_id' => $feed_id,
            'feed_title' => $feed['title'],
            'feed_url' => $feed['feed_url'],
            'articles_analyzed' => count($articles),
            'articles_with_enclosures' => $articles_with_enclosures,
            'total_enclosures' => $total_enclosures,
            'articles_with_inline' => $articles_with_inline,
            'current_setting' => sql_bool_to_bool($feed['always_display_enclosures']),
            'recommendation' => null,
            'reason' => null,
            'issue_type' => null
        );

        // Determine if configuration needs adjustment
        if ($articles_with_enclosures > 0 && $articles_with_inline == 0) {
            // Feed has enclosures but no inline images
            if (!$analysis['current_setting']) {
                $analysis['recommendation'] = true;
                $analysis['reason'] = 'This feed only provides images as enclosures (media:content). Without enabling enclosure display, images won\'t show in your RSS reader.';
                $analysis['issue_type'] = self::ISSUE_ENCLOSURES_DISABLED;
            }
        } elseif ($articles_with_enclosures > 0 && $articles_with_inline > 0) {
            // Feed has both enclosures and inline images
            if ($analysis['current_setting']) {
                $analysis['recommendation'] = false;
                $analysis['reason'] = 'This feed provides images both inline and as enclosures. Enabling enclosure display will cause duplicate images.';
                $analysis['issue_type'] = self::ISSUE_ENCLOSURES_ENABLED;
            }
        }

        // Create advisory if we have a recommendation and enclosure checking is enabled
        if ($analysis['recommendation'] !== null && $this->is_enclosure_check_enabled()) {
            // If auto-apply is enabled, apply the recommendation directly
            if ($this->is_auto_apply_enabled()) {
                $this->apply_recommendation($analysis['feed_id'], $analysis['recommendation'], $analysis['reason']);
            }
            $this->create_advisory($analysis);
        }
    }

    /**
     * Create an advisory article
     */
    private function create_advisory($analysis)
    {
        // Check if we've already created this advisory
        if ($this->already_advised($analysis['feed_id'], $analysis['issue_type'])) {
            return;
        }

        $pdo = Db::pdo();

        // Format the advisory content
        $timestamp = date('Y-m-d H:i:s');
        $setting_current = $analysis['current_setting'] ? 'true' : 'false';
        $setting_recommended = $analysis['recommendation'] ? 'true' : 'false';

        $content = "<div class='feed-advisor-article'>";
        $content .= "<h2>Feed Analysis Results</h2>";
        $content .= "<ul>";
        $content .= "<li><strong>Feed:</strong> {$analysis['feed_title']} (ID {$analysis['feed_id']})</li>";
        $content .= "<li><strong>URL:</strong> {$analysis['feed_url']}</li>";
        $content .= "</ul>";

        $content .= "<h2>Analysis</h2>";
        $content .= "<ul>";
        if ($analysis['articles_with_enclosures'] > 0) {
            $content .= "<li>✓ Found {$analysis['articles_with_enclosures']} articles with image enclosures ({$analysis['total_enclosures']} total images)</li>";
        } else {
            $content .= "<li>✗ Found 0 articles with image enclosures</li>";
        }
        if ($analysis['articles_with_inline'] > 0) {
            $content .= "<li>✓ Found {$analysis['articles_with_inline']} articles with inline &lt;img&gt; tags</li>";
        } else {
            $content .= "<li>✗ Found 0 articles with inline &lt;img&gt; tags</li>";
        }
        $content .= "</ul>";

        $content .= "<h2>Recommendation</h2>";
        $content .= "<ul>";
        $content .= "<li><strong>Current Setting:</strong> always_display_enclosures = {$setting_current}</li>";
        $content .= "<li><strong>Recommended:</strong> always_display_enclosures = {$setting_recommended}</li>";
        $content .= "</ul>";

        $content .= "<p><strong>Reason:</strong> {$analysis['reason']}</p>";

        $content .= "<h2>Change This Setting</h2>";
        $content .= "<p>In TT-RSS Preferences &rarr; Feeds, open the settings for &quot;" .
                    htmlspecialchars($analysis['feed_title']) .
                    "&quot; and go to the <strong>Display</strong> tab and toggle <em>Always display image attachments</em>.</p>";

        $content .= "<h2>SQL to apply this change</h2>";
        $content .= "<pre>UPDATE ttrss_feeds SET always_display_enclosures = {$setting_recommended} WHERE id = {$analysis['feed_id']};</pre>";

        $content .= "<hr>";
        $content .= "<p><small>Articles analyzed: {$analysis['articles_analyzed']} most recent<br>";
        $content .= "Last checked: {$timestamp}</small></p>";
        $content .= "</div>";

        // Create the advisory as a special article
        $title = "{$analysis['feed_title']}: " .
                 ($analysis['recommendation'] ? "Enable enclosure display" : "Disable enclosure display");

        // Insert into ttrss_entries
        $guid = "feed-advisor:" . $analysis['feed_id'] . ":" . $analysis['issue_type'] . ":" . time();
        $link = "about:feed-advisor#advisory-" . $analysis['feed_id'];
        $content_hash = 'SHA1:' . sha1($content);

        $sth = $pdo->prepare('
            INSERT INTO ttrss_entries (title, guid, link, content, content_hash, updated, date_entered, date_updated)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
            RETURNING id
        ');
        $sth->execute([$title, $guid, $link, $content, $content_hash]);
        $entry_id = $sth->fetch(PDO::FETCH_COLUMN);

        // Link to the owner's user entries
        $sth = $pdo->prepare('
            INSERT INTO ttrss_user_entries (ref_id, feed_id, owner_uid, unread, marked, published, uuid, tag_cache, label_cache)
            SELECT ?, id, owner_uid, true, false, false, \'\', \'\', \'{"no-labels":1}\'
            FROM ttrss_feeds
            WHERE id = ?
            LIMIT 1
        ');
        $sth->execute([$entry_id, $analysis['feed_id']]);

        // Record that we've created this advisory
        $this->record_advisory($analysis['feed_id'], $analysis['issue_type'], $analysis['recommendation']);
    }

    /**
     * Check if we've already created this advisory
     */
    private function already_advised($feed_id, $issue_type)
    {
        $state = $this->get_state();

        if (isset($state['advised'][$feed_id])) {
            $advisory = $state['advised'][$feed_id];
            if ($advisory['issue'] === $issue_type) {
                // Don't re-create if less than 30 days old
                $age_days = (time() - $advisory['timestamp']) / 86400;
                return $age_days < 30;
            }
        }

        return false;
    }

    /**
     * Record that we've created an advisory
     */
    private function record_advisory($feed_id, $issue_type, $recommendation = null)
    {
        $state = $this->get_state();

        if (!isset($state['advised'])) {
            $state['advised'] = array();
        }

        $state['advised'][$feed_id] = array(
            'issue' => $issue_type,
            'timestamp' => time(),
            'dismissed' => false,
            'applied' => false,
            'recommendation' => $recommendation
        );

        $this->set_state($state);
    }

    /**
     * Get plugin state from storage
     */
    private function get_state()
    {
        // Reuses get_plugin_setting()'s existing daemon-context fallback -
        // see set_state() below for why that fallback is needed at all.
        $state_json = $this->get_plugin_setting('state', '{}');
        return json_decode($state_json, true) ?: array();
    }

    /**
     * Save plugin state to storage
     */
    private function set_state($state)
    {
        $content = json_encode($state);
        $this->host->set($this, 'state', $content);

        // PluginHost::set()/save_data() only ever persists to the database
        // when running inside a real user session (owner_uid set) - it
        // silently no-ops during housekeeping/updater runs (owner_uid=0),
        // which is exactly when this is called for schedule bookkeeping
        // (last_log_check) and per-article advisories recorded during
        // normal feed updates. Without this fallback those writes vanish
        // the moment that process exits - "Last check" showed "Never"
        // forever despite system health reports arriving daily, because
        // the report itself is a raw SQL insert (unaffected) but the
        // bookkeeping write above was silently dropped. Mirrors
        // get_plugin_setting()'s existing admin-fallback convention. Only
        // do this when there's no real owner_uid, so a genuine per-user
        // session (e.g. uid=2) never clobbers uid=1's own state.
        if ($this->host->get_owner_uid()) {
            return;
        }

        try {
            $pdo = Db::pdo();
            $sth = $pdo->prepare(
                "SELECT content FROM ttrss_plugin_storage WHERE name = 'Af_Feed_Advisor' AND owner_uid = 1"
            );
            $sth->execute();
            $row = $sth->fetch();

            $stored = $row ? unserialize($row['content']) : [];
            if (!is_array($stored)) {
                $stored = [];
            }
            $stored['state'] = $content;
            $serialized = serialize($stored);

            if ($row) {
                $pdo->prepare(
                    "UPDATE ttrss_plugin_storage SET content = ? WHERE name = 'Af_Feed_Advisor' AND owner_uid = 1"
                )->execute([$serialized]);
            } else {
                $pdo->prepare(
                    "INSERT INTO ttrss_plugin_storage (name, owner_uid, content) VALUES ('Af_Feed_Advisor', 1, ?)"
                )->execute([$serialized]);
            }
        } catch (Exception $e) {
            Debug::log("Feed Advisor: Failed to persist state in daemon context: " . $e->getMessage());
        }
    }

    /**
     * Analyze all feeds in bulk (like smart-enclosure-settings.sql)
     * Returns categorized feed IDs: ['to_disable' => [], 'to_enable_images' => [], 'to_enable_media' => []]
     */
    private function analyze_all_feeds()
    {
        $pdo = Db::pdo();

        // Category 1: Feeds with image enclosures AND inline images (duplicates)
        $to_disable = $pdo->query("
            SELECT DISTINCT f.id, f.title, f.always_display_enclosures
            FROM ttrss_feeds f
            JOIN ttrss_user_entries ue ON ue.feed_id = f.id
            JOIN ttrss_enclosures enc ON enc.post_id = ue.ref_id
            JOIN ttrss_entries e ON e.id = ue.ref_id
            WHERE (
                enc.content_type LIKE 'image/%'
                OR (
                    (enc.content_type IS NULL OR enc.content_type = '')
                    AND (
                        LOWER(enc.content_url) LIKE '%.jpg'
                        OR LOWER(enc.content_url) LIKE '%.jpeg'
                        OR LOWER(enc.content_url) LIKE '%.png'
                        OR LOWER(enc.content_url) LIKE '%.gif'
                        OR LOWER(enc.content_url) LIKE '%.webp'
                    )
                )
            )
            AND e.content LIKE '%<img%'
            AND f.always_display_enclosures = true
            ORDER BY f.title
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Category 2: Feeds with image enclosures but NO inline images
        $to_enable_images = $pdo->query("
            SELECT DISTINCT fie.id, fie.title, fie.always_display_enclosures
            FROM (
                SELECT DISTINCT f.id, f.title, f.always_display_enclosures
                FROM ttrss_feeds f
                JOIN ttrss_user_entries ue ON ue.feed_id = f.id
                JOIN ttrss_enclosures enc ON enc.post_id = ue.ref_id
                WHERE enc.content_type LIKE 'image/%'
                   OR (
                       (enc.content_type IS NULL OR enc.content_type = '')
                       AND (
                           LOWER(enc.content_url) LIKE '%.jpg'
                           OR LOWER(enc.content_url) LIKE '%.jpeg'
                           OR LOWER(enc.content_url) LIKE '%.png'
                           OR LOWER(enc.content_url) LIKE '%.gif'
                           OR LOWER(enc.content_url) LIKE '%.webp'
                       )
                   )
            ) fie
            LEFT JOIN (
                SELECT DISTINCT f.id
                FROM ttrss_feeds f
                JOIN ttrss_user_entries ue ON ue.feed_id = f.id
                JOIN ttrss_entries e ON e.id = ue.ref_id
                WHERE e.content LIKE '%<img%'
            ) fii ON fie.id = fii.id
            WHERE fii.id IS NULL
            AND fie.always_display_enclosures = false
            ORDER BY fie.title
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Category 3: Feeds with audio/video enclosures (podcasts, videos)
        $to_enable_media = $pdo->query("
            SELECT DISTINCT f.id, f.title, f.always_display_enclosures
            FROM ttrss_feeds f
            JOIN ttrss_user_entries ue ON ue.feed_id = f.id
            JOIN ttrss_enclosures enc ON enc.post_id = ue.ref_id
            WHERE (enc.content_type LIKE 'audio/%'
               OR enc.content_type LIKE 'video/%')
            AND f.always_display_enclosures = false
            ORDER BY f.title
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array(
            'to_disable' => $to_disable,
            'to_enable_images' => $to_enable_images,
            'to_enable_media' => $to_enable_media
        );
    }

    /**
     * Apply a single recommendation
     */
    private function apply_recommendation($feed_id, $new_setting, $reason = '')
    {
        $pdo = Db::pdo();

        $sth = $pdo->prepare('UPDATE ttrss_feeds SET always_display_enclosures = ? WHERE id = ?');
        $sth->execute([$new_setting, $feed_id]);

        // Record in state
        $state = $this->get_state();
        if (!isset($state['advised'][$feed_id])) {
            return false;
        }

        $state['advised'][$feed_id]['applied'] = true;
        $state['advised'][$feed_id]['applied_timestamp'] = time();
        $this->set_state($state);

        return true;
    }

    /**
     * Apply all pending recommendations
     */
    public function apply_all_recommendations()
    {
        $state = $this->get_state();
        $applied = 0;
        $failed = 0;

        if (!isset($state['advised'])) {
            return array('applied' => 0, 'failed' => 0);
        }

        foreach ($state['advised'] as $feed_id => $advisory) {
            if (!$advisory['dismissed'] && !($advisory['applied'] ?? false)) {
                $new_setting = $advisory['recommendation'];
                if ($this->apply_recommendation($feed_id, $new_setting)) {
                    $applied++;
                } else {
                    $failed++;
                }
            }
        }

        return array('applied' => $applied, 'failed' => $failed);
    }

    /**
     * Bulk analyze and create advisories for all feeds
     */
    public function bulk_analyze()
    {
        $analysis = $this->analyze_all_feeds();

        $created = 0;

        // Create advisories for feeds that need enclosures disabled
        foreach ($analysis['to_disable'] as $feed) {
            $advisory_data = array(
                'feed_id' => $feed['id'],
                'feed_title' => $feed['title'],
                'feed_url' => '',
                'articles_analyzed' => 0,
                'articles_with_enclosures' => 1,
                'total_enclosures' => 1,
                'articles_with_inline' => 1,
                'current_setting' => true,
                'recommendation' => false,
                'reason' => 'This feed provides images both inline and as enclosures. Enabling enclosure display will cause duplicate images.',
                'issue_type' => self::ISSUE_ENCLOSURES_ENABLED
            );

            if (!$this->already_advised($feed['id'], self::ISSUE_ENCLOSURES_ENABLED)) {
                $this->create_advisory($advisory_data);
                $created++;
            }
        }

        // Create advisories for feeds that need enclosures enabled (images)
        foreach ($analysis['to_enable_images'] as $feed) {
            $advisory_data = array(
                'feed_id' => $feed['id'],
                'feed_title' => $feed['title'],
                'feed_url' => '',
                'articles_analyzed' => 0,
                'articles_with_enclosures' => 1,
                'total_enclosures' => 1,
                'articles_with_inline' => 0,
                'current_setting' => false,
                'recommendation' => true,
                'reason' => 'This feed only provides images as enclosures (media:content). Without enabling enclosure display, images won\'t show in your RSS reader.',
                'issue_type' => self::ISSUE_ENCLOSURES_DISABLED
            );

            if (!$this->already_advised($feed['id'], self::ISSUE_ENCLOSURES_DISABLED)) {
                $this->create_advisory($advisory_data);
                $created++;
            }
        }

        // Create advisories for feeds that need enclosures enabled (media)
        foreach ($analysis['to_enable_media'] as $feed) {
            $advisory_data = array(
                'feed_id' => $feed['id'],
                'feed_title' => $feed['title'],
                'feed_url' => '',
                'articles_analyzed' => 0,
                'articles_with_enclosures' => 1,
                'total_enclosures' => 1,
                'articles_with_inline' => 0,
                'current_setting' => false,
                'recommendation' => true,
                'reason' => 'This feed provides audio or video enclosures (podcast/video content). Enclosures should be enabled to display this media.',
                'issue_type' => self::ISSUE_ENCLOSURES_DISABLED
            );

            if (!$this->already_advised($feed['id'], self::ISSUE_ENCLOSURES_DISABLED)) {
                $this->create_advisory($advisory_data);
                $created++;
            }
        }

        return array(
            'created' => $created,
            'analysis' => $analysis
        );
    }

    /**
     * Bulk apply all recommendations (like smart-enclosure-settings.sql)
     */
    public function bulk_apply()
    {
        $analysis = $this->analyze_all_feeds();
        $pdo = Db::pdo();

        $disabled = 0;
        $enabled = 0;

        // Disable enclosures for feeds with duplicates
        foreach ($analysis['to_disable'] as $feed) {
            $sth = $pdo->prepare('UPDATE ttrss_feeds SET always_display_enclosures = false WHERE id = ?');
            $sth->execute([$feed['id']]);
            $disabled++;
        }

        // Enable enclosures for image-only feeds
        foreach ($analysis['to_enable_images'] as $feed) {
            $sth = $pdo->prepare('UPDATE ttrss_feeds SET always_display_enclosures = true WHERE id = ?');
            $sth->execute([$feed['id']]);
            $enabled++;
        }

        // Enable enclosures for media feeds
        foreach ($analysis['to_enable_media'] as $feed) {
            $sth = $pdo->prepare('UPDATE ttrss_feeds SET always_display_enclosures = true WHERE id = ?');
            $sth->execute([$feed['id']]);
            $enabled++;
        }

        return array(
            'disabled' => $disabled,
            'enabled' => $enabled,
            'total' => $disabled + $enabled
        );
    }

    /**
     * Elapsed-time gate, same design as should_check_health() below: runs
     * once get_system_check_interval_hours() worth of time has passed since
     * the last check. $last_run lets tests bypass get_last_log_check_time()
     * (which reads from state) for determinism; leave null in production.
     */
    private function should_check_logs(?int $now = null, ?int $last_run = null): bool
    {
        if ($last_run === null) {
            $last_run = $this->get_last_log_check_time();
        }
        $interval_seconds = $this->get_system_check_interval_hours() * 3600;
        return (($now ?? time()) - $last_run) >= $interval_seconds;
    }

    private function get_plugin_setting(string $key, $default)
    {
        // Works in web context where owner_uid is set
        $val = $this->host->get($this, $key, null);
        if ($val !== null && $val !== false) {
            return $val;
        }

        // In daemon context owner_uid is 0; fall back to admin user (uid=1) stored settings
        try {
            $pdo = Db::pdo();
            $sth = $pdo->prepare(
                "SELECT content FROM ttrss_plugin_storage WHERE name = 'Af_Feed_Advisor' AND owner_uid = 1"
            );
            $sth->execute();
            $row = $sth->fetch();
            if ($row) {
                $data = unserialize($row['content']);
                if (is_array($data) && isset($data[$key])) {
                    return $data[$key];
                }
            }
        } catch (Exception $e) {
            // fall through
        }

        return $default;
    }

    private function get_broken_days(): int
    {
        return max(1, (int)$this->get_plugin_setting('broken_days', self::FEED_HEALTH_BROKEN_DAYS));
    }

    private function get_stale_days(): int
    {
        return max(30, (int)$this->get_plugin_setting('stale_days', self::FEED_STALE_DAYS));
    }

    private function get_report_interval_hours(): int
    {
        $valid = [1, 6, 12, 24, 168];
        $v = (int)$this->get_plugin_setting('report_interval_hours', self::REPORT_INTERVAL_HOURS);
        return in_array($v, $valid) ? $v : self::REPORT_INTERVAL_HOURS;
    }

    private function get_last_health_check_time(): int
    {
        try {
            $pdo = Db::pdo();
            $sth = $pdo->query(
                "SELECT EXTRACT(EPOCH FROM date_entered)::int FROM ttrss_entries
                 WHERE guid LIKE 'feed-advisor:health-report:%'
                 ORDER BY date_entered DESC LIMIT 1"
            );
            $ts = $sth->fetchColumn();
            return $ts !== false ? (int)$ts : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function should_check_health(?int $now = null, ?int $last_run = null): bool
    {
        if ($last_run === null) {
            $last_run = $this->get_last_health_check_time();
        }
        $interval_seconds = $this->get_report_interval_hours() * 3600;
        return (($now ?? time()) - $last_run) >= $interval_seconds;
    }

    private function get_system_check_interval_hours(): int
    {
        $valid = [1, 6, 12, 24, 168];
        $v = (int)$this->get_plugin_setting('system_check_interval_hours', self::SYSTEM_CHECK_INTERVAL_HOURS);
        return in_array($v, $valid) ? $v : self::SYSTEM_CHECK_INTERVAL_HOURS;
    }

    // Unlike get_last_health_check_time() (which reads the timestamp off
    // its own report article), system health's last-check time already
    // lives in state (last_log_check) - reused directly here.
    private function get_last_log_check_time(): int
    {
        $state = $this->get_state();
        return (int)($state['last_log_check'] ?? 0);
    }

    private function check_feed_health(int $owner_uid, bool $force = false): int
    {
        $pdo = Db::pdo();
        $broken_days = $this->get_broken_days();
        $stale_days = $this->get_stale_days();

        $sth = $pdo->prepare("
            SELECT id, title, feed_url, site_url, last_error, last_updated,
                   last_successful_update, owner_uid
            FROM ttrss_feeds
            WHERE last_error != ''
              AND owner_uid = ?
              AND (last_successful_update IS NULL
                   OR last_successful_update < NOW() - INTERVAL '{$broken_days} days')
            ORDER BY last_successful_update NULLS FIRST, title
        ");
        $sth->execute([$owner_uid]);
        $broken_feeds = $sth->fetchAll(PDO::FETCH_ASSOC);

        $sth = $pdo->prepare("
            SELECT f.id, f.title, f.feed_url, f.site_url, f.owner_uid,
                   MAX(e.updated) AS last_article_date
            FROM ttrss_feeds f
            JOIN ttrss_user_entries ue ON ue.feed_id = f.id
            JOIN ttrss_entries e ON e.id = ue.ref_id
            WHERE (f.last_error = '' OR f.last_error IS NULL)
              AND f.owner_uid = ?
              AND f.feed_url NOT LIKE 'share-anything:%'
              AND f.feed_url != '" . self::ADVISOR_FEED_URL . "'
            GROUP BY f.id, f.title, f.feed_url, f.site_url, f.owner_uid
            HAVING MAX(e.updated) < NOW() - INTERVAL '{$stale_days} days'
            ORDER BY MAX(e.updated) ASC
        ");
        $sth->execute([$owner_uid]);
        $stale_feeds = $sth->fetchAll(PDO::FETCH_ASSOC);

        $this->create_consolidated_health_advisory($broken_feeds, $stale_feeds, $owner_uid, $force);

        $broken_count = count($broken_feeds);
        $stale_count = count($stale_feeds);
        Debug::log("Feed Advisor: Health check complete for uid={$owner_uid}, {$broken_count} broken, {$stale_count} stale.");
        return $broken_count + $stale_count;
    }

    // Returns the id of this user's "Feed Advisor" synthetic feed, creating
    // it (with a custom icon, and updates disabled since feed_url isn't a
    // real HTTP source) on first use.
    private function get_or_create_advisor_feed_id(int $owner_uid): int
    {
        $pdo = Db::pdo();

        $sth = $pdo->prepare("SELECT id FROM ttrss_feeds WHERE owner_uid = ? AND feed_url = ?");
        $sth->execute([$owner_uid, self::ADVISOR_FEED_URL]);
        $feed_id = $sth->fetchColumn();

        if ($feed_id) {
            return (int)$feed_id;
        }

        $sth = $pdo->prepare("
            INSERT INTO ttrss_feeds (owner_uid, title, feed_url, update_interval, cat_id)
            VALUES (?, 'Feed Advisor', ?, -1, NULL)
            RETURNING id
        ");
        $sth->execute([$owner_uid, self::ADVISOR_FEED_URL]);
        $feed_id = (int)$sth->fetchColumn();

        $this->set_advisor_feed_icon($feed_id);

        return $feed_id;
    }

    private function set_advisor_feed_icon(int $feed_id): void
    {
        try {
            $icon_path = __DIR__ . '/feed-icon.png';
            if (!is_file($icon_path)) return;

            $content = file_get_contents($icon_path);
            if ($content === false) return;

            DiskCache::instance('feed-icons')->put((string)$feed_id, $content);

            $feed = ORM::for_table('ttrss_feeds')->find_one($feed_id);
            if ($feed) {
                $feed->set(['favicon_avg_color' => null, 'favicon_is_custom' => true]);
                $feed->save();
            }
        } catch (Exception $e) {
            Debug::log("Feed Advisor: Failed to set advisor feed icon: " . $e->getMessage());
        }
    }

    private function create_consolidated_health_advisory(array $broken_feeds, array $stale_feeds, int $owner_uid, bool $force = false): void
    {
        $broken_count = count($broken_feeds);
        $stale_count = count($stale_feeds);

        if (!$force && $broken_count === 0 && $stale_count === 0 && $this->is_quiet_when_clean_enabled()) {
            Debug::log("Feed Advisor: No feed issues for uid={$owner_uid}, skipping report (quiet mode).");
            return;
        }

        $pdo = Db::pdo();
        $timestamp = date('Y-m-d H:i:s');
        $guid = 'feed-advisor:health-report:' . date('Y-m-d-H-i-s') . '-uid' . $owner_uid;
        $broken_days = $this->get_broken_days();
        $stale_days = $this->get_stale_days();

        $parts = [];
        if ($broken_count > 0) {
            $parts[] = "{$broken_count} broken";
        }
        if ($stale_count > 0) {
            $parts[] = "{$stale_count} stale";
        }
        $title = "Feed Health Report" . (!empty($parts) ? ': ' . implode(', ', $parts) : ': all clear');

        $content = "<div class='feed-advisor-article'>";
        $content .= "<h2>Feed Health Report</h2>";
        $content .= "<p><strong>Generated:</strong> {$timestamp}</p>";

        // Broken feeds section
        $content .= "<h3>Broken Feeds</h3>";
        if ($broken_count === 0) {
            $content .= "<p>No feeds have been failing for more than {$broken_days} days.</p>";
        } else {
            $content .= "<p>{$broken_count} feed" . ($broken_count !== 1 ? 's have' : ' has') .
                        " been failing for more than {$broken_days} days.</p>";
            $content .= "<table>";
            $content .= "<tr><th>Feed</th><th>Error Type</th><th>Last Success</th><th>Broken For</th><th>Suggestion</th><th>Links</th></tr>";

            foreach ($broken_feeds as $feed) {
                $error_info = $this->categorize_feed_error($feed['last_error']);

                if ($feed['last_successful_update']) {
                    $last_success = new DateTime($feed['last_successful_update']);
                    $days_broken = (int)(new DateTime())->diff($last_success)->days;
                    $last_success_str = $feed['last_successful_update'];
                } else {
                    $days_broken = null;
                    $last_success_str = 'Never';
                }

                $days_str = ($days_broken !== null) ? "{$days_broken}d" : 'Unknown';

                $feed_link = "<a href=\"" . htmlspecialchars($feed['feed_url']) . "\" target=\"_blank\" rel=\"noopener\">" .
                             htmlspecialchars($feed['title']) . "</a>";
                $links = "<a href=\"" . htmlspecialchars($this->rhesus_feed_url($feed['id'])) . "\" target=\"_blank\" rel=\"noopener\">Rhesus</a>";
                if (!empty($feed['site_url'])) {
                    $links .= " &middot; <a href=\"" . htmlspecialchars($feed['site_url']) . "\" target=\"_blank\" rel=\"noopener\">Site</a>";
                }
                $content .= "<tr>";
                $content .= "<td>{$feed_link}</td>";
                $content .= "<td>{$error_info['label']}</td>";
                $content .= "<td>{$last_success_str}</td>";
                $content .= "<td>{$days_str}</td>";
                $content .= "<td>{$error_info['suggestion']}</td>";
                $content .= "<td>{$links}</td>";
                $content .= "</tr>";
            }

            $content .= "</table>";
        }

        // Stale feeds section
        $content .= "<h3>Stale Feeds</h3>";
        if ($stale_count === 0) {
            $content .= "<p>No feeds have gone more than {$stale_days} days without a new article.</p>";
        } else {
            $content .= "<p>{$stale_count} feed" . ($stale_count !== 1 ? 's have' : ' has') .
                        " not published a new article in more than {$stale_days} days.</p>";
            $content .= "<table>";
            $content .= "<tr><th>Feed</th><th>Last Article</th><th>Days Silent</th><th>Links</th></tr>";

            foreach ($stale_feeds as $feed) {
                $last_date = $feed['last_article_date'];
                $last_dt = new DateTime($last_date);
                $days_silent = (int)(new DateTime())->diff($last_dt)->days;

                $feed_link = "<a href=\"" . htmlspecialchars($feed['feed_url']) . "\" target=\"_blank\" rel=\"noopener\">" .
                             htmlspecialchars($feed['title']) . "</a>";
                $links = "<a href=\"" . htmlspecialchars($this->rhesus_feed_url($feed['id'])) . "\" target=\"_blank\" rel=\"noopener\">Rhesus</a>";
                if (!empty($feed['site_url'])) {
                    $links .= " &middot; <a href=\"" . htmlspecialchars($feed['site_url']) . "\" target=\"_blank\" rel=\"noopener\">Site</a>";
                }
                $content .= "<tr>";
                $content .= "<td>{$feed_link}</td>";
                $content .= "<td>{$last_date}</td>";
                $content .= "<td>{$days_silent}d</td>";
                $content .= "<td>{$links}</td>";
                $content .= "</tr>";
            }

            $content .= "</table>";
        }

        $content .= "<hr>";
        $content .= "<p><small>Checked: {$timestamp}</small></p>";
        $content .= "</div>";

        $link = "about:feed-advisor#feed-health";
        $content_hash = 'SHA1:' . sha1($content);

        try {
            $sth = $pdo->prepare("
                INSERT INTO ttrss_entries
                    (title, guid, link, content, content_hash, updated, date_entered, date_updated)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ON CONFLICT (guid) DO NOTHING
                RETURNING id
            ");
            $sth->execute([$title, $guid, $link, $content, $content_hash]);
            $entry_id = $sth->fetchColumn();

            if (!$entry_id) {
                Debug::log("Feed Advisor: Health report for this window already exists, skipping.");
                return;
            }

            $feed_id = $this->get_or_create_advisor_feed_id($owner_uid);

            $sth = $pdo->prepare("
                INSERT INTO ttrss_user_entries
                    (ref_id, feed_id, owner_uid, unread, marked, published, uuid, tag_cache, label_cache)
                VALUES (?, ?, ?, true, false, false, '', '', '')
                ON CONFLICT DO NOTHING
            ");
            $sth->execute([$entry_id, $feed_id, $owner_uid]);

            if ($broken_count > 0 || $stale_count > 0) {
                $label_id = $this->get_or_create_health_label($owner_uid);
                if ($label_id) {
                    $this->apply_label_to_entry($entry_id, $label_id);
                }
            }

            Debug::log("Feed Advisor: Created health report ({$broken_count} broken, {$stale_count} stale).");
        } catch (Exception $e) {
            Debug::log("Feed Advisor: Failed to create consolidated health report: " . $e->getMessage());
        }
    }

    private function categorize_feed_error(string $error): array
    {
        if (preg_match('/404|Not Found/i', $error)) {
            return [
                'type' => self::ISSUE_FEED_404,
                'label' => 'Not Found (404)',
                'suggestion' => 'The feed URL no longer exists. Check whether the site has a new feed URL, or remove this feed.',
            ];
        }
        if (preg_match('/403|Forbidden|Cloudflare/i', $error)) {
            return [
                'type' => self::ISSUE_FEED_403,
                'label' => 'Access Blocked (403)',
                'suggestion' => 'The server is blocking access, likely due to Cloudflare protection or bot detection. The feed may have moved behind a login or paywall.',
            ];
        }
        if (preg_match('/cURL error 6|Could not resolve host/i', $error)) {
            return [
                'type' => self::ISSUE_FEED_DNS,
                'label' => 'DNS Failure',
                'suggestion' => 'The domain cannot be resolved. The site may have shut down or moved to a new domain.',
            ];
        }
        if (preg_match('/cURL error 28|timed out|timeout/i', $error)) {
            return [
                'type' => self::ISSUE_FEED_TIMEOUT,
                'label' => 'Connection Timeout',
                'suggestion' => 'The server is not responding. It may be temporarily down or rate-limiting connections.',
            ];
        }
        if (preg_match('/LibXML|StartTag|invalid element/i', $error)) {
            return [
                'type' => self::ISSUE_FEED_PARSE,
                'label' => 'Parse Error',
                'suggestion' => 'The feed content cannot be parsed. The URL may now return non-feed content (an HTML page, for example).',
            ];
        }
        if (preg_match('/503|Service Unavailable|500|Server Error/i', $error)) {
            return [
                'type' => self::ISSUE_FEED_SERVER,
                'label' => 'Server Error',
                'suggestion' => 'The server is returning an error. This may be temporary; if it persists, the feed may have been removed.',
            ];
        }
        return [
            'type' => self::ISSUE_FEED_OTHER,
            'label' => 'Fetch Error',
            'suggestion' => 'An unexpected error occurred fetching this feed. Verify the URL is still a valid RSS/Atom feed.',
        ];
    }

    private function get_or_create_health_label(int $owner_uid): ?int
    {
        $pdo = Db::pdo();
        $caption = self::FEED_HEALTH_LABEL;

        $sth = $pdo->prepare("SELECT id FROM ttrss_labels2 WHERE caption = ? AND owner_uid = ?");
        $sth->execute([$caption, $owner_uid]);
        $row = $sth->fetch();
        if ($row) {
            return (int)$row['id'];
        }

        $sth = $pdo->prepare("
            INSERT INTO ttrss_labels2 (caption, owner_uid, fg_color, bg_color)
            VALUES (?, ?, '#ffffff', '#c0392b')
            RETURNING id
        ");
        $sth->execute([$caption, $owner_uid]);
        $id = $sth->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function apply_label_to_entry(int $article_id, int $label_id): void
    {
        $pdo = Db::pdo();
        $sth = $pdo->prepare("
            INSERT INTO ttrss_user_labels2 (label_id, article_id)
            VALUES (?, ?)
            ON CONFLICT DO NOTHING
        ");
        $sth->execute([$label_id, $article_id]);
    }

    /**
     * Check system logs for errors and warnings
     */
    private function check_system_logs(bool $force = false): bool
    {
        // Parse Docker logs from the last 24 hours
        $issues = $this->parse_docker_logs();
        $issues_found = !empty($issues['errors']) || !empty($issues['warnings']) || !empty($issues['exceptions']);

        if ($force || $issues_found || !$this->is_system_quiet_when_clean_enabled()) {
            $this->create_system_advisory($issues);
        }

        // Update state
        $state = $this->get_state();
        $state['last_log_check'] = time();
        $this->set_state($state);

        return $issues_found;
    }

    /**
     * Parse Docker logs for warnings, errors, and exceptions
     */
    private function parse_docker_logs()
    {
        $issues = array(
            'errors' => array(),
            'warnings' => array(),
            'exceptions' => array()
        );

        try {
            // Check if log file is mounted (optional feature)
            // To enable: docker run -v /var/run/docker.sock:/var/run/docker.sock
            // Or mount a log file at /var/log/ttrss/docker.log
            $log_file = '/var/log/ttrss/docker.log';

            if (!file_exists($log_file)) {
                Debug::log("Feed Advisor: Log file not found at {$log_file} - skipping system monitoring");
                return $issues;
            }

            // The time window is enforced by export-docker-logs.sh's `--since`
            // flag when it writes this file, not here - we just read whatever
            // range it exported.
            $output = array();

            $handle = fopen($log_file, 'r');
            if (!$handle) {
                return $issues;
            }

            while (($line = fgets($handle)) !== false) {
                $output[] = $line;
            }
            fclose($handle);

            if (empty($output)) {
                return $issues;
            }

            $error_counts = array();
            $warning_counts = array();
            $exception_counts = array();

            foreach ($output as $line) {
                // Skip empty lines
                if (trim($line) === '') {
                    continue;
                }

                // Match exceptions
                if (preg_match('/Exception: (.+)/', $line, $matches)) {
                    $error_msg = trim($matches[1]);
                    if (!isset($exception_counts[$error_msg])) {
                        $exception_counts[$error_msg] = 0;
                    }
                    $exception_counts[$error_msg]++;
                }
                // Match errors (case insensitive)
                elseif (preg_match('/\b(ERROR|SQLSTATE)\b/i', $line)) {
                    // Extract meaningful error message
                    $error_msg = $this->extract_error_message($line);
                    if ($error_msg && !isset($error_counts[$error_msg])) {
                        $error_counts[$error_msg] = 0;
                    }
                    if ($error_msg) {
                        $error_counts[$error_msg]++;
                    }
                }
                // Match warnings
                elseif (preg_match('/\b(WARNING|Warning|warning)\b/', $line)) {
                    $warning_msg = $this->extract_error_message($line);
                    if ($warning_msg && !isset($warning_counts[$warning_msg])) {
                        $warning_counts[$warning_msg] = 0;
                    }
                    if ($warning_msg) {
                        $warning_counts[$warning_msg]++;
                    }
                }
            }

            // Convert counts to issue arrays
            foreach ($exception_counts as $msg => $count) {
                $issues['exceptions'][] = array('message' => $msg, 'count' => $count);
            }
            foreach ($error_counts as $msg => $count) {
                $issues['errors'][] = array('message' => $msg, 'count' => $count);
            }
            foreach ($warning_counts as $msg => $count) {
                $issues['warnings'][] = array('message' => $msg, 'count' => $count);
            }

            // Sort by count (descending)
            usort($issues['exceptions'], function($a, $b) { return $b['count'] - $a['count']; });
            usort($issues['errors'], function($a, $b) { return $b['count'] - $a['count']; });
            usort($issues['warnings'], function($a, $b) { return $b['count'] - $a['count']; });

        } catch (Exception $e) {
            // Log parsing failed, return empty
            Debug::log("Feed Advisor: Failed to parse Docker logs: " . $e->getMessage());
        }

        return $issues;
    }

    /**
     * Extract meaningful error message from log line
     */
    private function extract_error_message($line)
    {
        // Remove timestamp and container prefix
        $line = preg_replace('/^[^|]*\|\s*/', '', $line);
        $line = preg_replace('/^\[\d+:\d+:\d+\/\d+\]\s*/', '', $line);

        // Extract just the error message (first 200 chars)
        $line = trim($line);
        if (strlen($line) > 200) {
            $line = substr($line, 0, 200) . '...';
        }

        return $line;
    }

    /**
     * Create a consolidated system advisory article
     */
    private function create_system_advisory($issues)
    {
        $pdo = Db::pdo();
        $timestamp = date('Y-m-d H:i:s');

        // Build advisory content
        $content = "<div class='feed-advisor-article'>";
        $content .= "<h2>System Health Report</h2>";
        $content .= "<p><strong>Generated:</strong> {$timestamp}</p>";
        $content .= "<p>This report summarizes errors, warnings, and exceptions from the last 24 hours.</p>";

        $total_issues = count($issues['exceptions']) + count($issues['errors']) + count($issues['warnings']);

        if ($total_issues === 0) {
            $content .= "<p><strong>✓ No issues detected</strong></p>";
        } else {
            // Exceptions section
            if (!empty($issues['exceptions'])) {
                $content .= "<h3>Exceptions (" . count($issues['exceptions']) . ")</h3>";
                $content .= "<ul>";
                foreach ($issues['exceptions'] as $issue) {
                    $count_text = $issue['count'] > 1 ? " ({$issue['count']} occurrences)" : "";
                    $content .= "<li><code>" . htmlspecialchars($issue['message']) . "</code>{$count_text}</li>";
                }
                $content .= "</ul>";
            }

            // Errors section
            if (!empty($issues['errors'])) {
                $content .= "<h3>Errors (" . count($issues['errors']) . ")</h3>";
                $content .= "<ul>";
                foreach ($issues['errors'] as $issue) {
                    $count_text = $issue['count'] > 1 ? " ({$issue['count']} occurrences)" : "";
                    $content .= "<li><code>" . htmlspecialchars($issue['message']) . "</code>{$count_text}</li>";
                }
                $content .= "</ul>";
            }

            // Warnings section
            if (!empty($issues['warnings'])) {
                $content .= "<h3>Warnings (" . count($issues['warnings']) . ")</h3>";
                $content .= "<ul>";
                foreach ($issues['warnings'] as $issue) {
                    $count_text = $issue['count'] > 1 ? " ({$issue['count']} occurrences)" : "";
                    $content .= "<li><code>" . htmlspecialchars($issue['message']) . "</code>{$count_text}</li>";
                }
                $content .= "</ul>";
            }
        }

        $content .= "<hr>";
        $content .= "<p><small>Monitoring period: Last 24 hours<br>";
        $content .= "Total issues: {$total_issues}</small></p>";
        $content .= "</div>";

        // Create the advisory article. GUID includes full timestamp (not
        // just the date) so that check frequencies shorter than daily
        // (hourly, every 6 hours, etc.) each get their own report instead
        // of every same-day attempt after the first silently no-op'ing via
        // the ON CONFLICT below - mirrors create_consolidated_health_advisory()'s
        // per-second feed-health GUID.
        $title = "System Health Report - {$timestamp}";
        $guid = 'feed-advisor:system-health:' . date('Y-m-d-H-i-s');
        $link = "about:feed-advisor#system-health";
        $content_hash = 'SHA1:' . sha1($content);

        try {
            $sth = $pdo->prepare('
                INSERT INTO ttrss_entries (title, guid, link, content, content_hash, updated, date_entered, date_updated)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ON CONFLICT (guid) DO NOTHING
                RETURNING id
            ');
            $sth->execute([$title, $guid, $link, $content, $content_hash]);
            $entry_id = $sth->fetchColumn();

            if (!$entry_id) {
                Debug::log("Feed Advisor: System health advisory for today already exists, skipping");
                return;
            }

            // Link to user entries (owner_uid = 1)
            $feed_id = $this->get_or_create_advisor_feed_id(1);

            $sth = $pdo->prepare('
                INSERT INTO ttrss_user_entries (ref_id, feed_id, owner_uid, unread, marked, published, uuid, tag_cache, label_cache)
                VALUES (?, ?, 1, true, false, false, \'\', \'\', \'{"no-labels":1}\')
                ON CONFLICT DO NOTHING
            ');
            $sth->execute([$entry_id, $feed_id]);

            Debug::log("Feed Advisor: Created system health advisory with {$total_issues} issues");
        } catch (Exception $e) {
            Debug::log("Feed Advisor: Failed to create system advisory: " . $e->getMessage());
        }
    }

    /**
     * Preferences tab
     */
    function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") {
            return;
        }

        print "<div dojoType='dijit.layout.AccordionPane' title=\"<i class='material-icons'>recommend</i> Feed Advisor\">";

        print "<h2>Feed Advisor Settings</h2>";

        print "<style>#af-advisor-save .dijitButtonNode { background: #1a73e8 !important; border-color: #1165c4 !important; } #af-advisor-save .dijitButtonText { color: #fff !important; }</style>";

        $enabled = $this->is_enabled();
        $auto_apply = $this->is_auto_apply_enabled();
        $enclosure_check = $this->is_enclosure_check_enabled();
        $system_health = $this->is_system_health_enabled();
        $quiet_when_clean = $this->is_quiet_when_clean_enabled();
        $system_quiet_when_clean = $this->is_system_quiet_when_clean_enabled();

        print "<form dojoType='dijit.form.Form'>";

        print "<script type='dojo/method' event='onSubmit' args='evt'>
            evt.preventDefault();
            if (this.validate()) {
                Notify.progress('Saving data...', true);
                xhr.post('backend.php', this.getValues(), (reply) => {
                    Notify.info(reply);
                });
            }
        </script>";

        print "<input dojoType='dijit.form.TextBox' style='display:none' name='op' value='PluginHandler'>";
        print "<input dojoType='dijit.form.TextBox' style='display:none' name='method' value='save'>";
        print "<input dojoType='dijit.form.TextBox' style='display:none' name='plugin' value='af_feed_advisor'>";

        // Feed health monitoring (load before form so values are available)
        $pdo = Db::pdo();
        $sth = $pdo->query("
            SELECT date_entered FROM ttrss_entries
            WHERE guid LIKE 'feed-advisor:health-report:%'
            ORDER BY date_entered DESC LIMIT 1
        ");
        $last_health_row = $sth->fetch();
        $last_health_str = $last_health_row ? $last_health_row['date_entered'] : 'Never';

        $broken_days = $this->get_broken_days();
        $stale_days = $this->get_stale_days();
        $report_interval_hours = $this->get_report_interval_hours();
        $system_check_interval_hours = $this->get_system_check_interval_hours();

        $sth = $pdo->query("
            SELECT COUNT(*) FROM ttrss_feeds
            WHERE last_error != ''
              AND (last_successful_update IS NULL
                   OR last_successful_update < NOW() - INTERVAL '{$broken_days} days')
        ");
        $broken_count = (int)$sth->fetchColumn();

        $sth = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT f.id
                FROM ttrss_feeds f
                JOIN ttrss_user_entries ue ON ue.feed_id = f.id
                JOIN ttrss_entries e ON e.id = ue.ref_id
                WHERE (f.last_error = '' OR f.last_error IS NULL)
                  AND f.feed_url NOT LIKE 'share-anything:%'
              AND f.feed_url != '" . self::ADVISOR_FEED_URL . "'
                GROUP BY f.id
                HAVING MAX(e.updated) < NOW() - INTERVAL '{$stale_days} days'
            ) stale
        ");
        $stale_count = (int)$sth->fetchColumn();

        $interval_options = [
            1   => __('Hourly'),
            6   => __('Every 6 hours'),
            12  => __('Twice daily'),
            24  => __('Daily'),
            168 => __('Weekly'),
        ];

        $state = $this->get_state();
        $last_check = $state['last_log_check'] ?? 0;
        $last_check_str = $last_check ? date('Y-m-d H:i:s', $last_check) : 'Never';

        print "<table>";

        print "<tr><td width='40%'><h3 style='margin:0'>" . __("Enable plugin") . "</h3></td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='enabled' " . ($enabled ? "checked='checked'" : "") . "></td></tr>";

        print "<tr><td colspan='2'><h3 style='margin-bottom:4px'>Feed Health Monitoring</h3></td></tr>";

        print "<tr><td width='40%'>" . __("Generate system health reports") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='system_health' " . ($system_health ? "checked='checked'" : "") . "></td></tr>";

        print "<tr><td width='40%'>" . __("Only report when there's an issue") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='quiet_when_clean' " . ($quiet_when_clean ? "checked='checked'" : "") . "></td></tr>";

        print "<tr><td width='40%'>Report frequency</td><td>";
        print "<select dojoType='dijit.form.Select' name='report_interval_hours' style='width:160px'>";
        foreach ($interval_options as $hours => $label) {
            $selected = ($hours === $report_interval_hours) ? " selected='selected'" : '';
            print "<option value='{$hours}'{$selected}>{$label}</option>";
        }
        print "</select></td></tr>";

        print "<tr><td>Broken feed threshold (days)</td>";
        print "<td><input dojoType='dijit.form.NumberSpinner' name='broken_days' value='{$broken_days}'" .
              " constraints='{min:1,max:365}' style='width:80px'></td></tr>";

        print "<tr><td>Stale feed threshold (days)</td>";
        print "<td><input dojoType='dijit.form.NumberSpinner' name='stale_days' value='{$stale_days}'" .
              " constraints='{min:30,max:3650}' style='width:80px'></td></tr>";

        print "<tr><td colspan='2'><ul style='margin:4px 0'>" .
            "<li><strong>" . __('Last report:') . "</strong> {$last_health_str}</li>" .
            "<li><strong>" . __('Currently broken feeds:') . "</strong> {$broken_count}</li>" .
            "<li><strong>" . __('Currently stale feeds:') . "</strong> {$stale_count}</li>" .
            "</ul></td></tr>";

        print "<tr><td colspan='2'><button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.checkHealthNow()'>" .
            __("Check Feed Health Now") . "</button></td></tr>";

        print "<tr><td colspan='2'><h3 style='margin-bottom:4px'>System Log Monitoring</h3></td></tr>";
        print "<tr><td colspan='2'><p style='margin:4px 0'>" .
            __('Reads Docker logs and creates advisory articles for errors, warnings, and exceptions.') .
            "</p></td></tr>";

        print "<tr><td width='40%'>" . __("Only report when there's an error") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='system_quiet_when_clean' " . ($system_quiet_when_clean ? "checked='checked'" : "") . "></td></tr>";

        print "<tr><td width='40%'>Check frequency</td><td>";
        print "<select dojoType='dijit.form.Select' name='system_check_interval_hours' style='width:160px'>";
        foreach ($interval_options as $hours => $label) {
            $selected = ($hours === $system_check_interval_hours) ? " selected='selected'" : '';
            print "<option value='{$hours}'{$selected}>{$label}</option>";
        }
        print "</select></td></tr>";

        print "<tr><td colspan='2'><ul style='margin:4px 0'>" .
            "<li><strong>" . __('Last check:') . "</strong> {$last_check_str}</li>" .
            "</ul></td></tr>";

        print "<tr><td colspan='2'><button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.checkSystemHealthNow()'>" .
            __("Check System Health Now") . "</button></td></tr>";

        print "<tr><td colspan='2'><h3 style='margin-bottom:4px'>Feed Enclosure Settings</h3></td></tr>";

        print "<tr><td width='40%'>" . __("Advise on enclosure display settings") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='enclosure_check' " . ($enclosure_check ? "checked='checked'" : "") . "></td></tr>";

        print "<tr><td width='40%'>" . __("Automatically apply recommendations") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='auto_apply' " . ($auto_apply ? "checked='checked'" : "") . "></td></tr>";

        print "</table>";

        print "<p id='af-advisor-save'><button dojoType='dijit.form.Button' type='submit'>" .
            __("Save") . "</button></p>";

        print "</form>";

        // Notification article: inserts a standalone article at an arbitrary
        // date/time, independent of this form's persisted settings, so it
        // lives outside the <form> above (own button, own handler - see
        // createNotificationArticle() below and its JS counterpart in
        // get_prefs_js()).
        print "<h2>" . __("Notification Article") . "</h2>";
        print "<p>" . __("Creates an article under the Feed Advisor feed that sorts into your article list at the date/time you choose below - the article's own displayed date is always when you actually click Create, not the insertion time.") . "</p>";
        print "<table>";
        print "<tr><td width='40%'>" . __("Title") . "</td>";
        print "<td><input type='text' id='af-advisor-notif-title' style='width:100%;box-sizing:border-box'></td></tr>";
        print "<tr><td width='40%' style='vertical-align:top'>" . __("Content") . "</td>";
        print "<td><textarea id='af-advisor-notif-content' rows='4' style='width:100%;box-sizing:border-box'></textarea></td></tr>";
        print "<tr><td width='40%'>" . __("Insert at") . "</td>";
        print "<td><input type='datetime-local' id='af-advisor-notif-datetime'>" .
            "<div style='font-size:11px;opacity:0.7;margin-top:2px'>" . __("Your local time zone - converted automatically.") . "</div></td></tr>";
        print "</table>";
        print "<style>#af-advisor-notif-create .dijitButtonNode { background: #1a73e8 !important; border-color: #1165c4 !important; } #af-advisor-notif-create .dijitButtonText { color: #fff !important; }</style>";
        print "<p id='af-advisor-notif-create'><button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.createNotificationArticle()'>" .
            __("Create Notification Article") . "</button></p>";

        // Bulk operations
        print "<h2>Bulk Operations</h2>";
        print "<p>";
        print "<button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.bulkAnalyze()'>" .
            __("Analyze All Feeds") . "</button> ";
        print "<button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.bulkApply()'>" .
            __("Apply All Recommendations") . "</button>";
        print "</p>";

        // Show current state
        $state = $this->get_state();
        if (!empty($state['advised'])) {
            print "<h2>Recent Advisories</h2>";
            print "<table class='prefPluginsList'>";
            print "<tr><th>Feed ID</th><th>Issue</th><th>Date</th><th>Status</th><th>Actions</th></tr>";

            foreach ($state['advised'] as $feed_id => $advisory) {
                $date = date('Y-m-d H:i', $advisory['timestamp']);
                $status = 'Pending';
                if ($advisory['dismissed']) {
                    $status = 'Dismissed';
                } elseif ($advisory['applied'] ?? false) {
                    $status = 'Applied';
                }

                print "<tr>";
                print "<td>{$feed_id}</td>";
                print "<td>{$advisory['issue']}</td>";
                print "<td>{$date}</td>";
                print "<td>{$status}</td>";
                print "<td>";
                if (!$advisory['dismissed'] && !($advisory['applied'] ?? false)) {
                    print "<button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.applyOne({$feed_id})'>" .
                        __("Apply") . "</button> ";
                    print "<button dojoType='dijit.form.Button' onclick='return Plugins.Af_Feed_Advisor.dismissOne({$feed_id})'>" .
                        __("Dismiss") . "</button>";
                }
                print "</td>";
                print "</tr>";
            }

            print "</table>";
        }

        print "</div>";
    }

    function get_prefs_js()
    {
        return "if (!Plugins.Af_Feed_Advisor) Plugins.Af_Feed_Advisor = {};

        // Prefill 'Insert at' with the current moment in the browser's own
        // local time zone - a server-rendered default would be in the
        // server's time zone instead, which is misleading given the field
        // is otherwise entirely local-time (see createNotificationArticle()).
        (function() {
            var el = document.getElementById('af-advisor-notif-datetime');
            if (el && !el.value) {
                var now = new Date();
                var pad = function(n) { return String(n).padStart(2, '0'); };
                el.value = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) +
                    'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
            }
        })();

        Plugins.Af_Feed_Advisor.bulkAnalyze = function() {
            Notify.progress('Analyzing all feeds...', true);
            xhr.json('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'bulkAnalyze'})
                .then(function(r) { Notify.info('Created ' + r.created + ' advisories'); });
            return false;
        };

        Plugins.Af_Feed_Advisor.bulkApply = function() {
            if (!confirm('Apply all pending recommendations?')) return false;
            Notify.progress('Applying recommendations...', true);
            xhr.json('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'bulkApplyRecommendations'})
                .then(function(r) { Notify.info('Applied ' + r.disabled + ' disables and ' + r.enabled + ' enables'); });
            return false;
        };

        Plugins.Af_Feed_Advisor.applyOne = function(feedId) {
            Notify.progress('Applying recommendation...', true);
            xhr.post('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'applyOne', feed_id: feedId})
                .then(function(r) { Notify.info(r); });
            return false;
        };

        Plugins.Af_Feed_Advisor.dismissOne = function(feedId) {
            Notify.progress('Dismissing advisory...', true);
            xhr.post('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'dismissOne', feed_id: feedId})
                .then(function(r) { Notify.info(r); });
            return false;
        };

        Plugins.Af_Feed_Advisor.checkHealthNow = function() {
            Notify.progress('Checking feed health...', true);
            xhr.json('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'checkHealthNow'})
                .then(function(r) { Notify.info('Feed health report created (' + r.created + ' issue(s) found).'); });
            return false;
        };

        Plugins.Af_Feed_Advisor.checkSystemHealthNow = function() {
            Notify.progress('Checking system logs...', true);
            xhr.json('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'checkSystemHealthNow'})
                .then(function(r) { Notify.info('System health report created (' + (r.issues_found ? 'issues found' : 'all clear') + ').'); });
            return false;
        };

        Plugins.Af_Feed_Advisor.createNotificationArticle = function() {
            var title = document.getElementById('af-advisor-notif-title').value;
            var content = document.getElementById('af-advisor-notif-content').value;
            var insertAtLocal = document.getElementById('af-advisor-notif-datetime').value;

            if (!title.trim()) {
                Notify.error('Title is required.');
                return false;
            }
            if (!insertAtLocal) {
                Notify.error('Insertion date/time is required.');
                return false;
            }

            // The datetime-local input's value has no timezone attached - the
            // browser edits/displays it in the user's own local time zone, so
            // that's how the Date constructor parses this exact string shape
            // (unlike PHP's strtotime(), which would otherwise interpret it
            // using the server's timezone). Converting to an ISO string here
            // (with its 'Z' suffix) makes the instant unambiguous by the time
            // it reaches the server.
            var insertAtDate = new Date(insertAtLocal);
            if (isNaN(insertAtDate.getTime())) {
                Notify.error('Invalid date/time.');
                return false;
            }
            var insertAtUtc = insertAtDate.toISOString();

            Notify.progress('Creating article...', true);
            xhr.json('backend.php', {op: 'PluginHandler', plugin: 'af_feed_advisor', method: 'createNotificationArticle',
                title: title, content: content, insert_at: insertAtUtc})
                .then(function(r) {
                    if (r.error) {
                        Notify.error(r.error);
                    } else {
                        Notify.info('Notification article created.');
                        document.getElementById('af-advisor-notif-title').value = '';
                        document.getElementById('af-advisor-notif-content').value = '';
                        // Rhesus doesn't auto-poll, and its pull-to-refresh gesture
                        // appends new articles without re-sorting them into place -
                        // a manual refresh (switching feeds/views, or reopening the
                        // article list) is what actually re-applies sort order.
                        var when = insertAtDate.toLocaleString();
                        alert('\"' + title + '\" article created for ' + when + '\\n\\nYou may need to refresh your article list for the new article to properly be included.');
                    }
                });
            return false;
        };";
    }

    /**
     * Save preferences
     */
    function save()
    {
        $enabled = checkbox_to_sql_bool($_POST['enabled'] ?? '');
        $auto_apply = checkbox_to_sql_bool($_POST['auto_apply'] ?? '');
        $broken_days = max(1, (int)($_POST['broken_days'] ?? self::FEED_HEALTH_BROKEN_DAYS));
        $stale_days = max(30, (int)($_POST['stale_days'] ?? self::FEED_STALE_DAYS));
        $valid_intervals = [1, 6, 12, 24, 168];
        $report_interval_hours = (int)($_POST['report_interval_hours'] ?? self::REPORT_INTERVAL_HOURS);
        if (!in_array($report_interval_hours, $valid_intervals)) {
            $report_interval_hours = self::REPORT_INTERVAL_HOURS;
        }
        $system_check_interval_hours = (int)($_POST['system_check_interval_hours'] ?? self::SYSTEM_CHECK_INTERVAL_HOURS);
        if (!in_array($system_check_interval_hours, $valid_intervals)) {
            $system_check_interval_hours = self::SYSTEM_CHECK_INTERVAL_HOURS;
        }

        $enclosure_check = checkbox_to_sql_bool($_POST['enclosure_check'] ?? '');
        $system_health = checkbox_to_sql_bool($_POST['system_health'] ?? '');
        $quiet_when_clean = checkbox_to_sql_bool($_POST['quiet_when_clean'] ?? '');
        $system_quiet_when_clean = checkbox_to_sql_bool($_POST['system_quiet_when_clean'] ?? '');

        $this->host->set($this, 'enabled', $enabled);
        $this->host->set($this, 'auto_apply', $auto_apply);
        $this->host->set($this, 'enclosure_check', $enclosure_check);
        $this->host->set($this, 'system_health', $system_health);
        $this->host->set($this, 'quiet_when_clean', $quiet_when_clean);
        $this->host->set($this, 'system_quiet_when_clean', $system_quiet_when_clean);
        $this->host->set($this, 'broken_days', $broken_days);
        $this->host->set($this, 'stale_days', $stale_days);
        $this->host->set($this, 'report_interval_hours', $report_interval_hours);
        $this->host->set($this, 'system_check_interval_hours', $system_check_interval_hours);

        echo __("Configuration saved.");
    }

    /**
     * AJAX handler: Bulk analyze all feeds
     */
    function bulkAnalyze()
    {
        $result = $this->bulk_analyze();
        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * AJAX handler: Run feed health check immediately. Always creates a
     * report, regardless of the "only report when there's an issue"
     * setting - that setting only governs the automatic scheduled checks.
     */
    function checkHealthNow()
    {
        $created = $this->check_feed_health((int)$_SESSION['uid'], true);
        header('Content-Type: application/json');
        echo json_encode(['created' => $created]);
    }

    /**
     * AJAX handler: Run the system log check immediately, bypassing the
     * once-daily schedule. Always creates a report, regardless of the
     * "only report when there's an error" setting - same rationale as
     * checkHealthNow() above.
     */
    function checkSystemHealthNow()
    {
        $issues_found = $this->check_system_logs(true);
        header('Content-Type: application/json');
        echo json_encode(['issues_found' => $issues_found]);
    }

    /**
     * AJAX handler: Creates a standalone article (same feed-less/"Archived"
     * shape as the health report articles below) whose date_entered - the
     * column TT-RSS actually sorts headlines by - is the user-chosen
     * insertion time, which may be in the past or future. `updated`/
     * `date_updated` are always NOW(), so the article's own displayed date
     * is when it was really created, independent of where it sorts.
     */
    function createNotificationArticle()
    {
        header('Content-Type: application/json');

        $title = trim(strip_tags($_POST['title'] ?? ''));
        $content_raw = trim($_POST['content'] ?? '');
        $insert_at = trim($_POST['insert_at'] ?? '');

        if ($title === '') {
            echo json_encode(['error' => 'Title is required.']);
            return;
        }

        $insert_ts = $insert_at !== '' ? strtotime($insert_at) : false;
        if ($insert_ts === false) {
            echo json_encode(['error' => 'Invalid date/time.']);
            return;
        }

        $owner_uid = (int)$_SESSION['uid'];
        $pdo = Db::pdo();

        // Plain-text textarea input, not trusted HTML - escape then restore
        // line breaks, rather than storing the raw value as article content.
        $content_html = nl2br(htmlspecialchars($content_raw));
        $date_entered = date('Y-m-d H:i:s', $insert_ts);
        $guid = 'feed-advisor:notification:' . uniqid('', true) . '-uid' . $owner_uid;
        $content_hash = 'SHA1:' . sha1($content_html);

        try {
            $sth = $pdo->prepare("
                INSERT INTO ttrss_entries
                    (title, guid, link, content, content_hash, updated, date_entered, date_updated)
                VALUES (?, ?, '', ?, ?, NOW(), ?, NOW())
                ON CONFLICT (guid) DO NOTHING
                RETURNING id
            ");
            $sth->execute([$title, $guid, $content_html, $content_hash, $date_entered]);
            $entry_id = $sth->fetchColumn();

            if (!$entry_id) {
                echo json_encode(['error' => 'Could not create article (duplicate guid, please retry).']);
                return;
            }

            $feed_id = $this->get_or_create_advisor_feed_id($owner_uid);

            $sth = $pdo->prepare("
                INSERT INTO ttrss_user_entries
                    (ref_id, feed_id, owner_uid, unread, marked, published, uuid, tag_cache, label_cache)
                VALUES (?, ?, ?, true, false, false, '', '', '')
            ");
            $sth->execute([$entry_id, $feed_id, $owner_uid]);

            Debug::log("Feed Advisor: Created notification article '{$title}' (id={$entry_id}) for uid={$owner_uid}, inserted at {$date_entered}.");
            echo json_encode(['success' => true, 'id' => $entry_id]);
        } catch (Exception $e) {
            Debug::log("Feed Advisor: Failed to create notification article: " . $e->getMessage());
            echo json_encode(['error' => 'Failed to create article.']);
        }
    }

    /**
     * AJAX handler: Bulk apply all recommendations
     */
    function bulkApplyRecommendations()
    {
        $result = $this->bulk_apply();
        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * AJAX handler: Apply a single recommendation
     */
    function applyOne()
    {
        $feed_id = (int)$_REQUEST['feed_id'];
        $state = $this->get_state();

        if (!isset($state['advised'][$feed_id])) {
            echo "Advisory not found.";
            return;
        }

        $advisory = $state['advised'][$feed_id];
        $new_setting = $advisory['recommendation'];

        if ($this->apply_recommendation($feed_id, $new_setting)) {
            echo "Recommendation applied successfully.";
        } else {
            echo "Failed to apply recommendation.";
        }
    }

    /**
     * AJAX handler: Dismiss a single advisory
     */
    function dismissOne()
    {
        $feed_id = (int)$_REQUEST['feed_id'];
        $state = $this->get_state();

        if (!isset($state['advised'][$feed_id])) {
            echo "Advisory not found.";
            return;
        }

        $state['advised'][$feed_id]['dismissed'] = true;
        $this->set_state($state);

        echo "Advisory dismissed.";
    }
}
