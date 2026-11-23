<?php
/**
 * Uninstall WP Menu Export Import.
 *
 * The plugin stores no options, no postmeta and no custom tables — menus
 * imported with it are plain WordPress menus and are left untouched.
 * This file exists so WordPress offers a clean uninstall path.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Nothing to clean up.
