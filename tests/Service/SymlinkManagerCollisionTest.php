<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;

final class SymlinkManagerCollisionTest extends TestCase
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
        symlink('public', 'alias');
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
        symlink('../acme/widget', 'vendor/other/widget');
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
        symlink($existingTarget, 'public/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($package);

        self::assertTrue(is_link('public/widget'));
        self::assertSame($existingTarget, readlink('public/widget'));
        self::assertSame('preserve me', file_get_contents('keep.txt'));
        self::assertStringContainsString('Symlink collision', $io->getOutput());
        self::assertStringContainsString('acme/widget', $io->getOutput());
        self::assertStringContainsString($existingTarget, $io->getOutput());
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
        symlink(getcwd() . '/vendor/acme/widget', 'public/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($package);

        $this->assertPackageLink('public/widget', 'acme/widget');
        self::assertStringStartsWith('..', readlink('public/widget'));
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    public function testDoesNotReserveDestinationsForMissingSources(): void
    {
        $missing = $this->package('acme/widget', [], false);
        $installed = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library']);

        $manager->createSymlinkForPackage($missing);
        $manager->createSymlinkForPackage($installed);

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

        $manager->createSymlinkForPackage($first);
        self::assertSame('preserve me', file_get_contents('public/widget'));
        unlink('public/widget');
        $manager->createSymlinkForPackage($second);

        $this->assertPackageLink('public/widget', 'other/widget');
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
    }

    public function testRecreatingAllPackagesPreservesTheFirstLinkOnEveryPass(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library'], [$first, $second]);

        $manager->createSymlinksForAllPackages();
        $this->assertPackageLink('public/widget', 'acme/widget');
        $manager->createSymlinksForAllPackages();

        $this->assertPackageLink('public/widget', 'acme/widget');
        self::assertSame(2, substr_count($io->getOutput(), 'Symlink collision'));
    }

    public function testRecreatingAllPackagesClearsPreviousPassClaims(): void
    {
        $first = $this->package('acme/widget');
        $second = $this->package('other/widget');
        [$manager, $io] = $this->manager(['public/{$name}' => 'type:library'], [$second]);
        $manager->createSymlinkForPackage($first);
        unlink('public/widget');

        $manager->createSymlinksForAllPackages();

        $this->assertPackageLink('public/widget', 'other/widget');
        self::assertStringNotContainsString('Symlink collision', $io->getOutput());
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

    private function manager(array $rules, array $packages = []): array
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

        $io = new BufferIO();
        return [new SymlinkManager($composer, $io), $io];
    }

    private function assertPackageLink(string $path, string $package): void
    {
        self::assertTrue(is_link($path), 'Expected a symlink at ' . $path);
        self::assertSame(realpath('vendor/' . $package), realpath($path), 'Link must resolve to ' . $package);
    }

    private function assertPackageCollision(BufferIO $io, string $destination, string $first, string $second): void
    {
        self::assertStringContainsString('Symlink collision', $io->getOutput());
        self::assertStringContainsString($destination, $io->getOutput());
        self::assertStringContainsString($first, $io->getOutput());
        self::assertStringContainsString($second, $io->getOutput());
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
