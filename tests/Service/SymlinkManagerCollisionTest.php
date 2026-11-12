<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Installer\InstallationManager;
use Composer\Installer\LibraryInstaller;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\Loop;
use DirectoryIterator;
use Pieceofcodero\SymlinkComposerPlugin\Tests\BaseTestCase;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;

final class SymlinkManagerCollisionTest extends BaseTestCase
{
    /** @var string */
    private $originalCwd;

    /** @var string */
    private $fixture;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->fixture = sys_get_temp_dir() . '/symlink-collision-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        chdir($this->fixture);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeFixture($this->fixture);
    }

    public function testPreservesTheFirstLinkWhenPackageBasenamesCollide(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($first);
        $originalLink = readlink('public/widget');
        $manager->createSymlinkForPackage($second);

        self::assertSame($originalLink, readlink('public/widget'));
        $this->assertPackageLink('public/widget', 'acme/widget');
        $this->assertPackageCollision($io, 'public/widget', 'acme/widget', 'other/widget');
    }

    public function testDetectsCollidingPreferredNames(): void
    {
        $first = $this->package('acme/first', ['symlink-preferred-name' => 'shared']);
        $second = $this->package('other/second', ['symlink-preferred-name' => 'shared']);
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($first);
        $manager->createSymlinkForPackage($second);

        $this->assertPackageLink('public/shared', 'acme/first');
        $this->assertPackageCollision($io, 'public/shared', 'acme/first', 'other/second');
    }

    public function testDetectsWindowsDestinationCasingCollisions(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Requires a Windows filesystem.');
        }
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager([
            'public/{$name}' => 'vendor:acme',
            'PUBLIC/{$name}' => 'vendor:other',
        ]);

        self::assertTrue($manager->createSymlinkForPackage($first), $io->getOutput());
        self::assertFalse($manager->createSymlinkForPackage($second));
        $this->assertPackageLink('public/widget', 'acme/widget');
        $this->assertPackageCollision($io, 'widget', 'acme/widget', 'other/widget');
    }

    public function testDetectsCollisionsAtLiteralDestinations(): void
    {
        $first = $this->package('acme/first');
        $second = $this->package('other/second');
        [$manager, $io] = $this->manager(['public/shared' => 'type:library']);

        $manager->createSymlinkForPackage($first);
        $manager->createSymlinkForPackage($second);

        $this->assertPackageLink('public/shared', 'acme/first');
        $this->assertPackageCollision($io, 'public/shared', 'acme/first', 'other/second');
    }

    /**
     * @dataProvider equivalentDestinationProvider
     */
    public function testDetectsEquivalentDestinationPaths(string $secondTemplate): void
    {
        mkdir('public');
        $this->createFixtureLink('public', 'alias');
        if ($secondTemplate === 'absolute') {
            $secondTemplate = getcwd() . '/public/{$name}';
        }
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager([
            'public/{$name}' => 'vendor:acme',
            $secondTemplate => 'vendor:other',
        ]);

        $manager->createSymlinkForPackage($first);
        $manager->createSymlinkForPackage($second);

        $this->assertPackageLink('public/widget', 'acme/widget');
        $this->assertPackageCollision($io, 'widget', 'acme/widget', 'other/widget');
        self::assertFalse(is_dir('uncreated'), 'A rejected destination must not create directories');
    }

    public static function equivalentDestinationProvider(): array
    {
        return [
            'absolute path' => ['absolute'],
            'dot segments' => ['./public/./{$name}'],
            'parent segments' => ['public/../public/{$name}'],
            'missing parent segments' => ['uncreated/../public/{$name}'],
            'symlinked parent' => ['alias/{$name}'],
        ];
    }

    public function testDetectsTwoPackagesClaimingTheSameSourceAndDestination(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget', [], false);
        mkdir('vendor/other');
        $this->createFixtureLink('../acme/widget', 'vendor/other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($first);
        $manager->createSymlinkForPackage($second);

        $this->assertPackageLink('public/widget', 'acme/widget');
        $this->assertPackageCollision($io, 'public/widget', 'acme/widget', 'other/widget');
    }

    public function testCreatesDistinctDestinationsForPackagesWithTheSameBasename(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$package}' => 'type:library']);

        $manager->createSymlinkForPackage($first);
        $manager->createSymlinkForPackage($second);

        $this->assertPackageLink('public/acme/widget', 'acme/widget');
        $this->assertPackageLink('public/other/widget', 'other/widget');
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    public function testPreservesAConflictingLinkFromAnEarlierRun(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$firstManager] = $this->manager(['public/{$name}' => 'type:library']);
        $firstManager->createSymlinkForPackage($first);
        $originalLink = readlink('public/widget');
        [$secondManager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $secondManager->createSymlinkForPackage($second);

        self::assertSame($originalLink, readlink('public/widget'));
        $this->assertPackageLink('public/widget', 'acme/widget');
        $this->assertPackageCollision($io, 'public/widget', 'acme/widget', 'other/widget');
    }

    /**
     * @dataProvider existingLinkProvider
     */
    public function testPreservesUnrelatedExistingLinks(string $existingTarget): void
    {
        $package = $this->package('acme/widget');
        mkdir('public');
        file_put_contents('keep.txt', 'preserve me');
        $originalTarget = $this->createFixtureLink($existingTarget, 'public/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($package);

        self::assertTrue(is_link('public/widget'));
        self::assertSame($originalTarget, readlink('public/widget'));
        self::assertSame('preserve me', file_get_contents('keep.txt'));
        self::assertStringContainsString('Symlink collision', $io->getOutput());
        self::assertStringContainsString('acme/widget', $io->getOutput());
        self::assertStringContainsString($originalTarget, $io->getOutput());
    }

    public static function existingLinkProvider(): array
    {
        return [
            'existing file target' => ['../keep.txt'],
            'dangling target' => ['../missing'],
        ];
    }

    public function testRecreatesALinkToTheSamePackageAcrossRuns(): void
    {
        $package = $this->package('acme/widget');
        mkdir('public');
        $this->createFixtureLink(getcwd() . '/vendor/acme/widget', 'public/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($package);

        $this->assertPackageLink('public/widget', 'acme/widget');
        $this->assertLinkTargetFormat('public/widget');
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    public function testDoesNotReserveDestinationsForMissingSources(): void
    {
        $missing = $this->package('acme/widget', [], false);
        $installed = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        self::assertFalse($manager->createSymlinkForPackage($missing));
        self::assertTrue($manager->createSymlinkForPackage($installed));

        $this->assertPackageLink('public/widget', 'other/widget');
        self::assertStringContainsString('Package path does not exist', $io->getOutput());
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    public function testDoesNotReserveDestinationsBlockedByRegularFiles(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        mkdir('public');
        file_put_contents('public/widget', 'preserve me');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        self::assertFalse($manager->createSymlinkForPackage($first));
        self::assertFalse($manager->createSymlinkForPackage($first), 'Duplicate calls must retain the failed result');
        self::assertSame('preserve me', file_get_contents('public/widget'));
        $this->removeFixtureEntry('public/widget');
        self::assertTrue($manager->createSymlinkForPackage($second));

        $this->assertPackageLink('public/widget', 'other/widget');
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    /**
     * @dataProvider nonSymlinkDestinationProvider
     */
    public function testReportsExistingNonSymlinkDestinationsAsErrors(string $type): void
    {
        if ($type === 'fifo' && !function_exists('posix_mkfifo')) {
            self::markTestSkipped('FIFO creation requires the POSIX extension.');
        }

        $package = $this->package('acme/widget');
        mkdir('public');
        if ($type === 'directory') {
            mkdir('public/widget');
            file_put_contents('public/widget/keep.txt', 'preserve me');
        } elseif ($type === 'fifo') {
            self::assertTrue(posix_mkfifo('public/widget', 0600));
        } else {
            file_put_contents('public/widget', 'preserve me');
        }
        $originalDestination = lstat('public/widget');
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::once())->method('write')->with(self::stringContains('<error>'));
        [$manager] = $this->manager(['public/{$name}' => 'type:library'], [], $io);

        $manager->createSymlinkForPackage($package);

        self::assertFalse(is_link('public/widget'));
        self::assertSame($type === 'directory' ? 'dir' : $type, filetype('public/widget'));
        self::assertSame($originalDestination, lstat('public/widget'), 'The existing destination must be preserved');
        if ($type !== 'fifo') {
            $file = $type === 'directory' ? 'public/widget/keep.txt' : 'public/widget';
            self::assertSame('preserve me', file_get_contents($file));
        }
    }

    public static function nonSymlinkDestinationProvider(): array
    {
        return [
            'regular file' => ['file'],
            'directory' => ['directory'],
            'special file' => ['fifo'],
        ];
    }

    public function testRecreatingAllPackagesPreservesTheFirstLinkOnEveryPass(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library'], [$first, $second]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        $this->assertPackageLink('public/widget', 'acme/widget');
        self::assertFalse($manager->createSymlinksForAllPackages());

        $this->assertPackageLink('public/widget', 'acme/widget');
        self::assertSame(2, substr_count($io->getOutput(), 'Symlink collision'));
    }

    public function testRecreatingAllPackagesClearsPreviousPassClaims(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library'], [$second]);
        $manager->createSymlinkForPackage($first);
        $this->removeFixtureEntry('public/widget');

        self::assertTrue($manager->createSymlinksForAllPackages());

        $this->assertPackageLink('public/widget', 'other/widget');
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    public function testRecreatingAllPackagesResetsEarlierFailures(): void
    {
        $package = $this->package('acme/widget');
        mkdir('public');
        file_put_contents('public/widget', 'preserve me');
        [$manager] = $this->manager(['public/{$name}' => 'type:library'], [$package]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        $this->removeFixtureEntry('public/widget');

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertTrue($manager->createSymlinkForPackage($package), 'A repeated successful package must remain successful');
        $this->assertPackageLink('public/widget', 'acme/widget');
    }

    /**
     * @dataProvider noOpRulesProvider
     */
    public function testSinglePackageReturnsSuccessWhenNoLinkIsRequested(array $rules): void
    {
        $package = $this->package('acme/widget', [], false);
        [$manager] = $this->manager($rules);

        self::assertTrue($manager->createSymlinkForPackage($package));
        self::assertTrue($manager->createSymlinkForPackage($package));
        self::assertFalse(file_exists('public'));
    }

    public static function noOpRulesProvider(): array
    {
        return [
            'no configuration' => [[]],
            'no matching rule' => [['public/{$name}' => 'vendor:other']],
        ];
    }

    private function package(string $name, array $extra = [], bool $installed = true): Package
    {
        $package = new Package($name, '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        if ($installed) {
            mkdir('vendor/' . $name, 0755, true);
        }
        return $package;
    }

    private function manager(array $rules, array $packages = [], ?IOInterface $io = null): array
    {
        $composer = new Composer();
        $config = new Config(false, getcwd());
        $config->merge(['config' => ['vendor-dir' => getcwd() . '/vendor']]);
        $composer->setConfig($config);
        $root = new RootPackage('test/root', '1.0.0.0', '1.0.0');
        $root->setExtra(['symlink-paths' => $rules]);
        $composer->setPackage($root);

        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository($packages));
        $composer->setRepositoryManager($repositoryManager);

        $io = $io ?? new BufferIO();
        $composer->setDownloadManager($this->createStub(DownloadManager::class));
        $installationManager = new InstallationManager($this->createStub(Loop::class), $io);
        $installationManager->addInstaller(new LibraryInstaller($io, $composer));
        $composer->setInstallationManager($installationManager);
        return [new SymlinkManager($composer, $io), $io];
    }

    private function assertPackageLink(string $path, string $package): void
    {
        self::assertTrue(is_link($path), 'Expected a symlink at ' . $path);
        $this->assertSamePath('vendor/' . $package, $path, 'Link must resolve to ' . $package);
    }

    private function assertPackageCollision(BufferIO $io, string $destination, string $first, string $second): void
    {
        self::assertStringContainsString('Symlink collision', $io->getOutput());
        self::assertStringContainsString(str_replace('/', DIRECTORY_SEPARATOR, $destination), $io->getOutput());
        self::assertStringContainsString($first, $io->getOutput());
        self::assertStringContainsString($second, $io->getOutput());
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            $this->removeFixtureEntry($path);
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
