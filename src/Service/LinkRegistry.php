<?php

namespace Pieceofcodero\SymlinkComposerPlugin\Service;

use Composer\IO\IOInterface;

/** Persistent ownership of links created by this plugin, scoped to one project. */
final class LinkRegistry
{
    private $path;
    private $project;
    private $io;
    private $links = [];
    private $lock;
    private $dirty = false;
    private $ready = false;

    public function __construct(string $vendorDir, IOInterface $io)
    {
        $this->path = $vendorDir . '/composer/symlink-composer-plugin.json';
        $this->project = Filesystem::canonicalPath(getcwd());
        $this->io = $io;
    }

    public function open(): bool
    {
        $this->links = [];
        $this->dirty = false;
        return !file_exists($this->path) && !is_link($this->path) ? true : $this->prepare();
    }

    /** Acquire before changing links; no metadata is created for no-op runs. */
    public function prepare(): bool
    {
        if (is_resource($this->lock)) {
            return $this->ready;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return $this->error('cannot create metadata directory');
        }
        if (!is_writable($directory)) {
            return $this->error('cannot write ownership metadata');
        }
        $lockPath = $this->path . '.lock';
        if (is_link($lockPath) || is_link($this->path)) {
            return $this->error('metadata files must not be symlinks');
        }
        $this->lock = @fopen($lockPath, 'c');
        if ($this->lock === false || !@flock($this->lock, LOCK_EX)) {
            $this->close();
            return $this->error('cannot lock ownership metadata');
        }
        if (!file_exists($this->path)) {
            $this->ready = true;
            return true;
        }
        $contents = @file_get_contents($this->path);
        $data = $contents === false ? null : json_decode($contents, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1
            || !isset($data['project']) || !is_string($data['project'])
            || Filesystem::pathKey($data['project']) !== Filesystem::pathKey($this->project)
            || !isset($data['links']) || !is_array($data['links'])) {
            return $this->error('invalid ownership metadata or project mismatch; links have been preserved');
        }
        foreach ($data['links'] as $path => $link) {
            if (!is_string($path) || strpos($path, "\0") !== false
                || !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $path)
                || preg_match('~(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)~', $path)
                || !is_array($link) || !isset($link['package'], $link['target'], $link['dev'], $link['ino'])
                || !is_string($link['package']) || !is_string($link['target'])
                || !is_int($link['dev']) || !is_int($link['ino'])) {
                return $this->error('invalid ownership entry; links have been preserved');
            }
        }
        $this->links = $data['links'];
        $this->ready = true;
        return true;
    }

    public function owns(string $path, ?string $package = null): bool
    {
        $link = $this->links[$path] ?? null;
        if ($link === null || ($package !== null && $link['package'] !== $package)) {
            return false;
        }
        clearstatcache(true, $path);
        // A replaced parent must not redirect deletion to a different directory.
        $parent = Filesystem::canonicalPath(dirname($path));
        if ($parent === null || Filesystem::pathKey($parent) !== Filesystem::pathKey(dirname($path)) || !is_link($path)) {
            return false;
        }
        $stat = @lstat($path);
        return $stat !== false && $stat['dev'] === $link['dev'] && $stat['ino'] === $link['ino']
            && @readlink($path) === $link['target'];
    }

    public function owner(string $path): ?string
    {
        return $this->owns($path) ? $this->links[$path]['package'] : null;
    }

    public function record(string $path, string $package): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $target = @readlink($path);
        if ($stat === false || $target === false) {
            return $this->error("cannot record link at $path");
        }
        $this->links[$path] = ['package' => $package, 'target' => $target, 'dev' => $stat['dev'], 'ino' => $stat['ino']];
        $this->dirty = true;
        return true;
    }

    /** Failed packages retain their old links so a failed reconfiguration can be retried. */
    public function cleanup(array $active, array $preservedPackages = [], ?string $onlyPackage = null): bool
    {
        $success = true;
        foreach ($this->links as $path => $link) {
            if (isset($active[$path]) || isset($preservedPackages[$link['package']])
                || ($onlyPackage !== null && $link['package'] !== $onlyPackage)) {
                continue;
            }
            if ($this->owns($path)) {
                if (!Filesystem::removeLink($path)) {
                    $this->error("cannot remove stale symlink at $path");
                    $success = false;
                    continue;
                }
                $this->io->write("<info>Removed stale symlink: $path.</info>");
            }
            // Missing or user-modified destinations are forgotten, never removed.
            unset($this->links[$path]);
            $this->dirty = true;
        }
        return $success;
    }

    public function close(): bool
    {
        $success = true;
        if ($this->dirty) {
            $json = json_encode(['version' => 1, 'project' => $this->project, 'links' => $this->links], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $temporary = @tempnam(dirname($this->path), '.symlink-');
            if ($json === false || $temporary === false
                || @file_put_contents($temporary, $json . "\n") !== strlen($json . "\n")
                || !@rename($temporary, $this->path)) {
                if ($temporary !== false && is_file($temporary)) {
                    @unlink($temporary);
                }
                $success = $this->error('cannot save ownership metadata');
            }
        }
        $this->dirty = false;
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
        $this->ready = false;
        return $success;
    }

    private function error(string $message): bool
    {
        $this->io->write("<error>Symlink ownership: $message ({$this->path}).</error>");
        return false;
    }
}
