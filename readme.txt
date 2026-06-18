=== Tagbridge – Deep Integration for PostHog ===
Contributors: mcgreat
Tags: analytics, posthog, tracking, events, statistics
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent PostHog integration for WordPress. Connect your project and send pageviews and events to PostHog. Not affiliated with PostHog.

== Description ==

Tagbridge adds PostHog to a WordPress site without writing code. Enter your PostHog project token, choose your region, and it loads PostHog with the settings you pick.

Tagbridge is an independent project. It is not affiliated with, endorsed by, or sponsored by PostHog. "PostHog" is a trademark of its respective owner and is used here only to describe what this plugin connects to.

= Features =

* Connect to PostHog Cloud (US or EU) or a custom host (self-hosted or a reverse proxy). The project token is validated with a live request before it is saved.
* Browser capture via posthog-js, loaded from the configured host. Each capability is a separate toggle: pageviews, autocapture, heatmaps, session replay, and JavaScript error tracking.
* Session replay masks all form inputs and, by default, text inside known WooCommerce / CheckoutWC containers that render a name, email, or address.
* Person profile mode (identified-only or everyone) and a cookieless mode (in-memory persistence; no PostHog cookie).
* Identity: logged-in users are tied to one PostHog person via a stable hashed identifier (not the raw user ID). Email, name, and role are individually optional.
* Server-side events via posthog-php, each with an on/off toggle behind a master switch. Sent from the server, so an ad blocker does not drop them; a failed or slow request never affects page output.
* Server-side PHP error tracking (opt-in): posthog-php installs chained exception/error handlers; captured errors carry the visitor's distinct ID.
* WooCommerce (when active): the full commerce funnel as server-side events, plus client-side variant selections. Product, cart, and purchase events carry the SKU, categories, and attributes, and each completed order emits one purchase event per line item, so sales break down by SKU and attribute.

= Events sent to PostHog =

Client-side (posthog-js): $pageview, $autocapture, $heatmap, $exception, session-replay snapshots, $identify, and product_variant_selected (WooCommerce variation picks).

Server-side (posthog-php), each individually toggleable:

* user_logged_in, user_registered, user_logged_out
* product_viewed, product_list_viewed, products_searched
* product_added_to_cart, product_removed_from_cart, cart_viewed
* coupon_applied, coupon_removed
* checkout_viewed, checkout_started, order_completed, product_purchased, payment_failed, order_refunded, order_cancelled
* product_review_submitted

WooCommerce events are registered only when WooCommerce is active. Order events include value and currency and resolve to the same person as the checkout session.

= On the roadmap =

Planned for future releases:

* A no-flicker feature-flag block and shortcode evaluated on the server.
* Optional starter dashboards created in your PostHog project.
* WordPress Consent API support.

== Installation ==

1. Install and activate the plugin.
2. In the WordPress admin, go to Settings, then Tagbridge.
3. Paste your PostHog project token and choose your region (US, EU, or self-hosted / reverse proxy).
4. Save. Tagbridge checks the key with PostHog before saving and tells you if it worked.
5. Choose what to track, then save again. PostHog will start receiving data from your site.

You can find your project token in PostHog under Settings, General, Project token. It is a public key and is safe to use in the browser.

== Frequently Asked Questions ==

= Is this an official PostHog plugin? =

No. Tagbridge is an independent project and is not affiliated with or endorsed by PostHog.

= Do I need a PostHog account? =

Yes. You need a PostHog project and its project token. PostHog offers a free tier. See https://posthog.com.

= Does it work with self-hosted PostHog or a reverse proxy? =

Yes. Choose "Self-hosted or reverse proxy" and enter your host URL. PostHog loads from that host.

We recommend PostHog's free managed reverse proxy: it routes events through a subdomain of your own domain so ad blockers do not drop them (typically 10-30% more events). Set it up at https://posthog.com/docs/advanced/proxy/managed-reverse-proxy and paste the subdomain as your host.

= Will it set cookies? =

By default PostHog uses its normal storage. Turn on "Privacy-first cookieless mode" to keep visitor state in memory only, so no PostHog cookie is set.

== Screenshots ==

1. Connect your PostHog project: paste the project token and choose your region. The key is checked with PostHog before it is saved.
2. Once connected, choose what to track with plain-language toggles: pageviews, autocapture, heatmaps, error tracking, session recording, person profiles, and privacy-first cookieless mode.

== Reference ==

= WordPress actions the plugin hooks =

* wp_head: print the posthog-js snippet
* wp_enqueue_scripts: load the WooCommerce variant-tracking script on product pages
* wp_login, user_register, wp_logout: the user_logged_in / user_registered / user_logged_out events (and identity on login/register)
* template_redirect: product_viewed, product_list_viewed, products_searched, cart_viewed, checkout_viewed
* woocommerce_add_to_cart, woocommerce_cart_item_removed
* woocommerce_applied_coupon, woocommerce_removed_coupon
* comment_post: product_review_submitted
* woocommerce_checkout_order_processed, woocommerce_order_status_completed
* woocommerce_order_status_failed, woocommerce_order_refunded, woocommerce_order_status_cancelled
* shutdown: flush queued server-side events
* admin_menu, admin_init, admin_enqueue_scripts, admin_notices, admin_post_*: the settings screen

= Filters the plugin provides =

* tagbridge_posthog_js_config: the posthog-js init config array, before it is printed.
* tagbridge_posthog_mask_text_selector: the session-replay text-masking CSS selector (empty disables it).
* tagbridge_module_manifest: the list of registered integration modules.

== External services ==

This plugin connects to PostHog, the analytics service you configure. It is required for the plugin to do anything, because the plugin's purpose is to send your site's analytics to your PostHog project.

What is sent and when:

* When you save your settings in the admin, the plugin makes one request to the PostHog feature-flags endpoint (for example https://us.i.posthog.com/flags) with your project token to confirm the key and host are valid. This request does not record any analytics event.
* On your site's front end, after you have connected and only when configured, the plugin loads PostHog (posthog-js) from the host you choose (PostHog US cloud, EU cloud, or your own host). PostHog then sends visitor analytics such as pageviews and, if enabled, clicks and form interactions and session recordings, to your PostHog project. The exact data depends on the toggles you choose.
* When server-side events are enabled, your WordPress server sends those events (for example user logged in, or a completed WooCommerce order with its value and currency) directly to your configured PostHog host. This happens from your server, not the visitor's browser.

The host the data is sent to is the one you configure:

* US cloud: https://us.i.posthog.com
* EU cloud: https://eu.i.posthog.com
* Self-hosted or reverse proxy: the URL you enter

PostHog terms of service: https://posthog.com/terms
PostHog privacy policy: https://posthog.com/privacy

== Privacy ==

No analytics are sent until you enter a PostHog project token and connect. You control what is captured with the tracking toggles, and you can turn on cookieless mode so no PostHog cookie is set.

You are responsible for telling your visitors what you collect and for obtaining any consent your jurisdiction requires. Support for the WordPress Consent API is planned for a future release.

== Changelog ==

= 0.8.0 =
* product_purchased: one event per line item in a completed order, with SKU, variant, quantity, line revenue, categories, and attributes - so sales break down by SKU and attribute, closing the want-to-bought loop.

= 0.7.0 =
* Attribute & SKU intent: product_viewed and product_added_to_cart now carry the product SKU, categories, and descriptive attributes (blade material, origin, etc.); add-to-cart and variant selection also carry the variation id, SKU, and chosen variant, so you can see which attributes and SKUs people want across the funnel.

= 0.6.0 =
* Track product variant selections: a new tracking toggle that sends a product_variant_selected event when a shopper picks a WooCommerce variation (size, colour, etc.) on a product page, with the variation, price, and stock status.

= 0.5.0 =
* Three more events: checkout_viewed (WooCommerce checkout page view), product_review_submitted (approved WooCommerce review), and user_logged_out.

= 0.4.1 =
* Move the settings screen under the WordPress Settings menu (Settings, Tagbridge) instead of a top-level sidebar item.

= 0.4.0 =
* Link to PostHog's WooCommerce data warehouse source from the Server events screen, for SQL analytics on your orders, customers, and products.
* Reverse proxy: the connection screen now recommends PostHog's free managed reverse proxy (with a link to the docs) and points the custom-host field at a proxy subdomain, so events get past ad blockers.
* Error tracking: capture unhandled browser (JavaScript) errors out of the box, and optionally uncaught PHP exceptions and errors from your server (opt-in; installs a chained PHP error handler that does not replace existing ones). Server-side errors are stitched to the same person as your other events.

= 0.3.1 =
* Heatmaps: a new tracking toggle that turns on PostHog click and scroll heatmaps (also enable heatmaps in your PostHog project settings).
* Renamed the connection field to "Project token" to match PostHog's wording (Settings, General, Project token).

= 0.3.0 =
* More WooCommerce events (each individually toggleable): product list viewed, products searched, removed from cart, cart viewed, coupon applied, coupon removed, payment failed, order refunded, and order cancelled. All are server-side and stitched to the same person.
* Session replay: when recording is on, form inputs are masked and a filterable text-masking selector (`tagbridge_posthog_mask_text_selector`) covers common WooCommerce / CheckoutWC surfaces that render a name, email, or address as text.

= 0.2.1 =
* Redesigned the settings screen: a clearer page header, each section's status or master switch in its header, a two-column layout for tracking and identity, and refined controls. No functional changes.

= 0.2.0 =
* Identity: tie logged-in users to one PostHog person with a stable, hashed identifier. Configurable, with per-property control over email, name, and role.
* Server-side events: send events from your WordPress server (user logged in, user registered), each with an on/off toggle and a master switch. A failed or slow PostHog request never affects your pages.
* WooCommerce events (when active): product viewed, added to cart, checkout started, and order completed with order value and currency, stitched to the same person from session through to the completed order.

= 0.1.0 =
* First release: connect to PostHog (US, EU, or self-hosted / reverse proxy), validate the key before saving, and load PostHog on the front end with tracking toggles (pageviews, autocapture, session recording, person profiles, cookieless mode).

== Upgrade Notice ==

= 0.8.0 =
Adds a per-line-item product_purchased event for SKU/attribute sales breakdowns.

= 0.7.0 =
Product events now include SKU, categories, and attributes for intent analysis.

= 0.6.0 =
Adds a product_variant_selected event for WooCommerce variations.

= 0.5.0 =
Adds checkout_viewed, product_review_submitted, and user_logged_out events.

= 0.4.1 =
The settings screen moved under Settings, Tagbridge.

= 0.4.0 =
Adds JavaScript error tracking (on by default) and opt-in server-side PHP error tracking.

= 0.3.1 =
Adds a heatmaps toggle and clarifies the connection field label.

= 0.3.0 =
Adds nine more WooCommerce events and session-replay input/text masking. All new events are on by default and individually toggleable.

= 0.2.1 =
A redesigned settings screen. No functional changes.

= 0.2.0 =
Adds identity stitching and server-side event capture (including WooCommerce), all configurable.

= 0.1.0 =
First release.
