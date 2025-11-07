# WP Composer Sync

A WP-CLI command to scan an existing WordPress site and sync active plugins, themes, and WordPress core as dependencies into `composer.json`.

## Features

- 🔍 **Scans your WordPress installation** for core version, active plugins, themes, and must-use plugins
- 📦 **Resolves packages** from multiple sources:
  - Known premium plugins (e.g., ACF Pro) with their composer repositories
  - Plugins/themes with local `composer.json` files
  - WordPress.org plugins/themes via [Wpackagist](https://wpackagist.org)
  - Existing packages in your `composer.json` (matches by slug)
- 🎯 **Smart WordPress package handling** - Preserves existing `roots/wordpress` or `johnpbloch/wordpress`, defaults to `roots/wordpress` for new projects
- 🔄 **Intelligent version constraints** - Uses major.minor versions (e.g., `^6.7`) and only updates when major version changes
- 🤝 **Interactive matching** - Prompts to confirm matches between installed plugins/themes and existing composer packages
- ✅ **Shows a diff** of proposed changes before updating
- 🤔 **Asks for confirmation** before modifying your `composer.json`
- ⚠️ **Reports unresolvable items** in a table for manual handling

## Installation

1.  Require this package as a development dependency in your project:
    ```bash
    composer require --dev peterhebert/wp-composer-sync
    ```

2.  Tell WP-CLI to load the package by creating or editing `wp-cli.yml` in your project root:
    ```yaml
    require:
      - vendor/peterhebert/wp-composer-sync
    ```

## Usage

From your project's root directory (where `composer.json` exists), run:

```bash
wp composer sync
```

The command will:

1. Scan WordPress core, active plugins, themes, and MU-plugins
2. Attempt to resolve each to a Composer package
3. Check for matches against existing packages in `composer.json`
4. Prompt for confirmation on potential matches
5. Display proposed changes to `composer.json`
6. Ask for confirmation
7. Update `composer.json` if confirmed
8. Report any items that couldn't be resolved

## Version Constraint Behavior

The command uses **intelligent version constraints** to minimize unnecessary updates:

- **Format**: Uses caret constraints with major.minor versions (e.g., `^6.7` instead of `^6.7.1`)
- **Updates**: Only modifies `composer.json` when the major version changes
- **Examples**:
  - Existing: `^5.3`, Installed: `5.5.1` → No update (same major version)
  - Existing: `^5.3`, Installed: `6.0.1` → Updates to `^6.0` (major version changed)
- **MU-plugins**: Use exact versions (e.g., `1.2`) instead of caret constraints

## Package Matching

For plugins/themes that can't be resolved automatically, the command attempts to match them against existing packages in your `composer.json`:

- Matches by comparing the plugin/theme slug with the package name after the vendor prefix
- Example: Plugin slug `searchwp` matches package `searchwp/searchwp`
- Prompts for confirmation before adding matches
- Works for regular plugins, themes, and single-file MU-plugins
- Shows unmatched items in a table with their slugs for manual handling

## Package Resolution Order

For each plugin/theme, the command attempts resolution in this order:

1. **Known premium plugins** - Checks a built-in map of popular premium plugins (e.g., Advanced Custom Fields Pro)
2. **Local composer.json** - Looks for a `composer.json` file in the plugin/theme directory
3. **WordPress.org** - Queries the WordPress.org API and uses Wpackagist if found
4. **Not found** - Added to the "unresolved" report for manual handling

## Example Output

```text
Scanning WordPress Core...
Scanning active plugins...
Scanning Must-Use plugins...
Scanning active theme...

Potential match found:
  plugin: SearchWP (v4.5.1)
  Package: searchwp/searchwp
Use this package? [y/N] y

The following changes are proposed for composer.json:
--- Requirements ---
ADD:    roots/wordpress: ^6.4
ADD:    wpackagist-plugin/akismet: ^5.3
ADD:    wpackagist-theme/twentytwentyfour: ^1.0
ADD:    advanced-custom-fields/advanced-custom-fields-pro: ^6.2
MODIFY: searchwp/searchwp: ^3.8 -> ^4.5
--- Repositories ---
ADD:    Repository at https://wpackagist.org
ADD:    Repository at https://connect.advancedcustomfields.com

Apply these changes? [y/n] y
```

## Adding Custom Premium Plugins

To add support for additional premium plugins, edit the `check_known_pro_repos()` method in `src/Composer_Sync_Command.php`:

```php
private function check_known_pro_repos( $name ) {
    $known_repos = [
        'Advanced Custom Fields Pro' => [
            'package'    => 'advanced-custom-fields/advanced-custom-fields-pro',
            'repository' => [
                'type' => 'composer',
                'url'  => 'https://connect.advancedcustomfields.com',
            ],
        ],
        'Your Plugin Name' => [
            'package'    => 'vendor/package-name',
            'repository' => [
                'type' => 'composer',
                'url'  => 'https://your-plugin-repo.com',
            ],
        ],
    ];
    return $known_repos[ $name ] ?? null;
}
```

## Requirements

- PHP 7.4 or higher
- WP-CLI 2.0 or higher
- Composer

## License

GPL-2.0-or-later
