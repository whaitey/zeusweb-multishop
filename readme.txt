=== ZeusWeb Multishop ===
Contributors: zeusweb
Tags: woocommerce, multishop, cd-keys, elementor
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.02
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

= 0.1.0 =
* Initial scaffolding and bootstrap.


