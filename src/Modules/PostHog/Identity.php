<?php
/**
 * PostHog identity glue.
 *
 * Bridges WordPress (cookies, the current user, site salt) to the
 * platform-agnostic identity Resolver. This is where the shared
 * ph_<project_api_key>_posthog cookie is read so the server and client resolve
 * to one person. The cookie name and the choice of person properties are
 * PostHog-specific, so this lives inside the module.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Identity\Resolver;

/**
 * Resolves the current visitor's PostHog identity from WordPress state.
 */
final class Identity {

	/**
	 * The distinct id stored by posthog-js in its cookie, if present.
	 *
	 * @return string|null
	 */
	public static function cookie_distinct_id() {
		$key = Settings::project_api_key();
		if ( '' === $key ) {
			return null;
		}

		$name = Resolver::cookie_name( $key );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		// The cookie holds JSON; the extracted distinct id is sanitized below.
		$raw = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = is_string( $raw ) ? $raw : '';

		$distinct_id = Resolver::parse_distinct_id( $raw );

		return null === $distinct_id ? null : sanitize_text_field( $distinct_id );
	}

	/**
	 * The browser's current session id from the posthog-js cookie, if present
	 * and still fresh.
	 *
	 * Stamping this onto a server-side event links it to the visitor's session
	 * replay (and lets the recording be filtered by that event). Read from the
	 * same ph_<key>_posthog cookie as the distinct id; returns null when there is
	 * no cookie (e.g. cookieless mode or a browserless request) or the stored
	 * session has gone stale.
	 *
	 * @return string|null
	 */
	public static function cookie_session_id() {
		$key = Settings::project_api_key();
		if ( '' === $key ) {
			return null;
		}

		$name = Resolver::cookie_name( $key );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}

		// The cookie holds JSON; the extracted session id is sanitized below.
		$raw = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = is_string( $raw ) ? $raw : '';

		$now_ms     = (int) round( microtime( true ) * 1000 );
		$session_id = Resolver::parse_session_id( $raw, $now_ms );

		return null === $session_id ? null : sanitize_text_field( $session_id );
	}

	/**
	 * The identity (stable distinct id + person properties) for the logged-in
	 * user, or null when the visitor is not logged in.
	 *
	 * @return array{distinct_id:string,properties:array<string,string>}|null
	 */
	public static function current_user_identity() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		return self::identity_for( wp_get_current_user() );
	}

	/**
	 * Build the identity for a specific user, respecting the identity settings.
	 *
	 * Used by login/registration hooks where the current-user globals are not
	 * yet reliable. Returns null when identifying users is disabled or the user
	 * is invalid.
	 *
	 * @param \WP_User|null $user A WordPress user object.
	 * @return array{distinct_id:string,properties:array<string,string>}|null
	 */
	public static function identity_for( $user ) {
		$settings = Settings::identity();
		if ( empty( $settings['identify_logged_in'] ) ) {
			return null;
		}

		if ( ! $user || ! isset( $user->ID ) || ! $user->ID ) {
			return null;
		}

		$distinct_id = Resolver::stable_user_id( $user->ID, self::salt() );

		// Only send the person properties the site owner has opted into.
		$raw_props = array();
		if ( ! empty( $settings['send_email'] ) ) {
			$raw_props['email'] = $user->user_email;
		}
		if ( ! empty( $settings['send_name'] ) ) {
			$raw_props['name'] = $user->display_name;
		}
		if ( ! empty( $settings['send_role'] ) ) {
			$raw_props['role'] = ( is_array( $user->roles ) && ! empty( $user->roles ) ) ? (string) reset( $user->roles ) : '';
		}

		return array(
			'distinct_id' => $distinct_id,
			'properties'  => Resolver::person_properties( $raw_props ),
		);
	}

	/**
	 * The stable distinct id for a given user (ignores identity on/off setting).
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	public static function stable_id_for_user( $user_id ) {
		return Resolver::stable_user_id( (int) $user_id, self::salt() );
	}

	/**
	 * Resolve the distinct id a server-side event should use.
	 *
	 * Logged-in users resolve to their stable id; anonymous visitors resolve to
	 * the posthog-js cookie id. Used by server-side capture.
	 *
	 * @param string|null $fallback Optional fallback id when nothing else exists.
	 * @return array{distinct_id:string,source:string}
	 */
	public static function server_distinct_id( $fallback = null ) {
		$identity      = self::current_user_identity();
		$identified_id = $identity ? $identity['distinct_id'] : null;

		return Resolver::resolve_distinct_id( self::cookie_distinct_id(), $identified_id, $fallback );
	}

	/**
	 * The site-specific salt used to derive stable, non-reversible user ids.
	 *
	 * @return string
	 */
	private static function salt() {
		return wp_salt( 'auth' );
	}
}
