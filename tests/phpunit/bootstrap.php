<?php
/**
 * Bootstrap for the platform-agnostic unit test suite.
 *
 * These tests exercise Core (and pure helpers) without WordPress. They run on
 * the host PHP via Composer's autoloader. WordPress integration tests use a
 * separate bootstrap that loads the WP test framework inside wp-env.
 *
 * @package Tagbridge\Tests
 */

$tagbridge_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! is_readable( $tagbridge_autoload ) ) {
	// This is a CLI test bootstrap; WordPress (and WP_Filesystem) is not loaded here.
	fwrite( STDERR, "Could not find vendor/autoload.php. Run 'composer install' first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require $tagbridge_autoload;
