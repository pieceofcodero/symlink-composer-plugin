# Symlink Composer Plugin

A Composer plugin that creates symlinks for specific packages according to defined criteria.

## Installation

Requires PHP 7.4 or PHP 8.x and Composer 2.

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

### Package-provided path overrides

A dependency can override these placeholder values in its own `composer.json`:

```json
{
    "extra": {
        "symlink-preferred-name": "custom-name",
        "symlink-preferred-vendor": "custom-vendor",
        "symlink-preferred-package": "custom-vendor/custom-name"
    }
}
```

Overrides must be relative paths with nonempty segments separated by `/`. Nested
paths are supported. Absolute paths, `.` and `..` segments, backslashes, control
characters, and the characters `<>:"|?*{}` are rejected. Segments cannot end with
a dot or space. Omitted, `null`, or empty-string values use the package's default
name, vendor, or full name. Only overrides used by the destination's placeholders
are validated.

The literal directory before the first placeholder defines the destination
boundary. For example, `public/components/{$package}` keeps generated links inside
`public/components`. Existing parent symlinks cannot redirect a generated path
outside that boundary. The root project may explicitly choose an absolute,
parent-relative, or symlinked base directory.

Invalid overrides or escaping destinations are reported and skipped before
creating directories or replacing links.

## Development

Install development dependencies with `composer install`, then run the PHPUnit
regression suite with `composer test`. To run a subset, pass PHPUnit options
through Composer, for example `composer test -- --filter RejectsInvalidMetadata`.

The suite uses PHPUnit 9.6, which runs on the minimum supported PHP version, 7.4.
Composer resolves dependencies against PHP 7.4.0 so development dependencies
remain compatible with that minimum even when installed using a newer PHP.
