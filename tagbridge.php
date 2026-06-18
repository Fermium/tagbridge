<?php
/**
 * Plugin Name:       Tagbridge – Deep Integration for PostHog
 * Plugin URI:        https://github.com/0xdidi/tagbridge
 * Description:       Independent PostHog integration for WordPress. Connect your project, configure tracking, and send pageviews and events to PostHog. Not affiliated with PostHog.
 * Version:           0.5.0
 * Requires at least: 5.8
 * Requires PHP:      8.2
 * Author:            Great Anthony
 * Author URI:        https://greatanthony.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tagbridge
 * Domain Path:       /languages
 *
 * @package Tagbridge
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Single source of truth for plugin metadata. Prefixed per coding standards.
define( 'TAGBRIDGE_VERSION', '0.5.0' );
define( 'TAGBRIDGE_FILE', __FILE__ );
define( 'TAGBRIDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAGBRIDGE_URL', plugin_dir_url( __FILE__ ) );
define( 'TAGBRIDGE_MIN_PHP', '8.2' );

/**
 * Render an admin notice and bail out when the PHP version is too low.
 *
 * We never fatal on an unsupported host. We show a clear notice and stop loading
 * so the rest of the site keeps working.
 *
 * @return void
 */
function tagbridge_php_version_notice() {
	$message = sprintf(
		/* translators: 1: required PHP version, 2: current PHP version. */
		esc_html__( 'Tagbridge requires PHP %1$s or higher. You are running PHP %2$s. The plugin has been kept inactive to avoid breaking your site.', 'tagbridge' ),
		esc_html( TAGBRIDGE_MIN_PHP ),
		esc_html( PHP_VERSION )
	);
	printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
}

// Guard the PHP version before loading any plugin code that may use newer syntax.
if ( version_compare( PHP_VERSION, TAGBRIDGE_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'tagbridge_php_version_notice' );
	return;
}

/**
 * Lightweight PSR-4 style autoloader for the plugin's own classes.
 *
 * Maps the Tagbridge\ namespace to the src/ directory. This keeps our classes
 * loading without a Composer dump step on every change. Third-party libraries
 * (posthog-php) are loaded from the vendored Composer autoloader below.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function tagbridge_autoload( $class_name ) {
	$prefix   = 'Tagbridge\\';
	$base_dir = TAGBRIDGE_DIR . 'src/';

	$len = strlen( $prefix );
	if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
		return;
	}

	$relative = substr( $class_name, $len );
	$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require $path;
	}
}
spl_autoload_register( 'tagbridge_autoload' );

// Load vendored third-party libraries (posthog-php) when present.
$tagbridge_vendor_autoload = TAGBRIDGE_DIR . 'vendor/autoload.php';
if ( is_readable( $tagbridge_vendor_autoload ) ) {
	require $tagbridge_vendor_autoload;
}

// Activation, deactivation, and uninstall hooks.
register_activation_hook( __FILE__, array( '\Tagbridge\Platform\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Tagbridge\Platform\Plugin', 'deactivate' ) );

/**
 * Boot the plugin once WordPress and other plugins are loaded.
 *
 * @return void
 */
function tagbridge_boot() {
	\Tagbridge\Platform\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'tagbridge_boot' );
