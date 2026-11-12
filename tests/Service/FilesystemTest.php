<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests\Service;

use DirectoryIterator;
use Pieceofcodero\SymlinkComposerPlugin\Service\Filesystem;
use Pieceofcodero\SymlinkComposerPlugin\Tests\BaseTestCase;

final class FilesystemTest extends BaseTestCase
{
    private $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/symlink-filesystem-test-' . bin2hex(random_bytes(8));
        mkdir($this->fixture);
    }

    protected function tearDown(): void
    {
        foreach (new DirectoryIterator($this->fixture) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if (is_dir($path) && !is_link($path)) {
                rmdir($path);
            } else {
                $this->removeFixtureEntry($path);
            }
        }
        rmdir($this->fixture);
    }

    /** @dataProvider linkProvider */
    public function testRemovesFileAndDirectoryLinksWithoutDeletingTheirTargets(bool $directory, bool $dangling): void
    {
        $target = $this->fixture . '/target';
        $link = $this->fixture . '/link';
        if ($directory) {
            mkdir($target);
        } else {
            file_put_contents($target, 'keep');
        }
        $this->createFixtureLink($target, $link);
        if ($dangling) {
            $directory ? rmdir($target) : unlink($target);
            clearstatcache();
        }

        self::assertTrue(Filesystem::removeLink($link));
        self::assertFalse(is_link($link));
        if (!$dangling) {
            self::assertTrue(file_exists($target));
            if (!$directory) {
                self::assertSame('keep', file_get_contents($target));
            }
        }
    }

    public static function linkProvider(): array
    {
        return [
            'file' => [false, false],
            'directory' => [true, false],
            'dangling file link' => [false, true],
            'dangling directory link' => [true, true],
        ];
    }

    public function testRefusesToRemoveRegularFilesAndDirectories(): void
    {
        file_put_contents($this->fixture . '/file', 'keep');
        mkdir($this->fixture . '/directory');
        self::assertFalse(Filesystem::removeLink($this->fixture . '/file'));
        self::assertFalse(Filesystem::removeLink($this->fixture . '/directory'));
        self::assertSame('keep', file_get_contents($this->fixture . '/file'));
        self::assertTrue(is_dir($this->fixture . '/directory'));
    }

    public function testCanonicalizesDirectoryAliasesWithoutFollowingMissingTargets(): void
    {
        mkdir($this->fixture . '/target');
        $this->createFixtureLink($this->fixture . '/target', $this->fixture . '/link');
        self::assertSame(
            Filesystem::pathKey(Filesystem::canonicalPath($this->fixture . '/target')),
            Filesystem::pathKey(Filesystem::canonicalPath($this->fixture . '/link'))
        );
        self::assertNull(Filesystem::canonicalPath($this->fixture . '/missing'));
    }

    public function testCanonicalizesFilesystemRoots(): void
    {
        $root = realpath($this->fixture);
        while (dirname($root) !== $root) {
            $root = dirname($root);
        }
        self::assertSame(Filesystem::pathKey($root), Filesystem::pathKey(Filesystem::canonicalPath($root)));
    }

    /** @dataProvider danglingLinkProvider */
    public function testCanonicalPathRejectsDanglingLinksOnUnix(bool $directory): void
    {
        if (Filesystem::isWindows()) {
            self::markTestSkipped('Requires Unix symlink semantics.');
        }
        [$target, $link] = $this->createDanglingLink($directory);

        self::assertFalse(file_exists($link), 'Unix existence checks follow the link to its missing target');
        self::assertFalse(file_exists($target));
        self::assertNull(Filesystem::canonicalPath($link));
        self::assertTrue(is_link($link), 'Resolution must preserve the dangling link');
    }

    /** @dataProvider danglingLinkProvider */
    public function testCanonicalPathRejectsDanglingLinksOnWindows(bool $directory): void
    {
        if (!Filesystem::isWindows()) {
            self::markTestSkipped('Requires Windows symlink semantics.');
        }
        [$target, $link] = $this->createDanglingLink($directory);

        // Windows may report the link entry as existing even after its target is gone.
        self::assertFalse(file_exists($target));
        self::assertNull(Filesystem::canonicalPath($link));
        self::assertTrue(is_link($link), 'Resolution must preserve the dangling link');
    }

    private function createDanglingLink(bool $directory): array
    {
        $target = $this->fixture . '/target';
        $link = $this->fixture . '/link';
        if ($directory) {
            mkdir($target);
        } else {
            file_put_contents($target, 'temporary target');
        }
        $this->createFixtureLink($target, $link);
        $directory ? rmdir($target) : unlink($target);
        clearstatcache();

        return [$target, $link];
    }

    public static function danglingLinkProvider(): array
    {
        return ['file link' => [false], 'directory link' => [true]];
    }

    public function testWindowsKeysIgnoreSeparatorAndDriveLetterCasing(): void
    {
        if (!Filesystem::isWindows()) {
            self::markTestSkipped('Requires Windows path conventions.');
        }
        self::assertSame('c:\\work\\public\\widget', Filesystem::pathKey('C:/Work/public/Widget'));
        self::assertSame('\\\\server\\share\\widget', Filesystem::pathKey('//SERVER/Share/Widget'));
    }
}
