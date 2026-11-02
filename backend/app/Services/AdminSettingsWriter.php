<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AdminSettingsWriter
{
    private string $path;
    private string $cachedConfigPath;

    public function __construct(?string $path = null, ?string $cachedConfigPath = null)
    {
        $path ??= config_path('v2board.php');
        $this->path = realpath($path) ?: $path;
        $this->cachedConfigPath = $cachedConfigPath ?? app()->getCachedConfigPath();
    }

    public function save(array $changes, ?AdminSettingsFiles $files = null): void
    {
        if ($changes === [] && ($files === null || $files->isEmpty())) return;
        $this->update(static fn (array $settings) => [$changes, $files]);
    }

    public function update(callable $prepare): void
    {
        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) $lock = @fopen($this->path . '.lock', 'r');
        if ($lock === false) throw new \RuntimeException('Cannot lock panel settings');
        $temporary = null;
        $files = null;
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock panel settings');
            clearstatcache(true, $this->path);
            $exists = is_file($this->path);
            if ($exists && function_exists('opcache_invalidate')) opcache_invalidate($this->path, true);
            $settings = $exists ? require $this->path : config('v2board', []);
            if (!is_array($settings)) throw new \RuntimeException('Invalid panel settings file');
            [$changes, $files] = $prepare($settings);
            $settings = array_replace($settings, $changes);
            $errors = AdminSettingsRules::errors($settings, $changes);
            if ($errors !== []) throw ValidationException::withMessages($errors);
            if ($changes !== []) {
                $contents = "<?php\nreturn " . var_export($settings, true) . ";\n";
                $mode = $exists ? fileperms($this->path) & 0777 : 0600;
                $temporary = tempnam(dirname($this->path), 'v2board-');
                if ($temporary === false) throw new \RuntimeException('Cannot stage panel settings');
                if (file_put_contents($temporary, $contents) !== strlen($contents)
                    || !chmod($temporary, $mode)) {
                    throw new \RuntimeException('Cannot stage panel settings');
                }
            }
            $files?->stage();
            // Validate and stage all resources before making any of them visible.
            try {
                if ($changes !== [] && is_file($this->cachedConfigPath) && !unlink($this->cachedConfigPath)) {
                    throw new \RuntimeException('Cannot clear panel settings cache');
                }
                $files?->publish();
                if ($temporary !== null && !rename($temporary, $this->path)) {
                    throw new \RuntimeException('Cannot save panel settings');
                }
            } catch (\Throwable $error) {
                try { $files?->rollback(); }
                catch (\Throwable $rollbackError) {
                    throw new \RuntimeException($rollbackError->getMessage(), 0, $error);
                }
                throw $error;
            }
            $temporary = null;
            if (function_exists('opcache_invalidate')) opcache_invalidate($this->path, true);
            config(['v2board' => $settings]);
        } finally {
            $files?->cleanup();
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
