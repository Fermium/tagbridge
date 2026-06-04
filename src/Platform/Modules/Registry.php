<?php
/**
 * Module registry.
 *
 * Holds the list of available integration modules and is the single place that
 * decides which ones load. Enabled state is read without instantiating a module,
 * so a disabled module's class file is never touched on a normal request; only
 * enabled modules are instantiated and booted.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Modules;

use Tagbridge\Platform\Settings\Options;

/**
 * Registers, resolves, and boots integration modules.
 */
final class Registry {

	/**
	 * Module manifest, keyed by id: array{ class:class-string, default:bool }.
	 *
	 * @var array<string,array{class:string,default:bool}>
	 */
	private $manifest;

	/**
	 * Instantiated modules, keyed by id (lazy).
	 *
	 * @var array<string,ModuleInterface>
	 */
	private $instances = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,array{class:string,default:bool}> $manifest Module manifest.
	 */
	public function __construct( array $manifest ) {
		$this->manifest = $manifest;
	}

	/**
	 * All known module ids, in registration order.
	 *
	 * @return string[]
	 */
	public function ids() {
		return array_keys( $this->manifest );
	}

	/**
	 * Whether a module is enabled, without instantiating it.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public function is_enabled( $id ) {
		if ( ! isset( $this->manifest[ $id ] ) ) {
			return false;
		}
		return Options::is_module_enabled( $id, (bool) $this->manifest[ $id ]['default'] );
	}

	/**
	 * Get (and lazily instantiate) a module by id.
	 *
	 * @param string $id Module id.
	 * @return ModuleInterface|null
	 */
	public function get( $id ) {
		if ( ! isset( $this->manifest[ $id ] ) ) {
			return null;
		}
		if ( ! isset( $this->instances[ $id ] ) ) {
			$class                    = $this->manifest[ $id ]['class'];
			$this->instances[ $id ] = new $class();
		}
		return $this->instances[ $id ];
	}

	/**
	 * Every module instance, in registration order (instantiates all; admin use).
	 *
	 * @return ModuleInterface[]
	 */
	public function all() {
		$modules = array();
		foreach ( $this->ids() as $id ) {
			$modules[ $id ] = $this->get( $id );
		}
		return $modules;
	}

	/**
	 * Boot every enabled module. Disabled modules are never instantiated here.
	 *
	 * @return void
	 */
	public function boot_enabled() {
		foreach ( $this->ids() as $id ) {
			if ( $this->is_enabled( $id ) ) {
				$this->get( $id )->boot();
			}
		}
	}
}
