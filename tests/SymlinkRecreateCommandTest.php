<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests;

use Composer\Composer;
use Composer\Console\Application;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\ErrorHandler;
use DirectoryIterator;
use PHPUnit\Framework\TestCase;
use Pieceofcodero\SymlinkComposerPlugin\SymlinkRecreateCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class SymlinkRecreateCommandTest extends TestCase
{
    /** @var string */
    private $originalCwd;

    /** @var string */
    private $fixture;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        $this->fixture = sys_get_temp_dir() . '/symlink-command-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
        chdir($this->fixture);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeFixture($this->fixture);
    }

    public function testReturnsZeroAfterCreatingAllRequestedLinks(): void
    {
        $package = $this->package('acme/widget');
        [$tester] = $this->command(['public/{$name}' => 'vendor:acme'], [$package]);

        self::assertSame(0, $tester->execute([]));
        self::assertTrue(is_link('public/widget'));
        self::assertSame(realpath('vendor/acme/widget'), realpath('public/widget'));
    }

    /**
     * @dataProvider noWorkProvider
     */
    public function testReturnsZeroWhenThereIsNothingToDo(array $rules, bool $includePackage): void
    {
        $packages = $includePackage ? [$this->package('acme/widget', [], false)] : [];
        [$tester] = $this->command($rules, $packages);

        self::assertSame(0, $tester->execute([]));
        self::assertFalse(file_exists('public'));
    }

    public static function noWorkProvider(): array
    {
        return [
            'no configuration' => [[], true],
            'no matching rules' => [['public/{$name}' => 'vendor:other'], true],
            'no installed packages' => [['public/{$name}' => 'vendor:acme'], false],
        ];
    }

    /**
     * @dataProvider blockedDestinationProvider
     */
    public function testReturnsOneForBlockedDestinationsAndContinues(string $type): void
    {
        $blocked = $this->package('acme/blocked');
        $good = $this->package('acme/good');
        mkdir('public');
        if ($type === 'directory') {
            mkdir('public/blocked');
        } elseif ($type === 'symlink') {
            symlink('../unrelated', 'public/blocked');
        } else {
            file_put_contents('public/blocked', 'preserve me');
        }
        [$tester, $io] = $this->command(['public/{$name}' => 'vendor:acme'], [$blocked, $good]);

        self::assertSame(1, $tester->execute([]));
        self::assertTrue(is_link('public/good'), 'A failure must not prevent later links');
        self::assertSame(realpath('vendor/acme/good'), realpath('public/good'));
        self::assertStringContainsString($type === 'symlink' ? 'Symlink collision' : 'Target path exists', $io->getOutput());
        if ($type === 'directory') {
            self::assertTrue(is_dir('public/blocked') && !is_link('public/blocked'));
        } elseif ($type === 'symlink') {
            self::assertSame('../unrelated', readlink('public/blocked'));
        } else {
            self::assertSame('preserve me', file_get_contents('public/blocked'));
        }
    }

    public static function blockedDestinationProvider(): array
    {
        return [
            'regular file' => ['file'],
            'directory' => ['directory'],
            'unrelated dangling symlink' => ['symlink'],
        ];
    }

    public function testReturnsOneForCollisionsAndStillProcessesLaterPackages(): void
    {
        $first = $this->package('acme/widget');
        $collision = $this->package('other/widget');
        $good = $this->package('acme/good');
        [$tester, $io] = $this->command(['public/{$name}' => 'type:library'], [$first, $collision, $good]);

        self::assertSame(1, $tester->execute([]));
        self::assertSame(realpath('vendor/acme/widget'), realpath('public/widget'));
        self::assertSame(realpath('vendor/acme/good'), realpath('public/good'));
        self::assertStringContainsString('Symlink collision', $io->getOutput());
    }

    public function testReturnsOneForInvalidOverridesAndContinues(): void
    {
        $invalid = $this->package('acme/invalid', ['symlink-preferred-name' => '../escape']);
        $good = $this->package('acme/good');
        [$tester, $io] = $this->command(['public/{$name}' => 'vendor:acme'], [$invalid, $good]);

        self::assertSame(1, $tester->execute([]));
        self::assertFalse(file_exists('escape'));
        self::assertSame(realpath('vendor/acme/good'), realpath('public/good'));
        self::assertStringContainsString('Invalid symlink-preferred-name', $io->getOutput());
    }

    public function testReturnsOneForEscapingParentSymlinksAndContinues(): void
    {
        $invalid = $this->package('acme/invalid', ['symlink-preferred-name' => 'redirect/widget']);
        $good = $this->package('acme/good');
        mkdir('public');
        mkdir('outside');
        symlink('../outside', 'public/redirect');
        [$tester, $io] = $this->command(['public/{$name}' => 'vendor:acme'], [$invalid, $good]);

        self::assertSame(1, $tester->execute([]));
        self::assertFalse(file_exists('outside/widget'));
        self::assertSame(realpath('vendor/acme/good'), realpath('public/good'));
        self::assertStringContainsString('Invalid symlink destination', $io->getOutput());
    }

    public function testReturnsOneForMissingSourcesAndContinues(): void
    {
        $missing = $this->package('acme/missing', [], false);
        $good = $this->package('acme/good');
        [$tester, $io] = $this->command(['public/{$name}' => 'vendor:acme'], [$missing, $good]);

        self::assertSame(1, $tester->execute([]));
        self::assertSame(realpath('vendor/acme/good'), realpath('public/good'));
        self::assertStringContainsString('Package path does not exist', $io->getOutput());
    }

    public function testDoesNotCarryFailuresOverToTheNextInvocation(): void
    {
        $package = $this->package('acme/widget');
        mkdir('public');
        file_put_contents('public/widget', 'preserve me');
        [$tester] = $this->command(['public/{$name}' => 'vendor:acme'], [$package]);

        self::assertSame(1, $tester->execute([]));
        unlink('public/widget');

        self::assertSame(0, $tester->execute([]));
        self::assertSame(realpath('vendor/acme/widget'), realpath('public/widget'));
    }

    /**
     * @dataProvider filesystemFailureProvider
     */
    public function testReturnsOneForFilesystemFailuresUnderComposersErrorHandler(string $operation, string $preferredName): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('This test requires POSIX directory permissions.');
        }
        $blocked = $this->package('acme/widget', ['symlink-preferred-name' => $preferredName]);
        $good = $this->package('other/good');
        mkdir('public');
        if ($operation === 'remove existing symlink') {
            symlink('../vendor/acme/widget', 'public/widget');
        }
        [$tester, $io] = $this->command([
            'public/{$name}' => 'vendor:acme',
            'good/{$name}' => 'vendor:other',
        ], [$blocked, $good]);

        chmod('public', 0555);
        try {
            if (is_writable('public')) {
                self::markTestSkipped('The current user can write to read-only directories.');
            }
            $errorReporting = error_reporting();
            ErrorHandler::register($io);
            try {
                self::assertSame(1, $tester->execute([]));
            } finally {
                restore_error_handler();
                error_reporting($errorReporting);
            }
        } finally {
            chmod('public', 0755);
        }

        self::assertStringContainsString('Failed to ' . $operation, $io->getOutput());
        self::assertSame(realpath('vendor/other/good'), realpath('good/good'));
        if ($operation === 'remove existing symlink') {
            self::assertSame('../vendor/acme/widget', readlink('public/widget'));
        } else {
            self::assertFalse(file_exists('public/' . $preferredName));
        }
    }

    public static function filesystemFailureProvider(): array
    {
        return [
            'mkdir failure' => ['create target directory', 'new/widget'],
            'symlink failure' => ['create symlink', 'widget'],
            'unlink failure' => ['remove existing symlink', 'widget'],
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

    private function command(array $rules, array $packages): array
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
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));

        $command = new SymlinkRecreateCommand();
        $command->setComposer($composer);
        $command->setIO($io);
        $application = new Application();
        $application->add($command);

        return [new CommandTester($command), $io];
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
