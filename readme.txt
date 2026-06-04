=== Tagbridge – Deep Integration for PostHog ===
Contributors: mcgreat
Tags: analytics, posthog, tracking, events, statistics
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent PostHog integration for WordPress. Connect your project and send pageviews and events to PostHog. Not affiliated with PostHog.

== Description ==

Tagbridge is a simple, independent way to add PostHog to WordPress: PostHog for WordPress, without touching code. Enter your PostHog project API key, choose your region, and Tagbridge loads PostHog on your site with the settings you pick.

Tagbridge is an independent project. It is not affiliated with, endorsed by, or sponsored by PostHog. "PostHog" is a trademark of its respective owner and is used here only to describe what this plugin connects to.

= What it does today =

* Connect to PostHog Cloud (US or EU) or your own self-hosted or reverse-proxy host.
* Your project API key is checked with a live test call before it is saved, so you know right away if it is correct.
* Loads PostHog (posthog-js) from the host you configure, so self-hosted and reverse-proxy setups work without code.
* Plain-language toggles for what gets captured: pageviews, autocapture (clicks and form interactions), session recording, and person profile mode.
* Privacy-first cookieless mode that keeps visitor state in memory, so no PostHog cookie is set.
* Identity: tie logged-in WordPress users to one PostHog person across anonymous and logged-in sessions, using a stable hashed identifier (never the raw user ID). You choose whether to identify logged-in users and which properties to send (email, name, role).
* Server-side events: send key events (user logged in, user registered) from your own WordPress server, so they still arrive when a visitor's browser blocks tracking. Runs on your existing hosting, with a per-event on/off switch. A failed or slow PostHog request never affects your pages.
* WooCommerce events (when WooCommerce is active): product viewed, added to cart, checkout started, and order completed with order value and currency. The same person is tracked from the browser session through to the completed order.
* A clean settings screen that fits WordPress and stays out of your way.

= On the roadmap =

Planned for future releases:

* A no-flicker feature-flag block and shortcode evaluated on the server.
* Optional starter dashboards created in your PostHog project.
* WordPress Consent API support.

== Installation ==

1. Install and activate the plugin.
2. In the WordPress admin, open the "PostHog" menu.
3. Paste your PostHog project API key and choose your region (US, EU, or self-hosted / reverse proxy).
4. Save. Tagbridge checks the key with PostHog before saving and tells you if it worked.
5. Choose what to track, then save again. PostHog will start receiving data from your site.

You can find your project API key in PostHog under Settings, Project, Project API key. It is a public key and is safe to use in the browser.

== Frequently Asked Questions ==

= Is this an official PostHog plugin? =

No. Tagbridge is an independent project and is not affiliated with or endorsed by PostHog.

= Do I need a PostHog account? =

Yes. You need a PostHog project and its project API key. PostHog offers a free tier. See https://posthog.com.

= Does it work with self-hosted PostHog or a reverse proxy? =

Yes. Choose "Self-hosted or reverse proxy" and enter your host URL. PostHog loads from that host.

= Will it set cookies? =

By default PostHog uses its normal storage. Turn on "Privacy-first cookieless mode" to keep visitor state in memory only, so no PostHog cookie is set.

== Screenshots ==

1. Connect your PostHog project: paste the project API key and choose your region. The key is checked with PostHog before it is saved.
2. Once connected, choose what to track with plain-language toggles: pageviews, autocapture, session recording, person profiles, and privacy-first cookieless mode.

== External services ==

This plugin connects to PostHog, the analytics service you configure. It is required for the plugin to do anything, because the plugin's purpose is to send your site's analytics to your PostHog project.

What is sent and when:

* When you save your settings in the admin, the plugin makes one request to the PostHog feature-flags endpoint (for example https://us.i.posthog.com/flags) with your project API key to confirm the key and host are valid. This request does not record any analytics event.
* On your site's front end, after you have connected and only when configured, the plugin loads PostHog (posthog-js) from the host you choose (PostHog US cloud, EU cloud, or your own host). PostHog then sends visitor analytics such as pageviews and, if enabled, clicks and form interactions and session recordings, to your PostHog project. The exact data depends on the toggles you choose.
* When server-side events are enabled, your WordPress server sends those events (for example user logged in, or a completed WooCommerce order with its value and currency) directly to your configured PostHog host. This happens from your server, not the visitor's browser.

The host the data is sent to is the one you configure:

* US cloud: https://us.i.posthog.com
* EU cloud: https://eu.i.posthog.com
* Self-hosted or reverse proxy: the URL you enter

PostHog terms of service: https://posthog.com/terms
PostHog privacy policy: https://posthog.com/privacy

== Privacy ==

No analytics are sent until you enter a PostHog project API key and connect. You control what is captured with the tracking toggles, and you can turn on cookieless mode so no PostHog cookie is set.

You are responsible for telling your visitors what you collect and for obtaining any consent your jurisdiction requires. Support for the WordPress Consent API is planned for a future release.

== Changelog ==

= 0.2.1 =
* Redesigned the settings screen: a clearer page header, each section's status or master switch in its header, a two-column layout for tracking and identity, and refined controls. No functional changes.

= 0.2.0 =
* Identity: tie logged-in users to one PostHog person with a stable, hashed identifier. Configurable, with per-property control over email, name, and role.
* Server-side events: send events from your WordPress server (user logged in, user registered), each with an on/off toggle and a master switch. A failed or slow PostHog request never affects your pages.
* WooCommerce events (when active): product viewed, added to cart, checkout started, and order completed with order value and currency, stitched to the same person from session through to the completed order.

= 0.1.0 =
* First release: connect to PostHog (US, EU, or self-hosted / reverse proxy), validate the key before saving, and load PostHog on the front end with tracking toggles (pageviews, autocapture, session recording, person profiles, cookieless mode).

== Upgrade Notice ==

= 0.2.1 =
A redesigned settings screen. No functional changes.

= 0.2.0 =
Adds identity stitching and server-side event capture (including WooCommerce), all configurable.

= 0.1.0 =
First release.
