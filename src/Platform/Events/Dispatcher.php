<?php
/**
 * Server-side event dispatcher.
 *
 * Owns the Core ServerClient lifecycle: it lazily initializes posthog-php on the
 * first event, flushes on shutdown, gates each event on its setting, and logs
 * (only when WP_DEBUG) without ever letting a failure reach the visitor.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Events;

use Tagbridge\Core\Events\Schema;
use Tagbridge\Core\Events\ServerClient;
use Tagbridge\Platform\Settings\Options;

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

		if ( ! Options::is_configured() ) {
			return null;
		}

		$client = new ServerClient();
		if ( ! $client->init( Options::project_api_key(), Options::host() ) ) {
			return null;
		}

		$this->client = $client;

		// Deliver queued events at the end of the request without blocking it.
		add_action( 'shutdown', array( $this, 'flush' ), 100 );

		return $this->client;
	}

	/**
	 * Capture a server-side event if its setting is enabled.
	 *
	 * @param string              $event_key  Settings/event key (Schema key).
	 * @param string              $distinct_id Resolved distinct id.
	 * @param array<string,mixed> $properties Event properties.
	 * @return void
	 */
	public function capture( $event_key, $distinct_id, array $properties = array() ) {
		if ( ! Options::is_server_event_enabled( $event_key ) ) {
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

		if ( ! $client->capture( $distinct_id, $events[ $event_key ], $properties ) ) {
			$this->log( 'capture ' . $event_key . ' failed: ' . $client->last_error() );
		}
	}

	/**
	 * Identify a person server-side (respects the identity setting).
	 *
	 * @param string              $distinct_id Distinct id.
	 * @param array<string,mixed> $properties  Person properties.
	 * @return void
	 */
	public function identify( $distinct_id, array $properties = array() ) {
		$identity = Options::identity();
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
		$identity = Options::identity();
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
