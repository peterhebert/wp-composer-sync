<?php
/**
 * Package bootstrap file for WP-CLI.
 *
 * @package PeterHebert\WPComposerSync
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

// Load the command class
require_once __DIR__ . '/vendor/autoload.php';

// Register the command
WP_CLI::add_command( 'composer sync', 'PeterHebert\WPComposerSync\Composer_Sync_Command' );
