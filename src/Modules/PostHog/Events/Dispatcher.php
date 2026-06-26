<?php
/**
 * Server-side event dispatcher.
 *
 * Owns the Core ServerClient lifecycle: it lazily initializes posthog-php on the
 * first event, flushes on shutdown, gates each event on its setting, and logs
 * (only when WP_DEBUG) without ever letting a failure reach the visitor.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Events;

use Tagbridge\Core\Events\Schema;
use Tagbridge\Core\Events\ServerClient;
use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\Settings;

/**
 * Bridges WordPress events to the platform-agnostic ServerClient.
 */
final class Dispatcher {

	/**
	 * The Core client, created lazily.
	 *
	 * @var ServerClient|null
	 */
	private $client = null;

	/**
	 * Whether we already tried (and possibly failed) to create the client.
	 *
	 * @var bool
	 */
	private $attempted = false;

	/**
	 * Get a ready ServerClient, or null if not configured / init failed.
	 *
	 * @return ServerClient|null
	 */
	private function client() {
		if ( $this->attempted ) {
			return $this->client && $this->client->is_ready() ? $this->client : null;
		}
		$this->attempted = true;

		if ( ! Settings::is_configured() ) {
			return null;
		}

		$error_opts = array();
		if ( ! empty( Settings::error_tracking()['php'] ) ) {
			$error_opts = array(
				'enabled'          => true,
				'capture_errors'   => true,
				'context_provider' => static function () {
					$resolved = Identity::server_distinct_id();
					return array(
						'distinctId' => '' !== $resolved['distinct_id'] ? $resolved['distinct_id'] : null,
						'properties' => array(),
					);
				},
			);
		}

		$client = new ServerClient();
		if ( ! $client->init( Settings::project_api_key(), Settings::host(), $error_opts ) ) {
			return null;
		}

		$this->client = $client;

		// Deliver queued events at the end of the request without blocking it.
		add_action( 'shutdown', array( $this, 'flush' ), 100 );

		return $this->client;
	}

	/**
	 * Eagerly initialize the client. When server-side error tracking is on this
	 * installs PostHog's PHP error handlers early, before errors can occur.
	 *
	 * @return void
	 */
	public function boot() {
		$this->client();
	}

	/**
	 * Capture a server-side event if its setting is enabled.
	 *
	 * @param string              $event_key   Settings/event key (Schema key).
	 * @param string              $distinct_id Resolved distinct id.
	 * @param array<string,mixed> $properties  Event properties.
	 * @return void
	 */
	public function capture( $event_key, $distinct_id, array $properties = array() ) {
		if ( ! Settings::is_server_event_enabled( $event_key ) ) {
			return;
		}

		$events = Schema::all_events();
		if ( ! isset( $events[ $event_key ] ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		$properties = $this->with_request_context( $properties );

		if ( ! $client->capture( $distinct_id, $events[ $event_key ], $properties ) ) {
			$this->log( 'capture ' . $event_key . ' failed: ' . $client->last_error() );
		}
	}

	/**
	 * Stamp the visitor's user agent and IP onto a server-side event so PostHog
	 * can attribute geography and run its bot detection (isLikelyBot /
	 * getBotName) against the same identity the browser would have carried.
	 *
	 * Behind a CDN or reverse proxy (Cloudflare, or Google Cloud CDN plus a load
	 * balancer as on Closte) REMOTE_ADDR is the proxy, so the visitor IP is
	 * resolved from the forwarded headers by client_ip(). A listener that already
	 * set either property wins. Events with no browser request (payment-gateway
	 * or admin order callbacks) simply carry no user agent — that is intentional,
	 * and the "Filter Bot Events" transformation is configured to keep UA-less
	 * events.
	 *
	 * @param array<string,mixed> $properties Event properties.
	 * @return array<string,mixed>
	 */
	private function with_request_context( array $properties ) {
		if ( ! isset( $properties['$raw_user_agent'] ) && ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$properties['$raw_user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		if ( ! isset( $properties['$ip'] ) ) {
			$ip = $this->client_ip();
			if ( '' !== $ip ) {
				$properties['$ip'] = $ip;
			}
		}

		return $properties;
	}

	/**
	 * Resolve the visitor IP for a site behind a CDN or reverse proxy, supporting
	 * both Cloudflare and a Google Cloud / nginx front end.
	 *
	 * REMOTE_ADDR is the proxy in these setups, so we try, in order: the
	 * `tagbridge_server_event_ip` filter (override hook); CF-Connecting-IP, which
	 * only Cloudflare sets; X-Real-IP, the single client IP an nginx / load
	 * balancer / CDN front end sets; the trustworthy hop of X-Forwarded-For (a
	 * Google Cloud load balancer appends "<client>, <load-balancer>", so the
	 * visitor is the second-to-last entry); and finally REMOTE_ADDR, but only
	 * when it is itself public so a load balancer's private IP is never stamped
	 * on the event. Returns '' when no usable IP is found.
	 *
	 * @return string
	 */
	private function client_ip() {
		/**
		 * Filter the resolved server-event visitor IP. Return a non-empty string
		 * to override detection (e.g. for a proxy chain we do not handle).
		 *
		 * @param string              $ip     Resolved IP ('' if undetermined).
		 * @param array<string,mixed> $server The $_SERVER superglobal.
		 */
		$override = apply_filters( 'tagbridge_server_event_ip', '', $_SERVER );
		if ( is_string( $override ) && '' !== $override ) {
			return $override;
		}

		// Cloudflare sets the original visitor IP here; no other front end does.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		// nginx / load balancer / CDN single client-IP header.
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$real = trim( (string) wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
			if ( false !== filter_var( $real, FILTER_VALIDATE_IP ) ) {
				return $real;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$count = count( $parts );
			// Two or more hops: a load balancer appended its own IP last, so the
			// visitor is the second-to-last entry. A single hop is the visitor.
			$candidate = $count >= 2 ? $parts[ $count - 2 ] : $parts[0];
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( false !== filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $remote;
			}
		}

		return '';
	}

	/**
	 * Identify a person server-side (respects the identity setting).
	 *
	 * @param string              $distinct_id Distinct id.
	 * @param array<string,mixed> $properties  Person properties.
	 * @return void
	 */
	public function identify( $distinct_id, array $properties = array() ) {
		$identity = Settings::identity();
		if ( empty( $identity['identify_logged_in'] ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		if ( ! $client->identify( $distinct_id, $properties ) ) {
			$this->log( 'identify failed: ' . $client->last_error() );
		}
	}

	/**
	 * Merge a previous (anonymous) id into a person (respects identity setting).
	 *
	 * @param string $distinct_id Canonical id.
	 * @param string $alias       Previous id to merge.
	 * @return void
	 */
	public function alias( $distinct_id, $alias ) {
		$identity = Settings::identity();
		if ( empty( $identity['identify_logged_in'] ) ) {
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		if ( ! $client->alias( $distinct_id, $alias ) ) {
			$this->log( 'alias failed: ' . $client->last_error() );
		}
	}

	/**
	 * Flush queued events. Hooked to shutdown.
	 *
	 * @return void
	 */
	public function flush() {
		if ( $this->client && $this->client->is_ready() ) {
			$this->client->flush();
		}
	}

	/**
	 * Log a message only when debugging is on. Never surfaced to visitors.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'tagbridge: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
