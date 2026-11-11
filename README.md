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

### Linking individual files and folders

Use `path:vendor/package/relative/path` to link a specific file or folder from a
package:

```json
{
    "extra": {
        "symlink-paths": {
            "public/js/widget.js": "path:acme/widget/dist/widget.min.js",
            "public/css/widget.css": "path:acme/widget/dist/widget.css",
            "public/images/widget": "path:acme/widget/assets/images"
        }
    }
}
```

Each key is the exact destination of the symlink, including a filename for a
file link. The first two source segments identify the package; the remainder is
relative to its actual installation directory. Multiple `path:` destinations
can link files or folders from the same package. Destination placeholders and
package-provided path overrides also work with these rules.

Source paths must use `/` separators and nonempty segments. Absolute paths,
`.` and `..` segments, backslashes, control characters, and `<>:"|?*{}` are
rejected. Segments cannot end with a dot or space. Sources are literal paths;
glob patterns and source placeholders are not supported. The selected source
must resolve to a regular file or directory inside the package. Source symlinks
are allowed when they resolve within the package, including packages installed
through a symlinked path repository.

### Matching and precedence

- Whole-package criteria are an exact package name, `vendor:vendor`, or
  `type:type`. Only the first matching whole-package destination is processed
  for each package.
- Every destination whose matching criterion is `path:` is processed, alongside
  the first whole-package match.
- An array of criteria uses OR matching. The first matching criterion selects
  the source for that destination. If that source is missing, the rule reports
  an error without trying a later criterion.
- Valid rules for packages that are not installed are skipped.

For example, alternative packages can supply a file for the same destination:

```json
{
    "extra": {
        "symlink-paths": {
            "public/widget.js": [
                "path:acme/widget/dist/widget.js",
                "path:other/widget/dist/widget.js"
            ]
        }
    }
}
```

If both packages are installed, the destination collision is reported using the
same rules as other symlink destinations.

## Usage

The plugin automatically creates symlinks when packages are installed or updated.

Source directories come from Composer's installation manager. Custom installer
locations, custom vendor directories, legacy `target-dir` values, and symlinked
path-repository packages are supported without extra plugin configuration.
Matched packages without an installation directory, including metapackages,
are reported as errors. Missing directories and installer lookup failures are
also reported as errors while remaining packages continue to be processed.

### Recreating all symlinks

If you need to recreate all symlinks (for example, after changing the configuration), use the dedicated command:

```bash
composer symlink-recreate-all
```

This command will recreate all symlinks according to your configuration.

The command returns exit code `0` when all requested links succeed or there is
nothing to do. It returns `1` if any requested link fails, including invalid
paths, missing source directories, destination conflicts, or filesystem errors.
Failures are reported while the remaining packages continue to be processed.
Other `path:` destinations for the same package are also processed after a
failure. Malformed configuration is reported even when no packages are installed;
an invalid destination rule is skipped while other valid rules are processed.

### Destination collisions

If multiple packages resolve to the same destination, the first package to
successfully create a link keeps it. Later conflicting packages are skipped with
an error identifying both packages and the destination. This includes collisions
caused by preferred-name overrides or equivalent paths through different parent
symlinks, absolute paths, or dot segments.

For example, `acme/widget` and `other/widget` collide at `public/{$name}`. Use
`public/{$package}` to give them separate destinations.

Existing symlinks are recreated only when they already resolve to the expected
source file or directory. Links pointing elsewhere, including dangling links, are
preserved and reported as conflicts. To intentionally change a destination's
source, remove the conflicting link or choose another destination. Existing
regular files, directories, and special files are also preserved and reported
as errors.

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
