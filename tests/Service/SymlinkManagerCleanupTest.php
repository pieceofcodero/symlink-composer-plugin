<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use Composer\Composer;
use Composer\Autoload\AutoloadGenerator;
use Composer\Config;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallationManager;
use Composer\Installer\LibraryInstaller;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\ErrorHandler;
use Composer\Util\Loop;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Pieceofcodero\SymlinkComposerPlugin\Plugin;
use Pieceofcodero\SymlinkComposerPlugin\Service\LinkRegistry;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;
use Pieceofcodero\SymlinkComposerPlugin\SymlinkRecreateCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class SymlinkManagerCleanupTest extends TestCase
{
    private $originalCwd;
    private $fixture;
    private $package;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->fixture = sys_get_temp_dir() . '/symlink-cleanup-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        chdir($this->fixture);
        mkdir('vendor/acme/widget', 0755, true);
        file_put_contents('vendor/acme/widget/first.js', 'first');
        file_put_contents('vendor/acme/widget/second.js', 'second');
        $this->package = new Package('acme/widget', '1.0.0.0', '1.0.0');
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeFixture($this->fixture);
    }

    public function testRecordsOwnershipAcrossManagersAndRemovesChangedDestinations(): void
    {
        $this->createInitialLink();
        $state = $this->state();
        $path = getcwd() . '/public/widget';
        self::assertSame('acme/widget', $state['links'][$path]['package']);
        self::assertSame(readlink($path), $state['links'][$path]['target']);
        self::assertSame(lstat($path)['ino'], $state['links'][$path]['ino']);

        [$manager] = $this->context(['assets/{$name}' => 'vendor:acme']);
        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget'));
        self::assertTrue(is_dir('public'), 'Cleanup must leave parent directories in place');
        self::assertSame(realpath('vendor/acme/widget'), realpath('assets/widget'));
        self::assertCount(1, $this->state()['links']);
    }

    public function testPackageUpdateCleansOnlyThatPackagesOldPreferredName(): void
    {
        $other = new Package('other/gadget', '1.0.0.0', '1.0.0');
        mkdir('vendor/other/gadget', 0755, true);
        [$initial] = $this->context(['public/{$name}' => 'type:library'], [$this->package, $other]);
        self::assertTrue($initial->createSymlinksForAllPackages());
        $this->package->setExtra(['symlink-preferred-name' => 'renamed']);
        [$manager] = $this->context(['public/{$name}' => 'type:library']);

        self::assertTrue($manager->createSymlinkForPackage($this->package));
        self::assertFalse(is_link('public/widget'));
        self::assertTrue(is_link('public/renamed'));
        self::assertTrue(is_link('public/gadget'));
    }

    public function testRemovesOnlyObsoletePathRulesAndUpdatesOwnedSources(): void
    {
        [$initial] = $this->context([
            'public/widget.js' => 'path:acme/widget/first.js',
            'public/obsolete.js' => 'path:acme/widget/second.js',
        ]);
        self::assertTrue($initial->createSymlinksForAllPackages());
        [$manager] = $this->context(['public/widget.js' => 'path:acme/widget/second.js']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertSame('second', file_get_contents('public/widget.js'));
        self::assertFalse(is_link('public/obsolete.js'));
        self::assertSame('first', file_get_contents('vendor/acme/widget/first.js'));
    }

    public function testCleansLinksWhenPackageStopsMatching(): void
    {
        $this->createInitialLink();
        [$manager] = $this->context(['public/{$name}' => 'vendor:other']);
        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget'));
    }

    public function testEmptyConfigurationRemovesOnlyTrackedLinks(): void
    {
        $this->createInitialLink();
        symlink('../vendor/acme/widget', 'public/untracked');
        file_put_contents('public/keep.txt', 'keep');
        [$manager] = $this->context([]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget'));
        self::assertTrue(is_link('public/untracked'));
        self::assertSame('keep', file_get_contents('public/keep.txt'));
        self::assertSame([], $this->state()['links']);
    }

    public function testDoesNotClaimOldLinksWithoutRecreatingThem(): void
    {
        mkdir('public');
        symlink('../vendor/acme/widget', 'public/widget');
        [$manager] = $this->context(['assets/{$name}' => 'vendor:acme']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('public/widget'));
        self::assertTrue(is_link('assets/widget'));
        self::assertCount(1, $this->state()['links']);
    }

    public function testRemovedPackagesReleaseDestinationsBeforeNewPackagesAreLinked(): void
    {
        $this->createInitialLink();
        $this->removeFixture('vendor/acme/widget');
        $replacement = new Package('other/widget', '1.0.0.0', '1.0.0');
        mkdir('vendor/other/widget', 0755, true);
        [$manager] = $this->context(['public/{$name}' => 'type:library'], [$replacement]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertSame(realpath('vendor/other/widget'), realpath('public/widget'));
        self::assertSame('other/widget', $this->state()['links'][getcwd() . '/public/widget']['package']);
    }

    public function testUninstallRemovesOwnedDanglingLinksWithoutTouchingOtherPackages(): void
    {
        $other = new Package('other/gadget', '1.0.0.0', '1.0.0');
        mkdir('vendor/other/gadget', 0755, true);
        [$initial] = $this->context(['public/{$name}' => 'type:library'], [$this->package, $other]);
        self::assertTrue($initial->createSymlinksForAllPackages());
        symlink('../missing', 'public/untracked');
        $this->removeFixture('vendor/acme/widget');
        [$manager] = $this->context([]);

        self::assertTrue($manager->removeSymlinksForPackage($this->package));
        self::assertFalse(is_link('public/widget'));
        self::assertTrue(is_link('public/untracked'));
        self::assertTrue(is_link('public/gadget'));
        self::assertCount(1, $this->state()['links']);
    }

    /** @dataProvider replacementProvider */
    public function testPreservesUserReplacements(string $replacement): void
    {
        $this->createInitialLink();
        // Build the replacement before unlinking to avoid inode reuse in this fixture.
        if ($replacement === 'file') {
            file_put_contents('public/replacement', 'keep');
        } elseif ($replacement === 'directory') {
            mkdir('public/replacement');
            file_put_contents('public/replacement/keep.txt', 'keep');
        } else {
            symlink($replacement, 'public/replacement');
        }
        unlink('public/widget');
        rename('public/replacement', 'public/widget');
        clearstatcache();
        $stat = lstat('public/widget');
        [$manager] = $this->context([]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        clearstatcache();
        self::assertSame($stat['ino'], lstat('public/widget')['ino']);
        if ($replacement === 'file' || $replacement === 'directory') {
            self::assertSame('keep', file_get_contents($replacement === 'file' ? 'public/widget' : 'public/widget/keep.txt'));
        } else {
            self::assertSame($replacement, readlink('public/widget'));
        }
        self::assertSame([], $this->state()['links']);
    }

    public static function replacementProvider(): array
    {
        return [
            'file' => ['file'],
            'directory' => ['directory'],
            'unrelated dangling link' => ['../missing'],
            'replacement with identical target' => ['../vendor/acme/widget'],
            'unrelated existing directory' => [__DIR__],
        ];
    }

    public function testRedirectedParentCannotRedirectCleanup(): void
    {
        $this->createInitialLink();
        rename('public', 'moved');
        symlink('moved', 'public');
        [$manager] = $this->context([]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('moved/widget'), 'Even the original inode is preserved through a redirected parent');
        self::assertTrue(is_link('public'));
        self::assertSame([], $this->state()['links']);
    }

    public function testUsesCanonicalDestinationsForExplicitSymlinkedBases(): void
    {
        mkdir('assets');
        symlink('assets', 'public');
        $this->createInitialLink();
        self::assertArrayHasKey(getcwd() . '/assets/widget', $this->state()['links']);
        [$manager] = $this->context([]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('assets/widget'));
        self::assertTrue(is_link('public'));
    }

    public function testMissingRecordedDestinationsAreForgotten(): void
    {
        $this->createInitialLink();
        unlink('public/widget');
        [$manager] = $this->context([]);
        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertSame([], $this->state()['links']);
    }

    public function testFailedReconfigurationPreservesOldLinksAndCanBeRetried(): void
    {
        $this->createInitialLink();
        [$manager, $io] = $this->context(['assets/missing.js' => 'path:acme/widget/missing.js']);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('public/widget'));
        self::assertStringContainsString('Source path does not exist', $io->getOutput());
        file_put_contents('vendor/acme/widget/missing.js', 'new');

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget'));
        self::assertSame('new', file_get_contents('assets/missing.js'));
    }

    public function testInvalidConfigurationPreservesExistingOwnershipWhileValidRulesContinue(): void
    {
        $this->createInitialLink();
        [$manager] = $this->context([
            'bad' => 'path:acme/widget/../escape',
            'assets/{$name}' => 'vendor:acme',
        ]);
        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('public/widget'));
        self::assertTrue(is_link('assets/widget'));
        self::assertCount(2, $this->state()['links']);
    }

    /** @dataProvider corruptMetadataProvider */
    public function testInvalidMetadataStopsMutation(string $kind): void
    {
        $this->createInitialLink();
        $state = $this->state();
        if ($kind === 'project') {
            $state['project'] .= '/different';
        } elseif ($kind === 'version') {
            $state['version'] = 999;
        } elseif ($kind === 'entry') {
            $state['links'][getcwd() . '/public/widget']['ino'] = 'invalid';
        } elseif ($kind === 'traversal') {
            $state['links'][getcwd() . '/public/../widget'] = reset($state['links']);
        }
        $contents = $kind === 'json' ? '{broken' : json_encode($state);
        file_put_contents($this->statePath(), $contents);
        [$manager, $io] = $this->context(['assets/{$name}' => 'vendor:acme']);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('public/widget'));
        self::assertFalse(file_exists('assets'));
        self::assertSame($contents, file_get_contents($this->statePath()));
        self::assertStringContainsString('Symlink ownership:', $io->getOutput());
    }

    public static function corruptMetadataProvider(): array
    {
        return [['json'], ['project'], ['version'], ['entry'], ['traversal']];
    }

    public function testMetadataSymlinksAreRejected(): void
    {
        $this->createInitialLink();
        rename($this->statePath(), 'state.json');
        symlink(getcwd() . '/state.json', $this->statePath());
        $contents = file_get_contents('state.json');
        [$manager] = $this->context([]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('public/widget'));
        self::assertSame($contents, file_get_contents('state.json'));
    }

    public function testUsesCustomVendorDirectoryForOwnershipMetadata(): void
    {
        rename('vendor', 'dependencies');
        [$initial] = $this->context(['public/{$name}' => 'vendor:acme'], null, 'dependencies');
        self::assertTrue($initial->createSymlinksForAllPackages());
        self::assertFileExists('dependencies/composer/symlink-composer-plugin.json');
        [$manager] = $this->context([], null, 'dependencies');
        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget'));
    }

    public function testCleanupFailuresReturnNonzeroAndRetainOwnershipForRetry(): void
    {
        $this->createInitialLink();
        $this->requirePermissions('public');
        [$manager, $io, $composer] = $this->context([]);
        $command = new SymlinkRecreateCommand();
        $command->setComposer($composer);
        $command->setIO($io);
        (new Application())->add($command);
        $tester = new CommandTester($command);

        try {
            $reporting = error_reporting();
            ErrorHandler::register($io);
            try {
                self::assertSame(1, $tester->execute([]));
            } finally {
                restore_error_handler();
                error_reporting($reporting);
            }
            self::assertTrue(is_link('public/widget'));
            self::assertCount(1, $this->state()['links']);
            self::assertStringContainsString('cannot remove stale symlink', $io->getOutput());
        } finally {
            chmod('public', 0755);
        }
        self::assertSame(0, $tester->execute([]));
        self::assertFalse(is_link('public/widget'));
    }

    public function testCannotCreateUntrackedLinksWhenMetadataCannotBeWritten(): void
    {
        mkdir('vendor/composer');
        $this->requirePermissions('vendor/composer');
        [$manager, $io] = $this->context(['public/{$name}' => 'vendor:acme']);
        try {
            self::assertFalse($manager->createSymlinksForAllPackages());
            self::assertFalse(is_link('public/widget'));
            self::assertStringContainsString('cannot write ownership metadata', $io->getOutput());
        } finally {
            chmod('vendor/composer', 0755);
        }
    }

    public function testReadOnlyMetadataPreservesExistingLinksBeforeReconfiguration(): void
    {
        $this->createInitialLink();
        $contents = file_get_contents($this->statePath());
        $this->requirePermissions('vendor/composer');
        [$manager, $io] = $this->context(['assets/{$name}' => 'vendor:acme']);
        try {
            self::assertFalse($manager->createSymlinksForAllPackages());
            self::assertTrue(is_link('public/widget'));
            self::assertFalse(file_exists('assets'));
            self::assertSame($contents, file_get_contents($this->statePath()));
            self::assertStringContainsString('cannot write ownership metadata', $io->getOutput());
        } finally {
            chmod('vendor/composer', 0755);
        }
    }

    public function testEmptyRepositoryCleansOnlyRecordedLinks(): void
    {
        $this->createInitialLink();
        symlink('../vendor/acme/widget', 'public/untracked');
        [$manager] = $this->context(['public/{$name}' => 'vendor:acme'], []);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget'));
        self::assertTrue(is_link('public/untracked'));
    }

    public function testFailedMetadataReplacementIsReportedWithoutLeavingPartialState(): void
    {
        $io = new BufferIO();
        $registry = new LinkRegistry(getcwd() . '/vendor', $io);
        self::assertTrue($registry->open());
        self::assertTrue($registry->prepare());
        mkdir('public');
        symlink('../vendor/acme/widget', 'public/widget');
        self::assertTrue($registry->record(getcwd() . '/public/widget', 'acme/widget'));
        // A directory at the destination makes atomic replacement fail on every OS.
        mkdir($this->statePath());
        self::assertFalse($registry->close());
        self::assertStringContainsString('cannot save ownership metadata', $io->getOutput());
        self::assertTrue(is_link('public/widget'));
        self::assertTrue(is_dir($this->statePath()));
        self::assertSame([], glob('vendor/composer/.symlink-*'));
    }

    public function testComposerCompletionReportsFailures(): void
    {
        $this->createInitialLink();
        file_put_contents($this->statePath(), '{broken');
        [, $io, $composer] = $this->context([]);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to reconcile package symlinks');
        $plugin->onComposerFinished(new Event(ScriptEvents::POST_UPDATE_CMD, $composer, $io));
    }

    public function testComposerEventsCreateUpdateReconcileAndUninstallLinks(): void
    {
        [, $io, $composer, $repository] = $this->context(['public/{$name}' => 'vendor:acme']);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $dispatcher = $composer->getEventDispatcher();
        $dispatcher->addSubscriber($plugin);
        $install = new InstallOperation($this->package);
        $dispatcher->dispatch(PackageEvents::POST_PACKAGE_INSTALL, new PackageEvent(
            PackageEvents::POST_PACKAGE_INSTALL, $composer, $io, false, $repository, [$install], $install
        ));
        self::assertTrue(is_link('public/widget'));

        $updated = clone $this->package;
        $updated->setExtra(['symlink-preferred-name' => 'renamed']);
        $repository->removePackage($this->package);
        $repository->addPackage($updated);
        $update = new UpdateOperation($this->package, $updated);
        // A new activation represents a subsequent Composer process.
        $dispatcher = new EventDispatcher($composer, $io);
        $composer->setEventDispatcher($dispatcher);
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $dispatcher->addSubscriber($plugin);
        $dispatcher->dispatch(PackageEvents::POST_PACKAGE_UPDATE, new PackageEvent(
            PackageEvents::POST_PACKAGE_UPDATE, $composer, $io, false, $repository, [$update], $update
        ));
        self::assertFalse(is_link('public/widget'));
        self::assertTrue(is_link('public/renamed'));

        $composer->getPackage()->setExtra(['symlink-paths' => ['assets/{$name}' => 'vendor:acme']]);
        $dispatcher->dispatch(ScriptEvents::POST_INSTALL_CMD, new Event(ScriptEvents::POST_INSTALL_CMD, $composer, $io));
        self::assertFalse(is_link('public/renamed'));
        self::assertTrue(is_link('assets/renamed'));

        $this->removeFixture('vendor/acme/widget');
        $uninstall = new UninstallOperation($updated);
        $repository->removePackage($updated);
        $dispatcher->dispatch(PackageEvents::POST_PACKAGE_UNINSTALL, new PackageEvent(
            PackageEvents::POST_PACKAGE_UNINSTALL, $composer, $io, false, $repository, [$uninstall], $uninstall
        ));
        self::assertFalse(is_link('assets/renamed'));
        $dispatcher->dispatch(ScriptEvents::POST_UPDATE_CMD, new Event(ScriptEvents::POST_UPDATE_CMD, $composer, $io));
        self::assertSame([], $this->state()['links']);
    }

    private function createInitialLink(): void
    {
        [$manager] = $this->context(['public/{$name}' => 'vendor:acme']);
        self::assertTrue($manager->createSymlinksForAllPackages());
    }

    private function context(array $rules, ?array $packages = null, string $vendorDir = 'vendor'): array
    {
        $composer = new Composer();
        $config = new Config(false, getcwd());
        $config->merge(['config' => ['vendor-dir' => getcwd() . '/' . $vendorDir]]);
        $composer->setConfig($config);
        $root = new RootPackage('test/root', '1.0.0.0', '1.0.0');
        $root->setExtra(['symlink-paths' => $rules]);
        $composer->setPackage($root);
        $repository = new InstalledArrayRepository(array_map(function ($package) {
            return clone $package;
        }, $packages ?? [$this->package]));
        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn($repository);
        $composer->setRepositoryManager($repositoryManager);
        $io = new BufferIO();
        $composer->setDownloadManager($this->createStub(DownloadManager::class));
        $installationManager = new InstallationManager($this->createStub(Loop::class), $io);
        $installationManager->addInstaller(new LibraryInstaller($io, $composer));
        $composer->setInstallationManager($installationManager);
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));
        $composer->setAutoloadGenerator(new AutoloadGenerator($composer->getEventDispatcher(), $io));
        return [new SymlinkManager($composer, $io), $io, $composer, $repository];
    }

    private function statePath(): string
    {
        return 'vendor/composer/symlink-composer-plugin.json';
    }

    private function state(): array
    {
        return json_decode(file_get_contents($this->statePath()), true);
    }

    private function requirePermissions(string $directory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Requires POSIX directory permissions.');
        }
        chmod($directory, 0555);
        if (is_writable($directory)) {
            chmod($directory, 0755);
            self::markTestSkipped('The current user can write to read-only directories.');
        }
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                $this->removeFixture($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
