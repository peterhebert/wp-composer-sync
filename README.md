# WP Composer Sync

A WP-CLI command to scan an existing WordPress site and sync active plugins, themes, and WordPress core as dependencies into `composer.json`.

## Features

- 🔍 **Scans your WordPress installation** for core version, active plugins, themes, and must-use plugins
- 📦 **Resolves packages** from multiple sources:
  - Known premium plugins (e.g., ACF Pro) with their composer repositories
  - Plugins/themes with local `composer.json` files
  - WordPress.org plugins/themes via [Wpackagist](https://wpackagist.org)
- 🎯 **Smart WordPress package handling** - Preserves existing `roots/wordpress` or `johnpbloch/wordpress`, defaults to `roots/wordpress` for new projects
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
3. Display proposed changes to `composer.json`
4. Ask for confirmation
5. Update `composer.json` if confirmed
6. Report any items that couldn't be resolved

## Package Resolution Order

For each plugin/theme, the command attempts resolution in this order:

1. **Known premium plugins** - Checks a built-in map of popular premium plugins (e.g., Advanced Custom Fields Pro)
2. **Local composer.json** - Looks for a `composer.json` file in the plugin/theme directory
3. **WordPress.org** - Queries the WordPress.org API and uses Wpackagist if found
4. **Not found** - Added to the "unresolved" report for manual handling

## Example Output

```
Scanning WordPress Core...
Scanning active plugins...
Scanning Must-Use plugins...
Scanning active theme...

The following changes are proposed for composer.json:
--- Requirements ---
ADD:    roots/wordpress: ^6.4.2
ADD:    wpackagist-plugin/akismet: ^5.3
ADD:    wpackagist-theme/twentytwentyfour: ^1.0
ADD:    advanced-custom-fields/advanced-custom-fields-pro: ^6.2.5
--- Repositories ---
ADD:    Repository at https://wpackagist.org
ADD:    Repository at https://connect.advancedcustomfields.com

Apply these changes? [y/n] 
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
