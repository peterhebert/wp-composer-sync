<?php
/**
 * Package bootstrap file for WP-CLI.
 *
 * @package PeterHebert\WPComposerSync
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

// Autoload the command class
$autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

// Manually load the command class if autoloader isn't available
if ( ! class_exists( 'PeterHebert\WPComposerSync\Composer_Sync_Command' ) ) {
	require_once __DIR__ . '/src/Composer_Sync_Command.php';
}

// Register the command
WP_CLI::add_command( 'composer sync', 'PeterHebert\WPComposerSync\Composer_Sync_Command' );
