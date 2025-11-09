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

// Manually load the command classes if autoloader isn't available
if ( ! class_exists( 'PeterHebert\WPComposerSync\Composer_Sync_Command' ) ) {
	require_once __DIR__ . '/src/Composer_Sync_Command.php';
}
if ( ! class_exists( 'PeterHebert\WPComposerSync\Init_Manifest_Command' ) ) {
	require_once __DIR__ . '/src/Init_Manifest_Command.php';
}

// Register the commands
WP_CLI::add_command( 'composer sync', 'PeterHebert\WPComposerSync\Composer_Sync_Command' );
WP_CLI::add_command( 'composer init-manifest', 'PeterHebert\WPComposerSync\Init_Manifest_Command' );
