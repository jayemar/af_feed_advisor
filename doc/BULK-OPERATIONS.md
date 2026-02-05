# Feed Advisor Bulk Operations Guide

## Overview

The Feed Advisor plugin now includes bulk operations for analyzing and configuring all feeds at once, providing a UI-driven alternative to the `smart-enclosure-settings.sql` script.

## Features

### 1. Analyze All Feeds

**Location:** Preferences → Feeds → Feed Advisor → "Analyze All Feeds" button

**What it does:**
- Scans all feeds in your database
- Identifies misconfigured feeds:
  - Feeds with both inline images and enclosures (should disable enclosures)
  - Feeds with only image enclosures (should enable enclosures)
  - Feeds with audio/video enclosures (should enable enclosures)
- Creates advisory articles for each misconfigured feed
- Respects 30-day cooldown (won't re-create recent advisories)

**When to use:**
- Initial setup of feed advisor
- Periodic cleanup (monthly/quarterly)
- After importing new feeds
- To verify current configuration state

### 2. Apply All Recommendations

**Location:** Preferences → Feeds → Feed Advisor → "Apply All Recommendations" button

**What it does:**
- Applies all pending recommendations at once
- Updates `always_display_enclosures` setting for each feed
- Marks all advisories as "Applied"
- Shows summary: "Applied X disables and Y enables"

**When to use:**
- After reviewing bulk analysis results
- When you trust the recommendations
- For quick cleanup of all misconfigured feeds

**Safety:**
- Prompts for confirmation before applying
- Only applies "Pending" advisories (not dismissed ones)
- Reversible (can manually change feed settings back)

### 3. Individual Apply

**Location:** Preferences → Feeds → Feed Advisor → Recent Advisories table → "Apply" button

**What it does:**
- Applies recommendation for a single feed
- Updates database setting
- Marks advisory as "Applied"

**When to use:**
- Want to review each recommendation individually
- Only apply certain recommendations
- Test before bulk applying

### 4. Auto-Apply Mode

**Location:** Preferences → Feeds → Feed Advisor → "Automatically apply recommendations" checkbox

**What it does:**
- Automatically applies recommendations during normal feed updates
- Eliminates need for manual apply
- Still creates advisory articles for transparency
- Advisories immediately marked as "Applied"

**When to use:**
- Ongoing maintenance (set it and forget it)
- Trust the analysis logic
- Want hands-free operation

**When NOT to use:**
- Initial setup (want to review first)
- Feeds with unusual content patterns
- Prefer manual control

### 5. Dismiss Advisory

**Location:** Preferences → Feeds → Feed Advisor → Recent Advisories table → "Dismiss" button

**What it does:**
- Marks advisory as dismissed
- Removes from pending list
- Won't be included in bulk apply
- Won't be re-created for 30 days

**When to use:**
- Disagree with recommendation
- Feed has special requirements
- False positive detection

## Workflows

### Initial Setup (Recommended)

1. Enable Feed Advisor: Check "Enable feed analysis"
2. Run bulk analysis: Click "Analyze All Feeds"
3. Review advisories: Check Recent Advisories table
4. Apply selectively: Click "Apply" on trusted feeds
5. Apply remaining: Click "Apply All Recommendations" when satisfied
6. Enable auto-apply: Check "Automatically apply recommendations"

### Ongoing Maintenance

**Option A: Auto-Apply (Hands-Free)**
1. Enable "Automatically apply recommendations"
2. Feed updates automatically fix issues
3. Periodically review Recent Advisories

**Option B: Manual Review**
1. Leave auto-apply disabled
2. Run "Analyze All Feeds" monthly
3. Review and apply recommendations individually

### Quick Cleanup

1. Click "Analyze All Feeds"
2. Click "Apply All Recommendations"
3. Confirm and done

## Comparison with smart-enclosure-settings.sql

| Feature | SQL Script | Plugin |
|---------|-----------|--------|
| **Analysis** | Scans all feeds | Scans all feeds |
| **Logic** | Identical | Identical |
| **Interface** | Terminal commands | Web UI buttons |
| **Apply** | Automatic | Manual or auto |
| **Reversible** | No (commits immediately) | Yes (can dismiss) |
| **Granularity** | All-or-nothing | Individual or bulk |
| **Tracking** | No history | Advisory history |
| **Scheduling** | Manual cron | Auto-apply mode |

**Recommendation:** Use plugin for normal operation, keep SQL script for emergency/bulk operations.

## Advisory Status Meanings

- **Pending:** Not yet applied or dismissed, appears in bulk operations
- **Applied:** Recommendation has been applied to feed setting
- **Dismissed:** User dismissed advisory, won't be applied

## Database Verification

After applying recommendations, verify in database:

```sql
-- Check specific feed
SELECT id, title, always_display_enclosures
FROM ttrss_feeds
WHERE id = 358;

-- Check all modified feeds
SELECT f.id, f.title, f.always_display_enclosures,
       (SELECT COUNT(*) FROM ttrss_enclosures enc
        JOIN ttrss_user_entries ue ON enc.post_id = ue.ref_id
        WHERE ue.feed_id = f.id) as enclosure_count
FROM ttrss_feeds f
WHERE f.id IN (SELECT feed_id FROM ttrss_plugin_storage WHERE name = 'Af_Feed_Advisor');
```

## Troubleshooting

### "No advisories created"
- Check that feeds actually have misconfigurations
- Verify 30-day cooldown hasn't blocked re-creation
- Check plugin is enabled

### "Apply failed"
- Check feed still exists
- Verify database permissions
- Check browser console for errors

### "Too many advisories"
- Review and dismiss false positives
- Use auto-apply to handle automatically
- Adjust feed settings manually if needed

### "Auto-apply not working"
- Verify "Automatically apply recommendations" is checked
- Check feed updates are running
- Verify plugin is enabled

## Best Practices

1. **Initial Setup:** Run bulk analysis, review carefully, apply selectively
2. **Ongoing:** Enable auto-apply for hands-free maintenance
3. **Periodic Review:** Check Recent Advisories monthly
4. **Dismiss Wisely:** Only dismiss if you disagree with recommendation
5. **Test First:** Try individual apply before bulk apply

## Performance Notes

- Bulk analysis may take 10-30 seconds on large databases (100+ feeds)
- Uses database indexes efficiently
- Same performance as SQL script
- Auto-apply has minimal overhead (only when needed)

## Safety Notes

- All operations are reversible
- Bulk apply prompts for confirmation
- Dismissed advisories won't be auto-applied
- Can manually change feed settings anytime
- Advisory history preserved in plugin storage

## Next Steps

1. Enable Feed Advisor
2. Run "Analyze All Feeds"
3. Review the results in Recent Advisories table
4. Apply recommendations (individually or bulk)
5. Enable auto-apply for ongoing maintenance

For detailed testing, see `TEST-PLAN.md`.
For implementation details, see `IMPLEMENTATION-SUMMARY.md`.
