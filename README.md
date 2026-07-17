# Feed Advisor - TT-RSS Plugin

Monitors feed health and analyzes enclosure configuration, creating advisory articles
directly in your TT-RSS instance.

## Features

- **Feed health monitoring** - detects broken and stale feeds on a configurable schedule,
  creating a consolidated report article tagged `feed-health`
- **Enclosure analysis** - identifies feeds whose `always_display_enclosures` setting does
  not match their content, and recommends or auto-applies corrections
- **System log monitoring** - reads Docker logs on a configurable schedule and surfaces
  errors, warnings, and exceptions as advisory articles
- **Notification articles** - manually create an article that sorts into your article
  list at a date/time you choose, past or future, with your own title and message
- **Bulk operations** - analyze all feeds at once and apply all pending recommendations
  in one action
- **Per-user isolation** - each user's health reports only reflect their own feeds

All articles this plugin creates that aren't tied to a specific feed (health reports,
system log advisories, notification articles) appear under a synthetic **Feed Advisor**
feed, created automatically the first time any of them fires. It has updates disabled
(it isn't backed by a real URL) and its own icon.

## Installation

Add the plugin source path to `plugins.conf` in your homelab ttrss directory, then run
`manage-plugins.sh`:

```
/home/jayemar/projects/af_feed_advisor
```

```bash
./manage-plugins.sh -u
```

Enable the plugin in TT-RSS: **Preferences - Plugins - Feed Advisor**.

## Settings

**Preferences - Feeds - Feed Advisor**

| Setting | Default | Description |
|---|---|---|
| Enable feed analysis | on | Enables per-article enclosure analysis via `HOOK_ARTICLE_FILTER` |
| Automatically apply recommendations | off | Applies enclosure setting corrections without manual confirmation |
| Report frequency | Twice daily | How often the scheduled feed health check runs (see below) |
| Broken feed threshold | 7 days | A feed is "broken" if it has had a persistent error for at least this many days |
| Stale feed threshold | 365 days | A feed is "stale" if it has published no new articles in at least this many days |

### Report frequency options

| Option | Interval |
|---|---|
| Hourly | 1 hour |
| Every 6 hours | 6 hours |
| Twice daily | 12 hours (default) |
| Daily | 24 hours |
| Weekly | 168 hours |

## Feed Health Monitoring

The plugin runs a health check on a timer driven by TT-RSS's housekeeping daemon
(`HOOK_HOUSE_KEEPING` fires every few minutes). The plugin records when it last ran and
compares the elapsed time against the configured interval - it only acts when enough time
has passed.

When the check runs, it queries every user's feeds and creates one consolidated report
article per user, under the **Feed Advisor** feed (see above), with the `feed-health`
label applied.

### Broken feeds

A feed is reported as broken if `last_error` is non-empty and the last successful update
was more than the configured threshold ago (default: 7 days). The report includes the
error type, last successful update date, a remediation suggestion, and a Links column
for each feed - a link into Rhesus's article view for that feed (assumes Rhesus is
reachable on its own port, `RHESUS_PORT` in `init.php`, currently 3001), plus a link to
the feed's site URL when one is known, useful for checking whether the site itself is
still alive.

### Stale feeds

A feed is reported as stale if it has no parse error but has not published a new article
within the configured threshold (default: 365 days). Feeds with URLs starting with
`share-anything:`, and the plugin's own synthetic **Feed Advisor** feed, are excluded.
Same Links column as the broken feeds table (Rhesus + site URL).

### Manual check

The **Check Feed Health Now** button in the settings pane runs the health check
immediately for the current user, regardless of when the last scheduled check ran. It
always creates a new report article - it does not replace or update existing ones. The
scheduled check timer is not affected.

## Enclosure Analysis

When enabled, the plugin hooks into every article fetch (`HOOK_ARTICLE_FILTER`) and
analyzes each feed's last 20 articles to determine whether `always_display_enclosures`
is set correctly:

| Feed content | Recommendation |
|---|---|
| Image enclosures only | Enable enclosure display |
| Image enclosures + inline `<img>` tags | Disable enclosure display (prevents duplicates) |
| Audio/video enclosures | Enable enclosure display |
| Inline images only | No change |

Advisory articles appear directly in the feed's article list. Each advisory includes
the current setting, the recommended setting, and the reason.

With **auto-apply** enabled, the correction is applied immediately and the advisory
is still created for transparency (marked "Applied").

### Bulk operations

- **Analyze All Feeds** - runs enclosure analysis across all feeds in one pass
- **Apply All Recommendations** - applies every pending enclosure advisory at once

Individual advisories can be applied or dismissed from the **Recent Advisories** table
in the settings pane.

## System Log Monitoring

Reads the Docker log file mounted at `/var/log/ttrss/docker.log` on a configurable
schedule (Hourly / Every 6 hours / Twice daily / Daily / Weekly - same options as feed
health's report frequency, default Daily) and creates an advisory article if any
exceptions, errors, or warnings are found. Like feed health, an "Only report when
there's an error" checkbox controls whether a clean ("all clear") result still creates
an article for the *automatic* scheduled check - manual "Check System Health Now" always
creates one regardless.

### Required setup

1. Create the log directory on the host:
   ```bash
   mkdir -p /tmp/ttrss-logs
   ```

2. Mount the log file into the containers in `docker-compose.yaml`:
   ```yaml
   volumes:
     - /tmp/ttrss-logs/docker.log:/var/log/ttrss/docker.log:ro
   ```

3. Add a cron job on the host to run `scripts/export-docker-logs.sh` at least as often
   as your configured check frequency, so the log file it writes is never older than one
   check interval when this plugin reads it.

The plugin skips system log monitoring silently if the log file does not exist.

## Notification Articles

**Preferences - Feeds - Feed Advisor - Notification Article**

Creates a standalone article under the **Feed Advisor** feed with a title, optional
message, and a date/time you choose:

| Field | Description |
|---|---|
| Title | Required |
| Content | Optional plain text - stored escaped, with line breaks preserved. Not interpreted as HTML |
| Insert at | Date/time the article sorts to in your article list, past or future. Entered and interpreted in **your browser's local time zone** - converted to UTC client-side before it's sent, so it doesn't depend on the server's time zone |
| Repeat | Optional. If enabled, also creates a recurring schedule (see below) in addition to the one-time article created immediately |

The key distinction is between *where the article sorts* and *when it was actually
created*:

- `date_entered` - the column TT-RSS's default headline ordering actually sorts by - is
  set to the "Insert at" value, so the article appears exactly where you placed it,
  even if that's in the past or the future relative to when you created it.
- `updated` (the article's own displayed date) is always the real creation time, i.e.
  when you clicked "Create Notification Article" - never the insertion time.

The article is created unread, with no category, under the Feed Advisor feed like any
other article this plugin generates.

### Repeating notifications

Checking "Repeat every" and picking a quantity/unit (days/weeks/months/years)
registers a recurring schedule alongside the immediately-created article - a fresh copy
of the same title/content is created every time the interval elapses, processed on
TT-RSS's normal housekeeping cycle (`HOOK_HOUSE_KEEPING`, same as feed health checks).

- The "Insert at" field only applies to the first, immediately-created article. Each
  repeat instead uses its own firing time as `date_entered` - a recurring reminder is
  meant to show up at roughly when it actually fires, not backdated to whenever the
  schedule was originally set up.
- The schedule is anchored to its own previous due time when advancing (`next_due_at =
  next_due_at + interval`, not `NOW() + interval`), so a late-running housekeeping pass
  doesn't drift the cadence forward over time.
- Active schedules are listed under "Recurring Notifications" in this same settings
  pane, each with a **Stop** button - stopping a schedule only cancels future
  occurrences, it does not delete articles already created by it.

## Article GUIDs

| Report type | GUID format |
|---|---|
| Scheduled health report | `feed-advisor:health-report:YYYY-MM-DD-HH-ii-ss-uidN` |
| Manual health report | same format, different timestamp |
| System log advisory | `feed-advisor:system-health:YYYY-MM-DD-HH-ii-ss` |
| Notification article | `feed-advisor:notification:<uniqid>-uidN` |
| Enclosure advisory | stored in plugin state, not a standalone entry |

## Troubleshooting

**No health report article appearing after pressing "Check Feed Health Now"**

The article is created immediately in the database but Rhesus does not auto-poll.
Navigate away from the preferences pane and back to an article view, or pull down
at the bottom of the article list to fetch new articles.

**"Feed Advisor" feed doesn't show up in the feed tree yet**

It's created lazily on first use, not on plugin install. Run a health check, wait for a
scheduled report, or create a notification article, and it will appear.

**Health report shows feeds belonging to other users**

Confirm the plugin is current - earlier versions did not filter by `owner_uid`. Run
`manage-plugins.sh -u` to update.

**Report frequency changed but the schedule did not shift**

The interval is measured from the last time the check actually ran. If the interval
was just shortened, the next check will fire once the new (shorter) interval elapses
from the previous run.

**No system log advisory articles appearing**

Check that the log file exists inside the container:
```bash
docker compose exec app ls -l /var/log/ttrss/docker.log
```

Check that the cron job is active:
```bash
crontab -l
```

**Reset all plugin state**

```sql
DELETE FROM ttrss_plugin_storage WHERE name = 'Af_Feed_Advisor';
```

## Testing

Unit tests cover the configurable report frequency logic: interval validation,
elapsed-time scheduling, and `save()` persistence. They run inside the app container
using the PHPUnit installation bundled in the `vendor/` directory.

```bash
docker compose exec app php /var/www/html/tt-rss/plugins.local/af_feed_advisor/vendor/bin/phpunit \
  --bootstrap /var/www/html/tt-rss/plugins.local/af_feed_advisor/tests/bootstrap.php \
  /var/www/html/tt-rss/plugins.local/af_feed_advisor/tests/
```

Run this from the `ttrss` directory where `docker-compose.yaml` lives.

The `vendor/` directory was seeded by copying it from `af_enhance_images`, which uses
the same `phpunit/phpunit ^9.5` requirement. If PHPUnit needs to be updated or
reinstalled, install Composer inside the container and run `composer install` in the
plugin directory.

### What is tested

| Group | Method | Cases |
|---|---|---|
| Interval validation | `get_report_interval_hours()` | All 5 valid values pass through; 11 invalid values fall back to default 12 |
| Scheduling gate | `should_check_health()` | Returns true when interval elapsed; returns false when not yet elapsed; handles "never run" state |
| Settings persistence | `save()` | All 4 non-default valid intervals stored correctly; 4 invalid inputs store default 12 |

The tests use PHPUnit mocks for `PluginHost` and a `Db` stub that throws, causing
`get_plugin_setting()` to fall through to its default - no database connection required.

## License

GPL-2.0-or-later
