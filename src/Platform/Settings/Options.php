<?php
/**
 * Settings storage for the whole plugin.
 *
 * Holds one option array shared by every module. The top-level "modules" key is
 * an enabled map; each module stores its own settings under its id. Modules read
 * and write their slice through here so storage stays in one place while each
 * module owns the shape of its own data.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Settings;

/**
 * Central, module-agnostic settings accessor.
 */
final class Options {

	/**
	 * Option name for the single plugin settings array.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'tagbridge';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * The full stored settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = $stored;

		return $stored;
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
	 * Whether a module is enabled. Falls back to the supplied default when the
	 * site owner has never toggled it.
	 *
	 * @param string $id      Module id.
	 * @param bool   $fallback Default enabled state when never toggled.
	 * @return bool
	 */
	public static function is_module_enabled( $id, $fallback = false ) {
		$all = self::all();
		if ( isset( $all['modules'] ) && is_array( $all['modules'] ) && array_key_exists( $id, $all['modules'] ) ) {
			return (bool) $all['modules'][ $id ];
		}
		return (bool) $fallback;
	}

	/**
	 * Enable or disable a module.
	 *
	 * @param string $id Module id.
	 * @param bool   $on Whether the module should be enabled.
	 * @return void
	 */
	public static function set_module_enabled( $id, $on ) {
		$all = self::all();
		if ( ! isset( $all['modules'] ) || ! is_array( $all['modules'] ) ) {
			$all['modules'] = array();
		}
		$all['modules'][ $id ] = (bool) $on;
		update_option( self::OPTION_KEY, $all );
		self::$cache = null;
	}

	/**
	 * The raw stored settings for one module (no defaults merged in).
	 *
	 * @param string $id Module id.
	 * @return array<string,mixed>
	 */
	public static function module_data( $id ) {
		$all = self::all();
		return ( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) ? $all[ $id ] : array();
	}

	/**
	 * Persist one module's settings slice.
	 *
	 * @param string              $id   Module id.
	 * @param array<string,mixed> $data Sanitized module settings.
	 * @return void
	 */
	public static function save_module_data( $id, array $data ) {
		$all        = self::all();
		$all[ $id ] = $data;
		update_option( self::OPTION_KEY, $all );
		self::$cache = null;
	}
}
