# WP Composer Sync

A WP-CLI command to scan a WordPress site and sync active plugins and themes as dependencies into `composer.json`.

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

From your project's root directory, simply run:

```bash
wp composer sync
```