# Symlink Composer Plugin

A Composer plugin for symlinking packages, individual files, and folders to custom
locations, with package, vendor, and type matching.

Only the root project needs to install and allow this plugin. The packages you
link do not need to require it or change their metadata. Matching works with
ordinary packages and custom package types alike. Packages stay in Composer's
chosen installation location; the plugin makes them accessible at the additional
paths you configure.

Composer installs dependencies, but a web application may need their assets in
its public directory. For example, link Bootstrap's compiled CSS and JavaScript
from its `dist/` folder to `public/vendor/bootstrap` without copying files after
each update.

## Installation

Requires PHP 7.4 or PHP 8.x and Composer 2.

On Windows, PHP needs permission to create symbolic links; an elevated terminal
may be required. After moving a project, follow the
[recovery instructions](#cleaning-up-stale-links) to recreate links for its new
location.

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

### How this differs from custom installers

[`composer/installers`](https://github.com/composer/installers#custom-install-paths)
chooses where whole packages of supported types are installed. This plugin adds
links to installed packages or selected files and folders, using the installation
paths supplied by Composer. It also works with packages already handled by a
custom installer.

Your rules can select a package by name or vendor regardless of its type, or
match a declared custom type such as `acme-widget` with `type:acme-widget`.
There is no list of supported framework types to extend in this plugin.

## Configuration

### Example: serve Bootstrap assets

[Bootstrap supports installation with Composer](https://getbootstrap.com/docs/5.3/getting-started/download/#composer).
To expose only Bootstrap's compiled `dist/` folder, add a `symlink-paths` section
to the `extra` section of your root `composer.json`:

```json
{
    "extra": {
        "symlink-paths": {
            "public/vendor/bootstrap": "path:twbs/bootstrap/dist"
        }
    }
}
```

With the plugin enabled and this configuration in place, install Bootstrap 5:

```bash
composer require twbs/bootstrap:^5.3
```

The link is created automatically during installation. If Bootstrap was already
installed before you added the rule, apply the new configuration immediately with:

```bash
composer symlink-recreate-all
```

With the default Composer vendor directory, the result on Unix is:

```text
public/vendor/bootstrap -> ../../vendor/twbs/bootstrap/dist
```

If `public/` is your web root and your webserver allows serving symlinked assets,
use them in your pages:

```html
<link rel="stylesheet" href="/vendor/bootstrap/css/bootstrap.min.css">
<script src="/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
```

### Example: serve the whole TinyMCE package

TinyMCE's
[Composer guide](https://www.tiny.cloud/docs/tinymce/latest/php-projects/)
asks you to expose the installed editor through a webserver route or copy its
directory into a public folder. To link the whole editor directory, add this
configuration to your root `composer.json`:

```json
{
    "extra": {
        "symlink-paths": {
            "public/vendor/tinymce": "package:tinymce/tinymce"
        }
    }
}
```

Then install TinyMCE; the link is created automatically:

```bash
composer require tinymce/tinymce
```

If TinyMCE is already installed, run `composer symlink-recreate-all` to apply the
rule immediately. With the default vendor directory, the result on Unix is:

```text
public/vendor/tinymce -> ../../vendor/tinymce/tinymce
```

With `public/` as your web root, the editor script is available at
`/vendor/tinymce/tinymce.min.js`, alongside its plugins, skins, and other files.

### Example: share configuration and API contracts

A package maintained by your team could supply an `.editorconfig` file and an
OpenAPI specification. For example, if `acme/team-config` contains `.editorconfig`
and `openapi.yaml` at its package root, expose them at the paths your tools expect:

```json
{
    "extra": {
        "symlink-paths": {
            ".editorconfig": "path:acme/team-config/.editorconfig",
            "docs/openapi.yaml": "path:acme/team-config/openapi.yaml"
        }
    }
}
```

If the rules are in place before installing the package, linking is automatic.
If the package is already installed, run `composer symlink-recreate-all` to apply
the new rules immediately. The links read the installed package's files directly,
so updates need no separate copying step.
Existing handwritten files are preserved and reported as destination conflicts.
You can ignore these generated links in Git alongside your vendor directory.

Check that the consuming tool supports symlinked configuration files. For
example, [Git does not follow symlinked `.gitattributes` files](https://git-scm.com/docs/gitattributes#_notes),
so `.gitattributes` is unsuitable for this pattern.

### Source selectors

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

### Linking whole packages

For an application that discovers modules in a `modules/` directory, use an
exact package name, a vendor selector, or a package-type selector to link entire
installed packages:

```json
{
    "extra": {
        "symlink-paths": {
            "modules/special-widget": "acme/special-widget",
            "modules/acme/{$name}": "vendor:acme",
            "modules/{$package}": "type:acme-module"
        }
    }
}
```

For example, if these three installed packages all declare type `acme-module`,
the rules produce:

| Installed package | Whole-package link |
| --- | --- |
| `acme/special-widget` | `modules/special-widget` |
| `acme/catalog` | `modules/acme/catalog` |
| `other/calendar` | `modules/other/calendar` |

Only the first matching whole-package rule applies to each package. The exact
name takes precedence here, followed by the vendor rule, then the type rule.
Each link points to the package's installation directory; the package stays in
Composer's chosen location. Linking is automatic when these rules are in place
before installation. For packages already installed, use
`composer symlink-recreate-all` to apply the rules immediately.

### Linking individual files and folders

Use `vendor/package/relative/path`, optionally prefixed with `path:`, to link a
specific file or folder from a package:

```json
{
    "extra": {
        "symlink-paths": {
            "public/css/bootstrap.min.css": "path:twbs/bootstrap/dist/css/bootstrap.min.css",
            "public/js/bootstrap": "twbs/bootstrap/dist/js"
        }
    }
}
```

This is an alternative to linking the entire Bootstrap `dist/` folder: it links
one CSS file and the JavaScript folder separately. If you need the CSS source map
for browser debugging, add a rule for `dist/css/bootstrap.min.css.map` too.

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

- Use `vendor:some-vendor` or `type:component` to select whole packages by vendor
  or Composer package type. Destination placeholders such as `public/{$name}`
  give each matching package its own link; see [Path Placeholders](#path-placeholders).
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

### Choosing symlinks for your project

Symlinks give each destination access to the installed source without a separate
copy. Editing a file through a link also changes the installed source. Choose
copies when destinations need independent changes.

When deploying, keep the link targets available and preserve their paths relative
to the links, or package the linked files as real files by dereferencing links.
Check that your hosting environment permits symlinks. Windows uses absolute
targets and needs the [recovery steps below](#cleaning-up-stale-links) after a move.

For public destinations, prefer links to the specific assets you intend to
serve. A whole-package link exposes the package tree to your webserver, including
any configuration, tests, or documentation it contains. PHP's
[`__DIR__` and `__FILE__`](https://www.php.net/manual/en/language.constants.magic.php)
resolve to the source location; check applications that assume they were
physically installed at the destination.

### Recreating all symlinks

Linking is automatic when packages are installed or updated with the rules
already in place. To apply configuration added or changed after a package was
installed, or to recreate existing links manually, use the dedicated command:

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
`<vendor-dir>/composer/symlink-composer-plugin.json`, using your configured vendor
directory. This ownership file lets the plugin identify the links it can safely
remove.

When a package is removed, only its recorded links are considered for deletion.
After a successful package reconfiguration, obsolete destinations for that
package are removed. A full reconciliation also cleans up links belonging to
packages that are no longer installed. Removing all `symlink-paths` rules
cleans up all unchanged recorded links. Parent directories are left in place.

Only unchanged plugin-owned links are removed, including dangling links whose
sources no longer exist. Their targets are preserved. Replaced links, regular
files, directories, unrelated links, and links under redirected parent directories
are left in place. Links created before ownership tracking are left in place
until a successful recreation records them.

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

See [Link ownership internals](docs/link-ownership.md) for the tracking and
platform-specific implementation details.

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

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, tests, and CI details.
