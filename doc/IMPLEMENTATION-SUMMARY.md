# Feed Advisor Enhancement Implementation Summary

## Changes Made

The af_feed_advisor plugin has been enhanced with bulk analysis and auto-apply features while maintaining separation from af_filter_enclosures.

### Files Modified
- `/home/jayemar/projects/af_feed_advisor/init.php` (443 → 886 lines)

### Files Created
- `/home/jayemar/projects/af_feed_advisor/TEST-PLAN.md` - Comprehensive test plan
- `/home/jayemar/projects/af_feed_advisor/IMPLEMENTATION-SUMMARY.md` - This file

## New Features

### 1. Bulk Analysis
**Method:** `analyze_all_feeds()`
- Ports SQL logic from `smart-enclosure-settings.sql`
- Analyzes ALL feeds in database
- Returns categorized results: to_disable, to_enable_images, to_enable_media
- Handles empty content_type with URL extension detection

**UI:** "Analyze All Feeds" button in Preferences → Feeds → Feed Advisor

### 2. Auto-Apply Mode
**Setting:** `auto_apply` (default: false)
- When enabled, recommendations are automatically applied during feed updates
- Advisory articles still created for transparency
- Advisories immediately marked as "Applied"

**UI:** "Automatically apply recommendations" checkbox in settings

### 3. Bulk Apply
**Method:** `bulk_apply()`
- Applies all pending recommendations in one operation
- Updates database settings for all misconfigured feeds
- Returns count of disabled/enabled feeds

**UI:** "Apply All Recommendations" button

### 4. Individual Apply
**Method:** `applyOne()` (AJAX handler)
- Applies single recommendation from advisory table
- Updates feed setting in database
- Marks advisory as "Applied"

**UI:** "Apply" button per advisory in Recent Advisories table

### 5. Dismiss Advisory
**Method:** `dismissOne()` (AJAX handler)
- Marks advisory as dismissed
- Removes from pending list
- Won't be included in bulk operations

**UI:** "Dismiss" button per advisory in Recent Advisories table

## Enhanced State Tracking

Plugin state now includes:
```json
{
  "enabled": true,
  "auto_apply": false,
  "advised": {
    "358": {
      "issue": "enclosures_disabled",
      "timestamp": 1738000000,
      "dismissed": false,
      "applied": false,
      "recommendation": true
    }
  }
}
```

**New fields:**
- `applied` - Whether recommendation has been applied
- `recommendation` - The recommended setting (true/false)
- `applied_timestamp` - When recommendation was applied

## Code Architecture

### New Constants
```php
const CATEGORY_DISABLE = 'disable';
const CATEGORY_ENABLE_IMAGES = 'enable_images';
const CATEGORY_ENABLE_MEDIA = 'enable_media';
```

### New Private Methods
- `is_auto_apply_enabled()` - Check auto-apply setting
- `analyze_all_feeds()` - Bulk analysis matching SQL script
- `apply_recommendation($feed_id, $new_setting, $reason)` - Apply single recommendation

### New Public Methods (AJAX handlers)
- `apply_all_recommendations()` - Apply all pending
- `bulk_analyze()` - Create advisories for all misconfigured feeds
- `bulk_apply()` - Directly apply all recommendations
- `bulkAnalyze()` - AJAX wrapper for bulk_analyze()
- `bulkApplyRecommendations()` - AJAX wrapper for bulk_apply()
- `applyOne()` - AJAX handler for individual apply
- `dismissOne()` - AJAX handler for dismiss

### Enhanced Methods
- `record_advisory()` - Now accepts `$recommendation` parameter
- `create_advisory()` - Checks auto-apply mode before creating
- `hook_prefs_tab()` - Enhanced UI with bulk operations and actions

## UI Enhancements

### Settings Section
```
☑ Enable feed analysis
☐ Automatically apply recommendations (skip creating advisories)
```

### Bulk Operations Section
```
[Analyze All Feeds Now]    [Apply All Recommendations]
```

### Recent Advisories Table
```
Feed ID | Issue                | Date       | Status  | Actions
--------|---------------------|------------|---------|----------
358     | enclosures_disabled | 2026-01-27 | Pending | [Apply] [Dismiss]
421     | enclosures_enabled  | 2026-01-27 | Applied |
```

**Status values:**
- "Pending" - Not yet applied or dismissed
- "Applied" - Recommendation has been applied
- "Dismissed" - User dismissed the advisory

## JavaScript Functions

Added to handle AJAX operations:
- `Plugins.Af_Feed_Advisor.bulkAnalyze()`
- `Plugins.Af_Feed_Advisor.bulkApply()`
- `Plugins.Af_Feed_Advisor.applyOne(feedId)`
- `Plugins.Af_Feed_Advisor.dismissOne(feedId)`

All functions:
- Show loading spinner during operation
- Display success/error messages
- Reload page to show updated state

## Workflow Options

### Option A: Advisory Mode (default, current)
1. Feed updates → Analysis runs
2. Issue detected → Advisory article created
3. User reads advisory → Clicks "Apply" or runs SQL manually

### Option B: Auto-Apply Mode (new)
1. Enable "Automatically apply recommendations"
2. Feed updates → Analysis runs
3. Issue detected → Setting automatically updated
4. Advisory article created for transparency (marked as "Applied")

### Option C: Bulk Analysis Mode (new)
1. User clicks "Analyze All Feeds"
2. All feeds analyzed using SQL logic
3. Advisories created for all misconfigured feeds
4. User reviews and clicks "Apply All" or individual "Apply"

### Option D: Direct Bulk Apply (new)
1. User clicks "Apply All Recommendations"
2. All pending recommendations applied directly
3. Database updated for all feeds
4. Advisories marked as "Applied"

## Compatibility with smart-enclosure-settings.sql

The `analyze_all_feeds()` method uses identical SQL queries to the script:
- Same categorization logic (disable, enable_images, enable_media)
- Same empty content_type handling (URL extension detection)
- Same feed identification queries

**Difference:** Plugin creates advisories + applies; script directly updates database.

## Migration Path

### Phase 1: Testing (Current)
- Test bulk analysis matches SQL script output
- Verify apply functionality works correctly
- Test auto-apply mode with sample feeds

### Phase 2: Production Use
- Enable for normal feed updates
- Use bulk operations for initial cleanup
- Monitor for issues

### Phase 3: Deprecation (Future)
- Add notice to smart-enclosure-settings.sql
- Document plugin as preferred method
- Keep script for backwards compatibility

## Advantages Over SQL Script

1. **User-Friendly:** Click buttons instead of Docker commands
2. **Reversible:** Can dismiss advisories without applying
3. **Auditable:** State tracked in plugin storage
4. **Granular:** Can apply individually or in bulk
5. **Automated:** Auto-apply mode for ongoing maintenance
6. **Integrated:** No context switching to terminal

## Performance Considerations

### Bulk Analysis
- Queries entire database (all feeds, all entries, all enclosures)
- May take 10-30 seconds on large databases (100+ feeds)
- Uses same indexes as SQL script
- Consider adding LIMIT to queries if needed

### Auto-Apply Mode
- Minimal overhead (single UPDATE per feed)
- Only runs when issue detected
- No performance impact on normal operation

### State Storage
- JSON stored in ttrss_plugin_storage
- Grows with feed count
- Consider cleanup of old advisories (30+ days)

## Security Considerations

- All AJAX handlers validate feed_id as integer
- No SQL injection vectors (uses PDO prepared statements)
- User must have Preferences access (built-in TT-RSS permission check)
- No external data sources

## Future Enhancements (Potential)

1. **Bulk Dismiss:** Dismiss all advisories at once
2. **Scheduled Analysis:** Cron job to run bulk analysis weekly
3. **Email Notifications:** Alert on new advisories
4. **Advisory History:** View all advisories, not just active ones
5. **Export/Import:** Share advisory state between instances
6. **Dashboard Widget:** Show pending advisories count
7. **Feed Details Integration:** Show advisory in feed edit screen

## Known Limitations

1. **30-Day Cooldown:** Won't re-create advisory for 30 days (by design)
2. **No Undo:** Applied recommendations must be manually reverted
3. **Page Reload:** UI refreshes after operations (could use live updates)
4. **No Dry-Run:** Bulk apply directly modifies database (could add preview)

## Breaking Changes

None. This is a backwards-compatible enhancement.

Existing functionality:
- Still creates advisories during feed updates
- Still tracks advisory history
- Still respects 30-day cooldown

New functionality is opt-in via:
- Auto-apply checkbox (default: off)
- Bulk operation buttons (manual action required)

## Documentation Updates Needed

1. **README.md:** Add section on bulk operations
2. **smart-enclosure-settings.sql:** Add deprecation notice
3. **CLAUDE.md (project):** Update plugin capabilities
4. **User Guide:** Document UI changes and workflows

## Testing Checklist

See `TEST-PLAN.md` for comprehensive test scenarios.

## Conclusion

The af_feed_advisor plugin now provides a complete UI-driven alternative to smart-enclosure-settings.sql while maintaining clean separation from af_filter_enclosures. Users can:
- Analyze all feeds at once
- Apply recommendations individually or in bulk
- Enable auto-apply for hands-free operation
- Dismiss unwanted advisories

The implementation follows the plan's recommendation to enhance rather than combine plugins, maintaining the Single Responsibility Principle while improving user experience.
