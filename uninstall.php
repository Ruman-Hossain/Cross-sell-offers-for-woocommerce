<?php
/**
 * Runs when the plugin is DELETED (not merely deactivated).
 * Removes only its own two option rows. Orders and products are untouched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cso_offers' );
delete_option( 'cso_enabled' );
