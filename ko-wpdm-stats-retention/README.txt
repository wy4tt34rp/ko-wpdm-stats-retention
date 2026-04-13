# KO WPDM Stats Retention (90 Days)

**MU-plugin to automatically purge old WP Download Manager download history records.**

This plugin keeps **WP Download Manager → History and Stats → Download History** trimmed to the **last 90 days**, preventing uncontrolled database growth on high-traffic sites.

---

## What this plugin does

- Targets WP Download Manager’s **Download History** table  
  `{$wpdb->prefix}ahm_download_stats`
- Uses the correct **Unix timestamp** column (`timestamp`)
- Deletes records **older than 90 days**
- Runs **daily** via WP-Cron
- Deletes in **safe batches** to avoid table locks
- Writes a lightweight log for visibility

---

## Why this is needed

WP Download Manager does **not** provide a built-in way to:
- limit download history retention by time
- auto-purge old records

On busy sites, the `ahm_download_stats` table can grow into the **hundreds of MBs** (or millions of rows), impacting:
- database size
- backups
- admin performance

This MU-plugin enforces a rolling retention window automatically.

---

## Installation

1. Create the MU-plugins directory (if it doesn’t exist):