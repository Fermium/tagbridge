<?php
/**
 * PostHog integration module.
 *
 * The single entry point for the PostHog integration. It declares the module's
 * identity, boots its runtime (front-end snippet and server-side listeners) only
 * when enabled, and supplies its settings panel to the admin shell. Adding a new
 * integration later means adding a sibling module; none of this class changes.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Connection\Host;
use Tagbridge\Modules\PostHog\Connection\Validator;
use Tagbridge\Modules\PostHog\Events\Dispatcher;
use Tagbridge\Modules\PostHog\Frontend\Enqueue;
use Tagbridge\Modules\PostHog\Listeners\CoreEvents;
use Tagbridge\Modules\PostHog\Listeners\WooEvents;
use Tagbridge\Platform\Modules\ConfigurableModule;

/**
 * The PostHog integration module.
 */
final class Module implements ConfigurableModule {

	/**
	 * Stable module id. Used as the settings key and the input field prefix.
	 *
	 * @var string
	 */
	const ID = 'posthog';

	/**
	 * The module id.
	 *
	 * @return string
	 */
	public function id() {
		return self::ID;
	}

	/**
	 * The human-readable module name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'PostHog', 'tagbridge' );
	}

	/**
	 * A one-line description for the integrations list.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send pageviews, autocapture, and server-side events to PostHog.', 'tagbridge' );
	}

	/**
	 * A Dashicons class used as the module's icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'dashicons-chart-bar';
	}

	/**
	 * Wire the module's runtime hooks. Only called when the module is enabled.
	 *
	 * @return void
	 */
	public function boot() {
		// Front-end posthog-js injection.
		( new Enqueue() )->register();

		// Server-side event capture (core + WooCommerce when active).
		$dispatcher = new Dispatcher();
		( new CoreEvents( $dispatcher ) )->register();
		( new WooEvents( $dispatcher ) )->register();

		// Install PostHog's PHP error handlers early when server-side error tracking
		// is enabled, so uncaught errors from here on are captured.
		if ( ! empty( Settings::error_tracking()['php'] ) ) {
			$dispatcher->boot();
		}
	}

	/**
	 * The module's default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function default_settings() {
		return Settings::defaults();
	}

	/**
	 * Sanitize a raw input array into clean settings.
	 *
	 * @param array<string,mixed> $raw Raw, untrusted input.
	 * @return array<string,mixed> Sanitized settings ready to save.
	 */
	public function sanitize( array $raw ) {
		return Settings::sanitize( $raw );
	}

	/**
	 * Validate sanitized settings before they are persisted.
	 *
	 * Runs a live connection check when a key is present, so a bad key is caught
	 * before it is saved. An empty key is allowed (the module is simply not yet
	 * configured) and saves without a network call.
	 *
	 * @param array<string,mixed> $sanitized Sanitized settings.
	 * @return array{ok:bool,message:string}
	 */
	public function before_save( array $sanitized ) {
		$key  = isset( $sanitized['project_api_key'] ) ? (string) $sanitized['project_api_key'] : '';
		$host = Host::resolve(
			isset( $sanitized['region'] ) ? (string) $sanitized['region'] : '',
			isset( $sanitized['custom_host'] ) ? (string) $sanitized['custom_host'] : ''
		);

		if ( '' === $key ) {
			return array(
				'ok'      => true,
				'message' => __( 'Settings saved.', 'tagbridge' ),
			);
		}

		$result = Validator::validate( $key, $host );
		if ( ! $result['ok'] ) {
			return $result;
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connected and saved. PostHog will start receiving events on your site.', 'tagbridge' ),
		);
	}

	/**
	 * Whether the module has enough configuration to run.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return Settings::is_configured();
	}

	/**
	 * Render the body of the module's settings panel.
	 *
	 * The admin shell owns the page chrome, tab nav, nonce, and save flow; this
	 * renders the tab panels specific to PostHog. Field names are namespaced under
	 * the module id so the shell can route the saved data to this module.
	 *
	 * @return void
	 */
	public function render_settings() {
		( new SettingsPanel( self::ID ) )->render();
	}
}
