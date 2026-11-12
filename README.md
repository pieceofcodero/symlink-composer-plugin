# Symlink Composer Plugin

A Composer plugin that creates symlinks for specific packages according to defined criteria.

## Installation

Requires PHP 7.4 or PHP 8.x and Composer 2.

On Windows, PHP needs permission to create symbolic links; an elevated terminal
may be required. Links use absolute targets on Windows, including when an installer
places sources on another drive. Unix links use relative targets. After moving a
Windows project, follow the [recovery instructions](#cleaning-up-stale-links) to
reset ownership tracking and recreate links with updated targets.

```bash
composer require pieceofcodero/symlink-composer-plugin
```

Composer 2.2 and later asks whether to allow this plugin to execute code. Approve
it if you trust it. For noninteractive installs, add this setting to the root
project's `composer.json` before running `composer require` or `composer install`:

```json
{
    "config": {
        "allow-plugins": {
            "pieceofcodero/symlink-composer-plugin": true
        }
    }
}
```

Merge this entry into any existing `config.allow-plugins` settings. Without
approval, a noninteractive install fails. See Composer's
[`allow-plugins` documentation](https://getcomposer.org/doc/06-config.md#allow-plugins).

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

Exact package names and package-relative paths accept optional prefixes:

| Source | Short form | Explicit form |
| --- | --- | --- |
| Whole package | `acme/widget` | `package:acme/widget` |
| File or folder within a package | `acme/widget/dist/widget.js` | `path:acme/widget/dist/widget.js` |

Without a prefix, two slash-separated components identify a package; additional
components identify a path inside that package. Both forms have identical
matching and validation behavior. `package:` accepts only a package name, and
`path:` requires a nonempty relative source path. The `vendor:` and `type:`
prefixes remain required for their selectors; slashes in a `type:` value are
part of that type.

### Linking individual files and folders

Use `vendor/package/relative/path`, optionally prefixed with `path:`, to link a
specific file or folder from a package:

```json
{
    "extra": {
        "symlink-paths": {
            "public/js/widget.js": "path:acme/widget/dist/widget.min.js",
            "public/css/widget.css": "acme/widget/dist/widget.css",
            "public/images/widget": "path:acme/widget/assets/images"
        }
    }
}
```

Each key is the exact destination of the symlink, including a filename for a
file link. The first two source segments identify the package; the remainder is
relative to its actual installation directory. Multiple package-relative path
destinations can link files or folders from the same package. Destination
placeholders and package-provided path overrides also work with these rules.

Source paths must use `/` separators and nonempty segments. Absolute paths,
`.` and `..` segments, backslashes, control characters, and `<>:"|?*{}` are
rejected. Segments cannot end with a dot or space. Sources are literal paths;
glob patterns and source placeholders are not supported. The selected source
must resolve to a regular file or directory inside the package. Source symlinks
are allowed when they resolve within the package, including packages installed
through a symlinked path repository.

### Matching and precedence

- Whole-package criteria are an exact package name (optionally prefixed with
  `package:`), `vendor:vendor`, or `type:type`. Only the first matching
  whole-package destination is processed for each package.
- Every destination whose matching criterion is a package-relative path (with
  or without `path:`) is processed, alongside the first whole-package match.
- An array of criteria uses OR matching. The first matching criterion selects
  the source for that destination. If that source is missing, the rule reports
  an error without trying a later criterion.
- Each destination rule with no installed package matching any of its criteria
  emits one warning identifying the destination and criteria during full
  reconciliation, including `symlink-recreate-all`. This applies to package-relative
  paths, exact package names, `vendor:`, and `type:`, including both optional
  prefix forms. Any matching alternative in an array suppresses the warning.
  Candidates are checked before destination
  precedence; missing source files in matching packages still produce errors.
  Unmatched rules are skipped, and these warnings do not fail the command.

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
It removes unchanged plugin-owned links when packages are uninstalled and
reconciles configuration changes at the end of `composer install` and
`composer update`, even when no packages needed installation or updating.

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

This command recreates all symlinks according to your configuration and cleans
up obsolete plugin-owned links.

The command returns exit code `0` when all requested links succeed or there is
nothing to do. It returns `1` if any requested link fails, including invalid
paths, missing source directories, destination conflicts, ownership metadata
errors, or filesystem errors during creation or cleanup.
Failures are reported while the remaining packages continue to be processed.
Other package-relative path destinations for the same package are also processed
after a failure. Malformed configuration is reported even when no packages are
installed; an invalid destination rule is skipped while other valid rules are
processed.

### Destination collisions

If multiple packages resolve to the same destination, the first package to
successfully create a link keeps it. Later conflicting packages are skipped with
an error identifying both packages and the destination. This includes collisions
caused by preferred-name overrides or equivalent paths through different parent
symlinks, absolute paths, or dot segments.

For example, `acme/widget` and `other/widget` collide at `public/{$name}`. Use
`public/{$package}` to give them separate destinations.

Existing symlinks are recreated when they already resolve to the expected source
file or directory. An unchanged link recorded as owned by the same package can
also be updated to a new source, including when its previous source no longer
exists. Other links pointing elsewhere, including dangling links, are preserved
and reported as conflicts. To intentionally change an unowned destination's
source, remove the conflicting link or choose another destination. Existing
regular files, directories, and special files are also preserved and reported
as errors.

### Cleaning up stale links

The plugin records links it successfully creates or recreates in
`<vendor-dir>/composer/symlink-composer-plugin.json`. The record includes the
canonical destination, package name, exact symlink target, and filesystem
identity. The configured vendor directory is used, including custom locations.
Access to the record is locked, and updates replace the metadata file atomically.

When a package is removed, only its recorded links are considered for deletion.
After a successful package reconfiguration, obsolete destinations for that
package are removed. A full reconciliation also cleans up links belonging to
packages that are no longer installed. Removing all `symlink-paths` rules
cleans up all unchanged recorded links. Parent directories are left in place.

A link is removed only if its identity and target still match the ownership
record and its parent still resolves to the recorded directory. This also
allows removal of owned dangling links. Replaced links, regular files,
directories, unrelated links, and destinations under redirected parents are
preserved; obsolete ownership records for them are forgotten. Links created
before ownership tracking are left in place until a successful recreation
records them.

On Windows, directory symlinks are removed with `rmdir()` and file symlinks with
`unlink()`. Both operations remove the link itself and preserve its target.
Windows path comparisons account for separators, ASCII casing, and short directory
aliases such as `RUNNER~1`.

If any requested link for a package fails, that package's obsolete links are
kept for a retry. Invalid root configuration suppresses obsolete-link cleanup
while valid rules continue to run. Cleanup failures retain the affected records
and cause `symlink-recreate-all` to return `1`. Automatic reconciliation also
reports failures to Composer through an exception.

The ownership file is local project state; do not commit or edit it. Missing
metadata means existing links cannot be identified for cleanup. Invalid,
unwritable, or symlinked metadata files stop processing with an error and preserve
existing destinations. The record is scoped to the project's absolute directory;
after moving or copying a project, remove the old ownership file to reset tracking,
then run `composer symlink-recreate-all`. Unrecorded old links remain untouched.

On Windows, before running that command, also remove the old project symlinks
whose absolute targets still point to the previous location, including dangling
links. Remove only the links themselves, leaving their targets intact. Resetting
ownership metadata does not allow the plugin to replace these links: they are
preserved and reported as conflicts when they point somewhere other than the
expected source in the new location.

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

GitHub Actions runs `composer validate --strict`, installs development dependencies,
checks the actual runtime requirements with `composer check-platform-reqs`, and
runs `composer test` on Linux and Windows with PHP 7.4 and PHP 8.0–8.5. The workflow
runs on pushes and pull requests and can also be started manually. Each PHP version runs
independently on each operating system so a failure does not cancel the other jobs.

The regression suite covers package and individual-path linking, preservation of
existing files and unrelated links, destination collisions, traversal protection,
custom installation paths, invalid configuration, and command failure reporting.
Cleanup tests cover ownership persistence, configuration changes, package removal,
user replacements, redirected parents, corrupt metadata, and retrying failures.
Permission-failure tests require a non-root POSIX user; FIFO tests require the
POSIX extension. These tests are skipped on Windows; the remaining suite runs
on both operating systems. The POSIX extension is requested only for Linux jobs.
Windows fixtures create links to existing targets before making them dangling;
self-referencing fixtures are built by moving a link onto its former target.
Assertions compare filesystem identities so long paths and 8.3 aliases can refer
to the same object. Windows-specific tests also cover path casing, drive roots,
and custom installer sources on another drive when one is available.
