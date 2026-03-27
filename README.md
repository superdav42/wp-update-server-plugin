## WP Update Server Plugin

This project creates an update server to enable automatic updates for WordPress plugins which are Woocommerce downloadable products.

## Requires
* Woocommerce
* [WP OAuth Server](https://wordpress.org/plugins/oauth2-provider/)

## Features

### Telemetry Dashboard
Opt-in telemetry from Ultimate Multisite installations. Tracks PHP/WP/plugin versions, network types, active addons, and error reports.

### Site Discovery (Network Health)
Background scraper that discovers network health signals from domains found via passive install tracking. Runs daily via WP-Cron and processes up to 20 domains per batch.

**What it detects:**
- Whether the site is live (HTTP 2xx)
- Whether it is a production domain (not staging/dev/local)
- SSL/HTTPS availability
- Presence of a checkout or registration page (UM shortcodes/blocks)
- Estimated subsite count (subdirectory path probing + HTML signals)
- Network type: subdomain vs subdirectory
- Ultimate Multisite version (from asset URLs or meta tags)

**Health score (0–100):**

| Signal | Points |
|---|---|
| Site is live | +20 |
| Production domain | +15 |
| Has SSL | +10 |
| Has checkout page | +15 |
| Detected subsites > 5 | +20 |
| Detected subsites > 50 | +10 |
| UM version fingerprinted | +10 |

**Privacy and ethics:**
- Only scrapes publicly accessible pages (homepage, `/register/`)
- Respects `robots.txt` — skips sites that disallow `UltimateMultisiteBot`
- User-Agent: `UltimateMultisiteBot/1.0 (+https://ultimatemultisite.com/bot)`
- 10-second timeout per request; unresponsive sites are marked `failed` and retried next run
- Stores only aggregate signals, not page content

**Dashboard answers:**
- How many production networks have 10+ subsites?
- How many networks have a checkout page (are selling to customers)?
- Distribution of health scores across all discovered domains
