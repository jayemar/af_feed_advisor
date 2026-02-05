# Feed Advisor Plugin for Tiny Tiny RSS

## Overview

The Feed Advisor plugin automatically analyzes your RSS feeds and generates advisory articles with configuration recommendations. It helps you optimize feed settings for the best reading experience without making automatic changes.

## Features

- **Enclosure Detection**: Identifies feeds that provide images as enclosures (media:content) and recommends enabling `always_display_enclosures`
- **Duplicate Prevention**: Detects feeds with both inline images and enclosures, recommending disabling enclosure display to prevent duplicates
- **Advisory Feed**: Creates a special "System Advisories" feed where recommendations appear as articles
- **Non-destructive**: Never changes settings automatically - provides SQL commands you can run manually
- **Smart Analysis**: Only analyzes feeds when needed, avoiding duplicate advisories

## Installation

1. Copy the plugin directory to your TT-RSS plugins location:
   ```bash
   cp -r af_feed_advisor /path/to/ttrss/plugins.local/
   ```

   Or add to `plugins.conf`:
   ```
   /home/jayemar/projects/af_feed_advisor
   ```

2. Add to your TT-RSS environment configuration:
   ```yaml
   environment:
     - TTRSS_PLUGINS=auth_internal,note,nginx_xaccel,af_feed_advisor
   ```

3. Restart TT-RSS:
   ```bash
   docker compose restart
   ```

4. Enable the plugin:
   - Go to Preferences → Plugins
   - Enable "Feed Advisor"

## Configuration

Go to Preferences → Feeds → Feed Advisor to configure:

- **Enable feed analysis**: Toggle automatic feed analysis on/off
- **Recent Advisories**: View all advisories created by the plugin

## How It Works

1. When articles are fetched from a feed, the plugin analyzes recent content
2. It examines the last 20 articles to detect patterns:
   - Count articles with image enclosures
   - Count articles with inline `<img>` tags
   - Compare to current `always_display_enclosures` setting
3. If a configuration mismatch is detected, an advisory article is created
4. Advisory articles appear in the "System Advisories" special feed
5. Each advisory includes:
   - Analysis results
   - Current vs recommended settings
   - Explanation of why the change is recommended
   - SQL command to apply the change

## Advisory Format

```
Title: Crowd Supply: Enable enclosure display
Date: 2026-01-27 14:30

Feed Analysis Results:
• Feed: Crowd Supply (ID 358)
• URL: https://hachyderm.io/@crowdsupply.rss

Analysis:
✓ Found 8 articles with image enclosures (32 total images)
✗ Found 0 articles with inline <img> tags

Current Setting: always_display_enclosures = false
Recommended: always_display_enclosures = true

Reason: This feed only provides images as enclosures (media:content).
Without enabling enclosure display, images won't show in your RSS reader.

SQL to apply this change:
UPDATE ttrss_feeds SET always_display_enclosures = true WHERE id = 358;

---
Articles analyzed: 20 most recent
Last checked: 2026-01-27 14:30:45
```

## Use Cases

### Images Not Showing

If images aren't appearing in your RSS reader:
1. Check the "System Advisories" feed
2. Look for advisories about your feed
3. Follow the recommendation to enable enclosure display

### Duplicate Images

If you see the same image twice in articles:
1. Check for an advisory recommending to disable enclosures
2. The feed likely provides images both inline and as enclosures
3. Apply the recommended setting to prevent duplicates

### New Feed Configuration

When adding a new feed:
1. Wait for the first few articles to be fetched
2. Check "System Advisories" for recommendations
3. Apply suggested configuration for optimal display

## Technical Details

### Database Tables Used

- `ttrss_feeds`: Feed configuration and settings
- `ttrss_entries`: Article content for analysis
- `ttrss_user_entries`: Article ownership
- `ttrss_enclosures`: Media attachments (images, audio, video)
- `ttrss_plugin_storage`: Plugin state (advisory history)

### Analysis Algorithm

1. Query last 20 articles from feed
2. Count articles with enclosures: `JOIN ttrss_enclosures`
3. Count articles with inline images: regex search for `<img` tags
4. Apply decision logic:
   - **Enclosures only** → recommend `always_display_enclosures = true`
   - **Both enclosures and inline** → recommend `always_display_enclosures = false`
   - **Inline only** → no recommendation
   - **Neither** → no recommendation

### Advisory Deduplication

- Plugin stores advisory history in `ttrss_plugin_storage`
- Each advisory is tracked by feed ID and issue type
- Re-analysis occurs after 30 days if issue not dismissed
- Prevents duplicate advisories for the same issue

## Future Enhancements

Planned features for future versions:

- **Priority levels**: Critical, Warning, Info
- **Batch advisories**: Group multiple feed recommendations
- **Action tracking**: Mark advisories as applied/dismissed
- **Additional checks**:
  - Empty MIME types in enclosures
  - Low-resolution image URLs
  - Feed update failures
  - Stale feeds (no updates in 30+ days)
- **Weekly digest**: Summary of pending advisories

## Troubleshooting

### Advisories not appearing

1. Check plugin is enabled: Preferences → Plugins
2. Verify feed analysis is enabled: Preferences → Feeds → Feed Advisor
3. Check plugin logs for errors

### Advisory created but settings unclear

- Each advisory includes the exact SQL command to run
- You can run it via `docker compose exec db psql ...`
- Or use TT-RSS's web interface if available

### Want to reset advisory history

Plugin state is stored in `ttrss_plugin_storage`:
```sql
DELETE FROM ttrss_plugin_storage WHERE name = 'Af_Feed_Advisor';
```

## License

GPLv2 or later

## Author

Created for Tiny Tiny RSS
