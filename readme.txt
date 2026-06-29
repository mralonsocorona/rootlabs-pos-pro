=== RootLabs POS for WooCommerce ===
Contributors: blacknovamx
Tags: woocommerce, pos, point of sale, retail, cash register
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open source point of sale foundation for WooCommerce stores.

== Description ==

RootLabs POS for WooCommerce is an open source point of sale plugin for WooCommerce.

It provides a browser-based POS interface, cash session handling, cash cuts, payment methods, refunds, parked carts, customer lookup, product search, audit logs, and WooCommerce order integration.

This is an early public release. Review the documentation before using it in production.

== External services ==

This plugin can optionally connect to the Telegram Bot API to send POS notifications configured by the store administrator.

The Telegram integration is disabled by default. The plugin only sends data to Telegram when the administrator enables Telegram notifications and saves a bot token and chat ID.

When enabled, the plugin sends notification message text related to POS events, such as sales or cash register events, to the configured Telegram chat. The message may include store operational information such as order identifiers, totals, payment method labels, cash session information, and timestamps. The plugin sends this data when the corresponding notification event occurs or when the administrator uses the test connection action.

This service is provided by Telegram. Terms of Service: https://telegram.org/tos Privacy Policy: https://telegram.org/privacy

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin folder or install the release ZIP.
3. Activate RootLabs POS for WooCommerce.
4. Open the RootLabs POS admin menu.
5. Configure branches, registers, employees, payment methods, and ticket settings.
6. Open the POS route and test the full sales flow.

== Frequently Asked Questions ==

= Does this replace WooCommerce? =

No. It works with WooCommerce and creates WooCommerce orders for POS sales.

= Is this a SaaS product? =

No. This release is a self-hosted WordPress plugin.

= Does uninstall delete store data? =

No. The uninstall routine is intentionally non-destructive to prevent accidental business data loss.

== Screenshots ==

1. POS interface.
2. Cash session flow.
3. Admin settings.

== Changelog ==

= 0.1.4 =
* Added multi-store branch/register/employee context propagation across POS sales, WooCommerce orders, cash movements, cash cuts, refunds, dashboard filters, and tickets.
* Added WooCommerce POS order metadata for branch, register, session, and employee context.
* Added database migration 1.12 for refund branch/register/employee context with indexes and backfill.
* Fixed refund attribution so refunds prefer the original sale branch/register/employee instead of the current active session.
* Added server-side employee branch enforcement when opening POS register sessions.
* Preserved backward compatibility for single-store installs and employees without branch assignment.

= 0.1.3 =
* Fix POS coupon totals so coupons that exclude sale items do not discount products already on sale.


= 0.1.2 =
* Addresses WordPress.org prereview items for plugin metadata, internationalization, external service disclosure, REST permissions, and asset loading.

= 0.1.1 =
* Public release package for WordPress.org submission.
* Includes POS interface, cash sessions, payment methods, refunds, parked carts, customer lookup, product search, audit logs, and WooCommerce order integration.

= 0.1.0 =
* Initial public open source release.
