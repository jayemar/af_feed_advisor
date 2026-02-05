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

    function about()
    {
        return array(
            1.0,
            'Analyzes feeds and provides configuration recommendations',
            'Feed Advisor'
        );
    }

    function init($host)
    {
        $this->host = $host;

        // Register hooks
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
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

        // Create advisory if we have a recommendation
        if ($analysis['recommendation'] !== null) {
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
        $link = "about:feed-advisor#" . $analysis['feed_id'];

        $sth = $pdo->prepare('
            INSERT INTO ttrss_entries (title, guid, link, content, date_entered, date_updated)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ');
        $sth->execute([$title, $guid, $link, $content]);
        $entry_id = $sth->fetch(PDO::FETCH_COLUMN);

        // Link to the owner's user entries (assuming owner_uid = 1, adjust if needed)
        $sth = $pdo->prepare('
            INSERT INTO ttrss_user_entries (ref_id, feed_id, owner_uid, unread, marked, published, last_marked, last_published)
            SELECT ?, id, owner_id, true, false, false, NULL, NULL
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
        $state_json = $this->host->get($this, 'state', '{}');
        return json_decode($state_json, true) ?: array();
    }

    /**
     * Save plugin state to storage
     */
    private function set_state($state)
    {
        $this->host->set($this, 'state', json_encode($state));
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
     * Preferences tab
     */
    function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") {
            return;
        }

        print "<div dojoType='dijit.layout.AccordionPane' title='Feed Advisor'>";

        print "<h2>Feed Advisor Settings</h2>";

        $enabled = $this->is_enabled();
        $auto_apply = $this->is_auto_apply_enabled();

        print "<form dojoType='dijit.form.Form'>";

        print "<script type='dojo/method' event='onSubmit' args='evt'>
            evt.preventDefault();
            if (this.validate()) {
                Notify.progress('Saving data...', true);

                new Ajax.Request('backend.php', {
                    parameters: dojo.objectToQuery(this.getValues()),
                    onComplete: function(transport) {
                        Notify.close();
                        Notify.info(transport.responseText);
                    }
                });
            }
        </script>";

        print "<input dojoType='dijit.form.TextBox' style='display:none' name='op' value='pluginhandler'>";
        print "<input dojoType='dijit.form.TextBox' style='display:none' name='method' value='save'>";
        print "<input dojoType='dijit.form.TextBox' style='display:none' name='plugin' value='af_feed_advisor'>";

        print "<table>";

        print "<tr><td width='40%'>" . __("Enable feed analysis") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='enabled' " . ($enabled ? "checked='checked'" : "") . "></td></tr>";

        print "<tr><td width='40%'>" . __("Automatically apply recommendations") . "</td>";
        print "<td><input dojoType='dijit.form.CheckBox' name='auto_apply' " . ($auto_apply ? "checked='checked'" : "") . "></td></tr>";

        print "</table>";

        print "<p><button dojoType='dijit.form.Button' type='submit'>" .
            __("Save") . "</button>";

        print "</form>";

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

        // Add JavaScript for AJAX handlers
        print "<script type='text/javascript'>";
        print "if (!Plugins.Af_Feed_Advisor) Plugins.Af_Feed_Advisor = {};";

        print "Plugins.Af_Feed_Advisor.bulkAnalyze = function() {
            Notify.progress('Analyzing all feeds...', true);
            new Ajax.Request('backend.php', {
                parameters: {
                    op: 'pluginhandler',
                    plugin: 'af_feed_advisor',
                    method: 'bulkAnalyze'
                },
                onComplete: function(transport) {
                    Notify.close();
                    var response = JSON.parse(transport.responseText);
                    Notify.info('Created ' + response.created + ' advisories');
                    window.location.reload();
                }
            });
            return false;
        };";

        print "Plugins.Af_Feed_Advisor.bulkApply = function() {
            if (!confirm('Apply all pending recommendations?')) return false;
            Notify.progress('Applying recommendations...', true);
            new Ajax.Request('backend.php', {
                parameters: {
                    op: 'pluginhandler',
                    plugin: 'af_feed_advisor',
                    method: 'bulkApplyRecommendations'
                },
                onComplete: function(transport) {
                    Notify.close();
                    var response = JSON.parse(transport.responseText);
                    Notify.info('Applied ' + response.disabled + ' disables and ' + response.enabled + ' enables');
                    window.location.reload();
                }
            });
            return false;
        };";

        print "Plugins.Af_Feed_Advisor.applyOne = function(feedId) {
            Notify.progress('Applying recommendation...', true);
            new Ajax.Request('backend.php', {
                parameters: {
                    op: 'pluginhandler',
                    plugin: 'af_feed_advisor',
                    method: 'applyOne',
                    feed_id: feedId
                },
                onComplete: function(transport) {
                    Notify.close();
                    Notify.info(transport.responseText);
                    window.location.reload();
                }
            });
            return false;
        };";

        print "Plugins.Af_Feed_Advisor.dismissOne = function(feedId) {
            Notify.progress('Dismissing advisory...', true);
            new Ajax.Request('backend.php', {
                parameters: {
                    op: 'pluginhandler',
                    plugin: 'af_feed_advisor',
                    method: 'dismissOne',
                    feed_id: feedId
                },
                onComplete: function(transport) {
                    Notify.close();
                    Notify.info(transport.responseText);
                    window.location.reload();
                }
            });
            return false;
        };";

        print "</script>";

        print "</div>";
    }

    /**
     * Save preferences
     */
    function save()
    {
        $enabled = checkbox_to_sql_bool($_POST['enabled'] ?? '');
        $auto_apply = checkbox_to_sql_bool($_POST['auto_apply'] ?? '');

        $this->host->set($this, 'enabled', $enabled);
        $this->host->set($this, 'auto_apply', $auto_apply);

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
