# AGENTS.md — Ultimate Update Server Plugin

## Project Overview

WordPress plugin that creates an update server for distributing Ultimate Multisite plugins and addons via WooCommerce downloadable products. Handles update checks, telemetry collection, site discovery, Composer repository, Stripe/PayPal analytics, and release notifications. Runs on the marketplace site (multisiteultimate.com).

## Build Commands

```bash
composer install                    # Install PHP dependencies
# No npm build step — no compiled frontend assets
```

## Project Structure

```
ultimate-update-server-plugin/
├── wp-update-server-plugin.php     # Plugin entry point
├── inc/
│   ├── class-update-server.php         # Core update server logic
│   ├── class-request-endpoint.php      # Update request handling
│   ├── class-product-icon.php          # Product icon management
│   ├── class-store-api.php             # Store REST API
│   ├── class-telemetry-table.php       # Telemetry DB table
│   ├── class-telemetry-receiver.php    # Telemetry data ingestion
│   ├── class-telemetry-admin.php       # Telemetry dashboard
│   ├── class-passive-installs-table.php     # Install tracking table
│   ├── class-passive-install-tracker.php    # Passive install detection
│   ├── class-site-discovery-table.php       # Site health DB table
│   ├── class-site-discovery-scraper.php     # Background site scraper
│   ├── class-site-discovery-admin.php       # Discovery dashboard
│   ├── class-composer-token-table.php       # Composer auth tokens
│   ├── class-composer-token.php             # Token management
│   ├── class-product-versions.php           # Version tracking
│   ├── class-composer-repository.php        # Composer packages.json endpoint
│   ├── class-downloads-page.php             # Customer downloads page
│   ├── class-changelog-manager.php          # Changelog tracking
│   ├── class-release-notifier.php           # Email notifications (WooCommerce)
│   ├── class-stripe-analytics*.php          # Stripe Connect analytics
│   ├── class-paypal-*.php                   # PayPal Connect + transaction sync
│   └── ...
├── assets/                         # Admin CSS/JS
├── templates/                      # Admin page templates
├── vendor/                         # Composer dependencies
├── composer.json
└── README.md
```

## Code Style & Conventions

- **PHP version**: >= 7.4 (inferred)
- **Namespace**: `WP_Update_Server_Plugin\` for all classes in `inc/`
- **File naming**: `class-{name}.php` in `inc/`
- **No autoloader**: All files manually `require_once`'d in main plugin file
- **No PHPCS config** — follow WordPress Coding Standards
- **Relies on**: `yahnis-elsts/wp-update-server` library

## Key Patterns

- Classes instantiated at load time (global variables in main plugin file)
- WooCommerce-dependent classes loaded inside `woocommerce_loaded` hook
- Custom database tables for telemetry, installs, site discovery, tokens, analytics
- Site discovery scraper: daily WP-Cron, processes 20 domains/batch, respects robots.txt
- Composer repository endpoint serves `packages.json` for `composer require`
- Stripe and PayPal analytics track payment provider data

## Important Notes

- **Requires WooCommerce** and WP OAuth Server
- Runs only on the marketplace server, not on customer sites
- Telemetry is opt-in from Ultimate Multisite installations
- Site discovery respects `robots.txt` (User-Agent: `UltimateMultisiteBot`)
- Product name is "Ultimate Multisite" in user-facing text
