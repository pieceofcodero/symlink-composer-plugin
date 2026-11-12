<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Service;

/** Filesystem path and symlink operations shared by the plugin services. */
final class Filesystem
{
    public static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    public static function canonicalPath(string $path): ?string
    {
        $resolved = realpath($path);
        // On Windows realpath() can return the link's path even when its target is missing.
        if ($resolved === false || !file_exists($resolved)) {
            return null;
        }
        if (!self::isWindows()) {
            return $resolved;
        }

        // PHP may return an 8.3 directory alias after traversing a Windows link.
        // Expand aliases by directory identity rather than guessing their long names.
        $normalized = str_replace('\\', '/', $resolved);
        if (!preg_match('~^([A-Za-z]:/|//[^/]+/[^/]+/)(.*)$~', $normalized, $parts)) {
            return $resolved;
        }
        $directory = $parts[1];
        if ($parts[2] === '') {
            return str_replace('/', '\\', $directory);
        }
        foreach (explode('/', $parts[2]) as $part) {
            if (preg_match('/~[0-9]/', $part) && is_dir($directory . $part)) {
                $identity = @stat($directory . $part);
                $entries = @scandir($directory);
                if ($identity !== false && $identity['ino'] !== 0 && $entries !== false) {
                    foreach ($entries as $entry) {
                        if ($entry === '.' || $entry === '..' || is_link($directory . $entry)) {
                            continue;
                        }
                        $candidate = @stat($directory . $entry);
                        if ($candidate !== false && $identity['dev'] === $candidate['dev']
                            && $identity['ino'] === $candidate['ino']) {
                            $part = $entry;
                            break;
                        }
                    }
                }
            }
            $directory .= $part . '/';
        }
        return str_replace('/', '\\', rtrim($directory, '/'));
    }

    public static function pathKey(string $path): string
    {
        return self::isWindows() ? strtolower(str_replace('/', '\\', $path)) : $path;
    }

    /**
     * Resolve existing ancestors, including symlinks, without creating missing directories.
     */
    public static function resolveDirectoryPath(string $path): ?string
    {
        $resolved = self::canonicalPath($path);
        if ($resolved !== null) {
            return is_dir($resolved) ? $resolved : null;
        }
        if (file_exists($path) || is_link($path)) {
            return null;
        }

        $parent = dirname($path);
        if ($parent === $path) {
            return null;
        }
        $resolvedParent = self::resolveDirectoryPath($parent);
        if ($resolvedParent === null) {
            return null;
        }

        $name = basename($path);
        if ($name === '.') {
            return $resolvedParent;
        }
        if ($name === '..') {
            return dirname($resolvedParent);
        }

        return rtrim($resolvedParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    public static function isWithinDirectory(string $path, string $directory): bool
    {
        $path = self::pathKey($path);
        $directory = self::pathKey($directory);

        return $path === $directory
            || strpos($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
    }

    /** Calculate a relative target for Unix links; Windows links use absolute targets. */
    public static function getUnixRelativePath(string $from, string $to): string
    {
        // Convert to absolute paths and normalize them
        $from = realpath($from) ?: $from;
        $to = realpath($to) ?: $to;

        // Ensure both paths are absolute
        if (!str_starts_with($from, DIRECTORY_SEPARATOR)) {
            $from = getcwd() . DIRECTORY_SEPARATOR . $from;
        }
        if (!str_starts_with($to, DIRECTORY_SEPARATOR)) {
            $to = getcwd() . DIRECTORY_SEPARATOR . $to;
        }

        $from = self::normalizePath($from);
        $to = self::normalizePath($to);

        $from = rtrim($from, DIRECTORY_SEPARATOR);
        $to = rtrim($to, DIRECTORY_SEPARATOR);

        $fromParts = explode(DIRECTORY_SEPARATOR, $from);
        $toParts = explode(DIRECTORY_SEPARATOR, $to);

        // Find common base
        $commonLength = 0;
        $minLength = min(count($fromParts), count($toParts));

        for ($i = 0; $i < $minLength; $i++) {
            if ($fromParts[$i] === $toParts[$i]) {
                $commonLength++;
            } else {
                break;
            }
        }

        // Build relative path
        $relativeParts = [];

        // Add ".." for each remaining part in $from
        for ($i = $commonLength; $i < count($fromParts); $i++) {
            $relativeParts[] = '..';
        }

        // Add remaining parts from $to
        for ($i = $commonLength; $i < count($toParts); $i++) {
            $relativeParts[] = $toParts[$i];
        }

        return $relativeParts ? implode(DIRECTORY_SEPARATOR, $relativeParts) : '.';
    }

    /** Never recurse into a link's target, including when a directory link is dangling. */
    public static function removeLink(string $path): bool
    {
        clearstatcache(true, $path);
        if (!is_link($path)) {
            return false;
        }
        if (self::isWindows() && @rmdir($path)) {
            return true;
        }
        return @unlink($path);
    }

    private static function normalizePath(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalized);
    }
}
