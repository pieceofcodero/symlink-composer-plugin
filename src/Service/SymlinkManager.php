<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Service;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

class SymlinkManager
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var array<string, bool> Processing results indexed by package name.
     */
    private $processedPackages = [];

    /**
     * @var array<string, string> Canonical destination paths mapped to package names.
     */
    private $destinationOwners = [];

    /** @var LinkRegistry */
    private $registry;

    /** @var array<string, bool> Destinations successfully requested in the current pass. */
    private $activeDestinations = [];

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->registry = new LinkRegistry($composer->getConfig()->get('vendor-dir'), $io);
    }

    /**
     * Returns true if the package has not been processed yet, and marks it as processed.
     * We ensure that each package is processed only once to avoid redundant symlink creation.
     */
    public function shouldProcessPackage(string $packageName): bool
    {
        if (isset($this->processedPackages[$packageName])) {
            return false;
        }
        $this->processedPackages[$packageName] = true;
        return true;
    }

    /**
     * Create a symlink for a package, returning false if requested work failed.
     */
    public function createSymlinkForPackage(PackageInterface $package): bool
    {
        if (!$this->shouldProcessPackage($package->getName())) {
            return $this->processedPackages[$package->getName()];
        }

        [$symlinkConfig, $valid] = $this->getSymlinkConfig();

        $this->activeDestinations = [];
        $success = false;
        try {
            if ($this->registry->open()) {
                $success = $this->processPackage($package, $symlinkConfig) && $valid;
                if ($success) {
                    $success = $this->registry->cleanup($this->activeDestinations, [], $package->getName());
                }
            }
        } finally {
            $saved = $this->registry->close();
        }
        $this->processedPackages[$package->getName()] = $success && $saved;
        return $this->processedPackages[$package->getName()];
    }

    /**
     * Process every package, returning false if any requested link failed.
     */
    public function createSymlinksForAllPackages(): bool
    {
        // Reset processed packages
        $this->processedPackages = [];
        $this->destinationOwners = [];
        $this->activeDestinations = [];

        [$symlinkConfig, $valid] = $this->getSymlinkConfig();
        $success = $valid;
        try {
            if (!$this->registry->open()) {
                $success = false;
            } elseif (empty($symlinkConfig)) {
                if ($valid) {
                    $success = $this->registry->cleanup([]);
                }
            } else {
                $this->io->write('<info>Recreating all symlinks...</info>');
                $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
                $installed = [];
                foreach ($packages as $package) {
                    $installed[$package->getName()] = true;
                }
                // Removed packages must release destinations before new packages claim them.
                if ($valid && !$this->registry->cleanup([], $installed)) {
                    $success = false;
                }
                $failed = [];
                foreach ($packages as $package) {
                    if (!$this->shouldProcessPackage($package->getName())) {
                        continue;
                    }
                    $result = $this->processPackage($package, $symlinkConfig);
                    $this->processedPackages[$package->getName()] = $result && $valid;
                    if (!$result) {
                        $failed[$package->getName()] = true;
                        $success = false;
                    }
                }
                if ($valid && !$this->registry->cleanup($this->activeDestinations, $failed)) {
                    $success = false;
                }
            }
            if (empty($symlinkConfig) && $valid) {
                $this->io->write('<info>No symlink-paths configuration found.</info>');
            }
        } finally {
            $saved = $this->registry->close();
        }
        return $success && $saved;
    }

    /** Remove only unchanged, recorded links belonging to an uninstalled package. */
    public function removeSymlinksForPackage(PackageInterface $package): bool
    {
        $success = false;
        try {
            if ($this->registry->open()) {
                $success = $this->registry->cleanup([], [], $package->getName());
            }
        } finally {
            $saved = $this->registry->close();
        }
        unset($this->processedPackages[$package->getName()]);
        $this->destinationOwners = array_filter($this->destinationOwners, function ($owner) use ($package): bool {
            return $owner !== $package->getName();
        });
        return $success && $saved;
    }

    /**
     * Validate and normalize criteria before processing packages, including an empty repository.
     *
     * @return array{array, bool} Valid rules and whether the configuration is valid.
     */
    private function getSymlinkConfig(): array
    {
        $extra = $this->composer->getPackage()->getExtra();
        $config = $extra['symlink-paths'] ?? [];
        if (!is_array($config)) {
            $this->io->write('<error>Invalid symlink-paths configuration: expected a map of destinations to criteria.</error>');
            return [[], false];
        }

        $rules = [];
        $valid = true;
        foreach ($config as $targetPath => $criteria) {
            $targetPath = (string) $targetPath;
            if ($targetPath === '' || strpos($targetPath, "\0") !== false) {
                $this->io->write('<error>Invalid symlink destination: expected a nonempty path without NUL bytes.</error>');
                $valid = false;
                continue;
            }

            $parsedCriteria = [];
            $ruleValid = true;
            foreach (is_array($criteria) ? $criteria : [$criteria] as $criterion) {
                $parsed = is_string($criterion) ? $this->parseCriterion($criterion) : null;
                if ($parsed === null) {
                    $kind = is_string($criterion) && strpos($criterion, 'path:') === 0 ? 'path: criterion' : 'criterion';
                    $this->io->write("<error>Invalid $kind for destination $targetPath: expected a package name, vendor:vendor, type:type, or path:vendor/package/relative/path with a safe relative source path.</error>");
                    $ruleValid = false;
                    $valid = false;
                } else {
                    $parsedCriteria[] = $parsed;
                }
            }
            if ($ruleValid) {
                $rules[$targetPath] = $parsedCriteria;
            }
        }

        return [$rules, $valid];
    }

    private function parseCriterion(string $criterion): ?array
    {
        if (strpos($criterion, 'path:') === 0) {
            $path = substr($criterion, 5);
            $parts = explode('/', $path, 3);
            if (count($parts) !== 3 || !$this->isSafeRelativePath($path)
                || !$this->isPackageName($parts[0] . '/' . $parts[1])) {
                return null;
            }
            return ['package' => $parts[0] . '/' . $parts[1], 'path' => $parts[2]];
        }
        if (strpos($criterion, 'vendor:') === 0) {
            return preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', substr($criterion, 7)) ? ['rule' => $criterion] : null;
        }
        if (strpos($criterion, 'type:') === 0) {
            return strlen($criterion) > 5 && strpos($criterion, "\0") === false ? ['rule' => $criterion] : null;
        }

        return $this->isPackageName($criterion) ? ['rule' => $criterion] : null;
    }

    private function isPackageName(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/D', $name);
    }

    private function processPackage(PackageInterface $package, array $symlinkConfig): bool
    {
        $success = true;
        $wholePackageMatched = false;
        foreach ($symlinkConfig as $targetPath => $criteria) {
            $match = $this->getMatchingCriterion($package, $criteria);
            if ($match === null) {
                continue;
            }
            $relativeSource = $match['path'] ?? null;
            if ($relativeSource === null) {
                if ($wholePackageMatched) {
                    continue;
                }
                $wholePackageMatched = true;
            }
            if (!$this->createSymlink($package, (string) $targetPath, $relativeSource)) {
                $success = false;
            }
        }

        return $success;
    }

    private function getMatchingCriterion(PackageInterface $package, array $criteria): ?array
    {
        foreach ($criteria as $criterion) {
            if (isset($criterion['package'])) {
                if ($package->getName() === $criterion['package']) {
                    return $criterion;
                }
            } elseif ($this->satisfiesRule($package, $criterion['rule'])) {
                return $criterion;
            }
        }

        return null;
    }

    private function satisfiesRule(PackageInterface $package, string $criterion): bool
    {
        $packageType = $package->getType();
        $packageName = $package->getName();

        // Match by type (e.g., "type:custom-component").
        if (strpos($criterion, 'type:') === 0) {
            $requiredType = substr($criterion, 5);
            return $packageType === $requiredType;
        }

        // Match by vendor (e.g., "vendor:acme").
        if (strpos($criterion, 'vendor:') === 0) {
            $requiredVendor = substr($criterion, 7);
            return strpos($packageName, $requiredVendor . '/') === 0;
        }

        // Match by exact package name.
        return $packageName === $criterion;
    }

    private function createSymlink(PackageInterface $package, string $targetPath, ?string $relativeSource = null): bool
    {
        $packageName = $package->getName();

        $resolvedTargetPath = $this->resolveTargetPath($package, $targetPath);
        if ($resolvedTargetPath === null) {
            return false;
        }

        try {
            $packagePath = $this->composer->getInstallationManager()->getInstallPath($package);
        } catch (\Exception $exception) {
            $this->io->write("<error>Failed to resolve installation path for $packageName: {$exception->getMessage()}</error>");
            return false;
        }

        if ($packagePath === null || $packagePath === '') {
            $this->io->write("<error>Package $packageName has no installation path; cannot create symlink.</error>");
            return false;
        }

        if (!is_dir($packagePath)) {
            $this->io->write("<error>Package path does not exist: $packagePath.</error>");
            return false;
        }

        $sourcePath = $packagePath;
        if ($relativeSource !== null) {
            $sourcePath = $this->resolveSourcePath($packagePath, $relativeSource);
            if ($sourcePath === null) {
                return false;
            }
        }

        $targetDir = dirname($resolvedTargetPath);
        $canonicalTargetDir = $this->resolveDirectoryPath($targetDir);
        if ($canonicalTargetDir === null) {
            $this->io->write("<error>Failed to resolve target directory: $targetDir.</error>");
            return false;
        }

        // Resolve the parent, not the final symlink, so aliases share a destination key.
        $destinationKey = $this->normalizePathCase(
            rtrim($canonicalTargetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($resolvedTargetPath)
        );
        if (!$this->registry->prepare()) {
            return false;
        }
        if (isset($this->destinationOwners[$destinationKey]) && $this->destinationOwners[$destinationKey] !== $packageName) {
            $owner = $this->destinationOwners[$destinationKey];
            $this->io->write("<error>Symlink collision at $resolvedTargetPath: $packageName conflicts with $owner.</error>");
            return false;
        }
        $recordedOwner = $this->registry->owner($destinationKey);
        if ($recordedOwner !== null && $recordedOwner !== $packageName) {
            $this->io->write("<error>Symlink collision at $resolvedTargetPath: $packageName conflicts with $recordedOwner.</error>");
            return false;
        }

        // Existing links may come from an earlier Composer run or an unrelated owner.
        if (is_link($resolvedTargetPath) && (isset($this->activeDestinations[$destinationKey])
            || !$this->registry->owns($destinationKey, $packageName))) {
            $existingSource = realpath($resolvedTargetPath);
            $expectedSource = realpath($sourcePath);
            if ($existingSource === false || $expectedSource === false
                || $this->normalizePathCase($existingSource) !== $this->normalizePathCase($expectedSource)) {
                $existingTarget = readlink($resolvedTargetPath);
                $this->io->write("<error>Symlink collision for $packageName at $resolvedTargetPath: existing symlink points to $existingTarget.</error>");
                return false;
            }
        }

        // Create target directory if it doesn't exist.
        if (!is_dir($targetDir)) {
            // Report failures through IO instead of letting PHP warnings abort the pass.
            if (!@mkdir($targetDir, 0755, true)) {
                $this->io->write("<error>Failed to create target directory: $targetDir.</error>");
                return false;
            }
        }

        // Remove existing symlink if it exists, do not remove directories, regular files or special files (e.g. Unix sockets).
        if (is_link($resolvedTargetPath)) {
            if (!@unlink($resolvedTargetPath)) {
                $this->io->write("<error>Failed to remove existing symlink: $resolvedTargetPath.</error>");
                return false;
            }
        } elseif (file_exists($resolvedTargetPath)) {
            $this->io->write("<error>Target path exists and is not a symlink: $resolvedTargetPath.</error>");
            return false;
        }

        // Create the symlink
        $relativePath = $this->getRelativePath(dirname($resolvedTargetPath), $sourcePath);
        if (@symlink($relativePath, $resolvedTargetPath)) {
            $this->destinationOwners[$destinationKey] = $packageName;
            $this->activeDestinations[$destinationKey] = true;
            $this->io->write("<info>Created symlink: $resolvedTargetPath -> $sourcePath.</info>");
            return $this->registry->record($destinationKey, $packageName);
        }

        $this->io->write("<error>Failed to create symlink: $resolvedTargetPath.</error>");
        return false;
    }

    private function resolveSourcePath(string $packagePath, string $relativeSource): ?string
    {
        $source = $packagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeSource);
        $resolvedPackage = realpath($packagePath);
        $resolvedSource = realpath($source);
        if ($resolvedPackage === false || $resolvedSource === false) {
            $this->io->write("<error>Source path does not exist or cannot be resolved: $source.</error>");
            return null;
        }
        if (!$this->isWithinDirectory($resolvedSource, $resolvedPackage)) {
            $this->io->write("<error>Source path escapes the package directory: $source.</error>");
            return null;
        }
        if (!is_file($resolvedSource) && !is_dir($resolvedSource)) {
            $this->io->write("<error>Source path is not a regular file or directory: $source.</error>");
            return null;
        }

        return $resolvedSource;
    }

    /**
     * Root configuration chooses the destination; package metadata must stay within it.
     * Validate before creating directories or removing an existing link.
     */
    private function resolveTargetPath(PackageInterface $package, string $targetPath): ?string
    {
        $extra = $package->getExtra();
        $packageName = $package->getName();
        $placeholders = [
            '{$name}' => ['symlink-preferred-name', basename($packageName)],
            '{$vendor}' => ['symlink-preferred-vendor', dirname($packageName)],
            '{$package}' => ['symlink-preferred-package', $packageName],
        ];
        $replacements = [];
        $firstPlaceholder = null;

        foreach ($placeholders as $placeholder => list($key, $default)) {
            $position = strpos($targetPath, $placeholder);
            if ($position === false) {
                continue;
            }

            $firstPlaceholder = $firstPlaceholder === null ? $position : min($firstPlaceholder, $position);
            $value = $extra[$key] ?? null;
            if ($value === null || $value === '') {
                $value = $default;
            }
            if (!$this->isSafeRelativePath($value)) {
                $this->io->write("<error>Invalid $key for $packageName: expected a safe relative path.</error>");
                return null;
            }
            $replacements[$placeholder] = $value;
        }

        // A single substitution pass prevents metadata from expanding other placeholders.
        $resolvedTargetPath = str_replace('/', DIRECTORY_SEPARATOR, strtr($targetPath, $replacements));
        if ($firstPlaceholder === null) {
            return $resolvedTargetPath;
        }

        // The literal directory before the first placeholder is controlled by the root project.
        $baseDir = dirname(substr($targetPath, 0, $firstPlaceholder) . 'placeholder');
        $baseDir = $this->resolveDirectoryPath($baseDir);
        $targetDir = $this->resolveDirectoryPath(dirname($resolvedTargetPath));
        if ($baseDir === null || $targetDir === null || !$this->isWithinDirectory($targetDir, $baseDir)) {
            $this->io->write("<error>Invalid symlink destination for $packageName: path leaves the configured directory or has an invalid parent.</error>");
            return null;
        }

        return $resolvedTargetPath;
    }

    private function isSafeRelativePath($path): bool
    {
        if (!is_string($path) || $path === '' || strpos($path, '\\') !== false
            || preg_match('/[<>:"|?*{}\x00-\x1F\x7F]/', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            // Trailing dots/spaces can turn into traversal segments on Windows.
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve existing ancestors, including symlinks, without creating missing directories.
     */
    private function resolveDirectoryPath(string $path): ?string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return is_dir($resolved) ? $resolved : null;
        }
        if (file_exists($path) || is_link($path)) {
            return null;
        }

        $parent = dirname($path);
        if ($parent === $path) {
            return null;
        }
        $resolvedParent = $this->resolveDirectoryPath($parent);
        if ($resolvedParent === null) {
            return null;
        }

        $name = basename($path);
        if ($name === '.') {
            return $resolvedParent;
        }
        if ($name === '..') {
            return dirname($resolvedParent);
        }

        return rtrim($resolvedParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        $path = $this->normalizePathCase($path);
        $directory = $this->normalizePathCase($directory);

        return $path === $directory
            || strpos($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
    }

    private function normalizePathCase(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private function getRelativePath(string $from, string $to): string
    {
        // Convert to absolute paths and normalize them
        $from = realpath($from) ?: $from;
        $to = realpath($to) ?: $to;

        // Ensure both paths are absolute
        if (!str_starts_with($from, DIRECTORY_SEPARATOR)) {
            $from = getcwd() . DIRECTORY_SEPARATOR . $from;
        }
        if (!str_starts_with($to, DIRECTORY_SEPARATOR)) {
            $to = getcwd() . DIRECTORY_SEPARATOR . $to;
        }

        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);

        $from = rtrim($from, DIRECTORY_SEPARATOR);
        $to = rtrim($to, DIRECTORY_SEPARATOR);

        $fromParts = explode(DIRECTORY_SEPARATOR, $from);
        $toParts = explode(DIRECTORY_SEPARATOR, $to);

        // Find common base
        $commonLength = 0;
        $minLength = min(count($fromParts), count($toParts));

        for ($i = 0; $i < $minLength; $i++) {
            if ($fromParts[$i] === $toParts[$i]) {
                $commonLength++;
            } else {
                break;
            }
        }

        // Build relative path
        $relativeParts = [];

        // Add ".." for each remaining part in $from
        for ($i = $commonLength; $i < count($fromParts); $i++) {
            $relativeParts[] = '..';
        }

        // Add remaining parts from $to
        for ($i = $commonLength; $i < count($toParts); $i++) {
            $relativeParts[] = $toParts[$i];
        }

        return $relativeParts ? implode(DIRECTORY_SEPARATOR, $relativeParts) : '.';
    }

    private function normalizePath(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalized);
    }
}
