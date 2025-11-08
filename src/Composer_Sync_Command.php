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
                $not_found[] = [ 
                    'name' => $plugin_data['Name'], 
                    'version' => $plugin_data['Version'], 
                    'type' => 'plugin',
                    'slug' => $plugin_data['slug']
                ];
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
                // Single file MU-plugin - store filename without extension for matching
                $filename = pathinfo( $plugin_file, PATHINFO_FILENAME );
                $not_found[] = [
                    'name' => $plugin_data['Name'] . ' (single file)',
                    'version' => $plugin_data['Version'],
                    'type' => 'mu-plugin',
                    'slug' => $filename
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
                $not_found[] = [ 
                    'name' => $plugin_data['Name'], 
                    'version' => $plugin_data['Version'], 
                    'type' => 'mu-plugin',
                    'slug' => $slug
                ];
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
            $not_found[] = [ 
                'name' => $theme_data['Name'], 
                'version' => $theme_data['Version'], 
                'type' => 'theme',
                'slug' => $theme_data['slug']
            ];
        }

        // --- 7. Collate & Merge Results ---
        if ( $this->wpackagist_used ) {
            $new_repositories['https://wpackagist.org'] = [
                'type' => 'composer',
                'url'  => 'https://wpackagist.org',
            ];
        }

        // --- 7.5. Try to match unresolved items against existing composer.json packages ---
        $still_not_found = [];
        foreach ( $not_found as $item ) {
            $matched = $this->try_match_existing_package( $item, $composer_json );
            
            if ( $matched ) {
                $new_requires[ $matched['package'] ] = $matched['version'];
            } else {
                $still_not_found[] = $item;
            }
        }
        $not_found = $still_not_found;

        $final_json = $composer_json;
        
        // Merge requires, but preserve existing constraints if they satisfy the new version
        // Also check require-dev to avoid duplicates
        $merged_requires = $composer_json['require'] ?? [];
        $existing_require_dev = $composer_json['require-dev'] ?? [];
        
        foreach ( $new_requires as $package => $new_version ) {
            // Check if package is already in require-dev - if so, skip it
            if ( isset( $existing_require_dev[ $package ] ) ) {
                continue;
            }
            
            if ( isset( $merged_requires[ $package ] ) ) {
                // Package exists in require - only update if existing constraint doesn't satisfy
                if ( ! $this->constraint_satisfies( $merged_requires[ $package ], $new_version ) ) {
                    $merged_requires[ $package ] = $new_version;
                }
                // Otherwise keep the existing constraint
            } else {
                // New package - add it to require
                $merged_requires[ $package ] = $new_version;
            }
        }
        
        $final_json['require'] = $merged_requires;

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
            Utils\format_items( 'table', $not_found, [ 'name', 'version', 'type', 'slug' ] );
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
        $pro_repo = $this->check_known_pro_repos( $name, $slug );
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
     * @param string $slug The directory slug of the plugin/theme.
     * @return array|null Repository config if found, null otherwise.
     */
    private function check_known_pro_repos( $name, $slug = null ) {
        // Try to load from external manifest (custom first, then .default.json)
        $base_dir = dirname( __DIR__ );
        $manifest_file = null;
        
        if ( file_exists( $base_dir . '/pro-plugins.json' ) ) {
            $manifest_file = $base_dir . '/pro-plugins.json';
        } elseif ( file_exists( $base_dir . '/pro-plugins.default.json' ) ) {
            $manifest_file = $base_dir . '/pro-plugins.default.json';
        }
        
        if ( $manifest_file ) {
            $manifest_data = json_decode( file_get_contents( $manifest_file ), true );
            if ( $manifest_data && isset( $manifest_data['repositories'] ) ) {
                return $this->match_plugin_to_repo( $name, $slug, $manifest_data['repositories'] );
            }
        }
        
        // Fallback to minimal hardcoded repos if no manifest
        $known_repos = [
            [
                'url' => 'https://connect.advancedcustomfields.com',
                'type' => 'composer',
                'plugins' => [
                    'Advanced Custom Fields Pro' => 'advanced-custom-fields/advanced-custom-fields-pro',
                ],
            ],
        ];

        return $this->match_plugin_to_repo( $name, $slug, $known_repos );
    }

    /**
     * Match a plugin name to a repository and return package info.
     *
     * @param string $plugin_name The display name of the plugin.
     * @param string $plugin_slug The directory slug of the plugin.
     * @param array  $repositories Array of repository configurations with plugins.
     * @return array|null Repository config with package if found, null otherwise.
     */
    private function match_plugin_to_repo( $plugin_name, $plugin_slug, $repositories ) {
        foreach ( $repositories as $repo ) {
            if ( ! isset( $repo['plugins'] ) || ! is_array( $repo['plugins'] ) ) {
                continue;
            }
            
            foreach ( $repo['plugins'] as $name => $package_info ) {
                $package = null;
                $expected_slug = null;
                
                // Handle both formats: simple string or object with package/slug
                if ( is_string( $package_info ) ) {
                    // Simple format: "Plugin Name": "vendor/package"
                    $package = $package_info;
                } elseif ( is_array( $package_info ) && isset( $package_info['package'] ) ) {
                    // Extended format: "Plugin Name": {"package": "vendor/package", "slug": "actual-slug"}
                    $package = $package_info['package'];
                    $expected_slug = $package_info['slug'] ?? null;
                } else {
                    continue;
                }
                
                // Try to match by name first
                if ( $name === $plugin_name ) {
                    return [
                        'package' => $package,
                        'repository' => [
                            'type' => $repo['type'] ?? 'composer',
                            'url' => $repo['url'],
                        ],
                    ];
                }
                
                // If name didn't match but slug is provided, try matching by slug
                if ( $plugin_slug && $expected_slug && $plugin_slug === $expected_slug ) {
                    return [
                        'package' => $package,
                        'repository' => [
                            'type' => $repo['type'] ?? 'composer',
                            'url' => $repo['url'],
                        ],
                    ];
                }
            }
        }
        
        return null;
    }

    /**
     * Try to match an unresolved plugin/theme against existing packages in composer.json.
     * 
     * Looks for packages where the slug matches the package name after the vendor prefix.
     * For example: slug 'searchwp' would match package 'searchwp/searchwp'.
     * 
     * @param array $item The unresolved item with 'name', 'version', 'type', and 'slug' keys.
     * @param array $composer_json The current composer.json data.
     * @return array|null Array with 'package' and 'version' if matched and confirmed, null otherwise.
     */
    private function try_match_existing_package( $item, $composer_json ) {
        // Get the slug - need to look it up from the scan data
        // For now, we'll need to pass slug through the not_found array
        if ( ! isset( $item['slug'] ) ) {
            return null;
        }
        
        $slug = $item['slug'];
        $type = $item['type'];
        
        // Search through both 'require' and 'require-dev' for potential matches
        $all_packages = array_merge(
            $composer_json['require'] ?? [],
            $composer_json['require-dev'] ?? []
        );
        
        $potential_matches = [];
        
        foreach ( $all_packages as $package => $version ) {
            // Skip WordPress core packages
            if ( in_array( $package, [ 'roots/wordpress', 'johnpbloch/wordpress' ], true ) ) {
                continue;
            }
            
            // Extract package name after vendor (e.g., 'searchwp/searchwp' -> 'searchwp')
            $parts = explode( '/', $package );
            if ( count( $parts ) === 2 ) {
                $package_name = $parts[1];
                
                // Check if slug matches package name (case-insensitive)
                if ( strtolower( $slug ) === strtolower( $package_name ) ) {
                    $potential_matches[] = $package;
                }
            }
        }
        
        // If we found exactly one match, confirm with user
        if ( count( $potential_matches ) === 1 ) {
            $package = $potential_matches[0];
            
            // Check if this is a single-file MU-plugin (indicated by "(single file)" in the name)
            $is_single_file = strpos( $item['name'], '(single file)' ) !== false;
            
            WP_CLI::log( '' );
            WP_CLI::log( WP_CLI::colorize( "%YPotential match found:%n" ) );
            WP_CLI::log( "  {$type}: {$item['name']} (v{$item['version']})" );
            WP_CLI::log( "  Package: {$package}" );
            if ( $is_single_file ) {
                WP_CLI::log( WP_CLI::colorize( "  %RNote: This is a single-file MU-plugin%n" ) );
            }
            
            // Prompt with 'y' as default (safer to require explicit confirmation for matches)
            fwrite( STDOUT, 'Use this package? [y/N] ' );
            $response = trim( fgets( STDIN ) );
            
            if ( strtolower( $response ) === 'y' || strtolower( $response ) === 'yes' ) {
                // Convert version to major.minor format with caret
                $version_parts = explode( '.', $item['version'] );
                $minor_version = isset( $version_parts[1] ) ? "{$version_parts[0]}.{$version_parts[1]}" : $version_parts[0];
                $version_constraint = ( $type === 'mu-plugin' ) ? $minor_version : "^{$minor_version}";
                
                return [
                    'package' => $package,
                    'version' => $version_constraint,
                ];
            }
            
            // User declined or pressed enter (default)
            return null;
        }
        
        // Multiple matches or no matches - return null to add to not_found
        return null;
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
     * Logic: Only update composer.json if the installed version has a DIFFERENT major version
     * than what the existing constraint allows. Minor/patch updates within the same major
     * version are already handled by the existing constraint.
     *
     * Examples:
     * - Existing: ^5.3, Installed: 5.5.1 → No update needed (^5.3 covers 5.5.x)
     * - Existing: ^5.3, Installed: 6.0.1 → Update to ^6.0 (major version changed)
     * - Existing: ~5.3.0, Installed: 5.5.1 → No update needed (~5.3.0 doesn't cover 5.5, but we're lenient)
     *
     * @param string $existing_constraint The existing version constraint (e.g., "^5.3", "~5.2.3").
     * @param string $new_constraint The new version constraint based on installed (e.g., "^5.5", "^6.0").
     * @return bool True if the existing constraint is sufficient (no update needed).
     */
    private function constraint_satisfies( $existing_constraint, $new_constraint ) {
        // Extract versions
        $existing_version = preg_replace( '/^[^0-9]*/', '', $existing_constraint );
        $new_version = preg_replace( '/^[^0-9]*/', '', $new_constraint );
        
        // Get major versions
        $existing_parts = explode( '.', $existing_version );
        $new_parts = explode( '.', $new_version );
        
        $existing_major = isset( $existing_parts[0] ) ? (int) $existing_parts[0] : 0;
        $new_major = isset( $new_parts[0] ) ? (int) $new_parts[0] : 0;
        
        // If major versions are the same, existing constraint is good enough
        // (Minor/patch updates within same major are fine)
        return $existing_major === $new_major;
    }
}
