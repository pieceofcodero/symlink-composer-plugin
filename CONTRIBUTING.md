# Contributing

## Development setup

Install development dependencies with `composer install`, then run the PHPUnit
regression suite with `composer test`. To run a subset, pass PHPUnit options
through Composer, for example `composer test -- --filter RejectsInvalidMetadata`.

The suite uses PHPUnit 9.6, which runs on the minimum supported PHP version, 7.4.
Composer resolves dependencies against PHP 7.4.0 so development dependencies
remain compatible with that minimum even when installed using a newer PHP.

## Continuous integration

GitHub Actions runs `composer validate --strict`, installs development dependencies,
checks the actual runtime requirements with `composer check-platform-reqs`, and
runs `composer test` on Linux and Windows with PHP 7.4 and PHP 8.0–8.5. The workflow
runs on pushes and pull requests and can also be started manually. Each PHP version
runs independently on each operating system so a failure does not cancel the other jobs.

## Regression coverage

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

## Implementation notes

See [Link ownership internals](docs/link-ownership.md) for ownership tracking and
platform-specific filesystem behavior. User-facing cleanup rules and recovery
instructions are in the [README](README.md#cleaning-up-stale-links).
