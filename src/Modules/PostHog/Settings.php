<?php
/**
 * PostHog module settings: defaults, typed accessors, and sanitization.
 *
 * Reads and writes this module's slice of the shared option through the
 * platform Options store. Everything PostHog-specific about settings lives here
 * so the rest of the module asks one place for a value.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Connection\Host;
use Tagbridge\Platform\Settings\Options;

/**
 * Settings accessor for the PostHog module.
 */
final class Settings {

	/**
	 * Cached merged settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'project_api_key' => '',
			'region'          => Host::REGION_US,
			'custom_host'     => '',
			'client'          => array(
				'autocapture'       => true,
				'pageviews'         => true,
				'session_recording' => false,
				'person_profiles'   => 'identified_only',
				'cookieless'        => false,
			),
			'identity'        => array(
				'identify_logged_in' => true,
				'send_email'         => true,
				'send_name'          => true,
				'send_role'          => true,
			),
			'server_events'   => array(
				'enabled'                   => true,
				'user_logged_in'            => true,
				'user_registered'           => true,
				'product_viewed'            => true,
				'product_list_viewed'       => true,
				'products_searched'         => true,
				'product_added_to_cart'     => true,
				'product_removed_from_cart' => true,
				'cart_viewed'               => true,
				'coupon_applied'            => true,
				'coupon_removed'            => true,
				'checkout_started'          => true,
				'order_completed'           => true,
				'payment_failed'            => true,
				'order_refunded'            => true,
				'order_cancelled'           => true,
			),
		);
	}

	/**
	 * Get the full, defaults-merged settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored   = Options::module_data( Module::ID );
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $stored );

		// Deep-merge each known sub-array so partial stored data keeps defaults.
		foreach ( array( 'client', 'identity', 'server_events' ) as $group ) {
			$merged[ $group ] = array_merge(
				$defaults[ $group ],
				isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ? $stored[ $group ] : array()
			);
		}

		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Persist a sanitized settings array and clear the request cache.
	 *
	 * @param array<string,mixed> $settings Sanitized settings.
	 * @return void
	 */
	public static function save( array $settings ) {
		Options::save_module_data( Module::ID, $settings );
		self::$cache = null;
	}

	/**
	 * Clear the in-request cache (useful in tests).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * The PostHog project API key (public, safe for the browser).
	 *
	 * @return string
	 */
	public static function project_api_key() {
		return (string) self::all()['project_api_key'];
	}

	/**
	 * The selected region identifier.
	 *
	 * @return string
	 */
	public static function region() {
		return (string) self::all()['region'];
	}

	/**
	 * The raw custom host (only meaningful when region is custom).
	 *
	 * @return string
	 */
	public static function custom_host() {
		return (string) self::all()['custom_host'];
	}

	/**
	 * The resolved base ingestion host URL for the current settings.
	 *
	 * @return string Empty if not resolvable (e.g. invalid custom host).
	 */
	public static function host() {
		return Host::resolve( self::region(), self::custom_host() );
	}

	/**
	 * The client (posthog-js) configuration sub-array.
	 *
	 * @return array<string,mixed>
	 */
	public static function client() {
		return (array) self::all()['client'];
	}

	/**
	 * The identity configuration sub-array.
	 *
	 * @return array<string,mixed>
	 */
	public static function identity() {
		return (array) self::all()['identity'];
	}

	/**
	 * The server-side events configuration sub-array.
	 *
	 * @return array<string,mixed>
	 */
	public static function server_events() {
		return (array) self::all()['server_events'];
	}

	/**
	 * Whether a specific server-side event is enabled (master + per-event).
	 *
	 * @param string $key Event settings key (e.g. 'order_completed').
	 * @return bool
	 */
	public static function is_server_event_enabled( $key ) {
		$events = self::server_events();
		return ! empty( $events['enabled'] ) && ! empty( $events[ $key ] );
	}

	/**
	 * Whether the module has enough to load posthog-js (key + resolvable host).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::project_api_key() && '' !== self::host();
	}

	/**
	 * Sanitize a raw input array (e.g. from $_POST) into clean settings.
	 *
	 * Merges over current settings so a partial form does not wipe other values.
	 *
	 * @param array<string,mixed> $input Raw, untrusted input.
	 * @return array<string,mixed> Sanitized settings ready to save.
	 */
	public static function sanitize( array $input ) {
		$current = self::all();

		// Project API key: keep to a safe character set.
		if ( isset( $input['project_api_key'] ) ) {
			$key                        = sanitize_text_field( wp_unslash( $input['project_api_key'] ) );
			$current['project_api_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
		}

		// Region: must be one of the known regions.
		if ( isset( $input['region'] ) ) {
			$region            = sanitize_text_field( wp_unslash( $input['region'] ) );
			$current['region'] = in_array( $region, Host::regions(), true ) ? $region : Host::REGION_US;
		}

		// Custom host: store the normalized URL form.
		if ( isset( $input['custom_host'] ) ) {
			$raw                    = esc_url_raw( wp_unslash( $input['custom_host'] ) );
			$current['custom_host'] = Host::normalize_url( $raw );
		}

		// Client toggles. Checkboxes are present only when checked.
		$client = $current['client'];
		if ( isset( $input['client'] ) && is_array( $input['client'] ) ) {
			$raw_client                  = wp_unslash( $input['client'] );
			$client['autocapture']       = ! empty( $raw_client['autocapture'] );
			$client['pageviews']         = ! empty( $raw_client['pageviews'] );
			$client['session_recording'] = ! empty( $raw_client['session_recording'] );
			$client['cookieless']        = ! empty( $raw_client['cookieless'] );

			$profiles                  = isset( $raw_client['person_profiles'] )
				? sanitize_text_field( $raw_client['person_profiles'] )
				: 'identified_only';
			$client['person_profiles'] = in_array( $profiles, array( 'identified_only', 'always' ), true )
				? $profiles
				: 'identified_only';
		}
		$current['client'] = $client;

		// Identity toggles. A hidden marker ensures this is processed even when
		// every checkbox is unchecked (unchecked checkboxes are not posted).
		if ( isset( $input['identity'] ) && is_array( $input['identity'] ) ) {
			$raw_identity                   = wp_unslash( $input['identity'] );
			$identity                       = $current['identity'];
			$identity['identify_logged_in'] = ! empty( $raw_identity['identify_logged_in'] );
			$identity['send_email']         = ! empty( $raw_identity['send_email'] );
			$identity['send_name']          = ! empty( $raw_identity['send_name'] );
			$identity['send_role']          = ! empty( $raw_identity['send_role'] );
			$current['identity']            = $identity;
		}

		// Server-side event toggles (master + per-event). Hidden marker as above.
		if ( isset( $input['server_events'] ) && is_array( $input['server_events'] ) ) {
			$raw_server = wp_unslash( $input['server_events'] );
			$server     = $current['server_events'];
			foreach ( array_keys( $server ) as $event_key ) {
				$server[ $event_key ] = ! empty( $raw_server[ $event_key ] );
			}
			$current['server_events'] = $server;
		}

		return $current;
	}
}
