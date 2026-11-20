<?php
/**
 * Consent for PostHog's browser storage, through the WordPress Consent API.
 *
 * The browser decides when posthog-js may use cookies (see
 * Frontend\Enqueue::consent_init_call()). The server must follow the same rule
 * when it reads the posthog-js cookie to stitch server-side events: a cookie
 * left over from before consent was required, or from before consent was
 * withdrawn, must not be read.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

/**
 * Consent checks shared by the browser snippet and server-side identity.
 */
final class Consent {

	/**
	 * The WP Consent API category that unlocks PostHog cookies and replay.
	 *
	 * @return string
	 */
	public static function category() {
		/**
		 * Filter the WP Consent API category PostHog waits for.
		 *
		 * @param string $category One of the WP Consent API categories. Default 'statistics'.
		 */
		return (string) apply_filters( 'tagbridge_posthog_consent_category', 'statistics' );
	}

	/**
	 * The WP Consent API cookie holding the visitor's choice for the category.
	 *
	 * @return string
	 */
	public static function cookie_name() {
		/** This filter is documented in the WP Consent API (wp-consent-api). */
		$prefix = (string) apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The WP Consent API's own hook.

		return $prefix . '_' . self::category();
	}

	/**
	 * Whether the browser waits for consent before using PostHog storage.
	 *
	 * Off in cookieless mode, where there is never any storage to wait for.
	 *
	 * @param array<string,mixed> $client Client settings.
	 * @return bool
	 */
	public static function required( array $client ) {
		return ! empty( $client['require_consent'] ) && empty( $client['cookieless'] );
	}

	/**
	 * Whether the server may read the posthog-js cookie for this request.
	 *
	 * @return bool
	 */
	public static function may_read_posthog_cookie() {
		$has_consent = function_exists( 'wp_has_consent' ) ? 'wp_has_consent' : null;

		return self::cookie_read_allowed(
			Settings::client(),
			$_COOKIE, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with a fixed string.
			$has_consent,
			self::cookie_name(),
			self::category()
		);
	}

	/**
	 * The rule behind may_read_posthog_cookie(), without WordPress state.
	 *
	 * Allowed when consent is not required. Otherwise the WP Consent API decides
	 * when it is active (it also applies region rules), and without it only an
	 * explicit "allow" in the consent cookie counts.
	 *
	 * @param array<string,mixed> $client      Client settings.
	 * @param array<string,mixed> $cookies     Request cookies.
	 * @param callable|null       $has_consent wp_has_consent, when the Consent API is active.
	 * @param string              $cookie_name The consent cookie name.
	 * @param string              $category    The consent category.
	 * @return bool
	 */
	public static function cookie_read_allowed( array $client, array $cookies, $has_consent, $cookie_name, $category ) {
		if ( ! self::required( $client ) ) {
			return true;
		}

		if ( is_callable( $has_consent ) ) {
			return (bool) call_user_func( $has_consent, $category );
		}

		return isset( $cookies[ $cookie_name ] ) && 'allow' === $cookies[ $cookie_name ];
	}
}
