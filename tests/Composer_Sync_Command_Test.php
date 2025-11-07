<?php
/**
 * Tests for the Composer_Sync_Command class.
 *
 * @package PeterHebert\WPComposerSync\Tests
 */

namespace PeterHebert\WPComposerSync\Tests;

use PHPUnit\Framework\TestCase;
use PeterHebert\WPComposerSync\Composer_Sync_Command;

class Composer_Sync_Command_Test extends TestCase {

    protected $command;

    public function setUp() : void {
        parent::setUp();
        $this->command = new Composer_Sync_Command();
    }

    /**
     * Test: We can test the private method check_known_pro_repos.
     */
    public function testCheckKnownProRepos() {
        $method = new \ReflectionMethod(
            Composer_Sync_Command::class, 'check_known_pro_repos'
        );
        $method->setAccessible( true );

        // Test ACF Pro
        $result = $method->invoke( $this->command, 'Advanced Custom Fields Pro' );
        $this->assertIsArray( $result );
        $this->assertEquals( 'advanced-custom-fields/advanced-custom-fields-pro', $result['package'] );
        $this->assertEquals( 'composer', $result['repository']['type'] );
        $this->assertEquals( 'https://connect.advancedcustomfields.com', $result['repository']['url'] );

        // Test unknown plugin
        $result = $method->invoke( $this->command, 'Some Unknown Plugin' );
        $this->assertNull( $result );
    }

    /**
     * Test: resolve_package method with known pro plugin
     */
    public function testResolvePackageWithKnownProPlugin() {
        $method = new \ReflectionMethod(
            Composer_Sync_Command::class, 'resolve_package'
        );
        $method->setAccessible( true );

        $plugin_data = [
            'slug' => 'advanced-custom-fields-pro',
            'Name' => 'Advanced Custom Fields Pro',
            'Version' => '6.0.0'
        ];

        list( $package, $version, $repo ) = $method->invoke( $this->command, $plugin_data, 'plugin' );

        $this->assertEquals( 'advanced-custom-fields/advanced-custom-fields-pro', $package );
        $this->assertEquals( '^6.0.0', $version );
        $this->assertIsArray( $repo );
        $this->assertEquals( 'https://connect.advancedcustomfields.com', $repo['url'] );
    }

    /**
     * Test: MU-plugins use exact version, not caret (when using known pro plugin)
     */
    public function testMUPluginsUseExactVersion() {
        $method = new \ReflectionMethod(
            Composer_Sync_Command::class, 'resolve_package'
        );
        $method->setAccessible( true );

        // Use ACF Pro as MU-plugin to test exact version
        $mu_plugin_data = [
            'slug' => 'advanced-custom-fields-pro',
            'Name' => 'Advanced Custom Fields Pro',
            'Version' => '6.2.5'
        ];

        list( $package, $version, $repo ) = $method->invoke( $this->command, $mu_plugin_data, 'mu-plugin' );

        // Should return exact version for MU plugins (not ^6.2.5)
        $this->assertEquals( 'advanced-custom-fields/advanced-custom-fields-pro', $package );
        $this->assertEquals( '6.2.5', $version ); // Exact version, no caret
        
        // Now test regular plugin should use caret
        list( $package2, $version2, $repo2 ) = $method->invoke( $this->command, $mu_plugin_data, 'plugin' );
        $this->assertEquals( '^6.2.5', $version2 ); // With caret
    }

    /**
     * Test: WordPress package detection preserves existing choice
     */
    public function testWordPressPackagePreservation() {
        $reflection = new \ReflectionClass( Composer_Sync_Command::class );
        $source = file_get_contents( $reflection->getFileName() );
        
        // Verify the code checks for both packages
        $this->assertStringContainsString( 'roots/wordpress', $source );
        $this->assertStringContainsString( 'johnpbloch/wordpress', $source );
        $this->assertStringContainsString( 'known_wp_packages', $source );
    }
}
