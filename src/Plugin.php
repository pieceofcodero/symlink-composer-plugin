<?php

namespace Pieceofcodero\SymlinkComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Package\PackageInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;

class Plugin implements PluginInterface, EventSubscriberInterface, Capable
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
     * @var array
     */
    private $processedPackages = [];

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io) {}

    public function uninstall(Composer $composer, IOInterface $io) {}

    /**
     * {@inheritdoc}
     */
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstalled',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageUpdated',
        ];
    }

    /**
     * Handle individual package installation
     * @noinspection PhpUnused
     */
    public function onPackageInstalled(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
            $this->createSymlinkForPackage($package);
        }
    }

    /**
     * Handle individual package update
     * @noinspection PhpUnused
     */
    public function onPackageUpdated(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
            $this->createSymlinkForPackage($package);
        }
    }

    /**
     * Returns true if the package has not been processed yet, and marks it as processed.
     * We ensure that each package is processed only once to avoid redundant symlink creation.
     */
    private function shouldProcessPackage(string $packageName): bool
    {
        if (isset($this->processedPackages[$packageName])) {
            return false;
        }
        $this->processedPackages[$packageName] = true;
        return true;
    }

    /**
     * Create symlink for a single package that was just installed/updated
     */
    private function createSymlinkForPackage(PackageInterface $package): void
    {
        if (!$this->shouldProcessPackage($package->getName())) {
            return;
        }

        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $symlinkConfig = $this->getSymlinkConfig();

        if (empty($symlinkConfig)) {
            return; // No configuration, nothing to do
        }

        $this->processPackage($package, $symlinkConfig, $vendorDir);
    }

    /**
     * Create symlinks for all packages (used by the symlink-recreate-all command)
     */
    public function createSymlinksForAllPackages(): void
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $symlinkConfig = $this->getSymlinkConfig();

        if (empty($symlinkConfig)) {
            $this->io->write('<info>No symlink-paths configuration found.</info>');
            return;
        }

        $this->io->write('<info>Recreating all symlinks...</info>');

        $repository = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($repository->getPackages() as $package) {
            if ($this->shouldProcessPackage($package->getName())) {
                $this->processPackage($package, $symlinkConfig, $vendorDir);
            }
        }
    }

    /**
     * Fetch symlink-paths configuration from composer.json extra section.
     */
    private function getSymlinkConfig(): array
    {
        $extra = $this->composer->getPackage()->getExtra();
        return $extra['symlink-paths'] ?? [];
    }

    private function processPackage(PackageInterface $package, array $symlinkConfig, string $vendorDir): void
    {
        foreach ($symlinkConfig as $targetPath => $criteria) {
            if ($this->shouldCreateSymlink($package, $criteria)) {
                $this->createSymlink($package, $targetPath, $vendorDir);
                break; // Only create one symlink per package.
            }
        }
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

    private function createSymlink(PackageInterface $package, string $targetPath, string $vendorDir): void
    {
        $packageName = $package->getName();
        $packagePath = $vendorDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $packageName);

        // Replace placeholders in target path.
        $resolvedTargetPath = str_replace(
            ['{$name}', '{$vendor}', '{$package}'],
            [
                basename($packageName), // package name without vendor
                dirname($packageName),  // vendor name
                $packageName           // full package name
            ],
            $targetPath
        );

        // Normalize resolvedTargetPath for directory separators
        $resolvedTargetPath = str_replace('/', DIRECTORY_SEPARATOR, $resolvedTargetPath);

        if (!is_dir($packagePath)) {
            $this->io->write("<warning>Package path does not exist: $packagePath.</warning>");
            return;
        }

        // Create target directory if it doesn't exist.
        $targetDir = dirname($resolvedTargetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                $this->io->write("<error>Failed to create target directory: $targetDir.</error>");
                return;
            }
        }

        // Remove existing symlink if it exists, do not remove directories, regular files or special files (e.g. Unix sockets).
        if (is_link($resolvedTargetPath)) {
            unlink($resolvedTargetPath);
        } elseif (is_dir($resolvedTargetPath)) {
            $this->io->write("<warning>Target path exists and is not a symlink: $resolvedTargetPath.</warning>");
            return;
        } elseif (is_file($resolvedTargetPath)) {
            $this->io->write("<warning>Target path exists and is not a symlink: $resolvedTargetPath.</warning>");
            return;
        } elseif (file_exists($resolvedTargetPath)) {
            $this->io->write("<warning>Target path exists and is not a symlink: $resolvedTargetPath.</warning>");
            return;
        }

        // Create the symlink
        $relativePath = $this->getRelativePath(dirname($resolvedTargetPath), $packagePath);
        if (symlink($relativePath, $resolvedTargetPath)) {
            $this->io->write("<info>Created symlink: $resolvedTargetPath -> $packagePath.</info>");
        } else {
            $this->io->write("<error>Failed to create symlink: $resolvedTargetPath.</error>");
        }
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
