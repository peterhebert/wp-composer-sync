<?php
/**
 * File containing the main WP-CLI command class.
 *
 * @package PeterHebert\WPComposerSync
 */

namespace PeterHebert\WPComposerSync;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

/**
 * Scans the current WP install and merges dependencies into composer.json.
 */
class Composer_Sync_Command extends WP_CLI_Command {

    private $wpackagist_used = false;

    /**
     * Scans the current WP install and merges dependencies into composer.json.
     *
     * @invoke
     */
    public function __invoke( $args, $assoc_args ) {

        // --- 1. Load composer.json ---
        $output_file = 'composer.json';
        if ( ! file_exists( $output_file ) ) {
            WP_CLI::error( "{$output_file} not found. Please run 'composer init' first." );
        }

        $composer_json = json_decode( file_get_contents( $output_file ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_CLI::error( "Unable to parse existing {$output_file}. Aborting." );
        }
        
        $original_json = $composer_json;

        // --- 2. Initialize Buffers ---
        $new_requires = [];
        $new_repositories = []; // Keyed by URL
        $not_found = [];

        // --- 3. Process WordPress Core ---
        WP_CLI::log( 'Scanning WordPress Core...' );
        $wp_version = \get_bloginfo( 'version' );
        
        // Convert to major.minor only
        $version_parts = explode( '.', $wp_version );
        $wp_minor_version = isset( $version_parts[1] ) ? "{$version_parts[0]}.{$version_parts[1]}" : $version_parts[0];
        
        // Check if user already has a WordPress package configured
        $existing_wp_package = null;
        $known_wp_packages = [ 'roots/wordpress', 'johnpbloch/wordpress' ];
        
        foreach ( $known_wp_packages as $wp_package ) {
            if ( isset( $composer_json['require'][ $wp_package ] ) ) {
                $existing_wp_package = $wp_package;
                break;
            }
        }
        
        // Use existing package or default to roots/wordpress
        $wp_package_name = $existing_wp_package ?? 'roots/wordpress';
        $new_requires[ $wp_package_name ] = "^{$wp_minor_version}";

        // --- 4. Process Active Plugins ---
        WP_CLI::log( 'Scanning active plugins...' );
        if ( ! \function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = \get_plugins();
        $active_plugins = \get_option( 'active_plugins' );

        foreach ( $active_plugins as $plugin_file ) {
            if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
                continue;
            }
            $plugin_data = $all_plugins[ $plugin_file ];
            $plugin_data['slug'] = dirname( $plugin_file );

            list( $package, $version, $repo ) = $this->resolve_package( $plugin_data, 'plugin' );

            if ( $package ) {
                $new_requires[ $package ] = $version;
                if ( $repo ) {
                    $new_repositories[ $repo['url'] ] = $repo;
                }
            } else {
                $not_found[] = [ 'name' => $plugin_data['Name'], 'version' => $plugin_data['Version'], 'type' => 'plugin' ];
            }
        }

        // --- 5. Process MU-Plugins (Must-Use) ---
        WP_CLI::log( 'Scanning Must-Use plugins...' );
        if ( ! \function_exists( 'get_mu_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_mu_plugins = \get_mu_plugins();

        foreach ( $all_mu_plugins as $plugin_file => $plugin_data ) {
            $slug = dirname( $plugin_file );

            if ( $slug === '.' ) {
                $not_found[] = [
                    'name' => $plugin_data['Name'] . ' (single file)',
                    'version' => $plugin_data['Version'],
                    'type' => 'mu-plugin'
                ];
                continue;
            }
            
            $plugin_data['slug'] = $slug;

            list( $package, $version, $repo ) = $this->resolve_package( $plugin_data, 'mu-plugin' );

            if ( $package ) {
                $new_requires[ $package ] = $version;
                if ( $repo ) {
                    $new_repositories[ $repo['url'] ] = $repo;
                }
            } else {
                $not_found[] = [ 'name' => $plugin_data['Name'], 'version' => $plugin_data['Version'], 'type' => 'mu-plugin' ];
            }
        }

        // --- 6. Process Active Theme ---
        WP_CLI::log( 'Scanning active theme...' );
        $theme = \wp_get_theme();
        $theme_to_process = $theme->parent() ? $theme->parent() : $theme;
        
        $theme_data = [
            'Name'    => $theme_to_process->get( 'Name' ),
            'Version' => $theme_to_process->get( 'Version' ),
            'slug'    => $theme_to_process->get_stylesheet(),
        ];

        list( $package, $version, $repo ) = $this->resolve_package( $theme_data, 'theme' );
        
        if ( $package ) {
            $new_requires[ $package ] = $version;
            if ( $repo ) {
                $new_repositories[ $repo['url'] ] = $repo;
            }
        } else {
            $not_found[] = [ 'name' => $theme_data['Name'], 'version' => $theme_data['Version'], 'type' => 'theme' ];
        }

        // --- 7. Collate & Merge Results ---
        if ( $this->wpackagist_used ) {
            $new_repositories['https://wpackagist.org'] = [
                'type' => 'composer',
                'url'  => 'https://wpackagist.org',
            ];
        }

        $final_json = $composer_json;
        $final_json['require'] = array_merge(
            $composer_json['require'] ?? [],
            $new_requires
        );

        $existing_repos_map = [];
        foreach ( $composer_json['repositories'] ?? [] as $repo ) {
            if ( isset( $repo['url'] ) ) $existing_repos_map[ $repo['url'] ] = $repo;
        }
        $final_repo_map = array_merge( $existing_repos_map, $new_repositories );
        $final_json['repositories'] = array_values( $final_repo_map );

        // --- 8. Diff and Confirm ---
        $changes_applied = $this->diff_and_confirm( $original_json, $final_json, $output_file );

        // --- 9. Report ---
        if ( $changes_applied ) {
            WP_CLI::success( "Successfully updated {$output_file}." );
        }

        if ( ! empty( $not_found ) ) {
            WP_CLI::warning( 'The following items could not be resolved and were omitted:' );
            Utils\format_items( 'table', $not_found, [ 'name', 'version', 'type' ] );
        }
    }

    /**
     * Tries to resolve a WP plugin, theme, or mu-plugin into a Composer package.
     *
     * @param array  $data {
     * @type string $slug    The plugin/theme directory slug.
     * @type string $Name    Display name.
     * @type string $Version Current version.
     * }
     * @param string $type The package type ('plugin', 'theme', 'mu-plugin').
     * @return array [ $package_name, $version_constraint, $repository_config ]
     */
    private function resolve_package( $data, $type ) {
        $slug = $data['slug'];
        $name = $data['Name'];
        $version = $data['Version'];
        
        // Convert version to major.minor only (e.g., "1.2.3" -> "1.2")
        $version_parts = explode( '.', $version );
        $minor_version = isset( $version_parts[1] ) ? "{$version_parts[0]}.{$version_parts[1]}" : $version_parts[0];
        
        $version_constraint = ( $type === 'mu-plugin' ) ? $minor_version : "^{$minor_version}";

        // --- 1. Check Hard-Coded "Pro" Map ---
        $pro_repo = $this->check_known_pro_repos( $name );
        if ( $pro_repo ) {
            return [ $pro_repo['package'], $version_constraint, $pro_repo['repository'] ];
        }

        // --- 2. Check for a local composer.json ---
        $base_dir = '';
        switch ( $type ) {
            case 'plugin':    $base_dir = WP_PLUGIN_DIR; break;
            case 'theme':     $base_dir = WP_CONTENT_DIR . '/themes'; break;
            case 'mu-plugin': $base_dir = WPMU_PLUGIN_DIR; break;
            default:          return [ null, null, null ];
        }
        
        $local_composer_file = "{$base_dir}/{$slug}/composer.json";
        
        if ( file_exists( $local_composer_file ) ) {
            $composer_data = json_decode( file_get_contents( $local_composer_file ), true );
            if ( $composer_data && ! empty( $composer_data['name'] ) ) {
                return [ $composer_data['name'], $version_constraint, null ];
            }
        }

        // --- 3. Check WP.org API (Wpackagist) ---
        if ( $type === 'plugin' || $type === 'theme' ) {
            $api_url = "https://api.wordpress.org/{$type}s/info/1.0/{$slug}.json";
            $response = \wp_remote_get( $api_url );
            
            if ( ! \is_wp_error( $response ) && \wp_remote_retrieve_response_code( $response ) === 200 ) {
                $this->wpackagist_used = true;
                $vendor = ( $type === 'plugin' ) ? 'wpackagist-plugin' : 'wpackagist-theme';
                $package_name = "{$vendor}/{$slug}";
                
                return [ $package_name, $version_constraint, null ];
            }
        }

        // --- 4. If all fail ---
        return [ null, null, null ];
    }

    /**
     * Helper to check against a map of known private/pro repos.
     *
     * @param string $name The display name of the plugin/theme.
     * @return array|null Repository config if found, null otherwise.
     */
    private function check_known_pro_repos( $name ) {
        $known_repos = [
            'Advanced Custom Fields Pro' => [
                'package'    => 'advanced-custom-fields/advanced-custom-fields-pro',
                'repository' => [
                    'type' => 'composer',
                    'url'  => 'https://connect.advancedcustomfields.com',
                ],
            ],
            // ... Add other common pro plugins here ...
        ];

        return $known_repos[ $name ] ?? null;
    }

    /**
     * Compares the original and final JSON, displays a diff, and asks for user confirmation.
     *
     * @param array  $original_json The composer.json as it was on disk.
     * @param array  $final_json The proposed new composer.json state.
     * @param string $output_file The filename to write to.
     * @return bool True if changes were written, false otherwise.
     */
    private function diff_and_confirm( $original_json, $final_json, $output_file ) {
        $changes = [ 'require' => [], 'repositories' => [] ];

        // Diff 'require'
        $original_requires = $original_json['require'] ?? [];
        foreach ( $final_json['require'] as $package => $version ) {
            if ( ! isset( $original_requires[ $package ] ) ) {
                $changes['require'][] = "ADD:    {$package}: {$version}";
            } elseif ( $original_requires[ $package ] !== $version ) {
                // Check if the existing constraint would satisfy the new version
                if ( ! $this->constraint_satisfies( $original_requires[ $package ], $version ) ) {
                    $changes['require'][] = "MODIFY: {$package}: {$original_requires[$package]} -> {$version}";
                }
            }
        }

        // Diff 'repositories'
        $original_repo_urls = array_column( $original_json['repositories'] ?? [], 'url' );
        foreach ( $final_json['repositories'] as $repo ) {
            if ( ! in_array( $repo['url'], $original_repo_urls, true ) ) {
                $changes['repositories'][] = "ADD:    Repository at {$repo['url']}";
            }
        }

        // Display and Confirm
        if ( empty( $changes['require'] ) && empty( $changes['repositories'] ) ) {
            WP_CLI::log( 'composer.json is already up-to-date. No changes needed.' );
            return false;
        }

        WP_CLI::log( 'The following changes are proposed for composer.json:' );
        
        if ( ! empty( $changes['require'] ) ) {
            WP_CLI::log( WP_CLI::colorize( '%Y--- Requirements ---%n' ) );
            foreach ( $changes['require'] as $line ) WP_CLI::log( $line );
        }
        if ( ! empty( $changes['repositories'] ) ) {
            WP_CLI::log( WP_CLI::colorize( '%Y--- Repositories ---%n' ) );
            foreach ( $changes['repositories'] as $line ) WP_CLI::log( $line );
        }

        try {
            WP_CLI::confirm( 'Apply these changes?' );
        } catch ( \Exception $e ) {
            WP_CLI::log( 'Aborted. No changes were made.' );
            return false;
        }

        // Write File
        $json_output = json_encode( $final_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        file_put_contents( $output_file, $json_output );
        
        return true;
    }

    /**
     * Check if an existing constraint would satisfy a new version requirement.
     * 
     * This checks if the existing constraint is "good enough" compared to what we'd write.
     * We always write caret constraints (^X.Y) for new packages, but existing packages
     * might use other styles (tilde ~, exact, ranges, etc.).
     *
     * @param string $existing_constraint The existing version constraint (e.g., "^1.2", "~1.2.3", ">=1.2").
     * @param string $new_constraint The new version constraint we would write (always ^X.Y format).
     * @return bool True if the existing constraint is sufficient (no update needed).
     */
    private function constraint_satisfies( $existing_constraint, $new_constraint ) {
        // Extract the target version from new constraint (always ^X.Y format)
        $target_version = ltrim( $new_constraint, '^~><=! ' );
        
        // Caret (^) - allows changes that don't modify left-most non-zero digit
        // ^1.2 means >=1.2.0 <2.0.0
        if ( strpos( $existing_constraint, '^' ) === 0 ) {
            $existing_version = ltrim( $existing_constraint, '^' );
            // If existing ^X.Y >= target X.Y, it's satisfied
            return version_compare( $existing_version, $target_version, '>=' );
        }
        
        // Tilde (~) - allows patch-level changes
        // ~1.2 means >=1.2.0 <1.3.0
        // ~1.2.3 means >=1.2.3 <1.3.0
        if ( strpos( $existing_constraint, '~' ) === 0 ) {
            $existing_version = ltrim( $existing_constraint, '~' );
            $existing_parts = explode( '.', $existing_version );
            $target_parts = explode( '.', $target_version );
            
            // Check if major.minor match
            if ( isset( $existing_parts[0], $existing_parts[1], $target_parts[0], $target_parts[1] ) ) {
                if ( $existing_parts[0] === $target_parts[0] && 
                     version_compare( $existing_parts[1], $target_parts[1], '>=' ) ) {
                    return true;
                }
            }
            return false;
        }
        
        // Greater than or equal (>=, >, etc.)
        if ( preg_match( '/^(>=?)\s*(.+)$/', $existing_constraint, $matches ) ) {
            $operator = $matches[1];
            $existing_version = $matches[2];
            
            if ( $operator === '>=' ) {
                // If existing >=X.Y and X.Y >= target, it's satisfied
                return version_compare( $existing_version, $target_version, '<=' );
            } else if ( $operator === '>' ) {
                // If existing >X.Y and X.Y > target, it's satisfied
                return version_compare( $existing_version, $target_version, '<' );
            }
        }
        
        // Exact version match (no prefix)
        if ( preg_match( '/^\d+\.\d+/', $existing_constraint ) && 
             strpos( $existing_constraint, '*' ) === false &&
             strpos( $existing_constraint, ' ' ) === false ) {
            // Exact version - only satisfied if it's >= target
            return version_compare( $existing_constraint, $target_version, '>=' );
        }
        
        // For complex ranges (||, AND, ranges with spaces), wildcards, etc., 
        // we can't easily determine - safer to not modify
        return true;
    }
}
