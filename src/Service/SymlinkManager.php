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

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
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

        $symlinkConfig = $this->getSymlinkConfig();

        if (empty($symlinkConfig)) {
            return true; // No configuration, nothing to do
        }

        $result = $this->processPackage($package, $symlinkConfig);
        $this->processedPackages[$package->getName()] = $result;
        return $result;
    }

    /**
     * Process every package, returning false if any requested link failed.
     */
    public function createSymlinksForAllPackages(): bool
    {
        // Reset processed packages
        $this->processedPackages = [];
        $this->destinationOwners = [];

        $symlinkConfig = $this->getSymlinkConfig();

        if (empty($symlinkConfig)) {
            $this->io->write('<info>No symlink-paths configuration found.</info>');
            return true;
        }

        $this->io->write('<info>Recreating all symlinks...</info>');

        $success = true;
        $repository = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($repository->getPackages() as $package) {
            if ($this->shouldProcessPackage($package->getName())) {
                $result = $this->processPackage($package, $symlinkConfig);
                $this->processedPackages[$package->getName()] = $result;
                if (!$result) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Fetch symlink-paths configuration from composer.json extra section.
     */
    private function getSymlinkConfig(): array
    {
        $extra = $this->composer->getPackage()->getExtra();
        return $extra['symlink-paths'] ?? [];
    }

    private function processPackage(PackageInterface $package, array $symlinkConfig): bool
    {
        foreach ($symlinkConfig as $targetPath => $criteria) {
            if ($this->shouldCreateSymlink($package, $criteria)) {
                return $this->createSymlink($package, $targetPath);
            }
        }

        return true; // No matching rule, nothing to do.
    }

    private function shouldCreateSymlink(PackageInterface $package, $criteria): bool
    {
        // Handle array of criteria.
        if (is_array($criteria)) {
            foreach ($criteria as $criterion) {
                if ($this->satisfiesRule($package, $criterion)) {
                    return true;
                }
            }
            return false;
        }

        return $this->satisfiesRule($package, $criteria);
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

    private function createSymlink(PackageInterface $package, string $targetPath): bool
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
        if (isset($this->destinationOwners[$destinationKey]) && $this->destinationOwners[$destinationKey] !== $packageName) {
            $owner = $this->destinationOwners[$destinationKey];
            $this->io->write("<error>Symlink collision at $resolvedTargetPath: $packageName conflicts with $owner.</error>");
            return false;
        }

        // Existing links may come from an earlier Composer run or an unrelated owner.
        if (is_link($resolvedTargetPath)) {
            $existingSource = realpath($resolvedTargetPath);
            $expectedSource = realpath($packagePath);
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
        $relativePath = $this->getRelativePath(dirname($resolvedTargetPath), $packagePath);
        if (@symlink($relativePath, $resolvedTargetPath)) {
            $this->destinationOwners[$destinationKey] = $packageName;
            $this->io->write("<info>Created symlink: $resolvedTargetPath -> $packagePath.</info>");
            return true;
        }

        $this->io->write("<error>Failed to create symlink: $resolvedTargetPath.</error>");
        return false;
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

        return implode(DIRECTORY_SEPARATOR, $relativeParts);
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
