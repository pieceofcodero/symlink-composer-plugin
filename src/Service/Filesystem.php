<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Service;

/** Platform differences shared by link creation and ownership checks. */
final class Filesystem
{
    public static function canonicalPath(string $path): ?string
    {
        $resolved = realpath($path);
        // On Windows realpath() can return the link's path even when its target is missing.
        if ($resolved === false || !file_exists($resolved)) {
            return null;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
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
        return DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('/', '\\', $path)) : $path;
    }

    /** Never recurse into a link's target, including when a directory link is dangling. */
    public static function removeLink(string $path): bool
    {
        clearstatcache(true, $path);
        if (!is_link($path)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\' && @rmdir($path)) {
            return true;
        }
        return @unlink($path);
    }
}
