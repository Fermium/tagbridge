<?php
/**
 * Server-side PHP error filter for PostHog error tracking.
 *
 * The posthog-php error handler turns every PHP notice and warning into an
 * `$exception` event and has no hook to drop the harmless ones. This filter sits
 * in front of that handler: errors it calls benign skip PostHog and go to PHP's
 * normal handling (the error log), and everything else is passed on.
 *
 * Benign means either a low severity (notices, deprecations; filtered by
 * default) or a message matching a known-benign pattern, such as the
 * parse_str() nesting warning that bots trigger with deeply nested query strings.
 *
 * Platform-agnostic: native PHP only, no WordPress functions.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Events;

/**
 * Decides which PHP errors are worth sending to PostHog, and installs itself as
 * the error handler in front of PostHog's.
 */
final class ErrorFilter {

	/**
	 * Severities dropped by default: they never break a page.
	 *
	 * @var int
	 */
	const DEFAULT_IGNORED_LEVELS = E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED;

	/**
	 * Message fragments that are always benign, whatever the site.
	 *
	 * @var string[]
	 */
	const DEFAULT_IGNORED_MESSAGES = array(
		// Bots send query strings nested deeper than max_input_nesting_level; PHP
		// truncates the input and nothing else happens.
		'Input variable nesting level exceeded',
	);

	/**
	 * Bitmask of severities to drop.
	 *
	 * @var int
	 */
	private $ignored_levels;

	/**
	 * Case-sensitive message fragments to drop.
	 *
	 * @var string[]
	 */
	private $ignored_messages;

	/**
	 * The handler errors are passed to when they are not benign (PostHog's).
	 *
	 * @var callable|null
	 */
	private $next = null;

	/**
	 * Constructor.
	 *
	 * @param int      $ignored_levels   Bitmask of severities to drop.
	 * @param string[] $ignored_messages Message fragments to drop.
	 */
	public function __construct( $ignored_levels = self::DEFAULT_IGNORED_LEVELS, array $ignored_messages = self::DEFAULT_IGNORED_MESSAGES ) {
		$this->ignored_levels   = (int) $ignored_levels;
		$this->ignored_messages = array_values(
			array_filter(
				array_map( 'strval', $ignored_messages ),
				static function ( $fragment ) {
					return '' !== $fragment;
				}
			)
		);
	}

	/**
	 * Whether an error should be kept out of PostHog.
	 *
	 * @param int    $errno   PHP error severity.
	 * @param string $message Error message.
	 * @return bool
	 */
	public function is_benign( $errno, $message ) {
		if ( 0 !== ( (int) $errno & $this->ignored_levels ) ) {
			return true;
		}

		foreach ( $this->ignored_messages as $fragment ) {
			if ( false !== strpos( (string) $message, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Install this filter as the PHP error handler, in front of the current one.
	 *
	 * Call it right after posthog-php installed its handler, so the handler
	 * replaced here is PostHog's. Returns false when there was no handler to
	 * wrap (error capture is off), leaving PHP's handler state unchanged.
	 *
	 * @return bool Whether the filter was installed.
	 */
	public function install() {
		$current = set_error_handler( array( $this, 'handle' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Wraps PostHog's handler on purpose.
		if ( ! is_callable( $current ) ) {
			restore_error_handler();
			return false;
		}

		$this->next = $current;
		return true;
	}

	/**
	 * PHP error handler: drop benign errors from PostHog, pass the rest on.
	 *
	 * Returning false hands the error to PHP's standard handler, so benign
	 * errors are still logged exactly as they were before PostHog was installed.
	 *
	 * @param int    $errno   PHP error severity.
	 * @param string $message Error message.
	 * @param string $file    Source file.
	 * @param int    $line    Source line.
	 * @return bool
	 */
	public function handle( $errno, $message, $file = '', $line = 0 ) {
		if ( null === $this->next || $this->is_benign( $errno, $message ) ) {
			return false;
		}

		return (bool) call_user_func( $this->next, $errno, $message, $file, $line );
	}
}
