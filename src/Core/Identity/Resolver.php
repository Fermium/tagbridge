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
		if ( ! is_string( $cookie_value ) || '' === $cookie_value ) {
			return null;
		}

		$decoded = json_decode( $cookie_value, true );

		// Some servers/clients leave the value URL-encoded; try once more.
		if ( ! is_array( $decoded ) && false !== strpos( $cookie_value, '%' ) ) {
			$decoded = json_decode( rawurldecode( $cookie_value ), true );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['distinct_id'] ) ) {
			return null;
		}

		$distinct_id = $decoded['distinct_id'];
		if ( ! is_string( $distinct_id ) || '' === $distinct_id ) {
			return null;
		}

		return $distinct_id;
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
