<?php
/**
 * Uninstall cleanup.
 *
 * Runs when the user deletes the plugin from the WordPress admin. Removes the
 * options, user meta, and transients the plugin creates. Multisite-safe.
 *
 * @package Tagbridge
 */

// Bail if WordPress did not invoke this via the uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin data for the current site.
 *
 * @return void
 */
function tagbridge_uninstall_cleanup_site() {
	delete_option( 'tagbridge_settings' );

	// Remove the per-user "setup notice dismissed" flag for every user.
	delete_metadata( 'user', 0, 'tagbridge_setup_notice_dismissed', '', true );

	// Remove any leftover flash transients.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tagbridge\_%' OR option_name LIKE '\_transient\_timeout\_tagbridge\_%'"
	);
}

if ( is_multisite() ) {
	$tagbridge_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $tagbridge_site_ids as $tagbridge_site_id ) {
		switch_to_blog( (int) $tagbridge_site_id );
		tagbridge_uninstall_cleanup_site();
		restore_current_blog();
	}
	unset( $tagbridge_site_ids, $tagbridge_site_id );
} else {
	tagbridge_uninstall_cleanup_site();
}
