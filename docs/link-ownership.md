# Link ownership internals

For cleanup behavior and recovery instructions, see
[Cleaning up stale links](../README.md#cleaning-up-stale-links).

## Ownership records

The plugin records links it successfully creates or recreates in
`<vendor-dir>/composer/symlink-composer-plugin.json`. The configured vendor
directory is used, including custom locations. Each record includes the
canonical destination, package name, exact symlink target, and filesystem
identity. Access to the record is locked, and updates replace the metadata file
atomically.

A link is removed only if its identity and target still match the ownership
record and its parent still resolves to the recorded directory. This also
allows removal of owned dangling links. When an obsolete link has been replaced
or its parent redirected, it is preserved and its ownership record is forgotten.

The record is scoped to the project's absolute directory. Moving or copying a
project requires resetting the ownership file as described in the README.

## Platform differences

Unix links use relative targets. Windows links use absolute targets, including
when an installer places sources on another drive. This is why moving a Windows
project also requires removing old links whose targets point to the previous
location before recreating them.

On Windows, directory symlinks are removed with `rmdir()` and file symlinks with
`unlink()`. Both operations remove the link itself and preserve its target.
Windows path comparisons account for separators, ASCII casing, and short
directory aliases such as `RUNNER~1`.
