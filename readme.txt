=== ZeusWeb Multishop ===
Contributors: zeusweb
Tags: woocommerce, multishop, cd-keys, elementor
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multishop system for WooCommerce with Primary/Secondary architecture, Consumer/Business segments, CD key allocation, and Elementor templates.

== Description ==

This plugin enables a central Primary shop to control catalog, pricing (including business prices), Elementor templates, and CD keys across multiple Secondary shops. It supports Consumer (`/lakossagi`) and Business (`/uzleti`) segments, automatic key allocation on payment, and backorder handling.

== Changelog ==

= 0.2.01 =
* Fix: Apply business price to mirrored orders on primary when segment=business
* Fix: Trigger standard Woo emails for mirrored orders when not in custom-only mode

= 0.2.02 =
* Change: Gate customer emails (primary and mirrored) until keys are present

= 0.2.03 =
* Change: Require keys for all non-bundle items before sending emails
* Fix: Use remote order number on primary for mirrored orders

= 0.2.04 =
* Feature: Setting for unique order identifier prefix (`zw_ms_site_code`)
* Improvement: Secondary includes `site_code` in mirror payload; primary uses it for order numbers

= 0.2.05 =
* Fix: Suppress WooCommerce customer emails until all keys are present
* Fix: Validate and store mirrored order segment (consumer/business) on primary

= 0.2.06 =
* Fix: Persist consumer/business segment on primary-origin orders

= 0.2.07 =
* Change: Remove Multishop - Orders admin menu entry
* Feature: Allow admin order search by customer IP address

= 0.2.08 =
* Fix: Broaden IP search to cover alternative meta key

= 0.2.09 =
* Feature: Add admin URL param IP filter for legacy and HPOS orders

= 0.2.10 =
* Feature: Add IP filter input UI on Orders (legacy + HPOS)

= 0.2.11 =
* Feature: Blacklist admin page; block checkout by IP/email

= 0.1.0 =
* Initial scaffolding and bootstrap.


