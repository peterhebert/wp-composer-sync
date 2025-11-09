# WP Composer Sync

A WP-CLI command to scan an existing WordPress site and sync active plugins, themes, and WordPress core as dependencies into `composer.json`. 

This tool is meant primarlity for converting your WordPress site to use COmposer for managing dependencies. It can also be used for sites where users can manage their own plugins in the dashboard, but you want to keep your `composer.json` file up to date.

## Features

- 🔍 **Scans your WordPress installation** for core version, active plugins, themes, and must-use plugins
- 📦 **Resolves packages** from multiple sources:
  - WordPress.org plugins/themes via [Wpackagist](https://wpackagist.org)
  - Known premium plugins (e.g., Advanced Custom Fields Pro, Delicious Brains, Gravity Forms, etc) with their own composer repositories
  - Existing packages in your `composer.json` (matches by slug)
- 🎯 **WordPress core package handling** - Preserves existing `roots/wordpress` or `johnpbloch/wordpress`, defaults to `roots/wordpress` for new projects
- 🔄 **Intelligent version constraints** - Uses major.minor versions (e.g., `^6.7`) and only updates your `composer.json` on a major version change
- 🤝 **Interactive matching** - Prompts to confirm matches between installed plugins/themes and existing composer dependencies
- ✅ **Shows a diff** of proposed changes before updating
- 🤔 **Asks for confirmation** before modifying your `composer.json`
- ⚠️ **Reports unresolvable items** in a table for manual handling


## Setting Up Composer for WordPress

If you don't already have a `composer.json` file in your WordPress installation, you'll need to set up Composer for WordPress, before using this WP-CLI command. Here's how:

### 1. Initialize Composer

```bash
composer init --name=vendor-name/project-name --type=project
```

You can read more about [`composer init`](https://getcomposer.org/doc/03-cli.md#init) and  [package naming](https://getcomposer.org/doc/01-basic-usage.md#package-names) in the Composer documentation.

### 2. Add composer/installers

The `composer/installers` package will faciliate installing packages to the correct location based on the specified package type. It has support for WordPress package types: `wordpress-plugin`, `wordpress-theme`, `wordpress-muplugin`, and `wordpress-dropin`.

```bash
composer require composer/installers
```

### 3. Add WPackagist Repository

[WPackagist](https://wpackagist.org) mirrors the WordPress plugin and theme directories as a Composer repository.

```bash
composer config repositories.wpackagist composer https://wpackagist.org
```

### 4. Configure Install Paths

Add the `extra` section to your `composer.json` to specify where WordPress core, plugins, and themes should be installed.

**Example A - WordPress installed in the project root:**

```json
{
  "extra": {
    "wordpress-install-dir": ".",
    "installer-paths": {
      "wp-content/plugins/{$name}/": ["type:wordpress-plugin"],
      "wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"],
      "wp-content/themes/{$name}/": ["type:wordpress-theme"]
    }
  }
}
```

**Example B - A subdirectory `web` as the public web root - based on [Bedrock](https://roots.io/bedrock/):**

```json
{
  "extra": {
    "wordpress-install-dir": "web/wp",
    "installer-paths": {
      "web/app/plugins/{$name}/": ["type:wordpress-plugin"],
      "web/app/mu-plugins/{$name}/": ["type:wordpress-muplugin"],
      "web/app/themes/{$name}/": ["type:wordpress-theme"]
    }
  }
}
```

The `wordpress-install-dir` setting determines where WordPress core will be installed when you manage WordPress core as a dependency via packages like `roots/wordpress` or `johnpbloch/wordpress`.

For more on managing WordPress with Composer, see:

- [Managing Your WordPress Site With Git and Composer](https://deliciousbrains.com/storing-wordpress-in-git/)
- Roots [Bedrock](https://roots.io/bedrock/)

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

Once your `composer.json` is configured, run this command to sync it with your WordPress installation's plugins and themes:

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

### Additional Commands

**Initialize custom manifest for premium plugins:**

```bash
wp composer init-manifest [<path>]
```

Copies the default premium plugin manifest to your project root (or specified path) so you can customize it with your own premium plugin repositories. See [Adding Custom Premium Plugins](#adding-custom-premium-plugins) below.

## Version Constraint Behavior

The command uses **intelligent version constraints** to minimize unnecessary updates:

- **Format**: Uses caret constraints with major.minor versions (e.g., `^6.7` instead of `^6.7.1`)
- **Updates**: Only modifies `composer.json` when the major version changes
- **Examples**:
  - Existing: `^5.3`, Installed: `5.5.1` → No update (same major version)
  - Existing: `^5.3`, Installed: `6.0.1` → Updates to `^6.0` (major version changed)
- **MU-plugins**: Use exact versions (e.g., `1.2`) instead of caret constraints
- **require-dev**: Packages in `require-dev` are preserved and not moved to `require`

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

The package includes a default manifest file (`repositories.default.json`) with commonly used premium package repositories from Advanced Custom Fields, Gravity Forms, and Delicious Brains.

### When to Create a Custom Manifest

You only need to create a custom `repositories.json` if:

- Your premium plugin is not in the default manifest
- You want to add additional repositories or packages
- You need to override the default configuration

### Creating Your Custom Manifest

Use the built-in WP-CLI command to copy the default manifest to your project root:

```bash
wp composer init-manifest
```

This will copy `repositories.default.json` to `repositories.json` in your current directory (or specify a path as an argument).

Once copied, edit `repositories.json` to add your premium plugin repositories:

   ```json
   {
     "repositories": [
       {
         "url": "https://composer.gravityforms.com",
         "type": "composer",
         "packages": {
           "Gravity Forms": "gravity/gravityforms",
           "Gravity Forms Stripe Add-On": "gravity/gravityformsstripe"
         }
       },
       {
         "url": "https://deliciousbrains.com/composer",
         "type": "composer",
         "packages": {
           "WP Offload Media": {
             "package": "deliciousbrains/wp-amazon-s3-and-cloudfront-pro",
             "slug": "amazon-s3-and-cloudfront-pro"
           }
         }
       },
       {
         "url": "https://your-plugin-repo.com",
         "type": "composer",
         "packages": {
           "Your Plugin Name": "vendor/package-name"
         }
       }
     ]
   }
   ```

**Manifest format options:**

- **Simple format**: `"Package Name": "vendor/package"` - Use when package name and slug match
- **Extended format**: `"Package Name": {"package": "vendor/package", "slug": "actual-slug"}` - Use when the directory slug differs from the display name (common with rebranded plugins)

**Benefits of the repository-first structure:**

- No repetition - define the repository URL once for multiple packages
- Easy to add entire product families (Gravity Forms, WP Migrate, etc.)
- Maintainable - edit JSON instead of PHP code

### How the Manifest is Loaded

The command checks for manifests in this priority order:

1. **Project root** - `repositories.json` (your custom manifest)
2. **Package default** - `repositories.default.json` (included with this package)
3. **Hardcoded fallback** - Minimal ACF Pro support if no manifests found

This means your custom manifest completely overrides the default, so make sure to include any default plugins you still need when creating your custom version.

## Requirements

- PHP 7.4 or higher
- WP-CLI 2.0 or higher
- Composer

## License

GPL-2.0-or-later
