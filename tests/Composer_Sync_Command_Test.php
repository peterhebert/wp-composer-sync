<?php
/**
 * Tests for the Composer_Sync_Command class.
 *
 * @package PeterHebert\WPComposerSync\Tests
 */

namespace PeterHebert\WPComposerSync\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;
use PeterHebert\WPComposerSync\Composer_Sync_Command;
use org\bovigo\vfs\vfsStream;

class Composer_Sync_Command_Test extends TestCase {

    use Monkey\Monkey;

    /**
     * @var Composer_Sync_Command
     */
    protected $command;

    /**
     * @var \org\bovigo\vfs\vfsStreamDirectory
     */
    protected $vfs;

    public function setUp() : void {
        parent::setUp();
        Monkey\setUp();
        
        // Get the VFS root
        $this->vfs = vfsStream::setup( 'root' );
        
        $this->command = new Composer_Sync_Command();

        // Stub all WP-CLI logging functions
        Monkey\Functions\stubTranslationFunctions();
        Mockery::mock( 'WP_CLI' )
            ->shouldReceive( 'log' )->zeroOrMoreTimes()
            ->shouldReceive( 'success' )->zeroOrMoreTimes()
            ->shouldReceive( 'warning' )->zeroOrMoreTimes()
            ->shouldReceive( 'colorize' )->zeroOrMoreTimes()->andReturnUsing( fn( $str ) => $str );
        
        Mockery::mock( 'WP_CLI_Utils' )
            ->shouldReceive( 'format_items' )->zeroOrMoreTimes();
    }

    public function tearDown() : void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test: The command should error if composer.json is not found.
     */
    public function testInvokeErrorsIfComposerJsonNotFound() {
        // Expect WP_CLI::error to be called
        Mockery::mock( 'WP_CLI' )
            ->shouldReceive( 'error' )
            ->once()
            ->with( Mockery::on( fn( $msg ) => strpos( $msg, 'composer.json not found' ) !== false ) );

        $this->command->__invoke( [], [] );
    }

    /**
     * Test: The command successfully syncs a full site.
     */
    public function testInvokeSyncsSuccessfully() {
        // 1. Setup Virtual File System
        $start_json = json_encode( [
            'name' => 'peterhebert/my-site',
            'require-dev' => [ 'phpunit/phpunit' => '*' ]
        ], JSON_PRETTY_PRINT );

        vfsStream::newFile( 'composer.json' )
            ->at( $this->vfs )
            ->withContent( $start_json );
        
        // 2. Mock WordPress Functions
        Monkey\Functions\expect( 'get_bloginfo' )->with( 'version' )->andReturn( '6.0.0' );

        // Mock Plugins
        $mock_plugins = [
            'akismet/akismet.php' => [ 'Name' => 'Akismet', 'Version' => '5.0' ],
        ];
        Monkey\Functions\expect( 'get_plugins' )->andReturn( $mock_plugins );
        Monkey\Functions\expect( 'get_option' )->with( 'active_plugins' )->andReturn( ['akismet/akismet.php'] );

        // Mock MU-Plugins (single file, should be skipped)
        $mock_mu_plugins = [
            'hello.php' => [ 'Name' => 'Hello', 'Version' => '1.0' ],
        ];
        Monkey\Functions\expect( 'get_mu_plugins' )->andReturn( $mock_mu_plugins );

        // Mock Theme
        $mock_theme = Mockery::mock( 'WP_Theme' );
        $mock_theme->shouldReceive( 'parent' )->andReturn( null );
        $mock_theme->shouldReceive( 'get' )->with( 'Name' )->andReturn( 'Twenty Twenty-Two' );
        $mock_theme->shouldReceive( 'get' )->with( 'Version' )->andReturn( '1.2' );
        $mock_theme->shouldReceive( 'get_stylesheet' )->andReturn( 'twentytwentytwo' );
        Monkey\Functions\expect( 'wp_get_theme' )->andReturn( $mock_theme );

        // 3. Mock API Calls
        Monkey\Functions\expect( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
        
        // Akismet (Plugin)
        Monkey\Functions\expect( 'wp_remote_get' )
            ->once()
            ->with( 'https://api.wordpress.org/plugins/info/1.0/akismet.json' )
            ->andReturn( ['response' => ['code' => 200] ] );
        
        // Twenty Twenty-Two (Theme)
        Monkey\Functions\expect( 'wp_remote_get' )
            ->once()
            ->with( 'https://api.wordpress.org/themes/info/1.0/twentytwentytwo.json' )
            ->andReturn( ['response' => ['code' => 200] ] );

        Monkey\Functions\expect( 'wp_remote_retrieve_response_code' )->twice()->andReturn( 200 );

        // 4. Mock Confirmation
        Mockery::mock( 'WP_CLI' )
            ->shouldReceive( 'confirm' )
            ->once() // It must ask for confirmation
            ->andReturnNull(); // null = 'yes' (no exception)

        // 5. Mock file_put_contents (The main assertion)
        $written_json = null;
        Monkey\Functions\expect( 'file_put_contents' )
            ->once()
            ->with(
                'composer.json',
                Mockery::capture( $written_json )
            );
        
        // 6. Run the command
        $this->command->__invoke( [], [] );

        // 7. Assert the final JSON is correct
        $this->assertNotNull( $written_json );
        $final_data = json_decode( $written_json, true );

        // Check merged 'require'
        $this->assertEquals( 'peterhebert/my-site', $final_data['name'] );
        $this->assertEquals( '*', $final_data['require-dev']['phpunit/phpunit'] ); // Kept old
        $this->assertEquals( '^6.0.0', $final_data['require']['johnpbloch/wordpress'] ); // Added WP
        $this->assertEquals( '^5.0', $final_data['require']['wpackagist-plugin/akismet'] ); // Added plugin
        $this->assertEquals( '^1.2', $final_data['require']['wpackagist-theme/twentytwentytwo'] ); // Added theme

        // Check 'repositories'
        $this->assertCount( 1, $final_data['repositories'] );
        $this->assertEquals( 'https://wpackagist.org', $final_data['repositories'][0]['url'] );
    }
    
    /**
     * Test: The command aborts if the user says 'n'
     */
    public function testInvokeAbortsOnUserNo() {
        // Setup a basic composer.json
        vfsStream::newFile( 'composer.json' )->at( $this->vfs )->withContent( '{}' );

        // Mock just WP Core to trigger a change
        Monkey\Functions\expect( 'get_bloginfo' )->with( 'version' )->andReturn( '6.0.0' );
        Monkey\Functions\expect( 'get_plugins' )->andReturn( [] );
        Monkey\Functions\expect( 'get_option' )->with( 'active_plugins' )->andReturn( [] );
        Monkey\Functions\expect( 'get_mu_plugins' )->andReturn( [] );
        Monkey\Functions\expect( 'wp_get_theme' )->andReturn( Mockery::mock( 'WP_Theme' )->shouldReceive('parent')->andReturn(null)->getMock() );

        // Mock Confirmation to throw an exception (this is how WP_CLI::confirm works)
        Mockery::mock( 'WP_CLI' )
            ->shouldReceive( 'confirm' )
            ->once()
            ->andThrow( new \Exception( 'Command aborted' ) );
        
        // **Crucial Assertion**: file_put_contents should NEVER be called
        Monkey\Functions\expect( 'file_put_contents' )->never();

        // Run the command
        $this->command->__invoke( [], [] );
    }

    /**
     * Test: We can test the private method check_known_pro_repos
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
        $this->assertEquals( 'https://connect.advancedcustomfields.com', $result['repository']['url'] );

        // Test unknown
        $result = $method->invoke( $this->command, 'Some Unknown Plugin' );
        $this->assertNull( $result );
    }
}
