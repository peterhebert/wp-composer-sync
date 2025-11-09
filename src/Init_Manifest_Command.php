<?php
/**
 * File containing the Init Manifest command class.
 *
 * @package PeterHebert\WPComposerSync
 */

namespace PeterHebert\WPComposerSync;

use WP_CLI;
use WP_CLI_Command;

/**
 * Initializes a custom premium plugin manifest file.
 */
class Init_Manifest_Command extends WP_CLI_Command {

    /**
     * Copy the default premium plugin manifest to your project root for customization.
     *
     * ## OPTIONS
     *
     * [<path>]
     * : Path where to copy the manifest file. Defaults to current directory.
     *
     * ## EXAMPLES
     *
     *     # Copy manifest to current directory
     *     $ wp composer init-manifest
     *
     *     # Copy manifest to specific path
     *     $ wp composer init-manifest /path/to/project
     *
     * @when before_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        $target_dir = isset( $args[0] ) ? rtrim( $args[0], '/' ) : getcwd();
        $target_file = $target_dir . '/repositories.json';
        
        if ( file_exists( $target_file ) ) {
            WP_CLI::error( "File already exists: {$target_file}" );
        }
        
        // Find the default manifest in the package directory
        $package_dir = dirname( __DIR__ );
        $source_file = $package_dir . '/repositories.default.json';
        
        if ( ! file_exists( $source_file ) ) {
            WP_CLI::error( "Default manifest not found: {$source_file}" );
        }
        
        if ( ! is_writable( $target_dir ) ) {
            WP_CLI::error( "Target directory is not writable: {$target_dir}" );
        }
        
        if ( copy( $source_file, $target_file ) ) {
            WP_CLI::success( "Copied manifest to: {$target_file}" );
            WP_CLI::log( 'You can now edit this file to add your premium plugin repositories.' );
        } else {
            WP_CLI::error( "Failed to copy manifest file." );
        }
    }
}
