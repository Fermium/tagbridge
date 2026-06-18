<?php
/**
 * Server-side PostHog client.
 *
 * Platform-agnostic wrapper around posthog-php. Every call is wrapped so a
 * PostHog problem (timeout, bad host, rate limit) is swallowed and never
 * surfaces to the visitor. Uses only the posthog-php library and native PHP, no
 * WordPress functions.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Events;

use PostHog\PostHog;

/**
 * Thin, failure-tolerant wrapper over the posthog-php facade.
 */
final class ServerClient {

	/**
	 * Whether init() has successfully run this request.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * The last error message captured (for optional logging by the caller).
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Initialize the PostHog client with the project key and host.
	 *
	 * @param string              $api_key        Project API key.
	 * @param string              $host           Base host URL.
	 * @param array<string,mixed> $error_tracking Optional error-tracking config: enabled,
	 *                                            capture_errors, and an optional context_provider
	 *                                            callable returning array{distinctId,properties}.
	 * @return bool Whether initialization succeeded.
	 */
	public function init( $api_key, $host, array $error_tracking = array() ) {
		if ( '' === (string) $api_key || ! class_exists( '\PostHog\PostHog' ) ) {
			return false;
		}

		$options = array( 'host' => (string) $host );
		if ( ! empty( $error_tracking['enabled'] ) ) {
			$options['error_tracking'] = array(
				'enabled'        => true,
				'capture_errors' => ! empty( $error_tracking['capture_errors'] ),
			);
			if ( isset( $error_tracking['context_provider'] ) && is_callable( $error_tracking['context_provider'] ) ) {
				$options['error_tracking']['context_provider'] = $error_tracking['context_provider'];
			}
		}

		try {
			PostHog::init( (string) $api_key, $options );
			$this->initialized = true;
		} catch ( \Throwable $e ) {
			$this->last_error  = $e->getMessage();
			$this->initialized = false;
		}

		return $this->initialized;
	}

	/**
	 * Whether the client is ready to send.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->initialized;
	}

	/**
	 * Capture an event.
	 *
	 * @param string              $distinct_id Distinct id for the person.
	 * @param string              $event       Event name (from the Schema).
	 * @param array<string,mixed> $properties  Event properties.
	 * @return bool Whether the call was accepted (queued) without error.
	 */
	public function capture( $distinct_id, $event, array $properties = array() ) {
		if ( ! $this->guard( $distinct_id ) || '' === (string) $event ) {
			return false;
		}

		try {
			PostHog::capture(
				array(
					'distinctId' => (string) $distinct_id,
					'event'      => (string) $event,
					'properties' => $properties,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Identify a person and set properties.
	 *
	 * @param string              $distinct_id Distinct id for the person.
	 * @param array<string,mixed> $properties  Person properties to set.
	 * @return bool
	 */
	public function identify( $distinct_id, array $properties = array() ) {
		if ( ! $this->guard( $distinct_id ) ) {
			return false;
		}

		try {
			PostHog::identify(
				array(
					'distinctId' => (string) $distinct_id,
					'properties' => $properties,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Alias a previous (anonymous) id to a person, merging their history.
	 *
	 * Events from $alias become attributed to $distinct_id.
	 *
	 * @param string $distinct_id The canonical (identified) id.
	 * @param string $alias       The previous id to merge in.
	 * @return bool
	 */
	public function alias( $distinct_id, $alias ) {
		if ( ! $this->guard( $distinct_id ) || '' === (string) $alias || (string) $alias === (string) $distinct_id ) {
			return false;
		}

		try {
			PostHog::alias(
				array(
					'distinctId' => (string) $distinct_id,
					'alias'      => (string) $alias,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Flush queued events to PostHog. Safe to call on shutdown.
	 *
	 * @return bool
	 */
	public function flush() {
		if ( ! $this->initialized ) {
			return false;
		}

		try {
			PostHog::flush();
			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	/**
	 * The last error message, if any.
	 *
	 * @return string
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * Shared guard: client ready and a non-empty distinct id.
	 *
	 * @param string $distinct_id Distinct id.
	 * @return bool
	 */
	private function guard( $distinct_id ) {
		return $this->initialized && '' !== (string) $distinct_id;
	}
}
