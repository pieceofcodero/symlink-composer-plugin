<?php

namespace Pieceofcodero\SymlinkComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;

class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    /**
     * @var SymlinkManager
     */
    private $symlinkManager;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->symlinkManager = new SymlinkManager($composer, $io);
    }

    public function deactivate(Composer $composer, IOInterface $io) {}

    public function uninstall(Composer $composer, IOInterface $io) {}

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
            PackageEvents::POST_PACKAGE_UNINSTALL => 'onPackageUninstalled',
            ScriptEvents::POST_INSTALL_CMD => 'onComposerFinished',
            ScriptEvents::POST_UPDATE_CMD => 'onComposerFinished',
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
            $this->symlinkManager->createSymlinkForPackage($package);
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
            $this->symlinkManager->createSymlinkForPackage($package);
        }
    }

    public function onPackageUninstalled(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if ($operation instanceof UninstallOperation
            && !$this->symlinkManager->removeSymlinksForPackage($operation->getPackage())) {
            throw new \RuntimeException('Failed to clean up package symlinks; see the errors above.');
        }
    }

    /** Reconcile configuration changes even when no package operations were needed. */
    public function onComposerFinished(Event $event): void
    {
        if (!$this->symlinkManager->createSymlinksForAllPackages()) {
            throw new \RuntimeException('Failed to reconcile package symlinks; see the errors above.');
        }
    }
}
