<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use Pieceofcodero\SymlinkComposerPlugin\Service\Filesystem;
use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\BufferIO;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\Loop;
use DirectoryIterator;
use Pieceofcodero\SymlinkComposerPlugin\Tests\BaseTestCase;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;

final class SymlinkManagerPathRuleTest extends BaseTestCase
{
    /** @var string */
    private $originalCwd;

    /** @var string */
    private $fixture;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->fixture = sys_get_temp_dir() . '/symlink-path-rule-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        chdir($this->fixture);
        mkdir('vendor/acme/widget/dist', 0755, true);
        mkdir('vendor/acme/widget/assets/images', 0755, true);
        file_put_contents('vendor/acme/widget/dist/widget.js', 'widget();');
        file_put_contents('vendor/acme/widget/dist/widget.css', '.widget {}');
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeFixture($this->fixture);
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testLinksFilesAndDirectories(string $source, string $destination): void
    {
        [$manager] = $this->manager([$destination => 'path:acme/widget/' . $source]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink($destination, 'vendor/acme/widget/' . $source);
    }

    public static function sourceProvider(): array
    {
        return [
            'file with a custom destination name' => ['dist/widget.js', 'public/js/renamed.js'],
            'directory' => ['assets/images', 'public/images/widget'],
        ];
    }

    public function testCreatesEveryExplicitPathForTheSamePackage(): void
    {
        [$manager] = $this->manager([
            'public/widget.js' => 'path:acme/widget/dist/widget.js',
            'public/widget.css' => 'path:acme/widget/dist/widget.css',
            'public/images' => 'path:acme/widget/assets/images',
        ]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        $this->assertSourceLink('public/widget.css', 'vendor/acme/widget/dist/widget.css');
        $this->assertSourceLink('public/images', 'vendor/acme/widget/assets/images');
    }

    /**
     * @dataProvider wholePackageOrderProvider
     */
    public function testExplicitPathsCoexistWithTheFirstWholePackageMatch(bool $wholePackageFirst): void
    {
        $whole = ['whole/{$name}' => 'vendor:acme'];
        $paths = ['public/widget.js' => 'path:acme/widget/dist/widget.js'];
        $rules = $wholePackageFirst ? $whole + $paths : $paths + $whole;
        $rules['second-whole/{$name}'] = 'type:library';
        [$manager, $io] = $this->manager($rules);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('whole/widget', 'vendor/acme/widget');
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        self::assertFalse(file_exists('second-whole'));
        self::assertStringNotContainsString('No installed package matches', $io->getOutput());
    }

    public static function wholePackageOrderProvider(): array
    {
        return ['whole package first' => [true], 'explicit path first' => [false]];
    }

    public function testCriteriaArraysSelectTheFirstMatchingSource(): void
    {
        [$manager] = $this->manager([
            'public/widget.js' => [
                'path:other/widget/dist/missing.js',
                'path:acme/widget/dist/widget.js',
                'path:acme/widget/dist/widget.css',
            ],
            'whole/{$name}' => ['vendor:other', 'type:library'],
        ]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        $this->assertSourceLink('whole/widget', 'vendor/acme/widget');
    }

    public function testUsesDestinationPlaceholdersAndPreferredNames(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setExtra(['symlink-preferred-name' => 'custom-widget']);
        [$manager] = $this->manager(['public/{$vendor}/{$name}.js' => 'path:acme/widget/dist/widget.js'], [$package]);

        self::assertTrue($manager->createSymlinkForPackage($package));
        $this->assertSourceLink('public/acme/custom-widget.js', 'vendor/acme/widget/dist/widget.js');
    }

    public function testIgnoresValidRulesForOtherPackages(): void
    {
        [$manager, $io] = $this->manager(['public/widget.js' => 'path:other/widget/dist/missing.js']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertStringNotContainsString('does not exist', $io->getOutput());
    }

    public function testMissingSourcesFailWithoutBlockingOtherPathsFromTheSamePackage(): void
    {
        [$manager, $io] = $this->manager([
            'public/missing.js' => 'path:acme/widget/dist/missing.js',
            'public/widget.js' => 'path:acme/widget/dist/widget.js',
        ]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/missing.js'));
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        self::assertStringContainsString('Source path does not exist', $io->getOutput());
        self::assertStringNotContainsString('No installed package matches', $io->getOutput());
    }

    /**
     * @dataProvider unmatchedRuleProvider
     */
    public function testWarnsOnceForRulesWithoutCandidates($criteria, bool $includePackage): void
    {
        $packages = $includePackage ? [new Package('acme/widget', '1.0.0.0', '1.0.0')] : [];
        [$manager, $io] = $this->manager([
            'public/widget.js' => $criteria,
        ], $packages);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertSame(1, substr_count($io->getOutput(), 'No installed package matches'));
        self::assertStringContainsString('destination public/widget.js', $io->getOutput());
        foreach (is_array($criteria) ? $criteria : [$criteria] as $criterion) {
            self::assertStringContainsString($criterion, $io->getOutput());
        }
    }

    public static function unmatchedRuleProvider(): array
    {
        $cases = [];
        foreach ([
            'path' => 'path:other/widget/dist/widget.js',
            'package' => 'other/widget',
            'vendor' => 'vendor:other',
            'type' => 'type:component',
            'alternatives' => ['path:other/widget/dist/widget.js', 'other/widget', 'vendor:other', 'type:component'],
            'empty criteria' => [],
        ] as $name => $criteria) {
            $cases[$name . ' with empty repository'] = [$criteria, false];
            $cases[$name . ' with unrelated package'] = [$criteria, true];
        }
        return $cases;
    }

    /**
     * @dataProvider matchingAlternativeProvider
     */
    public function testAnyMatchingAlternativeSuppressesTheWarning(array $criteria): void
    {
        [$manager, $io] = $this->manager(['public/widget' => $criteria]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertTrue(is_link('public/widget'));
        self::assertStringNotContainsString('No installed package matches', $io->getOutput());
    }

    public static function matchingAlternativeProvider(): array
    {
        $cases = [];
        foreach (['path:acme/widget/dist/widget.js', 'acme/widget', 'vendor:acme', 'type:library'] as $criterion) {
            $cases[$criterion . ' first'] = [[$criterion, 'path:other/widget/missing.js']];
            $cases[$criterion . ' last'] = [['path:other/widget/missing.js', $criterion]];
        }
        return $cases;
    }

    public function testUnmatchedRuleWarningsDoNotBlockOtherDestinations(): void
    {
        [$manager, $io] = $this->manager([
            'public/widget.js' => ['path:other/widget/dist/widget.js', 'path:acme/widget/dist/widget.js'],
            'public/widget.css' => 'path:acme/widget/dist/widget.css',
            'whole/{$name}' => 'vendor:absent',
        ]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        $this->assertSourceLink('public/widget.css', 'vendor/acme/widget/dist/widget.css');
        self::assertSame(1, substr_count($io->getOutput(), 'No installed package matches'));
        self::assertStringContainsString('destination whole/{$name}: vendor:absent', $io->getOutput());
        self::assertStringNotContainsString('path:other/widget', $io->getOutput());
    }

    /**
     * @dataProvider multipleCandidateProvider
     */
    public function testVendorAndTypeRulesCanHaveMultipleCandidates(string $criterion): void
    {
        mkdir('vendor/acme/gadget', 0755, true);
        $packages = [new Package('acme/widget', '1.0.0.0', '1.0.0'), new Package('acme/gadget', '1.0.0.0', '1.0.0')];
        [$manager, $io] = $this->manager(['public/{$name}' => $criterion], $packages);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget', 'vendor/acme/widget');
        $this->assertSourceLink('public/gadget', 'vendor/acme/gadget');
        self::assertStringNotContainsString('No installed package matches', $io->getOutput());
    }

    public static function multipleCandidateProvider(): array
    {
        return ['vendor' => ['vendor:acme'], 'type' => ['type:library']];
    }

    public function testDoesNotWarnAboutOtherPackagesDuringIndividualPackageOperations(): void
    {
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        [$manager, $io] = $this->manager([
            'public/widget.js' => 'path:other/widget/dist/widget.js',
        ], [$package]);

        self::assertTrue($manager->createSymlinkForPackage($package));
        self::assertStringNotContainsString('No installed package matches', $io->getOutput());
    }

    public function testDetectsDifferentSourcesClaimingTheSameDestinationWithinOnePackage(): void
    {
        [$manager, $io] = $this->manager([
            'public/widget' => 'path:acme/widget/dist/widget.js',
            './public/./widget' => 'path:acme/widget/dist/widget.css',
            'public/images' => 'path:acme/widget/assets/images',
        ]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget', 'vendor/acme/widget/dist/widget.js');
        $this->assertSourceLink('public/images', 'vendor/acme/widget/assets/images');
        self::assertStringContainsString('Symlink collision', $io->getOutput());
    }

    /**
     * @dataProvider invalidSpecifierProvider
     */
    public function testRejectsInvalidSpecifiersEvenWithoutInstalledPackages(string $specifier): void
    {
        [$manager, $io] = $this->manager(['public/invalid' => $specifier], []);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Invalid path: criterion', $io->getOutput());
    }

    /**
     * @dataProvider safeSymlinkProvider
     */
    public function testAllowsSourceSymlinksWithinThePackage(string $target): void
    {
        $this->createFixtureLink($target, 'vendor/acme/widget/alias');
        [$manager] = $this->manager(['public/alias' => 'path:acme/widget/alias']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/alias', 'vendor/acme/widget/' . $target);
    }

    public static function safeSymlinkProvider(): array
    {
        return ['file' => ['dist/widget.js'], 'directory' => ['assets/images']];
    }

    /**
     * @dataProvider escapingSymlinkProvider
     */
    public function testRejectsSourcesThatEscapeThroughSymlinks(string $target, bool $directory): void
    {
        mkdir(dirname($target), 0755, true);
        if ($directory) {
            mkdir($target);
        } else {
            file_put_contents($target, 'preserve me');
        }
        $this->createFixtureLink(getcwd() . '/' . $target, 'vendor/acme/widget/alias');
        [$manager, $io] = $this->manager([
            'public/invalid' => 'path:acme/widget/alias',
            'public/widget.js' => 'path:acme/widget/dist/widget.js',
        ]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/invalid'));
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        self::assertStringContainsString('Source path escapes the package directory', $io->getOutput());
        if (!$directory) {
            self::assertSame('preserve me', file_get_contents($target));
        }
    }

    public static function escapingSymlinkProvider(): array
    {
        return [
            'external file' => ['outside/secret.txt', false],
            'external directory' => ['outside/assets', true],
            'sibling sharing the package prefix' => ['vendor/acme/widget-sibling/secret.txt', false],
        ];
    }

    /**
     * @dataProvider brokenSymlinkProvider
     */
    public function testRejectsBrokenSourceSymlinks(string $target): void
    {
        $this->createFixtureLink($target, 'vendor/acme/widget/alias');
        [$manager, $io] = $this->manager(['public/invalid' => 'path:acme/widget/alias']);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Source path does not exist', $io->getOutput());
    }

    public static function brokenSymlinkProvider(): array
    {
        return ['dangling' => ['missing'], 'loop' => ['alias']];
    }

    public function testRejectsSpecialSourceFiles(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('FIFO creation requires the POSIX extension.');
        }
        self::assertTrue(posix_mkfifo('vendor/acme/widget/fifo', 0600));
        [$manager, $io] = $this->manager(['public/fifo' => 'path:acme/widget/fifo']);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Source path is not a regular file or directory', $io->getOutput());
    }

    public function testSupportsPathRepositoryPackageRoots(): void
    {
        mkdir('workspace');
        rename('vendor/acme/widget', 'workspace/widget');
        $this->createFixtureLink('../../workspace/widget', 'vendor/acme/widget');
        [$manager] = $this->manager(['public/widget.js' => 'path:acme/widget/dist/widget.js']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'workspace/widget/dist/widget.js');
    }

    public function testUsesCustomInstallerRootsForSourcePaths(): void
    {
        mkdir('components/widget/dist', 0755, true);
        file_put_contents('components/widget/dist/widget.js', 'customWidget();');
        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setType('custom-component');
        [$manager, $io, $composer] = $this->manager(['public/widget.js' => 'path:acme/widget/dist/widget.js'], [$package]);
        $installer = $this->createStub(InstallerInterface::class);
        $installer->method('supports')->willReturnCallback(function ($type): bool {
            return $type === 'custom-component';
        });
        $installer->method('getInstallPath')->willReturn('components/widget');
        $composer->getInstallationManager()->addInstaller($installer);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'components/widget/dist/widget.js');
        self::assertSame('customWidget();', file_get_contents('public/widget.js'));
    }

    public function testRecreatesExistingLinksToTheSelectedFile(): void
    {
        mkdir('public');
        $this->createFixtureLink(getcwd() . '/vendor/acme/widget/dist/widget.js', 'public/widget.js');
        [$manager] = $this->manager(['public/widget.js' => 'path:acme/widget/dist/widget.js']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
    }

    public function testPreservesExistingRegularDestinationFiles(): void
    {
        mkdir('public');
        file_put_contents('public/widget.js', 'preserve me');
        [$manager, $io] = $this->manager(['public/widget.js' => 'path:acme/widget/dist/widget.js']);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/widget.js'));
        self::assertSame('preserve me', file_get_contents('public/widget.js'));
        self::assertStringContainsString('Target path exists and is not a symlink', $io->getOutput());
    }

    public function testDetectsCollisionsBetweenExplicitPathsFromDifferentPackages(): void
    {
        mkdir('vendor/other/widget/dist', 0755, true);
        file_put_contents('vendor/other/widget/dist/widget.js', 'otherWidget();');
        $packages = [new Package('acme/widget', '1.0.0.0', '1.0.0'), new Package('other/widget', '1.0.0.0', '1.0.0')];
        [$manager, $io] = $this->manager([
            'public/{$name}.js' => 'path:acme/widget/dist/widget.js',
            './public/{$name}.js' => 'path:other/widget/dist/widget.js',
        ], $packages);

        self::assertFalse($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        self::assertStringContainsString('Symlink collision', $io->getOutput());
        self::assertStringContainsString('other/widget conflicts with acme/widget', $io->getOutput());
    }

    public function testAcceptsNumericDestinationNames(): void
    {
        [$manager] = $this->manager(['0' => 'path:acme/widget/dist/widget.js']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('0', 'vendor/acme/widget/dist/widget.js');
    }

    public function testCreatesADirectoryLinkToItsOwnParent(): void
    {
        [$manager] = $this->manager(['vendor/acme/widget/assets/images/self' => 'path:acme/widget/assets/images']);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('vendor/acme/widget/assets/images/self', 'vendor/acme/widget/assets/images');
        if (!Filesystem::isWindows()) {
            self::assertSame('.', readlink('vendor/acme/widget/assets/images/self'));
        }
    }

    /**
     * @dataProvider invalidConfigurationProvider
     */
    public function testRejectsMalformedConfigurationWithoutTypeErrors($configuration): void
    {
        [$manager, $io, $composer] = $this->manager([], []);
        $composer->getPackage()->setExtra(['symlink-paths' => $configuration]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Invalid ', $io->getOutput());
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'root string' => ['bad'],
            'root boolean' => [false],
            'root number' => [123],
            'empty destination' => [['' => 'vendor:acme']],
            'NUL destination' => [["public/null\0byte" => 'vendor:acme']],
            'null criterion' => [['public/widget' => null]],
            'boolean criterion' => [['public/widget' => false]],
            'integer criterion' => [['public/widget' => 123]],
            'nested criterion' => [['public/widget' => [['vendor:acme']]]],
            'empty criterion' => [['public/widget' => '']],
            'missing type' => [['public/widget' => 'type:']],
            'missing vendor' => [['public/widget' => 'vendor:']],
            'invalid vendor' => [['public/widget' => 'vendor:acme/widget']],
            'missing package vendor' => [['public/widget' => 'widget']],
            'unsupported prefix' => [['public/widget' => 'file:acme/widget/dist/widget.js']],
        ];
    }

    public static function invalidSpecifierProvider(): array
    {
        return [
            'empty' => ['path:'],
            'missing package' => ['path:acme'],
            'missing source' => ['path:acme/widget'],
            'empty source' => ['path:acme/widget/'],
            'invalid package name' => ['path:Acme/widget/dist/widget.js'],
            'empty vendor' => ['path:/widget/dist/widget.js'],
            'empty package' => ['path:acme//dist/widget.js'],
            'traversal' => ['path:acme/widget/../escape'],
            'nested traversal' => ['path:acme/widget/dist/../../escape'],
            'dot segment' => ['path:acme/widget/dist/./widget.js'],
            'absolute source' => ['path:acme/widget//etc/passwd'],
            'Windows drive' => ['path:acme/widget/C:/escape'],
            'backslash traversal' => ['path:acme/widget/..\\escape'],
            'empty segment' => ['path:acme/widget/dist//widget.js'],
            'trailing slash' => ['path:acme/widget/assets/'],
            'null byte' => ["path:acme/widget/dist/null\0byte"],
            'control character' => ["path:acme/widget/dist/line\nbreak"],
            'placeholder' => ['path:acme/widget/{$name}'],
            'Windows traversal alias' => ['path:acme/widget/.. /escape'],
        ];
    }

    public function testInvalidRulesDoNotBlockValidRules(): void
    {
        [$manager, $io] = $this->manager([
            'public/invalid' => ['path:acme/widget/../escape', 'vendor:acme'],
            'public/widget.js' => 'path:acme/widget/dist/widget.js',
        ]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(is_link('public/invalid'), 'An invalid criterion must not fall back to a broader source');
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
        self::assertStringContainsString('Invalid path: criterion', $io->getOutput());
        self::assertFalse($manager->createSymlinkForPackage(new Package('acme/widget', '1.0.0.0', '1.0.0')));
    }

    public function testAMatchingMissingPathDoesNotFallBackToTheWholePackage(): void
    {
        [$manager, $io] = $this->manager([
            'public/widget' => ['path:acme/widget/missing.js', 'vendor:acme'],
        ]);

        self::assertFalse($manager->createSymlinksForAllPackages());
        self::assertFalse(file_exists('public'));
        self::assertStringContainsString('Source path does not exist', $io->getOutput());
    }

    public function testWholePackageCriteriaCanComeBeforePathCriteriaInAnArray(): void
    {
        [$manager] = $this->manager([
            'whole/widget' => ['acme/widget', 'path:acme/widget/dist/widget.js'],
            'public/widget.js' => 'path:acme/widget/dist/widget.js',
        ]);

        self::assertTrue($manager->createSymlinksForAllPackages());
        $this->assertSourceLink('whole/widget', 'vendor/acme/widget');
        $this->assertSourceLink('public/widget.js', 'vendor/acme/widget/dist/widget.js');
    }

    private function manager(array $rules, ?array $packages = null): array
    {
        $packages = $packages ?? [new Package('acme/widget', '1.0.0.0', '1.0.0')];
        $composer = new Composer();
        $config = new Config(false, getcwd());
        $config->merge(['config' => ['vendor-dir' => getcwd() . '/vendor']]);
        $composer->setConfig($config);
        $root = new RootPackage('test/root', '1.0.0.0', '1.0.0');
        $root->setExtra(['symlink-paths' => $rules]);
        $composer->setPackage($root);
        $io = new BufferIO();
        $composer->setDownloadManager($this->createStub(DownloadManager::class));
        $installationManager = new InstallationManager($this->createStub(Loop::class), $io);
        $installationManager->addInstaller(new LibraryInstaller($io, $composer));
        $composer->setInstallationManager($installationManager);
        $repositoryManager = $this->createStub(RepositoryManager::class);
        $repositoryManager->method('getLocalRepository')->willReturn(new InstalledArrayRepository($packages));
        $composer->setRepositoryManager($repositoryManager);

        return [new SymlinkManager($composer, $io), $io, $composer];
    }

    private function assertSourceLink(string $destination, string $source): void
    {
        self::assertTrue(is_link($destination), 'Expected a symlink at ' . $destination);
        self::assertNotFalse(realpath($source), 'Expected the source to exist');
        $this->assertSamePath($source, $destination);
        $this->assertLinkTargetFormat($destination);
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
