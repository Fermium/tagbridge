<?php
/**
 * Contract for modules that expose a settings panel.
 *
 * The admin shell (SettingsPage) owns the page chrome, nonce, and save flow; a
 * configurable module supplies its own defaults, sanitizer, optional pre-save
 * validation, and the body of its settings panel.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

/**
 * A module that stores settings and renders an admin panel.
 */
interface ConfigurableModule extends ModuleInterface {

	/**
	 * The module's default settings array.
	 *
	 * @return array<string,mixed>
	 */
	public function default_settings();

	/**
	 * Sanitize a raw input array (e.g. from $_POST) into clean settings.
	 *
	 * @param array<string,mixed> $raw Raw, untrusted input.
	 * @return array<string,mixed> Sanitized settings ready to save.
	 */
	public function sanitize( array $raw );

	/**
	 * Validate sanitized settings before they are persisted.
	 *
	 * Lets a module (for example) make a live connection check. Returning ok
	 * false aborts the save and shows the message to the user.
	 *
	 * @param array<string,mixed> $sanitized Sanitized settings.
	 * @return array{ok:bool,message:string}
	 */
	public function before_save( array $sanitized );

	/**
	 * Whether the module has enough configuration to run.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Render the body of the module's settings panel.
	 *
	 * @return void
	 */
	public function render_settings();
}
