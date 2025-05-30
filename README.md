# Symlink Composer Plugin

A Composer plugin that creates symlinks for specific packages according to defined criteria.

## Installation

```bash
composer require pieceofcodero/symlink-composer-plugin
```

## Configuration

Add a `symlink-paths` section to the `extra` section of your composer.json:

```json
{
    "extra": {
        "symlink-paths": {
            "public/components/{$name}": ["vendor:some-vendor", "type:component"],
            "public/js/{$name}": "vendor:js-vendor"
        }
    }
}
```

## Usage

The plugin automatically creates symlinks when packages are installed or updated.

### Recreating all symlinks

If you need to recreate all symlinks (for example, after changing the configuration), use the dedicated command:

```bash
composer symlink-recreate-all
```

This command will recreate all symlinks according to your configuration.

## Path Placeholders

The following placeholders are available for target paths:

- `{$name}`: Package name without vendor (e.g., "package" from "vendor/package")
- `{$vendor}`: Vendor name (e.g., "vendor" from "vendor/package")
- `{$package}`: Full package name (e.g., "vendor/package")
