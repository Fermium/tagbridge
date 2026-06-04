<?php
/**
 * PostHog host resolution and endpoint helpers.
 *
 * Platform-agnostic. Contains zero WordPress function calls: it takes plain
 * strings in and returns plain strings/bools out, so the future Shopify build
 * reuses it unchanged.
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Connection;

/**
 * Maps a region choice to a PostHog ingestion host and builds endpoint URLs.
 */
final class Host {

	/**
	 * US cloud ingestion host.
	 *
	 * @var string
	 */
	const US = 'https://us.i.posthog.com';

	/**
	 * EU cloud ingestion host.
	 *
	 * @var string
	 */
	const EU = 'https://eu.i.posthog.com';

	/**
	 * Region: US cloud.
	 *
	 * @var string
	 */
	const REGION_US = 'us';

	/**
	 * Region: EU cloud.
	 *
	 * @var string
	 */
	const REGION_EU = 'eu';

	/**
	 * Region: self-hosted / custom host.
	 *
	 * @var string
	 */
	const REGION_CUSTOM = 'custom';

	/**
	 * The set of valid region identifiers.
	 *
	 * @return string[]
	 */
	public static function regions() {
		return array( self::REGION_US, self::REGION_EU, self::REGION_CUSTOM );
	}

	/**
	 * Resolve a region (and optional custom host) to a base ingestion host URL.
	 *
	 * The returned URL never has a trailing slash.
	 *
	 * @param string $region      One of the region constants.
	 * @param string $custom_host Custom host URL, used only when region is custom.
	 * @return string Base host URL, or empty string if region is custom but the
	 *                custom host is not a valid URL.
	 */
	public static function resolve( $region, $custom_host = '' ) {
		switch ( $region ) {
			case self::REGION_EU:
				return self::EU;
			case self::REGION_CUSTOM:
				$normalized = self::normalize_url( $custom_host );
				return self::is_valid_url( $normalized ) ? $normalized : '';
			case self::REGION_US:
			default:
				return self::US;
		}
	}

	/**
	 * Normalize a host URL: trim whitespace and any trailing slashes.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized URL.
	 */
	public static function normalize_url( $url ) {
		return rtrim( trim( (string) $url ), '/' );
	}

	/**
	 * Whether a string is a syntactically valid http(s) URL with a host.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_valid_url( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		// Core must not call WordPress functions, so wp_parse_url() is intentionally
		// avoided here in favor of native parse_url().
		$scheme = parse_url( $url, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$scheme = is_string( $scheme ) ? strtolower( $scheme ) : '';
		return in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * The public, project-token flags endpoint used to validate a key/host.
	 *
	 * This endpoint is read-only and does not ingest any events, so it is safe
	 * to call for validation without polluting analytics data.
	 *
	 * @param string $host Base host URL.
	 * @return string Full endpoint URL.
	 */
	public static function flags_endpoint( $host ) {
		return self::normalize_url( $host ) . '/flags?v=2';
	}
}
