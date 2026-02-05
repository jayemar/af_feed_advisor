# Feed Advisor Enhancement Test Plan

## Overview
This test plan verifies the new bulk analysis and auto-apply features added to af_feed_advisor.

## Features to Test

### 1. Bulk Analysis
**Location:** Preferences → Feeds → Feed Advisor → "Analyze All Feeds" button

**Test Steps:**
1. Click "Analyze All Feeds" button
2. Wait for analysis to complete
3. Verify advisories are created in the "Recent Advisories" table
4. Verify advisory count matches feeds with misconfigurations

**Expected Results:**
- Advisories created for feeds with:
  - Image enclosures + inline images (should disable)
  - Image enclosures only (should enable)
  - Audio/video enclosures (should enable)
- No duplicate advisories within 30-day window

### 2. Individual Apply
**Location:** Preferences → Feeds → Feed Advisor → Recent Advisories table

**Test Steps:**
1. Find an advisory in "Pending" status
2. Click "Apply" button for that advisory
3. Verify database updated

**Expected Results:**
- Advisory status changes to "Applied"
- Database setting updated: `SELECT always_display_enclosures FROM ttrss_feeds WHERE id = <feed_id>;`
- Advisory recorded in plugin state with `applied: true`

### 3. Bulk Apply
**Location:** Preferences → Feeds → Feed Advisor → "Apply All Recommendations" button

**Test Steps:**
1. Create multiple advisories (via "Analyze All Feeds")
2. Click "Apply All Recommendations" button
3. Confirm when prompted
4. Verify all pending advisories are applied

**Expected Results:**
- All "Pending" advisories change to "Applied"
- All corresponding feed settings updated in database
- Success message shows count: "Applied X disables and Y enables"

### 4. Auto-Apply Mode
**Location:** Preferences → Feeds → Feed Advisor → "Automatically apply recommendations" checkbox

**Test Steps:**
1. Enable "Automatically apply recommendations"
2. Click "Save"
3. Trigger feed update for a misconfigured feed
4. Verify setting automatically corrected

**Expected Results:**
- Feed setting updated automatically during analysis
- Advisory still created for transparency
- Advisory marked as "Applied" immediately

### 5. Dismiss Advisory
**Location:** Preferences → Feeds → Feed Advisor → Recent Advisories table

**Test Steps:**
1. Find an advisory in "Pending" status
2. Click "Dismiss" button
3. Verify status changes

**Expected Results:**
- Advisory status changes to "Dismissed"
- Advisory removed from pending list
- No longer appears in bulk apply operations

## Database Verification

### Check Feed Settings
```sql
-- Before bulk apply
SELECT id, title, always_display_enclosures
FROM ttrss_feeds
WHERE id IN (<feed_ids_from_advisories>);

-- After bulk apply
SELECT id, title, always_display_enclosures
FROM ttrss_feeds
WHERE id IN (<feed_ids_from_advisories>);
-- Should show updated settings
```

### Check Plugin State
```sql
SELECT content
FROM ttrss_plugin_storage
WHERE name = 'Af_Feed_Advisor';
-- Should show JSON with advised feeds and their status
```

## Comparison with smart-enclosure-settings.sql

**Test Steps:**
1. Run smart-enclosure-settings.sql (dry-run mode)
2. Note which feeds it categorizes for disable/enable
3. Run af_feed_advisor bulk analysis
4. Compare results

**Expected Results:**
- Both should produce identical categorizations
- Same feeds identified for disable
- Same feeds identified for enable (images)
- Same feeds identified for enable (media)

## Edge Cases

### Empty content_type
**Test:** Feed with enclosures that have empty content_type but image URL extensions

**Expected:** Detected as image enclosures via URL pattern matching (.jpg, .png, etc.)

### Audio/Video Detection
**Test:** Podcast feed with audio/video enclosures

**Expected:** Categorized as enable_media, enclosures enabled

### Feed Switches Content Type
**Test:** Feed that previously had inline images now provides enclosures

**Expected:**
- New advisory created after 30-day cooldown
- Old advisory not re-created within 30 days

### Already Configured Feeds
**Test:** Feed already has correct setting

**Expected:** No advisory created (no misconfiguration detected)

## Performance Testing

### Large Feed Set
**Test:** Run bulk analysis on database with 100+ feeds

**Expected:**
- Completes within reasonable time (< 30 seconds)
- No timeout errors
- All feeds analyzed correctly

## UI/UX Verification

### Button Responsiveness
- All buttons show loading spinner during operation
- Success/error messages displayed clearly
- Page refreshes after operations to show updated state

### Table Display
- Recent Advisories table sorts correctly
- Status labels clear: "Pending", "Applied", "Dismissed"
- Feed IDs clickable/useful for reference

## Migration from smart-enclosure-settings.sql

### Documentation Update
**Task:** Update smart-enclosure-settings.sql with deprecation notice

**Add to top of file:**
```sql
-- DEPRECATION NOTICE:
-- This functionality is now available in the af_feed_advisor plugin UI.
-- Preferences → Feeds → Feed Advisor → "Analyze All Feeds" / "Apply All Recommendations"
--
-- This script is kept for backwards compatibility and manual operation.
```

## Rollback Plan

If issues are found:
1. Disable auto-apply mode
2. Manually review advisories before applying
3. Use smart-enclosure-settings.sql as fallback
4. Report issues for fixing

## Success Criteria

- [x] Bulk analysis produces same results as SQL script
- [x] Individual apply updates database correctly
- [x] Bulk apply updates all pending advisories
- [x] Auto-apply mode works during feed updates
- [x] Dismiss functionality removes from pending list
- [x] UI is responsive and provides clear feedback
- [x] No duplicate advisories created
- [x] Edge cases handled correctly
