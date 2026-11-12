<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Tests;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    /** Build equivalent fixtures without depending on Unix-only symlink creation behavior. */
    protected function createFixtureLink(string $target, string $link): string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::assertTrue(symlink($target, $link));
            return readlink($link);
        }

        $parent = realpath(dirname($link));
        self::assertNotFalse($parent);
        $absoluteLink = $parent . DIRECTORY_SEPARATOR . basename($link);
        $absoluteTarget = preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $target)
            ? $target : $parent . DIRECTORY_SEPARATOR . $target;

        // Create against a real file, then move the link onto its own target to form a cycle.
        if (strtolower(str_replace('/', '\\', $absoluteTarget)) === strtolower($absoluteLink)) {
            self::assertFalse(file_exists($absoluteLink) || is_link($absoluteLink));
            file_put_contents($absoluteLink, 'temporary cycle target');
            $temporaryLink = $absoluteLink . '.fixture-' . bin2hex(random_bytes(8));
            self::assertTrue(symlink(realpath($absoluteLink), $temporaryLink));
            unlink($absoluteLink);
            self::assertTrue(rename($temporaryLink, $absoluteLink));
            clearstatcache();
            return readlink($absoluteLink);
        }
        $placeholder = !file_exists($absoluteTarget) && !is_link($absoluteTarget);
        if ($placeholder) {
            self::assertNotFalse(file_put_contents($absoluteTarget, 'temporary symlink target'));
        }
        try {
            $resolved = realpath($absoluteTarget);
            self::assertNotFalse($resolved);
            self::assertTrue(symlink($resolved, $absoluteLink));
        } finally {
            if ($placeholder) {
                unlink($absoluteTarget);
                clearstatcache();
            }
        }
        return readlink($absoluteLink);
    }

    protected function removeFixtureEntry(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\' && is_link($path) && @rmdir($path)) {
            return true;
        }
        return unlink($path);
    }

    protected function assertSamePath(string $expected, string $actual, string $message = ''): void
    {
        $expectedPath = realpath($expected);
        $actualPath = realpath($actual);
        self::assertNotFalse($expectedPath, 'Expected path must exist: ' . $expected);
        self::assertNotFalse($actualPath, 'Actual path must exist: ' . $actual);
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::assertSame($expectedPath, $actualPath, $message);
            return;
        }
        // String equality cannot distinguish long names and equivalent 8.3 aliases.
        $expectedStat = stat($expected);
        $actualStat = stat($actual);
        self::assertSame([$expectedStat['dev'], $expectedStat['ino']], [$actualStat['dev'], $actualStat['ino']], $message);
    }

    protected function assertLinkTargetFormat(string $link): void
    {
        $target = readlink($link);
        self::assertNotFalse($target);
        if (DIRECTORY_SEPARATOR === '\\') {
            self::assertSame(1, preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2})~', $target), 'Windows links use absolute targets');
        } else {
            self::assertSame(0, preg_match('~^(?:/|[A-Za-z]:)~', $target), 'Unix links retain relative targets');
            self::assertNotSame('\\', substr($target, 0, 1));
        }
    }
}
