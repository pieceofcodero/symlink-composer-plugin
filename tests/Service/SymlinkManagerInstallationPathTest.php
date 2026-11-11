<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\BufferIO;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Installer\MetapackageInstaller;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\Loop;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;
use RuntimeException;

final class SymlinkManagerInstallationPathTest extends TestCase
{
    /** @var string */
    private $originalCwd;

    /** @var string */
    private $fixture;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->fixture = sys_get_temp_dir() . '/symlink-installation-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        chdir($this->fixture);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeFixture($this->fixture);
    }

    /**
     * @dataProvider customPathProvider
     */
    public function testUsesCustomInstallPathsInsteadOfTheVendorDirectory(bool $absolute): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setType('custom-component');
        mkdir('components/widget', 0755, true);
        mkdir('vendor/acme/widget', 0755, true); // A stale default location must not be used.
        [$manager, $io, $composer] = $this->manager([$package]);
        $path = ($absolute ? getcwd() . '/' : '') . 'components/widget';
        $this->addCustomInstaller($composer, $path);

        self::assertTrue($manager->createSymlinkForPackage($package));
        $this->assertPackageLink('components/widget');
        self::assertStringNotContainsString('Package path does not exist', $io->getOutput());
    }

    public static function customPathProvider(): array
    {
        return [
            'absolute custom path' => [true],
            'relative custom path' => [false],
        ];
    }

    public function testRespectsTheLibraryInstallersTargetDirectory(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setTargetDir('legacy/source');
        mkdir('vendor/acme/widget/legacy/source', 0755, true);
        [$manager] = $this->manager([$package]);

        self::assertTrue($manager->createSymlinkForPackage($package));
        $this->assertPackageLink('vendor/acme/widget/legacy/source');
    }

    public function testSupportsCustomSourcesOutsideTheProjectDirectory(): void
    {
        mkdir('project');
        mkdir('shared/widget', 0755, true);
        chdir('project');
        try {
            $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
            $package->setType('custom-component');
            [$manager, $io, $composer] = $this->manager([$package]);
            $this->addCustomInstaller($composer, '../shared/widget');

            self::assertTrue($manager->createSymlinkForPackage($package));
            $this->assertPackageLink('../shared/widget');
        } finally {
            chdir('..');
        }
    }

    public function testRespectsACustomVendorDirectory(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        mkdir('dependencies/acme/widget', 0755, true);
        [$manager] = $this->manager([$package], 'dependencies');

        self::assertTrue($manager->createSymlinkForPackage($package));
        $this->assertPackageLink('dependencies/acme/widget');
    }

    public function testSupportsSymlinkedPackagesFromPathRepositories(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        mkdir('workspace/widget', 0755, true);
        mkdir('vendor/acme', 0755, true);
        symlink('../../workspace/widget', 'vendor/acme/widget');
        [$manager] = $this->manager([$package]);

        self::assertTrue($manager->createSymlinkForPackage($package));
        $this->assertPackageLink('workspace/widget');
        self::assertTrue(is_link('vendor/acme/widget'));
    }

    public function testDoesNotFallBackToAStaleVendorPathWhenCustomSourceIsMissing(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setType('custom-component');
        mkdir('vendor/acme/widget', 0755, true);
        [$manager, $io, $composer] = $this->manager([$package]);
        $this->addCustomInstaller($composer, 'missing/component');

        self::assertFalse($manager->createSymlinkForPackage($package));
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Package path does not exist: missing/component', $io->getOutput());
    }

    public function testReportsPackagesWithoutAnInstallationPathAndContinues(): void
    {
        $metapackage = new Package('acme/meta', '1.0.0.0', '1.0.0');
        $metapackage->setType('metapackage');
        $good = new Package('acme/widget', '1.0.0.0', '1.0.0');
        mkdir('vendor/acme/widget', 0755, true);
        [$manager, $io] = $this->manager([$metapackage, $good]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/meta'));
        $this->assertPackageLink('vendor/acme/widget');
        self::assertStringContainsString('Package acme/meta has no installation path', $io->getOutput());
    }

    public function testRejectsEmptyInstallPaths(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setType('custom-component');
        [$manager, $io, $composer] = $this->manager([$package]);
        $this->addCustomInstaller($composer, '');

        self::assertFalse($manager->createSymlinkForPackage($package));
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Package acme/widget has no installation path', $io->getOutput());
    }

    public function testReportsUnsupportedInstallersAndContinues(): void
    {
        $unknown = new Package('acme/unknown', '1.0.0.0', '1.0.0');
        $unknown->setType('unsupported-component');
        $good = new Package('acme/widget', '1.0.0.0', '1.0.0');
        mkdir('vendor/acme/widget', 0755, true);
        [$manager, $io] = $this->manager([$unknown, $good]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        $this->assertPackageLink('vendor/acme/widget');
        self::assertFalse(is_link('public/unknown'));
        self::assertStringContainsString('Failed to resolve installation path for acme/unknown', $io->getOutput());
        self::assertStringContainsString('Unknown installer type: unsupported-component', $io->getOutput());
    }

    public function testReportsCustomInstallerExceptionsAndContinues(): void
    {
        $broken = new Package('acme/broken', '1.0.0.0', '1.0.0');
        $broken->setType('custom-component');
        $good = new Package('acme/widget', '1.0.0.0', '1.0.0');
        mkdir('vendor/acme/widget', 0755, true);
        [$manager, $io, $composer] = $this->manager([$broken, $good]);
        $installer = $this->createStub(InstallerInterface::class);
        $installer->method('supports')->willReturnCallback(function ($type): bool {
            return $type === 'custom-component';
        });
        $installer->method('getInstallPath')->willThrowException(new RuntimeException('Custom location unavailable'));
        $composer->getInstallationManager()->addInstaller($installer);

        self::assertFalse($manager->createSymlinksForAllPackages());
        $this->assertPackageLink('vendor/acme/widget');
        self::assertStringContainsString('Failed to resolve installation path for acme/broken', $io->getOutput());
        self::assertStringContainsString('Custom location unavailable', $io->getOutput());
    }

    public function testPreservesLinksPointingToAnOldDefaultInstallation(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setType('custom-component');
        mkdir('components/widget', 0755, true);
        mkdir('vendor/acme/widget', 0755, true);
        mkdir('public');
        symlink('../vendor/acme/widget', 'public/widget');
        [$manager, $io, $composer] = $this->manager([$package]);
        $this->addCustomInstaller($composer, 'components/widget');

        self::assertFalse($manager->createSymlinkForPackage($package));
        self::assertSame('../vendor/acme/widget', readlink('public/widget'));
        self::assertStringContainsString('Symlink collision', $io->getOutput());
    }

    private function addCustomInstaller(Composer $composer, string $path): void
    {
        $installer = $this->createStub(InstallerInterface::class);
        $installer->method('supports')->willReturnCallback(function ($type): bool {
            return $type === 'custom-component';
        });
        $installer->method('getInstallPath')->willReturn($path);
        $composer->getInstallationManager()->addInstaller($installer);
    }

    private function manager(array $packages, string $vendorDir = 'vendor'): array
    {
        $composer = new Composer();
        $config = new Config(false, getcwd());
        $config->merge(['config' => ['vendor-dir' => getcwd() . '/' . $vendorDir]]);
        $composer->setConfig($config);
        $root = new RootPackage('test/root', '1.0.0.0', '1.0.0');
        $root->setExtra(['symlink-paths' => ['public/{$name}' => 'vendor:acme']]);
        $composer->setPackage($root);
        $io = new BufferIO();

        $composer->setDownloadManager($this->createStub(DownloadManager::class));
        $installationManager = new InstallationManager($this->createStub(Loop::class), $io);
        $installationManager->addInstaller(new LibraryInstaller($io, $composer));
        $installationManager->addInstaller(new MetapackageInstaller($io));
        $composer->setInstallationManager($installationManager);
        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository($packages));
        $composer->setRepositoryManager($repositoryManager);

        return [new SymlinkManager($composer, $io), $io, $composer];
    }

    private function assertPackageLink(string $source): void
    {
        self::assertTrue(is_link('public/widget'));
        self::assertSame(realpath($source), realpath('public/widget'));
        self::assertStringStartsWith('..', readlink('public/widget'));
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
