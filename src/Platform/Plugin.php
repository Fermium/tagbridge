<?php
/**
 * Main plugin controller.
 *
 * @package Tagbridge
 */

namespace Tagbridge\Platform;

use Tagbridge\Modules\PostHog\Module as PostHogModule;
use Tagbridge\Platform\Admin\Notices;
use Tagbridge\Platform\Admin\SettingsPage;
use Tagbridge\Platform\Modules\Registry;

/**
 * Wires the plugin together on init.
 *
 * Intentionally thin: it builds the module registry, registers the admin shell
 * and notices, and boots only the integration modules the site owner enabled.
 * A disabled module's runtime classes are never loaded.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already run, to guard against double-boot.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * The module registry.
	 *
	 * @var Registry|null
	 */
	private $registry = null;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for the singleton.
	 */
	private function __construct() {}

	/**
	 * The integration module manifest: id => { class, default-enabled }.
	 *
	 * This is the one place new integrations are registered. Only the class name
	 * is referenced here, so the autoloader never touches a module's files until
	 * the registry instantiates it (and it only does that when enabled).
	 *
	 * @return array<string,array{class:string,default:bool}>
	 */
	private function manifest() {
		$manifest = array(
			PostHogModule::ID => array(
				'class'   => PostHogModule::class,
				'default' => true,
			),
		);

		/**
		 * Filter the integration module manifest.
		 *
		 * @param array<string,array{class:string,default:bool}> $manifest Module manifest.
		 */
		return (array) apply_filters( 'tagbridge_module_manifest', $manifest );
	}

	/**
	 * The module registry, built once per request.
	 *
	 * @return Registry
	 */
	public function registry() {
		if ( null === $this->registry ) {
			$this->registry = new Registry( $this->manifest() );
		}
		return $this->registry;
	}

	/**
	 * Initialise the plugin. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$registry = $this->registry();

		// Admin UI (always available so modules can be configured and toggled).
		( new SettingsPage( $registry ) )->register();
		( new Notices( $registry ) )->register();

		// Boot only the enabled integration modules.
		$registry->boot_enabled();
	}

	/**
	 * Activation callback. Kept side-effect-free and safe to re-run.
	 *
	 * @return void
	 */
	public static function activate() {
		// Flush rewrite rules defensively in case future rewrites are added.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
