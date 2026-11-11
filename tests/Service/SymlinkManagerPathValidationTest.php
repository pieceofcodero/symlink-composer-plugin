<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\IO\BufferIO;
use Composer\Installer\InstallationManager;
use Composer\Installer\LibraryInstaller;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Util\Loop;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;

final class SymlinkManagerPathValidationTest extends TestCase
{
    /** @var string */
    private $originalCwd;

    /** @var string */
    private $fixture;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->fixture = sys_get_temp_dir() . '/symlink-plugin-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        chdir($this->fixture);
        mkdir('vendor/acme/widget', 0755, true);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeFixture($this->fixture);
    }

    /**
     * @dataProvider validPathProvider
     */
    public function testCreatesLinksWithValidMetadata(string $placeholder, string $expected, array $extra): void
    {
        $this->createLink('public/{$' . $placeholder . '}', $extra);
        $this->assertPackageLink('public/' . $expected);
    }

    public static function validPathProvider(): array
    {
        $cases = [];
        foreach (['name' => 'widget', 'vendor' => 'acme', 'package' => 'acme/widget'] as $placeholder => $default) {
            $key = 'symlink-preferred-' . $placeholder;
            $cases['default ' . $placeholder] = [$placeholder, $default, []];
            $cases['nested override ' . $placeholder] = [$placeholder, 'custom/nested-widget', [$key => 'custom/nested-widget']];
            $cases['null fallback ' . $placeholder] = [$placeholder, $default, [$key => null]];
            $cases['empty fallback ' . $placeholder] = [$placeholder, $default, [$key => '']];
        }
        return $cases;
    }

    /**
     * @dataProvider invalidPathProvider
     */
    public function testRejectsInvalidMetadataWithoutFilesystemChanges(string $placeholder, $value): void
    {
        mkdir('public');
        file_put_contents('public/keep.txt', 'preserve me');
        symlink('../vendor/acme/widget', 'public/keep-link');
        $before = $this->snapshot('.');
        $key = 'symlink-preferred-' . $placeholder;

        $io = $this->createLink('public/new/{$' . $placeholder . '}', [$key => $value]);

        self::assertStringContainsString('Invalid ' . $key . ' for acme/widget:', $io->getOutput());
        self::assertSame($before, $this->snapshot('.'), 'Invalid metadata must not change files, links, or directories');
    }

    public static function invalidPathProvider(): array
    {
        $invalidValues = [
            'parent traversal' => '../escaped',
            'nested parent traversal' => 'nested/../escaped',
            'current directory' => '.',
            'parent directory' => '..',
            'nested current directory' => 'nested/./escaped',
            'absolute POSIX path' => '/absolute',
            'network path' => '//server/share',
            'absolute Windows path' => 'C:/absolute',
            'drive-relative Windows path' => 'C:relative',
            'rooted Windows path' => '\\absolute',
            'Windows UNC path' => '\\\\server\\share',
            'Windows backslash path' => 'C:\\absolute',
            'backslash traversal' => '..\\escaped',
            'nested backslash traversal' => 'nested\\..\\escaped',
            'empty segment' => 'nested//escaped',
            'trailing slash' => 'nested/',
            'trailing dot' => 'trailing.',
            'traversal with trailing space' => '.. ',
            'null byte' => "null\0byte",
            'newline' => "line\nbreak",
            'tab' => "tab\tname",
            'delete character' => "delete\x7f",
            'stream separator' => 'stream:target',
            'placeholder injection' => '{$vendor}',
            'asterisk' => 'wild*card',
            'question mark' => 'question?',
            'angle brackets' => '<tag>',
            'double quote' => 'quote"',
            'pipe' => 'pipe|',
            'empty array' => [],
            'array' => ['nested'],
            'false' => false,
            'true' => true,
            'zero integer' => 0,
            'integer' => 123,
            'float' => 1.5,
        ];

        $cases = [];
        foreach (['name', 'vendor', 'package'] as $placeholder) {
            foreach ($invalidValues as $description => $value) {
                $cases[$placeholder . ': ' . $description] = [$placeholder, $value];
            }
        }
        return $cases;
    }

    public function testExpandsMultiplePlaceholdersAndEmbeddedNames(): void
    {
        $this->createLink('public/{$vendor}/prefix-{$name}/{$package}', [
            'symlink-preferred-vendor' => 'custom-vendor',
            'symlink-preferred-name' => 'custom-name',
            'symlink-preferred-package' => 'another-vendor/custom-package',
        ]);
        $this->assertPackageLink('public/custom-vendor/prefix-custom-name/another-vendor/custom-package');
    }

    public function testAcceptsOrdinarySpacesAndUnicode(): void
    {
        $this->createLink('public/{$name}', ['symlink-preferred-name' => 'My widgets/árvíz']);
        $this->assertPackageLink('public/My widgets/árvíz');
    }

    public function testAcceptsZeroAsAStringOverride(): void
    {
        $this->createLink('public/{$name}', ['symlink-preferred-name' => '0']);
        $this->assertPackageLink('public/0');
    }

    public function testIgnoresUnusedOverridesForLiteralDestinations(): void
    {
        $this->createLink('public/widget', ['symlink-preferred-name' => '../unused']);
        $this->assertPackageLink('public/widget');
    }

    public function testAllowsAnExplicitAbsoluteDestination(): void
    {
        $this->createLink(getcwd() . '/absolute/{$name}');
        $this->assertPackageLink('absolute/widget');
    }

    public function testAllowsAnExplicitParentRelativeDestination(): void
    {
        mkdir('working');
        chdir('working');
        mkdir('vendor/acme/widget', 0755, true);
        try {
            $this->createLink('../public/{$name}');
            $this->assertPackageLink('../public/widget');
        } finally {
            chdir('..');
        }
    }

    /**
     * @dataProvider escapingParentProvider
     */
    public function testRejectsEscapingParentSymlinksWithoutFilesystemChanges(string $outside): void
    {
        mkdir('public');
        mkdir($outside);
        symlink('../' . $outside, 'public/redirect');
        $before = $this->snapshot('.');

        $io = $this->createLink('public/{$name}', ['symlink-preferred-name' => 'redirect/new/widget']);

        self::assertStringContainsString('Invalid symlink destination', $io->getOutput());
        self::assertSame($before, $this->snapshot('.'), 'An escaping parent symlink must not cause filesystem changes');
    }

    public static function escapingParentProvider(): array
    {
        return [
            'outside directory' => ['outside'],
            'sibling sharing the base prefix' => ['public-sibling'],
        ];
    }

    public function testAllowsAnExplicitSymlinkedBaseDirectory(): void
    {
        mkdir('chosen');
        symlink('chosen', 'public');
        $this->createLink('public/{$name}', ['symlink-preferred-name' => 'nested/widget']);
        $this->assertPackageLink('chosen/nested/widget');
    }

    public function testAllowsIntermediateSymlinksWithinTheBaseDirectory(): void
    {
        mkdir('public/actual', 0755, true);
        symlink('actual', 'public/redirect');
        $this->createLink('public/{$name}', ['symlink-preferred-name' => 'redirect/nested/widget']);
        $this->assertPackageLink('public/actual/nested/widget');
    }

    public function testRejectsDanglingIntermediateSymlinksWithoutFilesystemChanges(): void
    {
        mkdir('public');
        symlink('missing', 'public/redirect');
        $before = $this->snapshot('.');

        $io = $this->createLink('public/{$name}', ['symlink-preferred-name' => 'redirect/widget']);

        self::assertStringContainsString('Invalid symlink destination', $io->getOutput());
        self::assertSame($before, $this->snapshot('.'), 'A dangling parent symlink must be preserved');
    }

    public function testRecreatesExistingFinalSymlinksToTheSamePackage(): void
    {
        mkdir('public');
        file_put_contents('vendor/acme/widget/keep.txt', 'preserve me');
        symlink(getcwd() . '/vendor/acme/widget', 'public/widget');
        $this->createLink('public/{$name}');
        $this->assertPackageLink('public/widget');
        self::assertSame('preserve me', file_get_contents('vendor/acme/widget/keep.txt'), 'The link target must be preserved');
    }

    public function testPreservesExistingRegularDestinationFiles(): void
    {
        mkdir('public');
        file_put_contents('public/widget', 'preserve me');
        $this->createLink('public/{$name}');
        self::assertFalse(is_link('public/widget'));
        self::assertSame('preserve me', file_get_contents('public/widget'));
    }

    private function createLink(string $template, array $extra = []): BufferIO
    {
        $composer = new Composer();
        $config = new Config(false, getcwd());
        $config->merge(['config' => ['vendor-dir' => getcwd() . '/vendor']]);
        $composer->setConfig($config);
        $root = new RootPackage('test/root', '1.0.0.0', '1.0.0');
        $root->setExtra(['symlink-paths' => [$template => 'vendor:acme']]);
        $composer->setPackage($root);

        $package = new Package('acme/widget', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        $io = new BufferIO();
        $composer->setDownloadManager($this->createStub(DownloadManager::class));
        $installationManager = new InstallationManager($this->createStub(Loop::class), $io);
        $installationManager->addInstaller(new LibraryInstaller($io, $composer));
        $composer->setInstallationManager($installationManager);
        $manager = new SymlinkManager($composer, $io);
        $manager->createSymlinkForPackage($package);

        return $io;
    }

    private function assertPackageLink(string $path): void
    {
        self::assertTrue(is_link($path), 'Expected a symlink at ' . $path);
        self::assertSame(realpath('vendor/acme/widget'), realpath($path), 'Link must resolve to the package');
        self::assertStringStartsWith('..', readlink($path), 'Link should retain a relative source path');
    }

    private function snapshot(string $directory): array
    {
        $entries = [];
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if (is_link($path)) {
                $entries[$entry->getFilename()] = ['link', readlink($path)];
            } elseif (is_dir($path)) {
                $entries[$entry->getFilename()] = $this->snapshot($path);
            } else {
                $entries[$entry->getFilename()] = ['file', file_get_contents($path)];
            }
        }
        ksort($entries);
        return $entries;
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
