/**
 * Tagbridge admin script.
 *
 * Dependency-free progressive enhancement:
 *  - enable/disable a whole integration module from its header switch;
 *  - switch between settings tabs (one panel visible at a time);
 *  - show the custom host field only when "self-hosted" is selected;
 *  - dim a section's dependent rows when its master switch is off.
 */
( function () {
	'use strict';

	function initModules() {
		var modules = document.querySelectorAll( '[data-tagbridge-module]' );

		Array.prototype.forEach.call( modules, function ( module ) {
			var toggle = module.querySelector(
				'.tagbridge-module__toggle input[type="checkbox"]'
			);
			if ( ! toggle ) {
				return;
			}

			function sync() {
				module.classList.toggle( 'is-disabled', ! toggle.checked );
			}

			toggle.addEventListener( 'change', sync );
			sync();
		} );
	}

	function initTabs() {
		var tablists = document.querySelectorAll( '.tagbridge-tabs' );

		Array.prototype.forEach.call( tablists, function ( tablist ) {
			var tabs = Array.prototype.slice.call(
				tablist.querySelectorAll( '[role="tab"]' )
			);
			if ( ! tabs.length ) {
				return;
			}

			// Scope panels to the module this tablist belongs to, so multiple
			// modules on one page do not toggle each other's panels.
			var scope = tablist.closest( '[data-tagbridge-module]' ) || document;

			function panelFor( tab ) {
				return scope.querySelector(
					'#' + tab.getAttribute( 'aria-controls' )
				);
			}

			function select( tab, setFocus ) {
				tabs.forEach( function ( other ) {
					var selected = other === tab;
					other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
					other.setAttribute( 'tabindex', selected ? '0' : '-1' );
					other.classList.toggle( 'is-active', selected );

					var panel = panelFor( other );
					if ( panel ) {
						panel.hidden = ! selected;
						panel.classList.toggle( 'is-active', selected );
					}
				} );

				if ( setFocus ) {
					tab.focus();
				}
			}

			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'click', function () {
					select( tab, false );
				} );

				tab.addEventListener( 'keydown', function ( event ) {
					var next;
					switch ( event.key ) {
						case 'ArrowRight':
						case 'ArrowDown':
							next = tabs[ ( index + 1 ) % tabs.length ];
							break;
						case 'ArrowLeft':
						case 'ArrowUp':
							next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
							break;
						case 'Home':
							next = tabs[ 0 ];
							break;
						case 'End':
							next = tabs[ tabs.length - 1 ];
							break;
						default:
							return;
					}
					event.preventDefault();
					select( next, true );
				} );
			} );
		} );
	}

	function initRegion() {
		var region = document.querySelector( '[data-tagbridge-region]' );
		var customHost = document.querySelector( '[data-tagbridge-custom-host]' );

		if ( ! region || ! customHost ) {
			return;
		}

		function sync() {
			customHost.hidden = 'custom' !== region.value;
		}

		region.addEventListener( 'change', sync );
		sync();
	}

	function initMasters() {
		var masters = document.querySelectorAll( '[data-hp-master]' );

		Array.prototype.forEach.call( masters, function ( master ) {
			var key = master.getAttribute( 'data-hp-master' );
			var body = document.querySelector( '[data-hp-dependent="' + key + '"]' );
			var input = master.querySelector( 'input[type="checkbox"]' );

			if ( ! body || ! input ) {
				return;
			}

			function sync() {
				body.classList.toggle( 'is-muted', ! input.checked );
			}

			input.addEventListener( 'change', sync );
			sync();
		} );
	}

	function init() {
		initModules();
		initTabs();
		initRegion();
		initMasters();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
