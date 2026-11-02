<?php

namespace App\Services;

use RuntimeException;

class AdminSettingsFiles
{
    private array $entries = [];
    private array $staged = [];
    private array $applied = [];

    public function put(string $path, string $content): void
    {
        $this->entries[$path] = ['content' => $content];
    }

    public function remove(string $path): void
    {
        $this->entries[$path] = ['remove' => true];
    }

    public function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) throw new RuntimeException('Theme directory not found');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Unsupported theme file');
            $relative = substr($file->getPathname(), strlen(rtrim($source, '/\\')) + 1);
            $this->entries[$target . '/' . $relative] = ['source' => $file->getPathname()];
        }
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function replaceDirectory(string $source, string $target): void
    {
        $this->entries[$target] = ['directory' => true, 'source' => $source];
    }

    public function removeDirectory(string $target): void
    {
        $this->entries[$target] = ['directory' => true];
    }

    public function stage(): void
    {
        foreach ($this->entries as $path => $entry) {
            for ($parent = $path; dirname($parent) !== $parent; $parent = dirname($parent)) {
                if (is_link($parent)) throw new RuntimeException('Cannot replace linked settings resource');
            }
            if (isset($entry['directory'])) {
                $this->stageDirectory($path, $entry['source'] ?? null);
                continue;
            }
            if (file_exists($path) && !is_file($path)) throw new RuntimeException('Invalid settings resource target');
            if (isset($entry['remove']) && !is_file($path)) continue;
            if (isset($entry['content']) && is_file($path) && file_get_contents($path) === $entry['content']) continue;
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create settings resource directory');
            }
            $mode = is_file($path) ? fileperms($path) & 0777 : 0644;
            $this->staged[$path] = ['next' => null, 'before' => null];
            if (is_file($path)) {
                $backup = $this->temporary($directory);
                $this->staged[$path]['before'] = $backup;
                if (!copy($path, $backup) || !chmod($backup, $mode)) throw new RuntimeException('Cannot back up settings resource');
            }
            if (isset($entry['remove'])) continue;
            $next = $this->temporary($directory);
            $this->staged[$path]['next'] = $next;
            $written = isset($entry['source'])
                ? copy($entry['source'], $next)
                : file_put_contents($next, $entry['content']) === strlen($entry['content']);
            if (!$written || !chmod($next, $mode)) throw new RuntimeException('Cannot stage settings resource');
        }
    }

    public function publish(): void
    {
        foreach ($this->staged as $path => $entry) {
            if (isset($entry['directory'])) {
                if ($entry['before'] !== null && !rename($path, $entry['before'])) throw new RuntimeException('Cannot back up theme directory');
                $this->applied[] = $path;
                if ($entry['next'] !== null && !rename($entry['next'], $path)) throw new RuntimeException('Cannot publish theme directory');
                $this->staged[$path]['next'] = null;
                continue;
            }
            $published = $entry['next'] === null ? unlink($path) : rename($entry['next'], $path);
            if (!$published) throw new RuntimeException('Cannot save settings resource');
            $this->staged[$path]['next'] = null;
            $this->applied[] = $path;
            $this->invalidatePhp($path);
        }
    }

    public function rollback(): void
    {
        $failed = [];
        foreach (array_reverse($this->applied) as $path) {
            $before = $this->staged[$path]['before'];
            try {
                if (isset($this->staged[$path]['directory']) && is_dir($path)) {
                    (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($path);
                }
                $restored = $before !== null ? @rename($before, $path) : (!file_exists($path) || @unlink($path));
            } catch (\Throwable $error) {
                $restored = false;
            }
            if (!$restored) {
                $failed[] = $path . ($before !== null ? ' (backup: ' . $before . ')' : ' (new file)');
                // Retain the backup for recovery if the filesystem also rejects rollback.
                $this->staged[$path]['before'] = null;
            }
            if ($restored && !isset($this->staged[$path]['directory'])) $this->invalidatePhp($path);
        }
        $this->applied = [];
        if ($failed !== []) throw new RuntimeException('Cannot restore settings resources: ' . implode(', ', $failed));
    }

    public function cleanup(): void
    {
        foreach ($this->staged as $entry) {
            foreach (['next', 'before'] as $key) {
                $path = $entry[$key];
                if ($path === null) continue;
                try {
                    if (isset($entry['directory']) && is_dir($path)) (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($path);
                    elseif (is_file($path)) @unlink($path);
                } catch (\Throwable $error) {
                    // A leftover temporary backup must not turn a committed save into a failure.
                }
            }
        }
        $this->staged = [];
        $this->applied = [];
    }

    private function temporary(string $directory): string
    {
        $path = tempnam($directory, '.settings-');
        if ($path === false) throw new RuntimeException('Cannot stage settings resource');
        return $path;
    }

    private function invalidatePhp(string $path): void
    {
        if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) opcache_invalidate($path, true);
    }

    private function stageDirectory(string $target, ?string $source): void
    {
        if (file_exists($target) && !is_dir($target)) throw new RuntimeException('Invalid theme directory target');
        if ($source === null && !is_dir($target)) return;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new RuntimeException('Cannot create theme directory');
        $this->staged[$target] = ['directory' => true, 'next' => null, 'before' => null];
        if (is_dir($target)) {
            $backup = $this->temporary($parent);
            $this->staged[$target]['before'] = $backup;
            if (!unlink($backup)) throw new RuntimeException('Cannot stage theme backup');
        }
        if ($source === null) return;
        if (!is_dir($source) || is_link($source)) throw new RuntimeException('Invalid theme source directory');
        $next = $this->temporary($parent);
        $this->staged[$target]['next'] = $next;
        if (!unlink($next) || !mkdir($next, 0755)) throw new RuntimeException('Cannot stage theme directory');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new RuntimeException('Linked theme resources are not supported');
            $path = $next . '/' . substr($file->getPathname(), strlen(rtrim($source, '/\\')) + 1);
            if ($file->isDir()) {
                if (!mkdir($path, 0755)) throw new RuntimeException('Cannot stage theme subdirectory');
            } elseif (!$file->isFile() || !copy($file->getPathname(), $path) || !chmod($path, 0644)) {
                throw new RuntimeException('Cannot stage theme file');
            }
        }
    }
}
