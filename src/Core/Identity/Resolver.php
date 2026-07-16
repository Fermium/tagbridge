<?php
/**
 * Identity resolver: the heart of identity stitching.
 *
 * Platform-agnostic. Contains zero WordPress function calls. It takes plain
 * inputs (a cookie value, a user id, a salt) and returns the distinct id and
 * person properties to use, so the client and the server resolve to one person.
 *
 * The browser library stores its state, including distinct_id, in a cookie
 * named ph_<project_api_key>_posthog as (URL-encoded) JSON. Reading that cookie
 * server-side and using the same distinct_id is what stitches client and server
 * events into a single person.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Identity;

/**
 * Resolves distinct ids and person properties.
 */
final class Resolver {

	/**
	 * PostHog cookie name prefix.
	 *
	 * @var string
	 */
	const COOKIE_PREFIX = 'ph_';

	/**
	 * PostHog cookie name suffix.
	 *
	 * @var string
	 */
	const COOKIE_SUFFIX = '_posthog';

	/**
	 * Prefix applied to the stable, hashed WordPress user identifier.
	 *
	 * @var string
	 */
	const USER_ID_PREFIX = 'wp_';

	/**
	 * Key posthog-js stores its session state under in the cookie.
	 *
	 * The value is an array [last_activity_ms, session_id, session_start_ms].
	 *
	 * @var string
	 */
	const SESSION_ID_KEY = '$sesid';

	/**
	 * Idle window (ms) after which a stored browser session is stale.
	 *
	 * Matches posthog-js's default `session_idle_timeout_seconds` of 30 minutes:
	 * once the browser has been idle this long it rotates to a new session id, so
	 * the id in the cookie no longer names the session PostHog is recording.
	 *
	 * @var int
	 */
	const SESSION_MAX_IDLE_MS = 1800000;

	/**
	 * Maximum session length (ms) PostHog allows before a new session starts.
	 *
	 * A session tops out at 24 hours. Past that the browser has rotated to a new
	 * id, and PostHog rejects a custom $session_id whose UUIDv7 timestamp is more
	 * than 24 hours before the event, so a stored id this old must not be reused.
	 *
	 * @var int
	 */
	const SESSION_MAX_LENGTH_MS = 86400000;

	/**
	 * Source: the id came from the logged-in user (identified).
	 *
	 * @var string
	 */
	const SOURCE_IDENTIFIED = 'identified';

	/**
	 * Source: the id came from the posthog-js cookie (anonymous or prior).
	 *
	 * @var string
	 */
	const SOURCE_COOKIE = 'cookie';

	/**
	 * Source: a caller-supplied fallback id was used.
	 *
	 * @var string
	 */
	const SOURCE_FALLBACK = 'fallback';

	/**
	 * Source: no id could be determined.
	 *
	 * @var string
	 */
	const SOURCE_NONE = 'none';

	/**
	 * Build the posthog-js cookie name for a project API key.
	 *
	 * @param string $project_api_key Project API key.
	 * @return string
	 */
	public static function cookie_name( $project_api_key ) {
		return self::COOKIE_PREFIX . (string) $project_api_key . self::COOKIE_SUFFIX;
	}

	/**
	 * Extract the distinct id from a posthog-js cookie value.
	 *
	 * Handles a missing cookie, a malformed (non-JSON) value, and a value that
	 * is still URL-encoded. Returns null when no usable distinct id is present.
	 *
	 * @param string|null $cookie_value Raw cookie value (may be null).
	 * @return string|null The distinct id, or null.
	 */
	public static function parse_distinct_id( $cookie_value ) {
		$decoded = self::decode_cookie( $cookie_value );
		if ( null === $decoded || ! isset( $decoded['distinct_id'] ) ) {
			return null;
		}

		$distinct_id = $decoded['distinct_id'];
		if ( ! is_string( $distinct_id ) || '' === $distinct_id ) {
			return null;
		}

		return $distinct_id;
	}

	/**
	 * Extract the current browser session id from a posthog-js cookie value.
	 *
	 * The posthog-js library stores its session state under the `$sesid` key as
	 * the array [last_activity_ms, session_id, session_start_ms]. Stamping that id
	 * (a UUIDv7) onto a server-side event is what lets PostHog attach the event to
	 * the browser's session replay and lets the recording be filtered by it. The
	 * id is reused verbatim — never regenerated — so it stays consistent with the
	 * browser's own events and satisfies PostHog's custom-$session_id rules.
	 *
	 * When $now_ms is given, a stored id is only returned while the browser would
	 * still consider it current: not idle past SESSION_MAX_IDLE_MS and not older
	 * than SESSION_MAX_LENGTH_MS. Past either bound the browser has rotated to a
	 * new session, and PostHog would reject the expired id, so we return null and
	 * let the event go out without a session id rather than mis-attribute it.
	 *
	 * @param string|null $cookie_value Raw cookie value (may be null).
	 * @param int|null    $now_ms       Current time in ms, or null to skip the freshness check.
	 * @return string|null The session id, or null.
	 */
	public static function parse_session_id( $cookie_value, $now_ms = null ) {
		$decoded = self::decode_cookie( $cookie_value );
		if ( null === $decoded || ! isset( $decoded[ self::SESSION_ID_KEY ] ) || ! is_array( $decoded[ self::SESSION_ID_KEY ] ) ) {
			return null;
		}

		$sesid = $decoded[ self::SESSION_ID_KEY ];

		// [ last_activity_ms, session_id, session_start_ms ] — the id is at index 1.
		if ( ! isset( $sesid[1] ) || ! is_string( $sesid[1] ) || '' === $sesid[1] ) {
			return null;
		}

		if ( null !== $now_ms ) {
			$now = (float) $now_ms;
			if ( isset( $sesid[0] ) && is_numeric( $sesid[0] ) && ( $now - (float) $sesid[0] ) > self::SESSION_MAX_IDLE_MS ) {
				return null;
			}
			if ( isset( $sesid[2] ) && is_numeric( $sesid[2] ) && ( $now - (float) $sesid[2] ) > self::SESSION_MAX_LENGTH_MS ) {
				return null;
			}
		}

		return $sesid[1];
	}

	/**
	 * Decode a posthog-js cookie value to its property array, or null.
	 *
	 * The cookie holds JSON; some servers/clients leave it URL-encoded, so a
	 * value containing a percent sign is decoded once more before giving up.
	 *
	 * @param string|null $cookie_value Raw cookie value (may be null).
	 * @return array<string,mixed>|null
	 */
	private static function decode_cookie( $cookie_value ) {
		if ( ! is_string( $cookie_value ) || '' === $cookie_value ) {
			return null;
		}

		$decoded = json_decode( $cookie_value, true );

		// Some servers/clients leave the value URL-encoded; try once more.
		if ( ! is_array( $decoded ) && false !== strpos( $cookie_value, '%' ) ) {
			$decoded = json_decode( rawurldecode( $cookie_value ), true );
		}

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Compute a stable, non-reversible distinct id for a WordPress user.
	 *
	 * Uses an HMAC of the user id keyed by a site salt, so the raw email or user
	 * id is never used directly as the PostHog identifier. The same user always
	 * resolves to the same id (stable across sessions), and the value cannot be
	 * reversed back to the user id without the salt.
	 *
	 * @param int|string $user_id WordPress user id.
	 * @param string     $salt    Site-specific secret salt.
	 * @return string
	 */
	public static function stable_user_id( $user_id, $salt ) {
		$hash = hash_hmac( 'sha256', 'user:' . (string) $user_id, (string) $salt );
		return self::USER_ID_PREFIX . $hash;
	}

	/**
	 * Resolve which distinct id a server-side event should use.
	 *
	 * Precedence:
	 *  1. The identified id (a logged-in user's stable id), so server events
	 *     attach to the identified person.
	 *  2. The cookie distinct id (the anonymous browser id), so anonymous server
	 *     events join the same anonymous person as client events.
	 *  3. A caller-supplied fallback (for server events with no prior page load).
	 *
	 * @param string|null $cookie_distinct_id The id from the posthog-js cookie.
	 * @param string|null $identified_id      The logged-in user's stable id.
	 * @param string|null $fallback           Optional fallback id.
	 * @return array{distinct_id:string,source:string}
	 */
	public static function resolve_distinct_id( $cookie_distinct_id, $identified_id = null, $fallback = null ) {
		if ( is_string( $identified_id ) && '' !== $identified_id ) {
			return array(
				'distinct_id' => $identified_id,
				'source'      => self::SOURCE_IDENTIFIED,
			);
		}

		if ( is_string( $cookie_distinct_id ) && '' !== $cookie_distinct_id ) {
			return array(
				'distinct_id' => $cookie_distinct_id,
				'source'      => self::SOURCE_COOKIE,
			);
		}

		if ( is_string( $fallback ) && '' !== $fallback ) {
			return array(
				'distinct_id' => $fallback,
				'source'      => self::SOURCE_FALLBACK,
			);
		}

		return array(
			'distinct_id' => '',
			'source'      => self::SOURCE_NONE,
		);
	}

	/**
	 * Normalize raw person properties to the allowed, non-empty set.
	 *
	 * Only email, name, and role are kept. Empty values are dropped so we never
	 * overwrite an existing person property with a blank.
	 *
	 * @param array<string,mixed> $raw Raw properties (email, name, role).
	 * @return array<string,string>
	 */
	public static function person_properties( array $raw ) {
		$allowed = array( 'email', 'name', 'role' );
		$props   = array();

		foreach ( $allowed as $field ) {
			if ( ! isset( $raw[ $field ] ) ) {
				continue;
			}
			$value = is_scalar( $raw[ $field ] ) ? trim( (string) $raw[ $field ] ) : '';
			if ( '' !== $value ) {
				$props[ $field ] = $value;
			}
		}

		return $props;
	}
}
