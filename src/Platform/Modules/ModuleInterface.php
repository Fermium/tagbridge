<?php
/**
 * Integration module contract.
 *
 * A module is one self-contained integration (PostHog today; Segment, GA4, and
 * others later). The registry only instantiates and boots a module when it is
 * enabled, so a disabled module's runtime code is never loaded.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

/**
 * The lifecycle contract every integration module implements.
 */
interface ModuleInterface {

	/**
	 * The stable, unique module id (used as the settings key and slug).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * The human-readable module name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * A one-line description shown in the integrations list.
	 *
	 * @return string
	 */
	public function description();

	/**
	 * A Dashicons class used as the module's icon in the admin.
	 *
	 * @return string
	 */
	public function icon();

	/**
	 * Wire the module's runtime hooks. Only called when the module is enabled.
	 *
	 * @return void
	 */
	public function boot();
}
