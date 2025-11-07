<?php
/**
 * PHPUnit bootstrap file
 *
 * @package PeterHebert\WPComposerSync
 */

// 1. Load Composer Autoloader
$autoloader = require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// 2. Mock WP-CLI classes before they are used
// We use 'eval' to ensure they are loaded *before* the real WP_CLI
// This is a common pattern for mocking hard-to-reach static classes.
if ( ! class_exists( 'WP_CLI' ) ) {
    eval( "class WP_CLI {
        public static function __callStatic( \$name, \$arguments ) {
            return \Mockery::mock( 'WP_CLI' )->\$name( ...\$arguments );
        }
    }" );
}

if ( ! class_exists( 'WP_CLI\Utils' ) ) {
    eval( "namespace WP_CLI; class Utils {
        public static function __callStatic( \$name, \$arguments ) {
            return \Mockery::mock( 'WP_CLI_Utils' )->\$name( ...\$arguments );
        }
    }" );
}

// 3. Set up virtual file system
use org\bovigo\vfs\vfsStream;
$vfs = vfsStream::setup( 'root' );

// 4. Define WordPress constants pointing to the virtual file system
define( 'ABSPATH', $vfs->url() . '/wp/' );
define( 'WP_PLUGIN_DIR', $vfs->url() . '/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', $vfs->url() . '/wp-content/mu-plugins' );
define( 'WP_CONTENT_DIR', $vfs->url() . '/wp-content' );

// Manually create the directories
mkdir( $vfs->url() . '/wp-content' );
mkdir( WP_PLUGIN_DIR, 0755, true );
mkdir( WPMU_PLUGIN_DIR, 0755, true );
mkdir( WP_CONTENT_DIR . '/themes', 0755, true );
